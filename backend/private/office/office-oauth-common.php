<?php
declare(strict_types=1);

/**
 * Shared server-side primitives for OFFICE-OAUTH-00A.
 *
 * Configuration is read from the Hostinger process environment, with a private
 * account-level PHP file as fallback.
 * No credential or deployment-specific value belongs in this file.
 */

const OFFICE_GOOGLE_IDENTITY_SCOPES = ['openid', 'email', 'profile'];
const OFFICE_OAUTH_STATE_TTL_SECONDS = 600;
const OFFICE_OAUTH_NONCE_COOKIE = '__Host-lila-office-oauth-nonce';
const OFFICE_MAX_HTTP_RESPONSE_BYTES = 1048576;

final class OfficeOAuthException extends RuntimeException
{
    private int $httpStatus;
    private string $publicCode;

    public function __construct(string $publicCode, int $httpStatus = 500, ?Throwable $previous = null)
    {
        parent::__construct($publicCode, 0, $previous);
        $this->publicCode = $publicCode;
        $this->httpStatus = $httpStatus;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getPublicCode(): string
    {
        return $this->publicCode;
    }
}

function office_private_config(): array
{
    static $privateConfig = null;
    if (is_array($privateConfig)) {
        return $privateConfig;
    }

    $privateConfigPath = __DIR__ . '/office-oauth-secrets.php';
    if (!is_file($privateConfigPath) || !is_readable($privateConfigPath)) {
        throw new OfficeOAuthException('SERVER_MISCONFIGURED', 503);
    }

    try {
        $loadedConfig = require $privateConfigPath;
    } catch (Throwable $exception) {
        throw new OfficeOAuthException('SERVER_MISCONFIGURED', 503, $exception);
    }

    if (!is_array($loadedConfig)) {
        throw new OfficeOAuthException('SERVER_MISCONFIGURED', 503);
    }

    $privateConfig = $loadedConfig;
    return $privateConfig;
}

function office_env(string $name): string
{
    $value = getenv($name);
    if ($value !== false && trim($value) !== '') {
        return trim($value);
    }

    $privateConfig = office_private_config();
    $value = $privateConfig[$name] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new OfficeOAuthException('SERVER_MISCONFIGURED', 503);
    }

    return trim($value);
}

function office_https_url(string $value, string $errorCode = 'SERVER_MISCONFIGURED'): string
{
    if (filter_var($value, FILTER_VALIDATE_URL) === false) {
        throw new OfficeOAuthException($errorCode, 503);
    }

    $parts = parse_url($value);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])) {
        throw new OfficeOAuthException($errorCode, 503);
    }

    return $value;
}

function office_origin_from_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new OfficeOAuthException('SERVER_MISCONFIGURED', 503);
    }

    $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }

    return $origin;
}

function office_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $stateSecret = office_env('OAUTH_STATE_SECRET');
    $serviceRoleKey = office_env('SUPABASE_SERVICE_ROLE_KEY');
    if (strlen($stateSecret) < 32 || strlen($serviceRoleKey) < 32) {
        throw new OfficeOAuthException('SERVER_MISCONFIGURED', 503);
    }

    $appUrl = office_https_url(office_env('LILA_APP_URL'));
    $config = [
        'google_client_id' => office_env('GOOGLE_OAUTH_CLIENT_ID'),
        'google_client_secret' => office_env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'google_redirect_uri' => office_https_url(office_env('GOOGLE_OAUTH_REDIRECT_URI')),
        'supabase_url' => rtrim(office_https_url(office_env('SUPABASE_URL')), '/'),
        'supabase_service_role_key' => $serviceRoleKey,
        'lila_app_url' => $appUrl,
        'lila_app_origin' => office_origin_from_url($appUrl),
        'state_secret' => $stateSecret,
    ];

    return $config;
}

function office_security_headers(): void
{
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
}

function office_json_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"ok":false,"error":"RESPONSE_ENCODING_FAILED"}';
    }
    echo $json;
    exit;
}

function office_apply_cors(array $allowedMethods): void
{
    $config = office_config();
    $requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

    if ($requestOrigin !== '' && !hash_equals($config['lila_app_origin'], $requestOrigin)) {
        throw new OfficeOAuthException('ORIGIN_NOT_ALLOWED', 403);
    }

    if ($requestOrigin !== '') {
        header('Access-Control-Allow-Origin: ' . $config['lila_app_origin']);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
        if ($requestOrigin === '') {
            throw new OfficeOAuthException('ORIGIN_REQUIRED', 403);
        }
        header('Access-Control-Allow-Methods: ' . implode(', ', array_merge($allowedMethods, ['OPTIONS'])));
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Max-Age: 600');
        http_response_code(204);
        exit;
    }
}

