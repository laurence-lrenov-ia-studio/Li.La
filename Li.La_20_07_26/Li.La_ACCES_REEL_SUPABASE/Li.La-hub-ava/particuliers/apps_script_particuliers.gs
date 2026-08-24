/**
 * Li.La by La Maison d'AVA — demandes publiques particuliers
 *
 * Source destinée à un projet Apps Script DISTINCT, relié uniquement à un
 * Spreadsheet DISTINCT. Elle ne doit jamais être ajoutée au projet Hub.
 *
 * Configuration requise dans les Script Properties (valeurs jamais versionnées) :
 * - PARTICULIERS_ENABLED
 * - PARTICULIERS_GATEWAY_SHARED_SECRET
 * - PARTICULIERS_SPREADSHEET_ID
 * - PARTICULIERS_CONSENT_VERSION
 */

const PARTICULIERS_SHEET_NAME_ = 'Demandes_Particuliers_AVA';
const PARTICULIERS_MAX_BODY_BYTES_ = 32768;
const PARTICULIERS_MAX_CODE_ATTEMPTS_ = 12;
const PARTICULIERS_TRACKING_ALPHABET_ = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const PARTICULIERS_DEFAULT_STATUS_ = 'Reçue';
const PARTICULIERS_DEFAULT_MESSAGE_ = 'Votre demande a bien été reçue par La Maison d\'AVA.';
const PARTICULIERS_SOURCE_ = 'web_particuliers_ava';

const PARTICULIERS_HEADERS_ = [
  'id',
  'request_id',
  'code_suivi',
  'type_demande',
  'prenom',
  'nom',
  'email',
  'telephone',
  'secteur',
  'budget_max',
  'surface',
  'type_bien',
  'prix_souhaite',
  'informations',
  'urgence',
  'statut',
  'message_suivi',
  'consentement_rgpd',
  'version_consentement',
  'date_consentement',
  'source',
  'date_creation',
  'date_maj',
  'date_cloture',
  'actif'
].concat([
  'contact_prefere', 'nature_projet', 'marche_souhaite', 'secteurs_recherches', 'secteurs_exclus',
  'budget_min', 'apport_disponible', 'financement_statut', 'vente_prealable', 'nombre_pieces_min',
  'nombre_chambres_min', 'surface_min', 'terrain_min', 'exterieur_souhaite', 'stationnement_souhaite',
  'travaux_acceptes', 'criteres_indispensables', 'criteres_redhibitoires', 'accompagnement_souhaite',
  'adresse_bien', 'usage_bien', 'surface_terrain', 'nombre_pieces', 'nombre_chambres', 'etage', 'ascenseur',
  'exterieur', 'stationnement', 'annee_construction', 'etat_general', 'travaux_realises', 'travaux_a_prevoir',
  'diagnostics_statut', 'occupation_bien', 'estimation_existante', 'mandat_existant', 'motif_vente', 'disponibilites',
  'location_type', 'date_disponibilite', 'loyer_souhaite', 'charges_mensuelles', 'equipements', 'service_recherche',
  'assurance_loyers_impayes', 'situation_projet', 'secteurs_prioritaires', 'secteurs_secondaires',
  'budget_travaux_inclus', 'environnement_recherche', 'proximites_souhaitees', 'criteres_souhaites',
  'disponibilites_visites', 'pre_visites_deleguees', 'accompagnement_existant'
]);

const PARTICULIERS_INPUT_FIELDS_ = PARTICULIERS_HEADERS_.filter(function(header) {
  return ['id', 'code_suivi', 'statut', 'message_suivi', 'version_consentement', 'date_consentement', 'source', 'date_creation', 'date_maj', 'date_cloture', 'actif'].indexOf(header) < 0;
});

const PARTICULIERS_TYPES_ = [
  'recherche_achat',
  'vendre',
  'confier_location',
  'confier_recherche'
];

const PARTICULIERS_URGENCES_ = [
  'pas_presse',
  'sous_6_mois',
  'sous_3_mois',
  'urgent'
];

const PARTICULIERS_TYPES_BIEN_ = [
  'maison',
  'appartement',
  'terrain',
  'local_commercial',
  'autre'
];

const PARTICULIERS_PUBLIC_STATUSES_ = [
  'Reçue',
  'En cours de qualification',
  'Transmise au collectif',
  'Clôturée'
];

/**
 * Toutes les requêtes GET sont fermées. Aucune donnée métier n'est exposée.
 */
