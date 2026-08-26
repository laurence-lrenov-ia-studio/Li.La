<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Authenticates a Supabase session token, then lets the existing RLS policies
 * restrict every PostgREST read to the authenticated user.
 *
 * @return array{user_id:string,instance_id:string}
 */
function comAuthorize(array $config, string $accessToken): array
{
    $supabaseUrl = comAuthConfigString($config, 'supabase_url');
    comAuthConfigString($config, 'supabase_publishable_key');

    if (!filter_var($supabaseUrl, FILTER_VALIDATE_URL) || stripos($supabaseUrl, 'https://') !== 0) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }

    $user = comSupabaseRequest($config, $accessToken, '/auth/v1/user', [], true);
    $userId = isset($user['id']) && is_string($user['id']) ? strtolower(trim($user['id'])) : '';
    if (!comAuthIsUuid($userId)) {
        throw new ComApiException('AUTH_REQUIRED', 401);
    }

    $modules = comSupabaseRequest(
        $config,
        $accessToken,
        '/rest/v1/modules',
        [
            'select' => 'id,code,status',
            'code' => 'eq.com',
            'status' => 'eq.active',
            'limit' => '1',
        ]
    );
    $moduleId = '';
    if (isset($modules[0]['id']) && is_string($modules[0]['id']) && comAuthIsUuid($modules[0]['id'])) {
        $moduleId = strtolower($modules[0]['id']);
    }
    if ($moduleId === '') {
        throw new ComApiException('COM_NOT_ALLOWED', 403);
    }

    $memberships = comSupabaseRequest(
        $config,
        $accessToken,
        '/rest/v1/instance_members',
        [
            'select' => 'instance_id,user_id,status,access_start,access_end',
            'user_id' => 'eq.' . $userId,
            'status' => 'eq.active',
            'limit' => '100',
        ]
    );

    $candidateInstanceIds = [];
    foreach ($memberships as $membership) {
        if (!is_array($membership)) {
            continue;
        }
        $instanceId = isset($membership['instance_id']) && is_string($membership['instance_id'])
            ? strtolower($membership['instance_id'])
            : '';
        if (
            comAuthIsUuid($instanceId)
            && ($membership['status'] ?? null) === 'active'
            && comAuthWindowIsActive($membership['access_start'] ?? null, $membership['access_end'] ?? null)
        ) {
            $candidateInstanceIds[$instanceId] = true;
        }
    }
    if ($candidateInstanceIds === []) {
        throw new ComApiException('COM_NOT_ALLOWED', 403);
    }

    $instanceIds = array_keys($candidateInstanceIds);
    $instances = comSupabaseRequest(
        $config,
        $accessToken,
        '/rest/v1/instances',
        [
            'select' => 'id,status,access_start,access_end',
            'id' => 'in.(' . implode(',', $instanceIds) . ')',
            'status' => 'eq.active',
            'limit' => '100',
        ]
    );

    $activeInstanceIds = [];
    foreach ($instances as $instance) {
        if (!is_array($instance)) {
            continue;
        }
        $instanceId = isset($instance['id']) && is_string($instance['id'])
            ? strtolower($instance['id'])
            : '';
        if (
            isset($candidateInstanceIds[$instanceId])
            && ($instance['status'] ?? null) === 'active'
            && comAuthWindowIsActive($instance['access_start'] ?? null, $instance['access_end'] ?? null)
        ) {
            $activeInstanceIds[$instanceId] = true;
        }
    }
    if ($activeInstanceIds === []) {
        throw new ComApiException('COM_NOT_ALLOWED', 403);
    }

    $moduleAccessRows = comSupabaseRequest(
        $config,
        $accessToken,
        '/rest/v1/instance_modules',
        [
            'select' => 'instance_id,module_id,access_status,trial_end',
            'instance_id' => 'in.(' . implode(',', array_keys($activeInstanceIds)) . ')',
            'module_id' => 'eq.' . $moduleId,
            'limit' => '100',
        ]
    );

    foreach ($moduleAccessRows as $moduleAccess) {
        if (!is_array($moduleAccess)) {
            continue;
        }
        $instanceId = isset($moduleAccess['instance_id']) && is_string($moduleAccess['instance_id'])
            ? strtolower($moduleAccess['instance_id'])
            : '';
        if (!isset($activeInstanceIds[$instanceId])) {
            continue;
        }
        $accessStatus = $moduleAccess['access_status'] ?? null;
        $active = $accessStatus === 'active';
        $trial = $accessStatus === 'trial' && comAuthTrialIsValid($moduleAccess['trial_end'] ?? null);
        if ($active || $trial) {
            return ['user_id' => $userId, 'instance_id' => $instanceId];
        }
    }

    throw new ComApiException('COM_NOT_ALLOWED', 403);
}

function comSupabaseRequest(
    array $config,
    string $accessToken,
    string $path,
    array $query = [],
    bool $isAuthRequest = false
): array {
    if (!function_exists('curl_init')) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }

    $baseUrl = rtrim(comAuthConfigString($config, 'supabase_url'), '/');
    $publishableKey = comAuthConfigString($config, 'supabase_publishable_key');
    $url = $baseUrl . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => comAuthConfigInt($config, 'connect_timeout', 5, 1, 15),
        CURLOPT_TIMEOUT => comAuthConfigInt($config, 'supabase_timeout', 12, 2, 30),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'apikey: ' . $publishableKey,
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $rawResponse = curl_exec($curl);
    $curlError = curl_errno($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($rawResponse === false || $curlError !== 0) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }
    if ($isAuthRequest && ($status === 401 || $status === 403)) {
        throw new ComApiException('AUTH_REQUIRED', 401);
    }
    if ($status < 200 || $status >= 300) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }

    try {
        $decoded = json_decode($rawResponse, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503, $exception);
    }
    if (!is_array($decoded)) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }
    return $decoded;
}

function comAuthWindowIsActive($start, $end): bool
{
    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($start !== null && $start !== '' && comAuthDate($start, false) > $now) {
            return false;
        }
        if ($end !== null && $end !== '' && comAuthDate($end, true) < $now) {
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

function comAuthTrialIsValid($trialEnd): bool
{
    if (!is_string($trialEnd) || trim($trialEnd) === '') {
        return false;
    }
    try {
        return comAuthDate($trialEnd, true) >= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Throwable $exception) {
        return false;
    }
}

function comAuthDate($value, bool $endOfDay): DateTimeImmutable
{
    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException('Invalid date');
    }
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
        $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
}

function comAuthIsUuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
}

function comAuthConfigString(array $config, string $key): string
{
    $value = $config[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }
    return trim($value);
}

function comAuthConfigInt(array $config, string $key, int $default, int $min, int $max): int
{
    $value = $config[$key] ?? $default;
    if (!is_int($value) || $value < $min || $value > $max) {
        throw new ComApiException('GENERATION_UNAVAILABLE', 503);
    }
    return $value;
}