function office_run_api(array $allowedMethods, callable $handler): void
{
    try {
        office_security_headers();
        office_apply_cors($allowedMethods);

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, $allowedMethods, true)) {
            header('Allow: ' . implode(', ', array_merge($allowedMethods, ['OPTIONS'])));
            throw new OfficeOAuthException('METHOD_NOT_ALLOWED', 405);
        }

        $handler();
    } catch (OfficeOAuthException $exception) {
        office_json_response($exception->getHttpStatus(), [
            'ok' => false,
            'error' => $exception->getPublicCode(),
        ]);
    } catch (Throwable $exception) {
        office_json_response(500, ['ok' => false, 'error' => 'INTERNAL_ERROR']);
    }
}

function office_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function office_base64url_decode(string $value): string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        throw new OfficeOAuthException('OAUTH_STATE_INVALID', 400);
    }

    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        throw new OfficeOAuthException('OAUTH_STATE_INVALID', 400);
    }

    return $decoded;
}

function office_random_token(int $bytes = 32): string
{
    return office_base64url_encode(random_bytes($bytes));
}

function office_create_oauth_state(string $userId, string $nonce): string
{
    $now = time();
    $payload = [
        'v' => 1,
        'uid' => $userId,
        'iat' => $now,
        'exp' => $now + OFFICE_OAUTH_STATE_TTL_SECONDS,
        'nh' => office_base64url_encode(hash('sha256', $nonce, true)),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new OfficeOAuthException('OAUTH_STATE_CREATION_FAILED', 500);
    }

    $encoded = office_base64url_encode($json);
    $signature = hash_hmac('sha256', $encoded, office_config()['state_secret'], true);
    return $encoded . '.' . office_base64url_encode($signature);
}

function office_validate_oauth_state(string $state, string $nonce): array
{
    if (strlen($state) > 2048 || substr_count($state, '.') !== 1) {
        throw new OfficeOAuthException('OAUTH_STATE_INVALID', 400);
    }
    [$encoded, $providedSignature] = explode('.', $state, 2);
    $expectedSignature = hash_hmac('sha256', $encoded, office_config()['state_secret'], true);
    $decodedSignature = office_base64url_decode($providedSignature);
    if (!hash_equals($expectedSignature, $decodedSignature)) {
        throw new OfficeOAuthException('OAUTH_STATE_INVALID', 400);
    }

    $payload = json_decode(office_base64url_decode($encoded), true);
    $now = time();
    if (!is_array($payload)
        || ($payload['v'] ?? null) !== 1
        || !is_string($payload['uid'] ?? null)
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $payload['uid']) !== 1
        || !is_int($payload['iat'] ?? null)
        || !is_int($payload['exp'] ?? null)
        || !is_string($payload['nh'] ?? null)
        || $payload['iat'] > $now + 60
        || $payload['exp'] < $now
        || $payload['exp'] - $payload['iat'] !== OFFICE_OAUTH_STATE_TTL_SECONDS
        || !hash_equals($payload['nh'], office_base64url_encode(hash('sha256', $nonce, true)))) {
        throw new OfficeOAuthException('OAUTH_STATE_INVALID', 400);
    }

    return $payload;
}