function doGet() {
  return particuliersJsonResponse_({ ok: false, error: 'METHOD_NOT_ALLOWED' });
}

/**
 * Entrée exclusive de la passerelle PHP. Le navigateur ne doit jamais appeler
 * ce déploiement Apps Script directement.
 */
function doPost(e) {
  const startedAt = Date.now();
  let action = 'unknown';
  let requestId = '';

  try {
    const payload = particuliersParseJsonRequest_(e);
    action = String(payload.action || 'unknown');
    requestId = String(payload.request_id || '');

    if (!particuliersIsEnabled_()) {
      particuliersLog_(action, 'SERVICE_UNAVAILABLE', requestId, startedAt);
      return particuliersJsonResponse_({ ok: false, error: 'SERVICE_UNAVAILABLE' });
    }

    if (!particuliersVerifyGatewaySecret_(payload.gateway_secret)) {
      particuliersLog_(action, 'REQUEST_UNAVAILABLE', requestId, startedAt);
      return particuliersJsonResponse_({ ok: false, error: 'REQUEST_UNAVAILABLE' });
    }

    let result;
    if (action === 'createRequest') {
      result = particuliersCreateRequest_(payload);
    } else if (action === 'getRequestStatus') {
      result = particuliersGetRequestStatus_(payload);
    } else {
      result = { ok: false, error: 'INVALID_REQUEST' };
    }

    particuliersLog_(action, result.ok ? 'OK' : result.error, requestId, startedAt);
    return particuliersJsonResponse_(result);
  } catch (error) {
    particuliersLog_(action, 'SERVICE_UNAVAILABLE', requestId, startedAt);
    return particuliersJsonResponse_({ ok: false, error: 'SERVICE_UNAVAILABLE' });
  }
}

/**
 * Prépare le Spreadsheet séparé sans jamais supprimer de ligne ou de colonne.
 * À exécuter manuellement une seule fois après configuration des properties.
 */
function setupParticuliersSheet() {
  const spreadsheet = particuliersOpenSpreadsheet_();
  let sheet = spreadsheet.getSheetByName(PARTICULIERS_SHEET_NAME_);
  let created = false;

  if (!sheet) {
    sheet = spreadsheet.insertSheet(PARTICULIERS_SHEET_NAME_);
    created = true;
  }

  const schema = particuliersReadSchema_(sheet);
  if (schema.isEmpty) {
    sheet.getRange(1, 1, 1, PARTICULIERS_HEADERS_.length).setValues([PARTICULIERS_HEADERS_]);
  } else if (!schema.matches) {
    if (!schema.prefixMatches) {
      return { ok: false, error: 'SHEET_SCHEMA_DIVERGENT', created: created, expected_headers: PARTICULIERS_HEADERS_, found_headers: schema.headers };
    }
    const missing = PARTICULIERS_HEADERS_.slice(schema.headers.length);
    if (missing.length) sheet.getRange(1, schema.headers.length + 1, 1, missing.length).setValues([missing]);
  }

  sheet.setFrozenRows(1);
  particuliersApplyDateFormats_(sheet);
  const protectionWarning = particuliersProtectHeader_(sheet);

  return {
    ok: true,
    created: created,
    sheet_name: PARTICULIERS_SHEET_NAME_,
    columns: PARTICULIERS_HEADERS_.length,
    header_protection_warning: protectionWarning || ''
  };
}

