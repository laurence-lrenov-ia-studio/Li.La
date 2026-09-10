<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

/*
 * EXAMPLE ONLY — NEVER COMMIT A REAL SECRET.
 *
 * Store the real file outside the repository and outside Hostinger's public
 * document root. Point LILA_PLATFORM_CONFIG_PATH to that private file.
 */
return [
    'supabase_url' => 'https://PROJECT_REF.supabase.co',
    'supabase_secret_key' => '', // Modern sb_secret_... key, server-side only.
    'connect_timeout_seconds' => 5,
    'request_timeout_seconds' => 12,
];