function office_set_nonce_cookie(string $nonce): void
{
    if (headers_sent()) {
        throw new OfficeOAuthException('OAUTH_COOKIE_FAILED', 500);
    }
    $created = setcookie(OFFICE_OAUTH_NONCE_COOKIE, $nonce, [
        'expires' => time() + OFFICE_OAUTH_STATE_TTL_SECONDS,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!$created) {
        throw new OfficeOAuthException('OAUTH_COOKIE_FAILED', 500);
    }
}

function office_get_nonce_cookie(): string
{
    $nonce = (string) ($_COOKIE[OFFICE_OAUTH_NONCE_COOKIE] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{40,100}$/D', $nonce) !== 1) {
        throw new OfficeOAuthException('OAUTH_COOKIE_INVALID', 400);
    }
    return $nonce;
}

function office_clear_nonce_cookie(): void
{
    setcookie(OFFICE_OAUTH_NONCE_COOKIE, '', [
        'expires' => 1,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function office_authorization_header(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];
    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $candidates[] = (string) $value;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }
    throw new OfficeOAuthException('AUTH_REQUIRED', 401);
}

function office_supabase_access_token(): string
{
    $authorization = office_authorization_header();
    if (preg_match('/^Bearer\s+([^\s]+)$/iD', $authorization, $matches) !== 1
        || strlen($matches[1]) > 8192) {
        throw new OfficeOAuthException('AUTH_INVALID', 401);
    }
    return $matches[1];
}

function office_http_json(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new OfficeOAuthException('CURL_UNAVAILABLE', 503);
    }
    office_https_url($url, 'UPSTREAM_URL_INVALID');

    $responseBody = '';
    $curl = curl_init($url);
    if ($curl === false) {
        throw new OfficeOAuthException('UPSTREAM_UNAVAILABLE', 502);
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Li.La-Office-OAuth/00A',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
            if (strlen($responseBody) + strlen($chunk) > OFFICE_MAX_HTTP_RESPONSE_BYTES) {
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($curl, $options);

    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_errno($curl);
    curl_close($curl);

    if ($success === false || $curlError !== 0 || $status === 0) {
        throw new OfficeOAuthException('UPSTREAM_UNAVAILABLE', 502);
    }

    $decoded = $responseBody === '' ? null : json_decode($responseBody, true);
    if ($responseBody !== '' && !is_array($decoded)) {
        throw new OfficeOAuthException('UPSTREAM_RESPONSE_INVALID', 502);
    }

    return ['status' => $status, 'body' => $decoded, 'raw' => $responseBody];
}

function office_verified_supabase_user(): array
{
    $config = office_config();
    $accessToken = office_supabase_access_token();
    $response = office_http_json('GET', $config['supabase_url'] . '/auth/v1/user', [
        'Accept: application/json',
        'apikey: ' . $config['supabase_service_role_key'],
        'Authorization: Bearer ' . $accessToken,
    ]);

    if ($response['status'] === 401 || $response['status'] === 403) {
        throw new OfficeOAuthException('AUTH_INVALID', 401);
    }
    if ($response['status'] !== 200 || !is_array($response['body'])) {
        throw new OfficeOAuthException('SUPABASE_UNAVAILABLE', 502);
    }

    $userId = $response['body']['id'] ?? null;
    if (!is_string($userId)
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $userId) !== 1) {
        throw new OfficeOAuthException('AUTH_INVALID', 401);
    }

    return ['id' => strtolower($userId)];
}

function office_supabase_rest(string $method, string $pathAndQuery, ?array $payload = null, array $extraHeaders = []): array
{
    $config = office_config();
    $headers = array_merge([
        'Accept: application/json',
        'Content-Type: application/json',
        'apikey: ' . $config['supabase_service_role_key'],
        'Authorization: Bearer ' . $config['supabase_service_role_key'],
    ], $extraHeaders);
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($payload !== null && $body === false) {
        throw new OfficeOAuthException('REQUEST_ENCODING_FAILED', 500);
    }

    return office_http_json($method, $config['supabase_url'] . '/rest/v1/' . ltrim($pathAndQuery, '/'), $headers, $body);
}

function office_google_authorization_url(string $state, string $nonce): string
{
    $config = office_config();
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $config['google_client_id'],
        'redirect_uri' => $config['google_redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', OFFICE_GOOGLE_IDENTITY_SCOPES),
        'state' => $state,
        'nonce' => $nonce,
        'include_granted_scopes' => 'false',
    ], '', '&', PHP_QUERY_RFC3986);
}

function office_google_exchange_code(string $code): array
{
    if ($code === '' || strlen($code) > 4096) {
        throw new OfficeOAuthException('GOOGLE_CODE_INVALID', 400);
    }
    $config = office_config();
    $form = http_build_query([
        'code' => $code,
        'client_id' => $config['google_client_id'],
        'client_secret' => $config['google_client_secret'],
        'redirect_uri' => $config['google_redirect_uri'],
        'grant_type' => 'authorization_code',
    ], '', '&', PHP_QUERY_RFC3986);
    $response = office_http_json('POST', 'https://oauth2.googleapis.com/token', [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ], $form);

    if ($response['status'] !== 200 || !is_array($response['body'])) {
        throw new OfficeOAuthException('GOOGLE_TOKEN_EXCHANGE_FAILED', 502);
    }
    $idToken = $response['body']['id_token'] ?? null;
    if (!is_string($idToken) || $idToken === '' || strlen($idToken) > 16384) {
        throw new OfficeOAuthException('GOOGLE_IDENTITY_INVALID', 502);
    }

    return ['id_token' => $idToken];
}