function particuliersCreateRequest_(payload) {
  if (!particuliersHasOnlyKeys_(payload, ['action', 'gateway_secret'].concat(PARTICULIERS_INPUT_FIELDS_))) {
    return { ok: false, error: 'INVALID_REQUEST' };
  }

  const validation = particuliersValidateCreateInput_(payload);
  if (!validation.ok) return { ok: false, error: 'INVALID_REQUEST' };

  const lock = LockService.getScriptLock();
  if (!lock.tryLock(10000)) return { ok: false, error: 'SERVICE_UNAVAILABLE' };

  try {
    const sheet = particuliersGetReadySheet_();
    const headerIndex = particuliersHeaderIndex_();
    const existing = particuliersFindRowByRequestId_(sheet, headerIndex, validation.value.request_id);
    if (existing) {
      const existingCode = particuliersValidateTrackingCode_(existing[headerIndex.code_suivi]);
      const existingStatus = String(existing[headerIndex.statut] || '');
      if (!existingCode || PARTICULIERS_PUBLIC_STATUSES_.indexOf(existingStatus) < 0) {
        return { ok: false, error: 'SERVICE_UNAVAILABLE' };
      }
      return {
        ok: true,
        code_suivi: existingCode,
        statut: existingStatus
      };
    }

    const existingCodes = particuliersReadColumnSet_(sheet, headerIndex.code_suivi);
    const code = particuliersGenerateTrackingCode_(existingCodes);
    if (!code) return { ok: false, error: 'SERVICE_UNAVAILABLE' };

    const now = new Date();
    const consentVersion = particuliersReadConsentVersion_();
    if (!consentVersion) return { ok: false, error: 'SERVICE_UNAVAILABLE' };

    const row = particuliersBuildRequestRow_(validation.value, code, consentVersion, now);
    sheet.appendRow(row);
    return { ok: true, code_suivi: code, statut: PARTICULIERS_DEFAULT_STATUS_ };
  } catch (error) {
    return { ok: false, error: 'SERVICE_UNAVAILABLE' };
  } finally {
    lock.releaseLock();
  }
}

function particuliersGetRequestStatus_(payload) {
  if (!particuliersHasOnlyKeys_(payload, ['action', 'gateway_secret', 'code_suivi', 'email'])) {
    return { ok: false, error: 'REQUEST_NOT_FOUND' };
  }

  const code = particuliersValidateTrackingCode_(payload.code_suivi);
  const email = particuliersValidateEmail_(payload.email);
  if (!code || !email) return { ok: false, error: 'REQUEST_NOT_FOUND' };

  try {
    const sheet = particuliersGetReadySheet_();
    const headerIndex = particuliersHeaderIndex_();
    const row = particuliersFindRowByCode_(sheet, headerIndex, code);
    if (!row) return { ok: false, error: 'REQUEST_NOT_FOUND' };

    if (!particuliersSecureStringEquals_(String(row[headerIndex.email] || ''), email)) {
      return { ok: false, error: 'REQUEST_NOT_FOUND' };
    }
    if (!particuliersIsStrictTrue_(row[headerIndex.actif])) {
      return { ok: false, error: 'REQUEST_NOT_FOUND' };
    }

    const publicData = particuliersProjectPublicStatus_(row, headerIndex);
    return publicData || { ok: false, error: 'REQUEST_NOT_FOUND' };
  } catch (error) {
    return { ok: false, error: 'SERVICE_UNAVAILABLE' };
  }
}

function particuliersParseJsonRequest_(e) {
  const postData = e && e.postData;
  const type = String(postData && postData.type || '').toLowerCase();
  const contents = String(postData && postData.contents || '');
  if (!type.startsWith('application/json') || !contents || contents.length > PARTICULIERS_MAX_BODY_BYTES_) {
    throw new Error('INVALID_REQUEST');
  }

  const payload = JSON.parse(contents);
  if (!payload || Array.isArray(payload) || typeof payload !== 'object') throw new Error('INVALID_REQUEST');
  return payload;
}

function particuliersIsEnabled_() {
  return String(PropertiesService.getScriptProperties().getProperty('PARTICULIERS_ENABLED') || '').trim().toLowerCase() === 'true';
}

function particuliersVerifyGatewaySecret_(providedSecret) {
  const expectedSecret = String(PropertiesService.getScriptProperties().getProperty('PARTICULIERS_GATEWAY_SHARED_SECRET') || '');
  const receivedSecret = String(providedSecret || '');
  if (expectedSecret.length < 32 || receivedSecret.length < 32) return false;
  return particuliersSecureStringEquals_(expectedSecret, receivedSecret);
}

function particuliersSecureStringEquals_(left, right) {
  const leftDigest = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, String(left), Utilities.Charset.UTF_8);
  const rightDigest = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, String(right), Utilities.Charset.UTF_8);
  let difference = leftDigest.length ^ rightDigest.length;
  const length = Math.max(leftDigest.length, rightDigest.length);
  for (let index = 0; index < length; index++) {
    difference |= (leftDigest[index] || 0) ^ (rightDigest[index] || 0);
  }
  return difference === 0;
}

function particuliersOpenSpreadsheet_() {
  const spreadsheetId = String(PropertiesService.getScriptProperties().getProperty('PARTICULIERS_SPREADSHEET_ID') || '').trim();
  if (!spreadsheetId) throw new Error('SPREADSHEET_NOT_CONFIGURED');
  return SpreadsheetApp.openById(spreadsheetId);
}

