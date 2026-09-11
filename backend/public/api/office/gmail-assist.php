<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';
require_once dirname(__DIR__, 4) . '/private/office/office-gmail-assist.php';

office_run_api(['POST'], static function (): void {
    $user = office_verified_supabase_user();
    $request = office_gmail_read_assist_request();
    $refreshToken = office_google_refresh_token_for_gmail($user['id']);
    $accessToken = office_google_temporary_access_token($refreshToken);
    $message = office_gmail_message_detail($accessToken, $request['message_id']);
    $result = office_gmail_generate_assistance($request['action'], $message);

    office_json_response(200, ['ok' => true] + $result);
});
