<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';

office_run_api(['POST'], static function (): void {
    $user = office_verified_supabase_user();
    $changed = office_disconnect_google_identity($user['id']);

    office_json_response(200, [
        'ok' => true,
        'connected' => false,
        'changed' => $changed,
    ]);
});