function particuliersGetReadySheet_() {
  const spreadsheet = particuliersOpenSpreadsheet_();
  const sheet = spreadsheet.getSheetByName(PARTICULIERS_SHEET_NAME_);
  if (!sheet) throw new Error('SHEET_NOT_READY');
  const schema = particuliersReadSchema_(sheet);
  if (!schema.matches) {
    if (!schema.prefixMatches) throw new Error('SHEET_SCHEMA_DIVERGENT');
    const missing = PARTICULIERS_HEADERS_.slice(schema.headers.length);
    if (missing.length) sheet.getRange(1, schema.headers.length + 1, 1, missing.length).setValues([missing]);
  }
  return sheet;
}

function particuliersReadSchema_(sheet) {
  const hasRows = sheet.getLastRow() > 0;
  const hasColumns = sheet.getLastColumn() > 0;
  if (!hasRows || !hasColumns) return { isEmpty: true, matches: false, headers: [] };

  const width = Math.max(sheet.getLastColumn(), PARTICULIERS_HEADERS_.length);
  const headers = sheet.getRange(1, 1, 1, width).getValues()[0].map(function(value) { return String(value || '').trim(); });
  const anyHeader = headers.some(function(value) { return value !== ''; });
  if (!anyHeader && sheet.getLastRow() <= 1) return { isEmpty: true, matches: false, headers: [] };

  const actual = headers.filter(function(value) { return value !== ''; });
  const prefixMatches = actual.every(function(value, index) { return value === PARTICULIERS_HEADERS_[index]; });
  const matches = actual.length === PARTICULIERS_HEADERS_.length && prefixMatches;
  return { isEmpty: false, matches: matches, prefixMatches: prefixMatches, headers: actual };
}

function particuliersApplyDateFormats_(sheet) {
  const rows = Math.max(sheet.getMaxRows() - 1, 1);
  ['date_consentement', 'date_creation', 'date_maj', 'date_cloture'].forEach(function(header) {
    const column = PARTICULIERS_HEADERS_.indexOf(header) + 1;
    sheet.getRange(2, column, rows, 1).setNumberFormat('yyyy-mm-dd hh:mm:ss');
  });
}

function particuliersProtectHeader_(sheet) {
  try {
    const range = sheet.getRange(1, 1, 1, PARTICULIERS_HEADERS_.length);
    const existing = sheet.getProtections(SpreadsheetApp.ProtectionType.RANGE).some(function(protection) {
      return protection.getRange().getA1Notation() === range.getA1Notation();
    });
    if (existing) return '';

    const protection = range.protect().setDescription('En-têtes Demandes_Particuliers_AVA');
    const owner = Session.getEffectiveUser();
    if (owner && owner.getEmail()) protection.addEditor(owner);
    return '';
  } catch (error) {
    return 'HEADER_PROTECTION_NOT_APPLIED';
  }
}

