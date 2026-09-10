<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ai-usage-client.php';

function assertTrue($condition, string $message): void
{
    if ($condition !== true) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $callback, string $expectedClass, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $expectedClass) {
            return;
        }
        throw new RuntimeException($message . ': unexpected ' . get_class($exception));
    }
    throw new RuntimeException($message . ': no exception');
}

$requestId = '11111111-1111-4111-8111-111111111111';
$userId = '22222222-2222-4222-8222-222222222222';
$instanceId = '33333333-3333-4333-8333-333333333333';
$reservationId = '44444444-4444-4444-8444-444444444444';
$eventId = '55555555-5555-4555-8555-555555555555';
$calls = [];

$transport = static function (string $url, array $payload, array $headers, array $options) use (
    &$calls,
    $reservationId,
    $eventId
): array {
    $calls[] = compact('url', 'payload', 'headers', 'options');
    if (substr($url, -strlen('ai_usage_reserve_v1')) === 'ai_usage_reserve_v1') {
        return [[
            'reservation_id' => $reservationId,
            'reservation_status' => 'reserved',
            'usage_event_id' => null,
        ]];
    }
    return [[
        'usage_event_id' => $eventId,
        'finalization_status' => 'finalized',
        'currency' => 'USD',
        'provider_cost_total' => '0.001234000000',
        'customer_chargeable' => false,
    ]];
};

$client = new LilaAiUsageClient([
    'supabase_url' => 'https://project-ref.example.supabase.co',
    'supabase_secret_key' => 'sb_secret_TEST_ONLY_NOT_A_REAL_KEY',
    'connect_timeout_seconds' => 4,
    'request_timeout_seconds' => 10,
], $transport);

$reservation = $client->reserve(
    $requestId,
    ['user_id' => $userId, 'instance_id' => $instanceId],
    'com',
    'COM-01'
);
assertTrue($reservation['reservation_id'] === $reservationId, 'Reservation id mismatch');
assertTrue($reservation['reservation_status'] === 'reserved', 'Reservation status mismatch');

$finalization = $client->finalize(
    $reservationId,
    $requestId,
    'OpenAI',
    'resp_test_001',
    'test-model',
    'default',
    new DateTimeImmutable('2026-09-10T12:00:00Z'),
    [
        'input_tokens' => 1000,
        'cached_input_tokens' => 400,
        'output_tokens' => 200,
        'total_tokens' => 1200,
    ],
    'unusable_output'
);
assertTrue($finalization['usage_event_id'] === $eventId, 'Usage event id mismatch');
assertTrue($finalization['customer_chargeable'] === false, 'Unusable output must not be chargeable');
assertTrue(count($calls) === 2, 'Expected two RPC calls');
assertTrue($calls[0]['payload']['p_user_id'] === $userId, 'Validated user id not forwarded');
assertTrue($calls[0]['payload']['p_instance_id'] === $instanceId, 'Validated instance id not forwarded');
assertTrue($calls[1]['payload']['p_provider'] === 'openai', 'Provider not normalized');
assertTrue($calls[1]['payload']['p_cached_input_tokens'] === 400, 'Cached tokens not forwarded');
assertTrue($calls[1]['payload']['p_outcome'] === 'unusable_output', 'Outcome not forwarded');

foreach ($calls as $call) {
    assertTrue(
        count(array_filter($call['headers'], static fn(string $header): bool => stripos($header, 'apikey: sb_secret_') === 0)) === 1,
        'Secret key must be sent in the apikey header'
    );
    assertTrue(
        count(array_filter($call['headers'], static fn(string $header): bool => stripos($header, 'authorization:') === 0)) === 0,
        'Modern secret key must not be sent as a bearer token'
    );
    $serialized = json_encode($call['payload'], JSON_THROW_ON_ERROR);
    assertTrue(stripos($serialized, 'prompt') === false, 'Prompt field leaked into accounting payload');
    assertTrue(stripos($serialized, 'output_text') === false, 'Generated content field leaked into accounting payload');
}

assertThrows(
    static fn() => $client->reserve($requestId, ['user_id' => 'browser-value', 'instance_id' => $instanceId], 'com', 'COM-01'),
    InvalidArgumentException::class,
    'Unvalidated user identity must be rejected'
);
assertThrows(
    static fn() => $client->finalize(
        $reservationId,
        $requestId,
        'openai',
        'resp_test_002',
        'test-model',
        'default',
        new DateTimeImmutable('2026-09-10T12:00:00Z'),
        ['input_tokens' => 10, 'cached_input_tokens' => 11, 'output_tokens' => 1, 'total_tokens' => 11],
        'succeeded'
    ),
    InvalidArgumentException::class,
    'Cached tokens greater than input must be rejected'
);
assertThrows(
    static fn() => new LilaAiUsageClient([
        'supabase_url' => 'https://project-ref.example.supabase.co',
        'supabase_secret_key' => 'public-key',
    ]),
    InvalidArgumentException::class,
    'Public keys must be rejected'
);

echo "ai-usage-client tests: OK\n";
