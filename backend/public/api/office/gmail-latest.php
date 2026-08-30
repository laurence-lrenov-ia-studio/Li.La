<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';

office_run_api(['GET'], static function (): void {
    $user = office_verified_supabase_user();
    $refreshToken = office_google_refresh_token_for_gmail($user['id']);
    $accessToken = office_google_temporary_access_token($refreshToken);
    $messages = office_gmail_latest_messages($accessToken);

    office_json_response(200, [
        'ok' => true,
        'messages' => $messages,
    ]);
});