function particuliersValidateCreateInput_(payload) {
  const requestId = particuliersValidateUuidV4_(payload.request_id);
  const type = particuliersValidateEnum_(payload.type_demande, PARTICULIERS_TYPES_);
  const prenom = particuliersValidateName_(payload.prenom, 60);
  const nom = particuliersValidateName_(payload.nom, 80);
  const email = particuliersValidateEmail_(payload.email);
  const telephone = particuliersValidatePhone_(payload.telephone);
  const secteur = particuliersValidateText_(payload.secteur, 0, 120);
  const informations = particuliersValidateText_(payload.informations, 0, 2000);
  const urgence = particuliersValidateEnum_(payload.urgence, PARTICULIERS_URGENCES_);
  const typeBien = particuliersValidateEnum_(payload.type_bien, PARTICULIERS_TYPES_BIEN_);
  if (!requestId || !type || !prenom || !nom || !email || !telephone ||
      informations === null || !urgence || !typeBien || payload.consentement_rgpd !== true) {
    return { ok: false };
  }
  const requiredByType = {
    recherche_achat: ['nature_projet', 'secteurs_recherches', 'budget_max'],
    vendre: ['adresse_bien', 'secteur', 'prix_souhaite'],
    confier_location: ['adresse_bien', 'secteur', 'loyer_souhaite'],
    confier_recherche: ['nature_projet', 'secteurs_prioritaires', 'budget_max']
  };
  const numericFields = ['budget_min','budget_max','apport_disponible','prix_souhaite','loyer_souhaite','charges_mensuelles','surface_terrain','nombre_pieces','nombre_chambres','etage','annee_construction','nombre_pieces_min','nombre_chambres_min','terrain_min'];
  const decimalFields = ['surface','surface_min'];
  const enumSets = {
    contact_prefere: ['email','telephone','indifferent'], nature_projet: ['residence_principale','residence_secondaire','investissement','autre'], marche_souhaite: ['ancien','neuf','indifferent'], financement_statut: ['valide','en_cours','non_commence','comptant'], vente_prealable: ['oui','non'], exterieur_souhaite: ['indispensable','souhaite','non'], stationnement_souhaite: ['indispensable','souhaite','non'], travaux_acceptes: ['aucun','legers','importants','indifferent'], accompagnement_souhaite: ['agent','chasseur','a_definir'], usage_bien: ['principale','secondaire','locatif','succession','autre'], ascenseur: ['oui','non','sans_objet'], etat_general: ['excellent','bon','a_rafraichir','travaux_importants'], diagnostics_statut: ['realises','programmes','non_realises'], occupation_bien: ['libre','occupe_proprietaire','loue'], estimation_existante: ['oui','non'], mandat_existant: ['aucun','simple','exclusif'], location_type: ['vide','meublee'], service_recherche: ['locataire_uniquement','gestion_complete','a_definir'], assurance_loyers_impayes: ['oui','non','a_definir'], budget_travaux_inclus: ['oui','non'], pre_visites_deleguees: ['oui','non','a_definir'], accompagnement_existant: ['aucun','agence','chasseur','autre']
  };
  const value = {};
  PARTICULIERS_INPUT_FIELDS_.forEach(function(field) {
    let current = payload[field] === undefined ? '' : payload[field];
    const mandatory = requiredByType[type].indexOf(field) >= 0;
    if (numericFields.indexOf(field) >= 0) current = particuliersValidateInteger_(current, mandatory, 0, 50000000);
    else if (decimalFields.indexOf(field) >= 0) current = particuliersValidateDecimal_(current, mandatory, 0, 100000);
    else if (enumSets[field]) current = (current === '' || current === null) ? '' : particuliersValidateEnum_(current, enumSets[field]);
    else if (['request_id','type_demande','prenom','nom','email','telephone','consentement_rgpd','type_bien','urgence'].indexOf(field) < 0) current = particuliersValidateText_(current, mandatory ? 1 : 0, 1000);
    if (current === null || (mandatory && current === '')) value.invalid = true;
    value[field] = current;
  });
  if (value.invalid || (value.budget_min && value.budget_max && value.budget_min > value.budget_max) ||
      payload.consentement_rgpd !== true) {
    return { ok: false };
  }
  value.request_id = requestId; value.type_demande = type; value.prenom = prenom; value.nom = nom; value.email = email; value.telephone = telephone; value.secteur = secteur; value.informations = informations; value.urgence = urgence; value.type_bien = typeBien; value.consentement_rgpd = true;
  return { ok: true, value: value };
}

function particuliersValidateUuidV4_(value) {
  const uuid = String(value || '').trim().toLowerCase();
  return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(uuid) ? uuid : '';
}

