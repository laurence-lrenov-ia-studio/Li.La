<?php
declare(strict_types=1);

final class ComApiException extends RuntimeException
{
    private string $publicCode;
    private int $httpStatus;

    public function __construct(string $publicCode, int $httpStatus, ?Throwable $previous = null)
    {
        parent::__construct($publicCode, 0, $previous);
        $this->publicCode = $publicCode;
        $this->httpStatus = $httpStatus;
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

require_once __DIR__ . '/com-auth.php';
require_once __DIR__ . '/com-openai.php';

const COM_ERROR_MESSAGES = [
    'INVALID_REQUEST' => 'La demande est invalide.',
    'AUTH_REQUIRED' => 'Veuillez vous reconnecter.',
    'COM_NOT_ALLOWED' => "Li.La Com n'est pas activé pour ce compte.",
    'RATE_LIMITED' => 'Trop de générations ont été demandées. Réessayez plus tard.',
    'GENERATION_UNAVAILABLE' => 'La génération est momentanément indisponible.',
    'GENERATION_TIMEOUT' => 'La génération a pris trop de temps. Réessayez.',
];

function comMain(): void
{
    comSendBaseHeaders();
    $requestId = null;

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        if ($contentType !== 'application/json') {
            throw new ComApiException('INVALID_REQUEST', 400);
        }

        $config = comLoadPrivateConfig();
        comEnforceOrigin($config);
        $maxBodyBytes = comRequestConfigInt($config, 'max_body_bytes', 32768, 1024, 65536);
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
        if ($contentLength !== null && ($contentLength < 0 || $contentLength > $maxBodyBytes)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }

        $rawBody = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > $maxBodyBytes) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        try {
            $body = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ComApiException('INVALID_REQUEST', 400, $exception);
        }
        if (!is_array($body) || comRequestIsList($body)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }

        $requestId = isset($body['request_id']) && is_string($body['request_id'])
            ? strtolower(trim($body['request_id']))
            : null;
        if ($requestId === null || !comRequestIsUuid($requestId)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        if (($body['version'] ?? null) !== 1 || !is_string($body['action'] ?? null)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }

        $action = $body['action'];
        if ($action === 'check_access') {
            comRequestAssertExactKeys($body, ['version', 'action', 'request_id']);
        } elseif ($action === 'generate') {
            comRequestAssertAllowedKeys($body, [
                'version', 'action', 'request_id', 'workflow', 'data', 'channel', 'property',
                'professional_profile', 'preferences',
            ]);
            comAssertGenerateEnvelope($body);
        } else {
            throw new ComApiException('INVALID_REQUEST', 400);
        }

        $access = comAuthorize($config, comReadBearerToken());
        if ($action === 'check_access') {
            comRespond(200, ['ok' => true, 'request_id' => $requestId, 'allowed' => true]);
        }

        $validatedRequest = comValidateWorkflowRequest($body);
        comEnforceRateLimit($config, $access['user_id']);
        $generated = comGenerateWorkflow($config, $validatedRequest, $access['user_id']);
        $response = [
            'ok' => true,
            'request_id' => $requestId,
        ] + $generated;
        if ($validatedRequest['legacy']) {
            $response['meta'] = ['channel' => $validatedRequest['data']['channel']];
        }
        comRespond(200, $response);
    } catch (ComApiException $exception) {
        comRespondError($exception->httpStatus(), $exception->publicCode(), $requestId);
    } catch (Throwable $exception) {
        error_log('Li.La COM unexpected error request_id=' . ($requestId ?? 'unknown'));
        comRespondError(503, 'GENERATION_UNAVAILABLE', $requestId);
    }
}

function comAssertGenerateEnvelope(array $body): void
{
    $hasWorkflow = array_key_exists('workflow', $body);
    $hasData = array_key_exists('data', $body);
    if ($hasWorkflow || $hasData) {
        if (!$hasWorkflow || !$hasData) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        foreach (['channel', 'property', 'professional_profile', 'preferences'] as $legacyKey) {
            if (array_key_exists($legacyKey, $body)) {
                throw new ComApiException('INVALID_REQUEST', 400);
            }
        }
        return;
    }

    foreach (['channel', 'property', 'preferences'] as $requiredKey) {
        if (!array_key_exists($requiredKey, $body)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
    }
}

function comLoadPrivateConfig(): array
{
    $configPath = getenv('LILA_COM_CONFIG_PATH');
    if (is_string($configPath) && trim($configPath) !== '') {
        $configPath = trim($configPath);
    } else {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!is_string($documentRoot) || trim($documentRoot) === '') {
            throw new ComApiException('GENERATION_UNAVAILABLE', 503);
        }
        $configPath = dirname(rtrim(trim($documentRoot), '/\\'))
            . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'com'
            . DIRECTORY_SEPARATOR . 'com-config.php';
    }
    if (!is_file($configPath) || !is_readable($configPath)) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }
    return $config;
}

function comSendBaseHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function comEnforceOrigin(array $config): void
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
    if ($origin === '') {
        return;
    }
    $allowedOrigin = $config['allowed_origin'] ?? null;
    if (!is_string($allowedOrigin) || $allowedOrigin === '' || !hash_equals($allowedOrigin, $origin)) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

function comReadBearerToken(): string
{
    $authorization = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            $authorization = trim($_SERVER[$key]);
            break;
        }
    }
    if (
        strlen($authorization) > 8192
        || preg_match('/^Bearer[ \t]+([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/iD', $authorization, $matches) !== 1
    ) {
        throw new ComApiException('AUTH_REQUIRED', 401);
    }
    return $matches[1];
}

/** @return array{workflow:string,data:array,legacy:bool} */
function comValidateWorkflowRequest(array $body): array
{
    if (array_key_exists('workflow', $body)) {
        $workflow = comRequestRequiredEnum($body, 'workflow', [
            'COM-01', 'COM-02', 'COM-03', 'COM-04', 'COM-05', 'COM-06',
        ]);
        if (!is_array($body['data']) || comRequestIsList($body['data']) || $body['data'] === []) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        comRequestValidateBusinessData($body['data'], 0);
        comRequestAssertWorkflowMinimum($workflow, $body['data']);
        return ['workflow' => $workflow, 'data' => $body['data'], 'legacy' => false];
    }

    return ['workflow' => 'COM-01', 'data' => comValidateLegacyAnnouncementRequest($body), 'legacy' => true];
}

function comRequestAssertWorkflowMinimum(string $workflow, array $data): void
{
    $aliases = [
        'COM-01' => ['property', 'bien', 'type', 'highlights'],
        'COM-02' => ['context', 'contexte', 'situation', 'topic', 'sujet'],
        'COM-03' => ['objection', 'objection_exacte'],
        'COM-04' => ['notes', 'notes_brutes', 'visit_notes'],
        'COM-05' => ['context', 'contexte', 'situation', 'objective', 'objectif'],
        'COM-06' => ['topics', 'sujets', 'actions', 'content_inputs', 'contexte'],
    ];
    foreach ($aliases[$workflow] as $key) {
        if (array_key_exists($key, $data) && comRequestBusinessValueHasContent($data[$key])) {
            return;
        }
    }
    throw new ComApiException('INVALID_REQUEST', 400);
}

function comRequestBusinessValueHasContent($value): bool
{
    if (is_string($value)) {
        return trim($value) !== '';
    }
    return is_array($value) && $value !== [];
}

