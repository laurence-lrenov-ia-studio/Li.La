<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';
require_once dirname(__DIR__, 4) . '/private/office/office-gmail-assist.php';

office_run_api(['GET'], static function (): void {
    $user = office_verified_supabase_user();
    $messageId = office_gmail_require_message_id($_GET['id'] ?? null);
    $refreshToken = office_google_refresh_token_for_gmail($user['id']);
    $accessToken = office_google_temporary_access_token($refreshToken);
    $message = office_gmail_message_detail($accessToken, $messageId);

    office_json_response(200, [
        'ok' => true,
        'message' => $message,
    ]);
});