function particuliersValidateName_(value, maximum) {
  const name = particuliersValidateText_(value, 1, maximum);
  if (name === null) return '';
  return /^[A-Za-zÀ-ÖØ-öø-ÿĀ-ž'’ -]+$/.test(name) ? name : '';
}

function particuliersValidateEmail_(value) {
  const email = String(value || '').trim().toLowerCase();
  if (!email || email.length > 254 || /[\u0000-\u001F\u007F]/.test(email)) return '';
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? email : '';
}

function particuliersValidatePhone_(value) {
  const raw = String(value || '').trim();
  if (!raw || raw.length > 30 || /[^0-9+().\s-]/.test(raw)) return '';
  let compact = raw.replace(/[().\s-]/g, '');
  if (compact.indexOf('00') === 0) compact = '+' + compact.slice(2);
  if (/^0\d{9}$/.test(compact)) compact = '+33' + compact.slice(1);
  return /^\+[1-9]\d{7,14}$/.test(compact) ? compact : '';
}

function particuliersValidateText_(value, minimum, maximum) {
  if (value === null || value === undefined) return minimum === 0 ? '' : null;
  const raw = String(value);
  if (raw.length > maximum || /[\u0000-\u001F\u007F]/.test(raw)) return null;
  const clean = raw.replace(/\s+/g, ' ').trim();
  if (clean.length < minimum || clean.length > maximum || /[<>]/.test(clean)) return null;
  return clean;
}

function particuliersValidateEnum_(value, allowedValues) {
  const normalized = String(value || '').trim();
  return allowedValues.indexOf(normalized) >= 0 ? normalized : '';
}

function particuliersValidateInteger_(value, required, minimum, maximum) {
  if (value === null || value === undefined || value === '') return required ? null : '';
  if (typeof value === 'string' && !/^\d+$/.test(value.trim())) return null;
  const number = Number(value);
  if (!Number.isFinite(number) || !Number.isInteger(number) || number < minimum || number > maximum) return null;
  return number;
}

function particuliersValidateDecimal_(value, required, minimum, maximum) {
  if (value === null || value === undefined || value === '') return required ? null : '';
  if (typeof value === 'string' && !/^\d+(?:[.,]\d{1,2})?$/.test(value.trim())) return null;
  const number = Number(String(value).replace(',', '.'));
  if (!Number.isFinite(number) || number < minimum || number > maximum) return null;
  return number;
}

function particuliersValidateTrackingCode_(value) {
  const code = String(value || '').trim().toUpperCase();
  return /^AVA-P-[A-HJ-NP-Z2-9]{8}$/.test(code) ? code : '';
}

function particuliersReadConsentVersion_() {
  const version = String(PropertiesService.getScriptProperties().getProperty('PARTICULIERS_CONSENT_VERSION') || '').trim();
  return /^[A-Za-z0-9._-]{1,64}$/.test(version) ? version : '';
}

function particuliersBuildRequestRow_(value, code, consentVersion, now) {
  const technical = { id: Utilities.getUuid(), code_suivi: code, statut: PARTICULIERS_DEFAULT_STATUS_, message_suivi: PARTICULIERS_DEFAULT_MESSAGE_, version_consentement: consentVersion, date_consentement: now, source: PARTICULIERS_SOURCE_, date_creation: now, date_maj: now, date_cloture: '', actif: true };
  return PARTICULIERS_HEADERS_.map(function(header) { return Object.prototype.hasOwnProperty.call(technical, header) ? technical[header] : (value[header] === undefined ? '' : value[header]); });
}

function particuliersHeaderIndex_() {
  const index = {};
  PARTICULIERS_HEADERS_.forEach(function(header, position) { index[header] = position; });
  return index;
}

function particuliersFindRowByRequestId_(sheet, index, requestId) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return null;
  const values = sheet.getRange(2, 1, lastRow - 1, PARTICULIERS_HEADERS_.length).getValues();
  for (let position = 0; position < values.length; position++) {
    if (String(values[position][index.request_id] || '') === requestId) return values[position];
  }
  return null;
}

function particuliersFindRowByCode_(sheet, index, code) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return null;
  const values = sheet.getRange(2, 1, lastRow - 1, PARTICULIERS_HEADERS_.length).getValues();
  for (let position = 0; position < values.length; position++) {
    if (String(values[position][index.code_suivi] || '') === code) return values[position];
  }
  return null;
}

function particuliersReadColumnSet_(sheet, zeroBasedIndex) {
  const values = new Set();
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return values;
  sheet.getRange(2, zeroBasedIndex + 1, lastRow - 1, 1).getValues().forEach(function(row) {
    const value = String(row[0] || '');
    if (value) values.add(value);
  });
  return values;
}

function particuliersGenerateTrackingCode_(existingCodes) {
  for (let attempt = 0; attempt < PARTICULIERS_MAX_CODE_ATTEMPTS_; attempt++) {
    const code = 'AVA-P-' + particuliersRandomCharacters_(8);
    if (!existingCodes.has(code)) return code;
  }
  return '';
}

function particuliersRandomCharacters_(length) {
  let output = '';
  let source = Utilities.getUuid() + ':' + Date.now() + ':' + Math.random();
  while (output.length < length) {
    const digest = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, source, Utilities.Charset.UTF_8);
    for (let index = 0; index < digest.length && output.length < length; index++) {
      const byte = digest[index] & 0xff;
      if (byte < 224) output += PARTICULIERS_TRACKING_ALPHABET_.charAt(byte % PARTICULIERS_TRACKING_ALPHABET_.length);
    }
    source = source + ':' + Utilities.getUuid();
  }
  return output;
}

