<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

final class LilaAiUsageException extends RuntimeException
{
}

/**
 * Server-only client for the Li.La AI usage accounting V1 contract.
 *
 * The caller MUST populate $validatedIdentity from an already authenticated
 * backend session. Never build it from request JSON, form fields or query
 * parameters supplied by the browser.
 */
final class LilaAiUsageClient
{
    private string $supabaseUrl;
    private string $secretKey;
    private int $connectTimeoutSeconds;
    private int $requestTimeoutSeconds;

    /** @var callable|null */
    private $transport;

    public function __construct(array $config, ?callable $transport = null)
    {
        $url = self::requiredString($config, 'supabase_url');
        if (!filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'https://') !== 0) {
            throw new InvalidArgumentException('Invalid Supabase URL');
        }

        $secretKey = self::requiredString($config, 'supabase_secret_key');
        if (strpos($secretKey, 'sb_secret_') !== 0 || strlen($secretKey) < 20) {
            throw new InvalidArgumentException('A modern server-only Supabase secret key is required');
        }

        $this->supabaseUrl = rtrim($url, '/');
        $this->secretKey = $secretKey;
        $this->connectTimeoutSeconds = self::boundedInt($config, 'connect_timeout_seconds', 5, 1, 15);
        $this->requestTimeoutSeconds = self::boundedInt($config, 'request_timeout_seconds', 12, 2, 30);
        $this->transport = $transport;
    }

    /**
     * @param array{user_id:string,instance_id:string} $validatedIdentity
     * @return array{reservation_id:string,reservation_status:string,usage_event_id:?string}
     */
    public function reserve(
        string $requestId,
        array $validatedIdentity,
        string $moduleCode,
        string $workflowCode
    ): array {
        $userId = $validatedIdentity['user_id'] ?? '';
        $instanceId = $validatedIdentity['instance_id'] ?? '';
        self::assertUuid($requestId, 'request_id');
        self::assertUuid($userId, 'validated user_id');
        self::assertUuid($instanceId, 'validated instance_id');

        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/D', $moduleCode) !== 1) {
            throw new InvalidArgumentException('Invalid module_code');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $workflowCode) !== 1) {
            throw new InvalidArgumentException('Invalid workflow_code');
        }

        $row = $this->rpc('ai_usage_reserve_v1', [
            'p_contract_version' => 1,
            'p_request_id' => strtolower($requestId),
            'p_user_id' => strtolower($userId),
            'p_instance_id' => strtolower($instanceId),
            'p_module_code' => $moduleCode,
            'p_workflow_code' => $workflowCode,
        ]);

        self::assertUuid((string) ($row['reservation_id'] ?? ''), 'reservation_id response');
        $status = $row['reservation_status'] ?? null;
        if (!is_string($status) || !in_array($status, ['reserved', 'already_reserved', 'already_finalized'], true)) {
            throw new LilaAiUsageException('Invalid reservation response');
        }
        $eventId = $row['usage_event_id'] ?? null;
        if ($eventId !== null) {
            self::assertUuid((string) $eventId, 'usage_event_id response');
        }

        return [
            'reservation_id' => strtolower((string) $row['reservation_id']),
            'reservation_status' => $status,
            'usage_event_id' => $eventId === null ? null : strtolower((string) $eventId),
        ];
    }

    /**
     * @param array{input_tokens:int,cached_input_tokens:int,output_tokens:int,total_tokens:int} $usage
     * @return array{usage_event_id:string,finalization_status:string,currency:string,provider_cost_total:string,customer_chargeable:bool}
     */
    public function finalize(
        string $reservationId,
        string $requestId,
        string $provider,
        string $providerResponseId,
        string $model,
        string $serviceTier,
        DateTimeInterface $providerCreatedAt,
        array $usage,
        string $outcome
    ): array {
        self::assertUuid($reservationId, 'reservation_id');
        self::assertUuid($requestId, 'request_id');

        $provider = strtolower(trim($provider));
        $serviceTier = strtolower(trim($serviceTier));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/D', $provider) !== 1) {
            throw new InvalidArgumentException('Invalid provider');
        }
        if ($providerResponseId === '' || strlen($providerResponseId) > 255) {
            throw new InvalidArgumentException('Invalid provider_response_id');
        }
        if ($model === '' || strlen($model) > 160) {
            throw new InvalidArgumentException('Invalid model');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $serviceTier) !== 1) {
            throw new InvalidArgumentException('Invalid service_tier');
        }
        if (!in_array($outcome, ['succeeded', 'unusable_output', 'provider_incomplete', 'provider_error'], true)) {
            throw new InvalidArgumentException('Invalid outcome');
        }

        $normalizedUsage = [];
        foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'total_tokens'] as $key) {
            if (!array_key_exists($key, $usage) || !is_int($usage[$key]) || $usage[$key] < 0) {
                throw new InvalidArgumentException('Invalid usage metric: ' . $key);
            }
            $normalizedUsage[$key] = $usage[$key];
        }
        if ($normalizedUsage['cached_input_tokens'] > $normalizedUsage['input_tokens']) {
            throw new InvalidArgumentException('cached_input_tokens exceeds input_tokens');
        }

        $providerTimestamp = DateTimeImmutable::createFromInterface($providerCreatedAt)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');

        $row = $this->rpc('ai_usage_finalize_v1', [
            'p_contract_version' => 1,
            'p_reservation_id' => strtolower($reservationId),
            'p_request_id' => strtolower($requestId),
            'p_provider' => $provider,
            'p_provider_response_id' => $providerResponseId,
            'p_model' => $model,
            'p_service_tier' => $serviceTier,
            'p_provider_created_at' => $providerTimestamp,
            'p_input_tokens' => $normalizedUsage['input_tokens'],
            'p_cached_input_tokens' => $normalizedUsage['cached_input_tokens'],
            'p_output_tokens' => $normalizedUsage['output_tokens'],
            'p_total_tokens' => $normalizedUsage['total_tokens'],
            'p_outcome' => $outcome,
        ]);

        self::assertUuid((string) ($row['usage_event_id'] ?? ''), 'usage_event_id response');
        $status = $row['finalization_status'] ?? null;
        if (!is_string($status) || !in_array($status, ['finalized', 'already_finalized'], true)) {
            throw new LilaAiUsageException('Invalid finalization response');
        }
        $currency = $row['currency'] ?? null;
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new LilaAiUsageException('Invalid currency response');
        }
        $cost = $row['provider_cost_total'] ?? null;
        if ((!is_string($cost) && !is_int($cost) && !is_float($cost)) || !is_numeric((string) $cost)) {
            throw new LilaAiUsageException('Invalid provider cost response');
        }
        if (!is_bool($row['customer_chargeable'] ?? null)) {
            throw new LilaAiUsageException('Invalid chargeability response');
        }

        return [
            'usage_event_id' => strtolower((string) $row['usage_event_id']),
            'finalization_status' => $status,
            'currency' => $currency,
            'provider_cost_total' => (string) $cost,
            'customer_chargeable' => $row['customer_chargeable'],
        ];
    }

    /** @return array<string,mixed> */
    private function rpc(string $functionName, array $payload): array
    {
        $url = $this->supabaseUrl . '/rest/v1/rpc/' . rawurlencode($functionName);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'apikey: ' . $this->secretKey,
        ];

        if ($this->transport !== null) {
            $decoded = ($this->transport)($url, $payload, $headers, [
                'connect_timeout_seconds' => $this->connectTimeoutSeconds,
                'request_timeout_seconds' => $this->requestTimeoutSeconds,
            ]);
            if (!is_array($decoded)) {
                throw new LilaAiUsageException('Invalid accounting transport response');
            }
            return self::firstRpcRow($decoded);
        }

        if (!function_exists('curl_init')) {
            throw new LilaAiUsageException('Accounting transport unavailable');
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new LilaAiUsageException('Accounting request encoding failed', 0, $exception);
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new LilaAiUsageException('Accounting connection unavailable');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawResponse = curl_exec($curl);
        $curlError = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($rawResponse === false || $curlError !== 0) {
            throw new LilaAiUsageException('Accounting connection failed');
        }
        if ($status < 200 || $status >= 300) {
            throw new LilaAiUsageException('Accounting service rejected the request', $status);
        }

        try {
            $decoded = json_decode($rawResponse, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LilaAiUsageException('Accounting response decoding failed', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new LilaAiUsageException('Invalid accounting response');
        }

        return self::firstRpcRow($decoded);
    }

    /** @return array<string,mixed> */
    private static function firstRpcRow(array $decoded): array
    {
        if (isset($decoded[0]) && is_array($decoded[0])) {
            return $decoded[0];
        }
        throw new LilaAiUsageException('Accounting RPC returned no row');
    }

    private static function assertUuid(string $value, string $name): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw new InvalidArgumentException('Invalid ' . $name);
        }
    }

    private static function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Missing configuration key: ' . $key);
        }
        return trim($value);
    }

    private static function boundedInt(array $config, string $key, int $default, int $min, int $max): int
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Invalid configuration key: ' . $key);
        }
        return $value;
    }
}
