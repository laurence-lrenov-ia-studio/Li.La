<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';

office_run_api(['GET'], static function (): void {
    $user = office_verified_supabase_user();
    $status = office_google_connection_status($user['id']);

    office_json_response(200, array_merge(['ok' => true], $status));
});