function particuliersProjectPublicStatus_(row, index) {
  const type = particuliersValidateEnum_(row[index.type_demande], PARTICULIERS_TYPES_);
  const sector = particuliersValidateText_(row[index.secteur], 2, 120);
  const status = String(row[index.statut] || '');
  const message = particuliersValidateText_(row[index.message_suivi], 1, 300);
  const code = particuliersValidateTrackingCode_(row[index.code_suivi]);
  const created = particuliersToIsoDate_(row[index.date_creation]);
  const updated = particuliersToIsoDate_(row[index.date_maj]);
  if (!type || !sector || PARTICULIERS_PUBLIC_STATUSES_.indexOf(status) < 0 || !message || !code || !created || !updated) return null;

  return {
    ok: true,
    code_suivi: code,
    type_demande: type,
    secteur: sector,
    statut: status,
    message_suivi: message,
    date_creation: created,
    date_maj: updated
  };
}

function particuliersToIsoDate_(value) {
  const date = value instanceof Date ? value : new Date(value);
  return date instanceof Date && !isNaN(date.getTime()) ? date.toISOString() : '';
}

function particuliersIsStrictTrue_(value) {
  return value === true || String(value || '').trim().toLowerCase() === 'true';
}

function particuliersHasOnlyKeys_(object, allowedKeys) {
  const keys = Object.keys(object || {});
  return keys.every(function(key) { return allowedKeys.indexOf(key) >= 0; });
}

function particuliersJsonResponse_(object) {
  return ContentService.createTextOutput(JSON.stringify(object)).setMimeType(ContentService.MimeType.JSON);
}

function particuliersLog_(action, result, requestId, startedAt) {
  const safeAction = /^[A-Za-z]+$/.test(String(action || '')) ? String(action) : 'unknown';
  const safeResult = /^[A-Z_]+$/.test(String(result || '')) ? String(result) : 'UNKNOWN';
  const safeRequestId = /^[0-9a-f-]{36}$/i.test(String(requestId || '')) ? String(requestId) : '';
  const durationMs = Math.max(0, Date.now() - Number(startedAt || Date.now()));
  console.log(JSON.stringify({ timestamp: new Date().toISOString(), action: safeAction, result: safeResult, request_id: safeRequestId, duration_ms: durationMs }));
}

// ---------------------------------------------------------------------------
// Tests unitaires non exposés publiquement. Ils sont purs : aucun Spreadsheet,
// aucune propriété réelle, aucun déploiement et aucune donnée réelle ne sont
// touchés. Ils ne s'exécutent jamais automatiquement.
// ---------------------------------------------------------------------------

function runParticuliersUnitTests() {
  const results = [];
  function check(name, condition) {
    results.push({ name: name, ok: !!condition });
    if (!condition) throw new Error('TEST_FAILED_' + name);
  }

  const purchase = particuliersValidTestInput_('recherche_achat');
  const sale = particuliersValidTestInput_('vendre');
  const rental = particuliersValidTestInput_('confier_location');
  const search = particuliersValidTestInput_('confier_recherche');
  check('creation_achat_valide', particuliersValidateCreateInput_(purchase).ok);
  check('creation_vente_valide', particuliersValidateCreateInput_(sale).ok);
  check('creation_location_valide', particuliersValidateCreateInput_(rental).ok);
  check('creation_recherche_valide', particuliersValidateCreateInput_(search).ok);

  const replay = particuliersSimulateIdempotency_(purchase);
  check('request_id_rejoue', replay.first.code_suivi === replay.second.code_suivi && replay.first.statut === replay.second.statut && replay.rows_after_replay === 1 && replay.codes_after_replay === 1);
  check('deux_creations_simulees', replay.third.code_suivi !== replay.first.code_suivi && replay.rows === 2);
  check('code_unique', /^AVA-P-[A-HJ-NP-Z2-9]{8}$/.test(replay.first.code_suivi));

  const missing = particuliersClone_(purchase); delete missing.prenom;
  check('champ_obligatoire_absent', !particuliersValidateCreateInput_(missing).ok);
  const noConsent = particuliersClone_(purchase); noConsent.consentement_rgpd = false;
  check('consentement_absent', !particuliersValidateCreateInput_(noConsent).ok);
  const invalidEnum = particuliersClone_(purchase); invalidEnum.type_bien = 'invalide';
  check('enum_invalide', !particuliersValidateCreateInput_(invalidEnum).ok);
  const invalidAmount = particuliersClone_(sale); invalidAmount.prix_souhaite = -1;
  check('montant_invalide', !particuliersValidateCreateInput_(invalidAmount).ok);
  const tooLong = particuliersClone_(purchase); tooLong.informations = new Array(2002).join('x');
  check('texte_trop_long', !particuliersValidateCreateInput_(tooLong).ok);

  const publicRow = particuliersMockPublicRow_();
  const index = particuliersHeaderIndex_();
  const projection = particuliersProjectPublicStatus_(publicRow, index);
  check('suivi_correct', projection && projection.ok && projection.code_suivi === 'AVA-P-7K4M2X9Q');
  check('mauvais_email', !particuliersSecureStringEquals_('camille@example.fr', 'autre@example.fr'));
  check('code_inconnu', !particuliersValidateTrackingCode_('AVA-P-00000000'));
  const inactive = particuliersClone_(publicRow); inactive[index.actif] = false;
  check('demande_inactive', !particuliersIsStrictTrue_(inactive[index.actif]));
  check('projection_publique_limitee', Object.keys(projection).join(',') === 'ok,code_suivi,type_demande,secteur,statut,message_suivi,date_creation,date_maj');
  check('secret_absent', !particuliersSecureStringEquals_('', 'x'));
  check('backend_desactive', !particuliersIsEnabledValue_('false'));
  check('doGet_ferme', JSON.parse(doGet().getContent()).error === 'METHOD_NOT_ALLOWED');

  return { ok: true, tests: results, count: results.length };
}

