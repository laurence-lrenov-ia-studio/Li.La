<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';

office_run_api(['POST'], static function (): void {
    $user = office_verified_supabase_user();
    $nonce = office_random_token();
    $state = office_create_oauth_state($user['id'], $nonce);

    office_set_nonce_cookie($nonce);
    office_json_response(200, [
        'ok' => true,
        'authorization_url' => office_google_authorization_url($state, $nonce),
        'expires_in' => OFFICE_OAUTH_STATE_TTL_SECONDS,
    ]);
});
