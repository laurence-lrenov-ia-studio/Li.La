<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

const OFFICE_GMAIL_DRAFT_MAX_REQUEST_BYTES = 131072;
const OFFICE_GMAIL_DRAFT_MAX_BODY_BYTES = 100000;
const OFFICE_GMAIL_DRAFT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,256}$/D';

/** @return array{version:int,message_id:string,body_text:string,confirmed:true} */
function office_gmail_read_draft_request(): array
{
    $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
    if ($contentType === '' || preg_match('/^application\/json(?:\s*;|$)/', $contentType) !== 1) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400);
    }

    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($contentLength !== false && $contentLength > OFFICE_GMAIL_DRAFT_MAX_REQUEST_BYTES) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400);
    }
    $raw = file_get_contents('php://input', false, null, 0, OFFICE_GMAIL_DRAFT_MAX_REQUEST_BYTES + 1);
    if (!is_string($raw) || $raw === '' || strlen($raw) > OFFICE_GMAIL_DRAFT_MAX_REQUEST_BYTES) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400);
    }

    try {
        $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400, $exception);
    }
    if (!is_array($body)) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400);
    }
    return office_gmail_validate_draft_request($body);
}

/** @return array{version:int,message_id:string,body_text:string,confirmed:true} */
function office_gmail_validate_draft_request(array $body): array
{
    if (office_gmail_draft_is_list($body)) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400);
    }
    $keys = array_keys($body);
    sort($keys);
    if ($keys !== ['body_text', 'confirmed', 'message_id', 'version']
        || $body['version'] !== 1
        || $body['confirmed'] !== true) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400);
    }

    $messageId = $body['message_id'] ?? null;
    if (!is_string($messageId) || preg_match(OFFICE_GMAIL_DRAFT_ID_PATTERN, $messageId) !== 1) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400);
    }
    $bodyText = $body['body_text'] ?? null;
    if (!is_string($bodyText)
        || $bodyText === ''
        || trim($bodyText) === ''
        || strlen($bodyText) > OFFICE_GMAIL_DRAFT_MAX_BODY_BYTES
        || strpos($bodyText, "\0") !== false
        || preg_match('//u', $bodyText) !== 1) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400);
    }

    return [
        'version' => 1,
        'message_id' => $messageId,
        'body_text' => $bodyText,
        'confirmed' => true,
    ];
}