function particuliersIsEnabledValue_(value) {
  return String(value || '').trim().toLowerCase() === 'true';
}

function particuliersValidTestInput_(type) {
  const isSearch = type === 'recherche_achat' || type === 'confier_recherche';
  return {
    request_id: isSearch ? '7aa7ff36-85ea-4fb6-a6a6-05df9b79eef0' : (type === 'vendre' ? '8bb7ff36-85ea-4fb6-a6a6-05df9b79eef0' : '9cc7ff36-85ea-4fb6-a6a6-05df9b79eef0'),
    type_demande: type,
    prenom: 'Camille',
    nom: 'Martin',
    email: 'camille@example.fr',
    telephone: '06 12 34 56 78',
    secteur: 'Bordeaux',
    budget_max: isSearch ? 350000 : '',
    surface: 80,
    type_bien: 'maison',
    prix_souhaite: isSearch ? '' : 280000,
    informations: 'Recherche avec extérieur.',
    urgence: 'sous_6_mois',
    consentement_rgpd: true
  };
}

function particuliersSimulateIdempotency_(firstInput) {
  const rows = [];
  const codes = new Set();
  function insert(input) {
    const existing = rows.find(function(row) { return row.request_id === input.request_id; });
    if (existing) return existing;
    const row = { request_id: input.request_id, code_suivi: particuliersGenerateTrackingCode_(codes), statut: PARTICULIERS_DEFAULT_STATUS_ };
    codes.add(row.code_suivi);
    rows.push(row);
    return row;
  }
  const first = insert(firstInput);
  const second = insert(particuliersClone_(firstInput));
  const rowsAfterReplay = rows.length;
  const codesAfterReplay = codes.size;
  const thirdInput = particuliersClone_(firstInput);
  thirdInput.request_id = 'acc7ff36-85ea-4fb6-a6a6-05df9b79eef0';
  const third = insert(thirdInput);
  return { first: first, second: second, third: third, rows_after_replay: rowsAfterReplay, codes_after_replay: codesAfterReplay, rows: rows.length };
}

function particuliersMockPublicRow_() {
  const index = particuliersHeaderIndex_();
  const row = new Array(PARTICULIERS_HEADERS_.length).fill('');
  row[index.code_suivi] = 'AVA-P-7K4M2X9Q';
  row[index.type_demande] = 'recherche_achat';
  row[index.email] = 'camille@example.fr';
  row[index.secteur] = 'Bordeaux';
  row[index.statut] = 'En cours de qualification';
  row[index.message_suivi] = 'Votre demande est en cours d’étude par La Maison d’AVA.';
  row[index.date_creation] = new Date('2026-07-27T10:00:00.000Z');
  row[index.date_maj] = new Date('2026-07-27T12:00:00.000Z');
  row[index.actif] = true;
  return row;
}

function particuliersClone_(value) {
  return JSON.parse(JSON.stringify(value));
}
