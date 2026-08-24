<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

/* Public gateway for the isolated particuliers Apps Script backend. */
/* 32 KiB: questionnaires complets, sans pièce jointe ni document. */
const P_MAX_BODY = 32768;
const P_TOKEN_TTL = 7200;
const P_MIN_FORM_SECONDS = 3;
const P_ALLOWED_ORIGIN = 'https://li-la.fr';

$responseSent = false;

function sendUnexpectedResponse(): void {
    global $responseSent;
    if ($responseSent || headers_sent()) return;
    $responseSent = true;
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    echo '{"ok":false,"error":"SERVICE_UNAVAILABLE"}';
}

set_exception_handler(function (Throwable $exception): void {
    sendUnexpectedResponse();
});

register_shutdown_function(function (): void {
    global $responseSent;
    $lastError = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!$responseSent && is_array($lastError) && in_array($lastError['type'] ?? 0, $fatalTypes, true)) {
        sendUnexpectedResponse();
    }
});

function sendJson(array $body, int $status = 200): void {
    global $responseSent;
    if ($responseSent) exit;
    $responseSent = true;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $error, int $status = 400): void { sendJson(['ok' => false, 'error' => $error], $status); }
function particuliersReadPrivateConfig(): ?array {
    static $loaded = false;
    static $cached = null;
    if ($loaded) return $cached;
    $loaded = true;

    $configPath = dirname(__DIR__, 4) . '/private/lila-particuliers.php';
    if (!is_file($configPath) || !is_readable($configPath)) return null;

    $initialBufferLevel = ob_get_level();
    ob_start();
    try {
        $config = require $configPath;
    } catch (Throwable $exception) {
        return null;
    } finally {
        while (ob_get_level() > $initialBufferLevel) ob_end_clean();
    }

    $requiredKeys = ['PARTICULIERS_APPS_SCRIPT_URL', 'PARTICULIERS_GATEWAY_SHARED_SECRET', 'PARTICULIERS_TOKEN_SIGNING_SECRET', 'PARTICULIERS_ENABLED'];
    if (!is_array($config) || count($config) !== count($requiredKeys) || array_diff(array_keys($config), $requiredKeys) !== []) return null;
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $config) || !is_string($config[$key])) return null;
    }
    $cached = $config;
    return $cached;
}
function b64u(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function b64ud(string $value): ?string {
    if (!preg_match('/^[A-Za-z0-9_-]*$/', $value)) return null;
    $decoded = base64_decode(strtr($value . str_repeat('=', (4 - strlen($value) % 4) % 4), '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}
function readJsonBody(): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > P_MAX_BODY) fail('INVALID_REQUEST', 413);
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== 0) fail('INVALID_REQUEST');
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '' || strlen($raw) > P_MAX_BODY) fail('INVALID_REQUEST');
    try { $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (Throwable $e) { fail('INVALID_REQUEST'); }
    if (!is_array($body) || array_values($body) === $body) fail('INVALID_REQUEST');
    return $body;
}
function createFormToken(): string {
    $config = particuliersReadPrivateConfig();
    if ($config === null) fail('SERVICE_UNAVAILABLE', 503);
    $secret = $config['PARTICULIERS_TOKEN_SIGNING_SECRET'];
    if (strlen($secret) < 32) fail('SERVICE_UNAVAILABLE', 503);
    $now = time();
    $payload = json_encode(['iat' => $now, 'exp' => $now + P_TOKEN_TTL, 'nonce' => b64u(random_bytes(24)), 'version' => 1], JSON_UNESCAPED_SLASHES);
    $encoded = b64u($payload);
    return $encoded . '.' . b64u(hash_hmac('sha256', $encoded, $secret, true));
}
function validateFormToken($token): array {
    if (!is_string($token) || substr_count($token, '.') !== 1) fail('INVALID_TOKEN');
    [$encoded, $signature] = explode('.', $token, 2);
    $config = particuliersReadPrivateConfig();
    if ($config === null) fail('SERVICE_UNAVAILABLE', 503);
    $secret = $config['PARTICULIERS_TOKEN_SIGNING_SECRET'];
    $provided = b64ud($signature);
    $payloadJson = b64ud($encoded);
    if (strlen($secret) < 32 || $provided === null || $payloadJson === null || !hash_equals(hash_hmac('sha256', $encoded, $secret, true), $provided)) fail('INVALID_TOKEN');
    try { $payload = json_decode($payloadJson, true, 16, JSON_THROW_ON_ERROR); } catch (Throwable $e) { fail('INVALID_TOKEN'); }
    if (!is_array($payload) || ($payload['version'] ?? null) !== 1 || !is_int($payload['iat'] ?? null) || !is_int($payload['exp'] ?? null) || !is_string($payload['nonce'] ?? null)) fail('INVALID_TOKEN');
    if ($payload['exp'] < time()) fail('TOKEN_EXPIRED');
    if ($payload['iat'] > time() || $payload['exp'] - $payload['iat'] !== P_TOKEN_TTL) fail('INVALID_TOKEN');
    if (time() - $payload['iat'] < P_MIN_FORM_SECONDS) fail('FORM_TOO_FAST');
    return $payload;
}
function rateLimit(string $action): void {
    $limits = ['initForm' => 20, 'createRequest' => 10, 'getRequestStatus' => 30];
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $config = particuliersReadPrivateConfig();
    if ($config === null) fail('SERVICE_UNAVAILABLE', 503);
    $keySecret = $config['PARTICULIERS_TOKEN_SIGNING_SECRET'];
    if ($ip === '' || strlen($keySecret) < 32) fail('SERVICE_UNAVAILABLE', 503);
    $bucket = hash_hmac('sha256', $action . '|' . $ip . '|' . gmdate('YmdH'), $keySecret);
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lila-p-' . $bucket;
    $handle = @fopen($path, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) fail('SERVICE_UNAVAILABLE', 503);
    $count = (int)trim((string)stream_get_contents($handle));
    if ($count >= $limits[$action]) { flock($handle, LOCK_UN); fclose($handle); fail('RATE_LIMITED', 429); }
    ftruncate($handle, 0); rewind($handle); fwrite($handle, (string)($count + 1)); fflush($handle); flock($handle, LOCK_UN); fclose($handle);
}
function text($value, int $maximum, bool $required = true): ?string {
    if (!is_string($value)) return $required ? null : '';
    $value = trim(preg_replace('/\s+/', ' ', $value));
    return ($required && $value === '') || strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F<>]/', $value) ? null : $value;
}
function enumValue($value, array $allowed, bool $required = false): ?string {
    if ($value === '' || $value === null) return $required ? null : '';
    return is_string($value) && in_array($value, $allowed, true) ? $value : null;
}
function integerValue($value, int $maximum, bool $required = false): ?int {
    if ($value === '' || $value === null) return $required ? null : 0;
    if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) return null;
    $number = (int)$value;
    return $number >= 0 && $number <= $maximum ? $number : null;
}
function decimalValue($value, int $maximum, bool $required = false): int|float|null {
    if ($value === '' || $value === null) return $required ? null : 0;
    if (!is_int($value) && !is_float($value) && !(is_string($value) && preg_match('/^\d+(?:[.,]\d{1,2})?$/', $value))) return null;
    $number = (float)str_replace(',', '.', (string)$value);
    return is_finite($number) && $number >= 0 && $number <= $maximum ? $number : null;
}
function validateCreate(array $input): array {
    $common = ['request_id','type_demande','prenom','nom','email','telephone','contact_prefere','type_bien','urgence','informations','consentement_rgpd'];
    $byType = [
        'recherche_achat' => ['nature_projet','marche_souhaite','secteurs_recherches','secteurs_exclus','budget_min','budget_max','apport_disponible','financement_statut','vente_prealable','nombre_pieces_min','nombre_chambres_min','surface_min','terrain_min','exterieur_souhaite','stationnement_souhaite','travaux_acceptes','criteres_indispensables','criteres_redhibitoires','accompagnement_souhaite'],
        'vendre' => ['adresse_bien','secteur','usage_bien','surface','surface_terrain','nombre_pieces','nombre_chambres','etage','ascenseur','exterieur','stationnement','annee_construction','etat_general','travaux_realises','travaux_a_prevoir','diagnostics_statut','occupation_bien','prix_souhaite','estimation_existante','mandat_existant','motif_vente','disponibilites'],
        'confier_location' => ['adresse_bien','secteur','surface','nombre_pieces','nombre_chambres','location_type','date_disponibilite','loyer_souhaite','charges_mensuelles','etat_general','diagnostics_statut','equipements','exterieur','stationnement','service_recherche','assurance_loyers_impayes'],
        'confier_recherche' => ['nature_projet','situation_projet','secteurs_prioritaires','secteurs_secondaires','secteurs_exclus','budget_min','budget_max','budget_travaux_inclus','apport_disponible','financement_statut','vente_prealable','nombre_pieces_min','nombre_chambres_min','surface_min','terrain_min','exterieur_souhaite','stationnement_souhaite','environnement_recherche','proximites_souhaitees','travaux_acceptes','criteres_indispensables','criteres_souhaites','criteres_redhibitoires','disponibilites_visites','pre_visites_deleguees','accompagnement_existant']
    ];
    $types = ['recherche_achat','vendre','confier_location','confier_recherche'];
    if (!is_string($input['type_demande'] ?? null) || !in_array($input['type_demande'], $types, true)) fail('INVALID_REQUEST');
    $allowed = array_merge(['action','form_token'], $common, $byType[$input['type_demande']]);
    if (count($input) !== count($allowed) || array_diff(array_keys($input), $allowed) !== [] || array_diff($allowed, array_keys($input)) !== []) fail('INVALID_REQUEST');
    $data = array_intersect_key($input, array_flip(array_merge($common, $byType[$input['type_demande']])));
    if (!is_string($data['request_id']) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $data['request_id']) || $data['consentement_rgpd'] !== true) fail('INVALID_REQUEST');
    foreach (['prenom' => 60, 'nom' => 80, 'informations' => 2000] as $field => $max) { $data[$field] = text($data[$field], $max, $field !== 'informations'); if ($data[$field] === null) fail('INVALID_REQUEST'); }
    $data['email'] = strtolower((string)$data['email']);
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 254 || !is_string($data['telephone']) || strlen($data['telephone']) > 30) fail('INVALID_REQUEST');
    foreach (['contact_prefere' => ['email','telephone','indifferent'], 'urgence' => ['pas_presse','sous_6_mois','sous_3_mois','urgent'], 'type_bien' => ['maison','appartement','terrain','immeuble','local_commercial','autre'], 'nature_projet' => ['residence_principale','residence_secondaire','investissement','autre'], 'marche_souhaite' => ['ancien','neuf','indifferent'], 'financement_statut' => ['valide','en_cours','non_commence','comptant'], 'vente_prealable' => ['oui','non'], 'exterieur_souhaite' => ['indispensable','souhaite','non'], 'stationnement_souhaite' => ['indispensable','souhaite','non'], 'travaux_acceptes' => ['aucun','legers','importants','indifferent'], 'accompagnement_souhaite' => ['agent','chasseur','a_definir'], 'usage_bien' => ['principale','secondaire','locatif','succession','autre'], 'ascenseur' => ['oui','non','sans_objet'], 'etat_general' => ['excellent','bon','a_rafraichir','travaux_importants'], 'diagnostics_statut' => ['realises','programmes','non_realises'], 'occupation_bien' => ['libre','occupe_proprietaire','loue'], 'estimation_existante' => ['oui','non'], 'mandat_existant' => ['aucun','simple','exclusif'], 'location_type' => ['vide','meublee'], 'service_recherche' => ['locataire_uniquement','gestion_complete','a_definir'], 'assurance_loyers_impayes' => ['oui','non','a_definir'], 'budget_travaux_inclus' => ['oui','non'], 'pre_visites_deleguees' => ['oui','non','a_definir'], 'accompagnement_existant' => ['aucun','agence','chasseur','autre']] as $field => $values) if (array_key_exists($field, $data) && enumValue($data[$field], $values, in_array($field, ['contact_prefere','urgence','type_bien'], true)) === null) fail('INVALID_REQUEST');
    foreach (['secteurs_recherches','secteurs_exclus','adresse_bien','secteur','travaux_realises','travaux_a_prevoir','motif_vente','disponibilites','equipements','secteurs_prioritaires','secteurs_secondaires','situation_projet','environnement_recherche','proximites_souhaitees','criteres_indispensables','criteres_souhaites','criteres_redhibitoires','disponibilites_visites'] as $field) if (array_key_exists($field, $data) && (($data[$field] = text($data[$field], 1000, in_array($field, ['adresse_bien','secteur','secteurs_recherches','secteurs_prioritaires'], true))) === null)) fail('INVALID_REQUEST');
    foreach (['budget_min','budget_max','apport_disponible','prix_souhaite','loyer_souhaite','charges_mensuelles','surface_terrain','nombre_pieces','nombre_chambres','etage','annee_construction','nombre_pieces_min','nombre_chambres_min','terrain_min'] as $field) if (array_key_exists($field, $data) && (($data[$field] = integerValue($data[$field], 50000000)) === null)) fail('INVALID_REQUEST');
    foreach (['surface','surface_min'] as $field) if (array_key_exists($field, $data) && (($data[$field] = decimalValue($data[$field], 100000)) === null)) fail('INVALID_REQUEST');
    if (isset($data['budget_min'], $data['budget_max']) && $data['budget_min'] && $data['budget_max'] && $data['budget_min'] > $data['budget_max']) fail('INVALID_REQUEST');
    $requiredByType = ['recherche_achat' => ['nature_projet','secteurs_recherches','budget_max'], 'vendre' => ['adresse_bien','secteur','prix_souhaite'], 'confier_location' => ['adresse_bien','secteur','loyer_souhaite'], 'confier_recherche' => ['nature_projet','secteurs_prioritaires','budget_max']];
    foreach ($requiredByType[$data['type_demande']] as $field) if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === 0 || $data[$field] === 0.0) fail('INVALID_REQUEST');
    return $data;
}
function validateStatus(array $input): array {
    if (!isset($input['code_suivi'], $input['email']) || !is_string($input['code_suivi']) || !is_string($input['email'])) fail('INVALID_REQUEST');
    $code = strtoupper(trim($input['code_suivi'])); $email = strtolower(trim($input['email']));
    if (!preg_match('/^AVA-P-[A-HJ-NP-Z2-9]{8}$/', $code) || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('INVALID_REQUEST');
    return ['code_suivi' => $code, 'email' => $email];
}
function callAppsScript(array $payload): array {
    $config = particuliersReadPrivateConfig();
    if ($config === null) fail('SERVICE_UNAVAILABLE', 503);
    $url = $config['PARTICULIERS_APPS_SCRIPT_URL']; $secret = $config['PARTICULIERS_GATEWAY_SHARED_SECRET'];
    if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || strlen($secret) < 32 || !function_exists('curl_init')) fail('SERVICE_UNAVAILABLE', 503);
    $payload['gateway_secret'] = $secret;
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $raw = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    if (!is_string($raw) || $status < 200 || $status >= 300) fail('SERVICE_UNAVAILABLE', 503);
    try { $response = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (Throwable $e) { fail('SERVICE_UNAVAILABLE', 503); }
    if (!is_array($response)) fail('SERVICE_UNAVAILABLE', 503);
    return $response;
}
function filterPublic(array $response): array {
    $allowed = ['ok','code_suivi','type_demande','secteur','statut','message_suivi','date_creation','date_maj','error'];
    $safe = array_intersect_key($response, array_flip($allowed));
    if (($safe['ok'] ?? false) !== true) return ['ok' => false, 'error' => in_array($safe['error'] ?? '', ['REQUEST_NOT_FOUND'], true) ? $safe['error'] : 'SERVICE_UNAVAILABLE'];
    return $safe;
}
function enforceOrigin(): void { $origin = $_SERVER['HTTP_ORIGIN'] ?? ''; if ($origin !== '' && $origin !== P_ALLOWED_ORIGIN) fail('INVALID_REQUEST', 403); }

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('INVALID_REQUEST', 405);
    enforceOrigin();
    $config = particuliersReadPrivateConfig();
    if ($config === null) fail('SERVICE_UNAVAILABLE', 503);
    if ($config['PARTICULIERS_ENABLED'] !== 'true') fail('BACKEND_DISABLED', 503);
    $input = readJsonBody(); $action = $input['action'] ?? '';
    if (!is_string($action) || !in_array($action, ['initForm','createRequest','getRequestStatus'], true)) fail('INVALID_ACTION');
    rateLimit($action);
    if ($action === 'initForm') sendJson(['ok' => true, 'form_token' => createFormToken(), 'expires_in' => P_TOKEN_TTL]);
    if ($action === 'createRequest') { validateFormToken($input['form_token'] ?? null); $data = validateCreate($input); $data['action'] = 'createRequest'; sendJson(filterPublic(callAppsScript($data))); }
    $data = validateStatus($input); $data['action'] = 'getRequestStatus'; sendJson(filterPublic(callAppsScript($data)));
} catch (Throwable $exception) {
    sendUnexpectedResponse();
}
