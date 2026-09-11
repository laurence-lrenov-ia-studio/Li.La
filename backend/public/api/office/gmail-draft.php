<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/private/office/office-oauth-common.php';
require_once dirname(__DIR__, 4) . '/private/office/office-gmail-draft.php';

office_run_api(['POST'], static function (): void {
    $step = 'auth_supabase';
    try {
        $user = office_verified_supabase_user();
        $step = 'request_validation';
        $request = office_gmail_read_draft_request();
        $step = 'connection_read';
        $refreshToken = office_google_refresh_token_for_gmail($user['id'], [
            OFFICE_GOOGLE_GMAIL_READONLY_SCOPE,
            OFFICE_GOOGLE_GMAIL_COMPOSE_SCOPE,
        ]);
        $step = 'access_token_refresh';
        $accessToken = office_google_temporary_access_token($refreshToken);
        $step = 'draft_creation';
        $draft = office_gmail_create_reply_draft(
            $accessToken,
            $user['id'],
            $request['message_id'],
            $request['body_text']
        );
    } catch (OfficeOAuthException $exception) {
        $publicCode = $exception->getPublicCode();
        if (preg_match('/^[A-Z0-9_]{1,64}$/D', $publicCode) !== 1) {
            $publicCode = 'INTERNAL_ERROR';
        }
        error_log('OFFICE_GMAIL_DRAFT_ERROR step=' . $step . ' code=' . $publicCode);
        throw $exception;
    } catch (Throwable $exception) {
        error_log('OFFICE_GMAIL_DRAFT_ERROR step=' . $step . ' code=INTERNAL_ERROR');
        throw $exception;
    }

    office_json_response($draft['created'] ? 201 : 200, [
        'ok' => true,
        'draft' => $draft,
    ]);
});
