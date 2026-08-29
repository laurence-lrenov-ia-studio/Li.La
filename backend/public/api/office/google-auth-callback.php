<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';

office_security_headers();

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
    $tokens = office_google_exchange_code($code);
    $identity = office_google_validate_identity($tokens['id_token'], $nonce);
    office_store_google_identity($statePayload['uid'], $identity);

    office_redirect_to_app('connected');
} catch (Throwable $exception) {
    office_clear_nonce_cookie();
    try {
        office_redirect_to_app('error');
    } catch (Throwable $redirectException) {
        office_json_response(500, ['ok' => false, 'error' => 'OAUTH_CALLBACK_FAILED']);
    }
}
