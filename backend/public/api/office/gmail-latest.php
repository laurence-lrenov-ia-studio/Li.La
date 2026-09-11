<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';

office_run_api(['GET'], static function (): void {
    $step = 'supabase_auth';

    try {
        $user = office_verified_supabase_user();
        $step = 'google_connection_read';
        $refreshToken = office_google_refresh_token_for_gmail($user['id']);
        $step = 'google_access_token_refresh';
        $accessToken = office_google_temporary_access_token($refreshToken);
        $step = 'gmail_api';
        $messages = office_gmail_latest_messages($accessToken);
    } catch (OfficeOAuthException $exception) {
        $publicCode = $exception->getPublicCode();
        if (in_array($publicCode, [
            'GOOGLE_TOKEN_STORAGE_INVALID',
            'TOKEN_ENCRYPTION_UNAVAILABLE',
            'SERVER_MISCONFIGURED',
        ], true) && $step === 'google_connection_read') {
            $step = 'refresh_token_decryption';
        } elseif (in_array($publicCode, [
            'GMAIL_RESPONSE_INVALID',
            'UPSTREAM_RESPONSE_INVALID',
        ], true) && $step === 'gmail_api') {
            $step = 'gmail_response';
        }
        if (preg_match('/^[A-Z0-9_]{1,64}$/D', $publicCode) !== 1) {
            $publicCode = 'INTERNAL_ERROR';
        }
        error_log('OFFICE_GMAIL_LATEST_ERROR step=' . $step . ' code=' . $publicCode);
        throw $exception;
    } catch (Throwable $exception) {
        error_log('OFFICE_GMAIL_LATEST_ERROR step=' . $step . ' code=INTERNAL_ERROR');
        throw $exception;
    }

    office_json_response(200, [
        'ok' => true,
        'messages' => $messages,
    ]);
});