function office_gmail_draft_is_list(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function office_gmail_draft_safe_header(array $headers, string $name): ?string
{
    $value = office_gmail_header($headers, $name);
    if ($value === null) {
        return null;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return null;
    }
    if (function_exists('iconv_mime_decode')) {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (is_string($decoded)) {
            $value = $decoded;
        }
    }
    if (preg_match('//u', $value) !== 1) {
        return null;
    }
    $value = trim(preg_replace('/[\t ]+/u', ' ', $value) ?? '');
    return $value === '' || strlen($value) > 1000 ? null : $value;
}

function office_gmail_draft_extract_address(string $value): ?string
{
    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return null;
    }
    $candidate = trim($value);
    if (preg_match('/<([^<>]{3,320})>/', $candidate, $matches) === 1) {
        $candidate = trim($matches[1]);
    }
    if (strpos($candidate, ',') !== false
        || strlen($candidate) > 254
        || filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return $candidate;
}

function office_gmail_draft_reply_recipient(array $headers): string
{
    $replyToHeader = office_gmail_header($headers, 'Reply-To');
    if ($replyToHeader !== null) {
        $replyTo = office_gmail_draft_safe_header($headers, 'Reply-To');
        if ($replyTo === null) {
            throw new OfficeOAuthException('GMAIL_REPLY_RECIPIENT_INVALID', 422);
        }
        $address = office_gmail_draft_extract_address($replyTo);
        if ($address === null) {
            throw new OfficeOAuthException('GMAIL_REPLY_RECIPIENT_INVALID', 422);
        }
        return $address;
    }

    $from = office_gmail_draft_safe_header($headers, 'From');
    $address = $from === null ? null : office_gmail_draft_extract_address($from);
    if ($address === null) {
        throw new OfficeOAuthException('GMAIL_REPLY_RECIPIENT_INVALID', 422);
    }
    return $address;
}

function office_gmail_draft_reply_subject(array $headers): string
{
    $subject = office_gmail_draft_safe_header($headers, 'Subject') ?? '(Sans objet)';
    if (preg_match('/^re\s*:/iu', $subject) !== 1) {
        $subject = 'Re: ' . $subject;
    }
    if (strlen($subject) > 980) {
        throw new OfficeOAuthException('GMAIL_REPLY_SUBJECT_INVALID', 422);
    }
    return $subject;
}

function office_gmail_draft_message_id(array $headers): ?string
{
    $messageId = office_gmail_header($headers, 'Message-ID');
    if (!is_string($messageId)) {
        return null;
    }
    $messageId = trim($messageId);
    return preg_match('/^<[^<>\s\x00-\x1F\x7F]{3,900}>$/D', $messageId) === 1 ? $messageId : null;
}

/** @return list<string> */
function office_gmail_draft_references(array $headers): array
{
    $value = office_gmail_header($headers, 'References');
    if (!is_string($value)
        || $value === ''
        || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        || preg_match_all('/<[^<>\s\x00-\x1F\x7F]{3,998}>/', $value, $matches) === false) {
        return [];
    }
    $remainder = preg_replace('/<[^<>\s\x00-\x1F\x7F]{3,998}>/', '', $value);
    if (!is_string($remainder) || trim($remainder) !== '') {
        return [];
    }
    return array_values(array_unique($matches[0]));
}

/** @return array{recipient:string,subject:string,thread_id:?string,in_reply_to:?string,references:list<string>} */
function office_gmail_draft_reply_context(string $accessToken, string $messageId): array
{
    if (preg_match(OFFICE_GMAIL_DRAFT_ID_PATTERN, $messageId) !== 1) {
        throw new OfficeOAuthException('INVALID_REQUEST', 400);
    }
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($messageId)
        . '?format=metadata'
        . '&metadataHeaders=Reply-To'
        . '&metadataHeaders=From'
        . '&metadataHeaders=Subject'
        . '&metadataHeaders=Message-ID'
        . '&metadataHeaders=References'
        . '&fields=id,threadId,payload%2Fheaders';
    $response = office_http_json('GET', $url, [
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken,
    ]);
    if ($response['status'] === 401 || $response['status'] === 403) {
        throw new OfficeOAuthException('GMAIL_DRAFT_ACCESS_DENIED', 403);
    }
    if ($response['status'] === 404) {
        throw new OfficeOAuthException('GMAIL_MESSAGE_NOT_FOUND', 404);
    }
    if ($response['status'] !== 200 || !is_array($response['body'])) {
        throw new OfficeOAuthException('GMAIL_API_UNAVAILABLE', 502);
    }

    $message = $response['body'];
    $returnedId = $message['id'] ?? null;
    $headers = $message['payload']['headers'] ?? null;
    if (!is_string($returnedId)
        || !hash_equals($messageId, $returnedId)
        || !is_array($headers)) {
        throw new OfficeOAuthException('GMAIL_RESPONSE_INVALID', 502);
    }

    $threadId = $message['threadId'] ?? null;
    if (!is_string($threadId) || preg_match(OFFICE_GMAIL_DRAFT_ID_PATTERN, $threadId) !== 1) {
        $threadId = null;
    }
    $inReplyTo = office_gmail_draft_message_id($headers);
    $references = office_gmail_draft_references($headers);
    if ($threadId === null || $inReplyTo === null) {
        $threadId = null;
        $inReplyTo = null;
        $references = [];
    } elseif (!in_array($inReplyTo, $references, true)) {
        $references[] = $inReplyTo;
    }
    while (count($references) > 1 && strlen(implode(' ', $references)) > 950) {
        array_shift($references);
    }

    return [
        'recipient' => office_gmail_draft_reply_recipient($headers),
        'subject' => office_gmail_draft_reply_subject($headers),
        'thread_id' => $threadId,
        'in_reply_to' => $inReplyTo,
        'references' => $references,
    ];
}

function office_gmail_draft_sender(string $accessToken): string
{
    $response = office_http_json(
        'GET',
        'https://gmail.googleapis.com/gmail/v1/users/me/profile?fields=emailAddress',
        ['Accept: application/json', 'Authorization: Bearer ' . $accessToken]
    );
    if ($response['status'] === 401 || $response['status'] === 403) {
        throw new OfficeOAuthException('GMAIL_DRAFT_ACCESS_DENIED', 403);
    }
    $email = is_array($response['body']) ? ($response['body']['emailAddress'] ?? null) : null;
    if ($response['status'] !== 200
        || !is_string($email)
        || strlen($email) > 254
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new OfficeOAuthException('GMAIL_RESPONSE_INVALID', 502);
    }
    return $email;
}

function office_gmail_draft_subject_header(string $subject): string
{
    if (preg_match('/^[\x20-\x7E]+$/D', $subject) === 1) {
        return 'Subject: ' . $subject;
    }
    if (!function_exists('iconv_mime_encode')) {
        throw new OfficeOAuthException('MIME_ENCODING_UNAVAILABLE', 503);
    }
    $encoded = iconv_mime_encode('Subject', $subject, [
        'scheme' => 'B',
        'input-charset' => 'UTF-8',
        'output-charset' => 'UTF-8',
        'line-length' => 76,
        'line-break-chars' => "\r\n",
    ]);
    if (!is_string($encoded)
        || strpos($encoded, 'Subject: ') !== 0
        || preg_match('/\r\n(?![\t ])/D', $encoded) === 1) {
        throw new OfficeOAuthException('MIME_ENCODING_UNAVAILABLE', 503);
    }
    return $encoded;
}

function office_gmail_draft_idempotency_message_id(
    string $userId,
    string $messageId,
    string $bodyText
): string {
    $config = office_config();
    $host = strtolower((string) (parse_url($config['lila_app_url'], PHP_URL_HOST) ?? ''));
    if ($host === '' || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
        throw new OfficeOAuthException('SERVER_MISCONFIGURED', 503);
    }
    $digest = hash_hmac(
        'sha256',
        "gmail-draft\0" . $userId . "\0" . $messageId . "\0" . hash('sha256', $bodyText, true),
        $config['state_secret']
    );
    return '<lila.' . $digest . '@' . $host . '>';
}

function office_gmail_draft_build_mime(
    string $sender,
    array $context,
    string $bodyText,
    string $idempotencyMessageId
): string {
    $headers = [
        'From: ' . $sender,
        'To: ' . $context['recipient'],
        office_gmail_draft_subject_header($context['subject']),
        'Date: ' . gmdate('D, d M Y H:i:s +0000'),
        'Message-ID: ' . $idempotencyMessageId,
    ];
    if (is_string($context['thread_id']) && is_string($context['in_reply_to'])) {
        $headers[] = 'In-Reply-To: ' . $context['in_reply_to'];
        $headers[] = 'References: ' . implode(' ', $context['references']);
    }
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: base64';

    return implode("\r\n", $headers)
        . "\r\n\r\n"
        . rtrim(chunk_split(base64_encode($bodyText), 76, "\r\n"), "\r\n")
        . "\r\n";
}

/** @return array{id:string,message_id:string,thread_id:?string,created:bool}|null */
function office_gmail_find_existing_draft(string $accessToken, string $idempotencyMessageId): ?array
{
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/drafts?'
        . http_build_query([
            'maxResults' => 1,
            'q' => 'rfc822msgid:' . $idempotencyMessageId,
            'fields' => 'drafts(id,message(id,threadId))',
        ], '', '&', PHP_QUERY_RFC3986);
    $response = office_http_json('GET', $url, [
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken,
    ]);
    if ($response['status'] === 401 || $response['status'] === 403) {
        throw new OfficeOAuthException('GMAIL_DRAFT_ACCESS_DENIED', 403);
    }
    if ($response['status'] !== 200 || !is_array($response['body'])) {
        throw new OfficeOAuthException('GMAIL_DRAFT_LOOKUP_FAILED', 502);
    }
    $drafts = $response['body']['drafts'] ?? [];
    if (!is_array($drafts) || $drafts === []) {
        return null;
    }
    $draft = $drafts[0] ?? null;
    $draftId = is_array($draft) ? ($draft['id'] ?? null) : null;
    $message = is_array($draft) ? ($draft['message'] ?? null) : null;
    $draftMessageId = is_array($message) ? ($message['id'] ?? null) : null;
    $threadId = is_array($message) ? ($message['threadId'] ?? null) : null;
    if (!is_string($draftId)
        || preg_match(OFFICE_GMAIL_DRAFT_ID_PATTERN, $draftId) !== 1
        || !is_string($draftMessageId)
        || preg_match(OFFICE_GMAIL_DRAFT_ID_PATTERN, $draftMessageId) !== 1
        || ($threadId !== null
            && (!is_string($threadId) || preg_match(OFFICE_GMAIL_DRAFT_ID_PATTERN, $threadId) !== 1))) {
        throw new OfficeOAuthException('GMAIL_RESPONSE_INVALID', 502);
    }
    return [
        'id' => $draftId,
        'message_id' => $draftMessageId,
        'thread_id' => $threadId,
        'created' => false,
    ];
}

/** @return array{id:string,message_id:string,thread_id:?string,created:bool} */
function office_gmail_create_draft_resource(
    string $accessToken,
    string $rawMime,
    ?string $threadId,
    string $idempotencyMessageId
): array {
    $message = ['raw' => office_base64url_encode($rawMime)];
    if ($threadId !== null) {
        $message['threadId'] = $threadId;
    }
    try {
        $body = json_encode(['message' => $message], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new OfficeOAuthException('REQUEST_ENCODING_FAILED', 500, $exception);
    }

    try {
        $response = office_http_json(
            'POST',
            'https://gmail.googleapis.com/gmail/v1/users/me/drafts',
            [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            $body
        );
    } catch (OfficeOAuthException $exception) {
        if ($exception->getPublicCode() !== 'UPSTREAM_UNAVAILABLE') {
            throw $exception;
        }
        $existing = office_gmail_find_existing_draft($accessToken, $idempotencyMessageId);
        if ($existing !== null) {
            return $existing;
        }
        throw new OfficeOAuthException('GMAIL_DRAFT_STATUS_UNKNOWN', 502, $exception);
    }

    if ($response['status'] === 401 || $response['status'] === 403) {
        throw new OfficeOAuthException('GMAIL_DRAFT_ACCESS_DENIED', 403);
    }
    if ($response['status'] >= 500) {
        $existing = office_gmail_find_existing_draft($accessToken, $idempotencyMessageId);
        if ($existing !== null) {
            return $existing;
        }
        throw new OfficeOAuthException('GMAIL_DRAFT_STATUS_UNKNOWN', 502);
    }
    if ($response['status'] !== 200 || !is_array($response['body'])) {
        throw new OfficeOAuthException('GMAIL_DRAFT_CREATE_FAILED', 502);
    }

    $draftId = $response['body']['id'] ?? null;
    $returnedMessage = $response['body']['message'] ?? null;
    $draftMessageId = is_array($returnedMessage) ? ($returnedMessage['id'] ?? null) : null;
    $returnedThreadId = is_array($returnedMessage) ? ($returnedMessage['threadId'] ?? null) : null;
    if (!is_string($draftId)
        || preg_match(OFFICE_GMAIL_DRAFT_ID_PATTERN, $draftId) !== 1
        || !is_string($draftMessageId)
        || preg_match(OFFICE_GMAIL_DRAFT_ID_PATTERN, $draftMessageId) !== 1
        || ($returnedThreadId !== null
            && (!is_string($returnedThreadId)
                || preg_match(OFFICE_GMAIL_DRAFT_ID_PATTERN, $returnedThreadId) !== 1))) {
        throw new OfficeOAuthException('GMAIL_RESPONSE_INVALID', 502);
    }
    return [
        'id' => $draftId,
        'message_id' => $draftMessageId,
        'thread_id' => $returnedThreadId,
        'created' => true,
    ];
}

/** @return array{id:string,message_id:string,thread_id:?string,created:bool} */
function office_gmail_create_reply_draft(
    string $accessToken,
    string $userId,
    string $messageId,
    string $bodyText
): array {
    $idempotencyMessageId = office_gmail_draft_idempotency_message_id($userId, $messageId, $bodyText);
    $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'lila-office-gmail-draft-'
        . hash('sha256', $idempotencyMessageId) . '.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock !== false && !flock($lock, LOCK_EX)) {
        fclose($lock);
        $lock = false;
    }

    try {
        $existing = office_gmail_find_existing_draft($accessToken, $idempotencyMessageId);
        if ($existing !== null) {
            return $existing;
        }
        $context = office_gmail_draft_reply_context($accessToken, $messageId);
        $sender = office_gmail_draft_sender($accessToken);
        $rawMime = office_gmail_draft_build_mime(
            $sender,
            $context,
            $bodyText,
            $idempotencyMessageId
        );
        return office_gmail_create_draft_resource(
            $accessToken,
            $rawMime,
            $context['thread_id'],
            $idempotencyMessageId
        );
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }
}
