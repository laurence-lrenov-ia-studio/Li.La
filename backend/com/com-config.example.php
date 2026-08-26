<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

/*
 * EXAMPLE ONLY — DO NOT PUT REAL SECRETS IN GIT
 *
 * The real configuration must be stored outside the repository and outside
 * the public document root. Point LILA_COM_CONFIG_PATH to that private file.
 */
return [
    'supabase_url' => 'https://project-ref.example.supabase.co',
    'supabase_publishable_key' => 'sb_publishable_EXAMPLE_ONLY',
    'openai_api_key' => '',
    'openai_model' => 'OPENAI_MODEL_ID',
    'allowed_origin' => 'https://app.example.invalid',
    'connect_timeout' => 5,
    'supabase_timeout' => 12,
    'openai_timeout' => 45,
    'max_body_bytes' => 32768,
    'rate_limit_count' => 10,
    'rate_limit_window_seconds' => 600,
    'rate_limit_hmac_key' => '',
];
