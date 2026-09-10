<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';

office_security_headers();

$callbackStep = 'state';
try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        throw new OfficeOAuthException('METHOD_NOT_ALLOWED', 405);
    }

    $state = (string) ($_GET['state'] ?? '');
    $nonce = office_get_nonce_cookie();
    $statePayload = office_validate_oauth_state($state, $nonce);
    office_clear_nonce_cookie();

    if (isset($_GET['error'])) {
        office_redirect_to_app('denied');
    }

    $code = (string) ($_GET['code'] ?? '');
    $callbackStep = 'token_exchange';
    $tokens = office_google_exchange_code($code);
    $callbackStep = 'identity';
    $identity = office_google_validate_identity($tokens['id_token'], $nonce);
    $callbackStep = 'refresh_token_encryption';
    $encryptedRefreshToken = null;
    if (is_string($tokens['refresh_token'])) {
        $encryptedRefreshToken = office_encrypt_google_refresh_token($tokens['refresh_token']);
    } else {
        $storedRefreshToken = office_stored_google_refresh_token($statePayload['uid']);
        if ($storedRefreshToken === null) {
            error_log('OFFICE_OAUTH_CALLBACK_CONSENT_REQUIRED');
            office_redirect_to_app('consent_required');
        }
        $encryptedRefreshToken = office_encrypt_google_refresh_token($storedRefreshToken);
    }
    $callbackStep = 'supabase_upsert';
    office_store_google_identity($statePayload['uid'], $identity, $encryptedRefreshToken);

    office_redirect_to_app('connected');
} catch (Throwable $exception) {
    $logMessage = 'OFFICE_OAUTH_CALLBACK_ERROR step=' . $callbackStep;
    if ($exception instanceof OfficeOAuthException) {
        $publicCode = $exception->getPublicCode();
        if (preg_match('/^[A-Z0-9_]{1,64}$/D', $publicCode) === 1) {
            $logMessage .= ' code=' . $publicCode;
        }
    }
    error_log($logMessage);
    office_clear_nonce_cookie();
    try {
        office_redirect_to_app('error');
    } catch (Throwable $redirectException) {
        office_json_response(500, ['ok' => false, 'error' => 'OAUTH_CALLBACK_FAILED']);
    }
}