function comRequestValidateBusinessData($value, int $depth): void
{
    if ($depth > 5) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    if (is_string($value)) {
        if (comRequestTextLength(trim($value)) > 4000) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        return;
    }
    if (is_int($value) || is_bool($value) || $value === null) {
        return;
    }
    if (is_float($value)) {
        if (!is_finite($value)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        return;
    }
    if (!is_array($value) || count($value) > 50) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    foreach ($value as $key => $item) {
        if (!is_int($key) && (!is_string($key) || $key === '' || strlen($key) > 100)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        comRequestValidateBusinessData($item, $depth + 1);
    }
}

/** @return array{channel:string,property:array,professional_profile:array,preferences:array} */
function comValidateLegacyAnnouncementRequest(array $body): array
{
    $channel = comRequestRequiredEnum($body, 'channel', ['portail_immobilier']);
    if (!is_array($body['property'] ?? null) || comRequestIsList($body['property'])) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }

    $property = $body['property'];
    comRequestAssertAllowedKeys($property, [
        'operation', 'type', 'location', 'price_eur', 'surface_m2', 'rooms', 'bedrooms',
        'condition', 'energy_rating', 'features', 'highlights', 'linked_property_id',
    ]);
    foreach (['operation', 'type', 'location', 'highlights'] as $requiredKey) {
        if (!array_key_exists($requiredKey, $property)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
    }
    $validatedProperty = [
        'operation' => comRequestRequiredEnum($property, 'operation', ['vente', 'location']),
        'type' => comRequestRequiredEnum($property, 'type', [
            'appartement', 'maison', 'terrain', 'immeuble', 'local_commercial', 'parking', 'autre',
        ]),
        'location' => comRequestRequiredString($property, 'location', 2, 160),
        'highlights' => comRequestRequiredString($property, 'highlights', 1, 1200),
    ];

    if (array_key_exists('price_eur', $property)) {
        $validatedProperty['price_eur'] = comRequestNumber($property, 'price_eur', 1, 1000000000);
    }
    if (array_key_exists('surface_m2', $property)) {
        $validatedProperty['surface_m2'] = comRequestNumber($property, 'surface_m2', 1, 100000);
    }
    if (array_key_exists('rooms', $property)) {
        $validatedProperty['rooms'] = comRequestInteger($property, 'rooms', 1, 100);
    }
    if (array_key_exists('bedrooms', $property)) {
        $validatedProperty['bedrooms'] = comRequestInteger($property, 'bedrooms', 0, 100);
        if (isset($validatedProperty['rooms']) && $validatedProperty['bedrooms'] > $validatedProperty['rooms']) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
    }
    if (array_key_exists('condition', $property)) {
        $validatedProperty['condition'] = comRequestRequiredEnum($property, 'condition', [
            'neuf', 'renove', 'bon_etat', 'a_rafraichir', 'a_renover', 'autre',
        ]);
    }
    if (array_key_exists('energy_rating', $property)) {
        $validatedProperty['energy_rating'] = comRequestRequiredEnum(
            $property,
            'energy_rating',
            ['A', 'B', 'C', 'D', 'E', 'F', 'G']
        );
    }
    if (array_key_exists('features', $property)) {
        $validatedProperty['features'] = comRequestStringList($property['features'], 12, 120);
    }
    if (array_key_exists('linked_property_id', $property)) {
        $validatedProperty['linked_property_id'] = comRequestOptionalString($property['linked_property_id'], 100);
    }

    $profile = [];
    if (array_key_exists('professional_profile', $body)) {
        if (!is_array($body['professional_profile']) || comRequestIsList($body['professional_profile'])) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        $profile = $body['professional_profile'];
    }
    comRequestAssertAllowedKeys($profile, ['display_name', 'job', 'company', 'business_area', 'signature']);
    $validatedProfile = [];
    foreach ([
        'display_name' => 120,
        'job' => 120,
        'company' => 160,
        'business_area' => 160,
        'signature' => 500,
    ] as $key => $maxLength) {
        if (array_key_exists($key, $profile)) {
            $validatedProfile[$key] = comRequestOptionalString($profile[$key], $maxLength);
        }
    }

    if (!is_array($body['preferences'] ?? null) || comRequestIsList($body['preferences'])) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    $preferences = $body['preferences'];
    comRequestAssertAllowedKeys($preferences, ['tone', 'emojis']);
    $validatedPreferences = [
        'tone' => comRequestRequiredEnum($preferences, 'tone', [
            'professionnel_chaleureux', 'professionnel', 'dynamique', 'premium', 'sobre',
        ]),
        'emojis' => array_key_exists('emojis', $preferences)
            ? comRequestRequiredEnum($preferences, 'emojis', ['aucun', 'discrets', 'moderes'])
            : 'aucun',
    ];

    return [
        'channel' => $channel,
        'property' => $validatedProperty,
        'professional_profile' => $validatedProfile,
        'preferences' => $validatedPreferences,
    ];
}

function comEnforceRateLimit(array $config, string $userId): void
{
    $limit = comRequestConfigInt($config, 'rate_limit_count', 10, 1, 100);
    $window = comRequestConfigInt($config, 'rate_limit_window_seconds', 600, 60, 86400);
    $hmacKey = $config['rate_limit_hmac_key'] ?? null;
    if (!is_string($hmacKey) || strlen($hmacKey) < 32) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }

    $technicalKey = hash_hmac('sha256', $userId, $hmacKey);
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'lila-com-rate-' . $technicalKey . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }

    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $timestamps = [];
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $timestamps = array_values(array_filter($decoded, 'is_int'));
                }
            } catch (JsonException $exception) {
                $timestamps = [];
            }
        }

        $now = time();
        $cutoff = $now - $window;
        $timestamps = array_values(array_filter(
            $timestamps,
            static function (int $timestamp) use ($cutoff): bool {
                return $timestamp > $cutoff;
            }
        ));
        if (count($timestamps) >= $limit) {
            throw new ComApiException('RATE_LIMITED', 429);
        }

        $timestamps[] = $now;
        rewind($handle);
        ftruncate($handle, 0);
        $encoded = json_encode($timestamps, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $encoded) === false || !fflush($handle)) {
            throw new ComApiException('GENERATION_UNAVAILABLE', 503);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function comRequestAssertExactKeys(array $value, array $expected): void
{
    $actual = array_keys($value);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
}

function comRequestAssertAllowedKeys(array $value, array $allowed): void
{
    foreach (array_keys($value) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
    }
}

function comRequestRequiredString(array $source, string $key, int $minLength, int $maxLength): string
{
    if (!isset($source[$key]) || !is_string($source[$key])) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    $value = trim($source[$key]);
    $length = comRequestTextLength($value);
    if ($length < $minLength || $length > $maxLength) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    return $value;
}

function comRequestOptionalString($value, int $maxLength): string
{
    if (!is_string($value)) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    $value = trim($value);
    if (comRequestTextLength($value) > $maxLength) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    return $value;
}

function comRequestRequiredEnum(array $source, string $key, array $allowed): string
{
    $value = $source[$key] ?? null;
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    return $value;
}

/** @return int|float */
function comRequestNumber(array $source, string $key, float $min, float $max)
{
    $value = $source[$key] ?? null;
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    return $value;
}

function comRequestInteger(array $source, string $key, int $min, int $max): int
{
    $value = $source[$key] ?? null;
    if (!is_int($value) || $value < $min || $value > $max) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    return $value;
}

function comRequestStringList($value, int $maxItems, int $maxItemLength): array
{
    if (!is_array($value) || !comRequestIsList($value) || count($value) > $maxItems) {
        throw new ComApiException('INVALID_REQUEST', 400);
    }
    $result = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        $item = trim($item);
        if ($item === '' || comRequestTextLength($item) > $maxItemLength || in_array($item, $result, true)) {
            throw new ComApiException('INVALID_REQUEST', 400);
        }
        $result[] = $item;
    }
    return $result;
}

function comRequestIsList(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function comRequestIsUuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
}

function comRequestTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function comRequestConfigInt(array $config, string $key, int $default, int $min, int $max): int
{
    $value = $config[$key] ?? $default;
    if (!is_int($value) || $value < $min || $value > $max) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }
    return $value;
}

function comRespondError(int $status, string $code, ?string $requestId): void
{
    $message = COM_ERROR_MESSAGES[$code] ?? COM_ERROR_MESSAGES['GENERATION_UNAVAILABLE'];
    $payload = [
        'ok' => false,
        'request_id' => $requestId,
        'error' => ['code' => $code, 'message' => $message],
    ];
    if ($code === 'COM_NOT_ALLOWED') {
        $payload['allowed'] = false;
    }
    comRespond($status, $payload);
}

function comRespond(int $status, array $payload): void
{
    http_response_code($status);
    try {
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        http_response_code(503);
        echo '{"ok":false,"request_id":null,"error":{"code":"GENERATION_UNAVAILABLE","message":"La génération est momentanément indisponible."}}';
    }
    exit;
}

if (!defined('LILA_COM_LIBRARY_ONLY')) {
    comMain();
}