function office_google_validate_identity(string $idToken, string $nonce): array
{
    $config = office_config();
    $url = 'https://oauth2.googleapis.com/tokeninfo?' . http_build_query(
        ['id_token' => $idToken],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    $response = office_http_json('GET', $url, ['Accept: application/json']);
    if ($response['status'] !== 200 || !is_array($response['body'])) {
        throw new OfficeOAuthException('GOOGLE_IDENTITY_INVALID', 400);
    }

    $claims = $response['body'];
    $issuer = (string) ($claims['iss'] ?? '');
    $audience = (string) ($claims['aud'] ?? '');
    $authorizedParty = isset($claims['azp']) ? (string) $claims['azp'] : $audience;
    $subject = (string) ($claims['sub'] ?? '');
    $email = (string) ($claims['email'] ?? '');
    $verified = $claims['email_verified'] ?? false;
    $expiresAt = filter_var($claims['exp'] ?? null, FILTER_VALIDATE_INT);
    $tokenNonce = (string) ($claims['nonce'] ?? '');

    if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
        || !hash_equals($config['google_client_id'], $audience)
        || !hash_equals($config['google_client_id'], $authorizedParty)
        || preg_match('/^[0-9]{1,255}$/D', $subject) !== 1
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || !($verified === true || $verified === 'true')
        || $expiresAt === false
        || $expiresAt < time()
        || !hash_equals($nonce, $tokenNonce)) {
        throw new OfficeOAuthException('GOOGLE_IDENTITY_INVALID', 400);
    }

    return [
        'subject' => $subject,
        'email' => strtolower($email),
        'email_verified' => true,
        'scopes' => OFFICE_GOOGLE_IDENTITY_SCOPES,
    ];
}

function office_store_google_identity(string $userId, array $identity): void
{
    $now = gmdate('c');
    $response = office_supabase_rest(
        'POST',
        'office_google_connections?on_conflict=user_id',
        [[
            'user_id' => $userId,
            'google_subject' => $identity['subject'],
            'google_email' => $identity['email'],
            'google_email_verified' => true,
            'scopes' => OFFICE_GOOGLE_IDENTITY_SCOPES,
            'is_connected' => true,
            'connected_at' => $now,
            'disconnected_at' => null,
            'updated_at' => $now,
        ]],
        ['Prefer: resolution=merge-duplicates,return=minimal']
    );
    if (!in_array($response['status'], [200, 201, 204], true)) {
        if ($response['status'] === 409) {
            throw new OfficeOAuthException('GOOGLE_IDENTITY_ALREADY_LINKED', 409);
        }
        throw new OfficeOAuthException('CONNECTION_STORE_FAILED', 502);
    }
}

function office_google_connection_status(string $userId): array
{
    $query = 'office_google_connections?select=google_email,scopes,is_connected,connected_at,disconnected_at'
        . '&user_id=eq.' . rawurlencode($userId) . '&limit=1';
    $response = office_supabase_rest('GET', $query);
    if ($response['status'] !== 200 || !is_array($response['body'])) {
        throw new OfficeOAuthException('CONNECTION_READ_FAILED', 502);
    }
    if ($response['body'] === []) {
        return ['connected' => false];
    }

    $row = $response['body'][0];
    return [
        'connected' => ($row['is_connected'] ?? false) === true,
        'google_email' => is_string($row['google_email'] ?? null) ? $row['google_email'] : null,
        'scopes' => is_array($row['scopes'] ?? null) ? $row['scopes'] : [],
        'connected_at' => $row['connected_at'] ?? null,
        'disconnected_at' => $row['disconnected_at'] ?? null,
    ];
}

function office_disconnect_google_identity(string $userId): bool
{
    $now = gmdate('c');
    $query = 'office_google_connections?user_id=eq.' . rawurlencode($userId) . '&is_connected=eq.true';
    $response = office_supabase_rest('PATCH', $query, [
        'is_connected' => false,
        'disconnected_at' => $now,
        'updated_at' => $now,
    ], ['Prefer: return=representation']);
    if ($response['status'] !== 200 || !is_array($response['body'])) {
        throw new OfficeOAuthException('DISCONNECT_FAILED', 502);
    }
    return $response['body'] !== [];
}

function office_redirect_to_app(string $result): void
{
    $allowedResults = ['connected', 'denied', 'error'];
    if (!in_array($result, $allowedResults, true)) {
        $result = 'error';
    }
    $appUrl = office_config()['lila_app_url'];
    $separator = strpos($appUrl, '?') === false ? '?' : '&';
    header('Location: ' . $appUrl . $separator . 'office_google=' . rawurlencode($result), true, 303);
    exit;
}
