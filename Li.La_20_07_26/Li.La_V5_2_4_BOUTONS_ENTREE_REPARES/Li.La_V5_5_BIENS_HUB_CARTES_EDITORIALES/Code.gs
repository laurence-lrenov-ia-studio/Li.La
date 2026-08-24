/*************************************************************
 * Li·La — Connecteur Base  (Google Apps Script · Web App)
 * =======================================================
 * UNE base = ce Google Sheet (plusieurs onglets)
 * UN script = ce fichier. On choisit l'onglet avec ?type=
 *
 *   ?type=contacts     -> onglet "Contacts"    (CRM)        [compatible app actuelle]
 *   ?type=calendrier   -> onglet "Calendrier"  (editorial)
 *   ?type=production   -> onglet "Production"   (posts/annonces generes)
 *   ?type=biens        -> onglet "Biens"        (Hub : biens & recherches)
 *   ?type=galerie      -> onglet "Galerie"      (photos -> fichier Drive + URL)
 *
 * App -> Sheet : doPost  (ajoute / met a jour une fiche)
 * Sheet -> App : doGet   (renvoie toutes les fiches ; JSONP pour le navigateur)
 *
 * A LANCER UNE SEULE FOIS depuis l'editeur : setup()
 *   -> cree tous les onglets d'un coup + quelques lignes d'exemple.
 *
 * Deploiement : voir GUIDE-DEPLOIEMENT-BASE.md
 *************************************************************/

/* =========================================================
 * 1) REGLAGES — la seule zone a toucher
 * ========================================================= */

/* Securite (prod) : mets un mot-cle pour exiger un jeton dans chaque appel.
   Laisse '' pour la demo (acces libre par l'URL). */
const TOKEN = '';

/* Galerie photos : colle ici l'ID du dossier Drive qui recevra les images.
   (l'ID = la suite de caracteres dans l'URL du dossier, apres /folders/)
   Laisse '' si tu n'utilises pas encore la galerie. */
const GALERIE_FOLDER_ID = '';

/* Installation Li.La : renseigne ici l'ID du dossier parent AVA si l'espace
   doit etre cree dans un Drive partage. Laisse vide pour le Drive utilisateur. */
const LILA_INSTALL_PARENT_FOLDER_ID = '';

const LILA_INSTALL_SUBFOLDERS = [
  '01_Biens',
  '01_Biens/Photos',
  '01_Biens/Mandats',
  '01_Biens/Visuels',
  '02_Recherches',
  '02_Recherches/Acquéreurs',
  '02_Recherches/Chasse',
  '03_Studio',
  '03_Studio/Posts',
  '03_Studio/Calendrier',
  '03_Studio/Images',
  '04_CRM',
  '04_CRM/Contacts',
  '04_CRM/Notes',
  '05_Galerie',
  '06_Réglages',
  '07_Assistant'
];

const LILA_INSTALL_TAB_KEYS = ['recherches', 'reglages', 'statistiques'];

/* Catalogue des onglets : nom d'onglet + colonnes + couleur d'entete (charte Li.La).
   Pour ajouter un module plus tard : une ligne ici, et c'est tout. */
const TABLES = {
  contacts: {
    sheet: 'Contacts',
    headers: ['ID', 'Nom', 'Type', 'Statut', 'Telephone', 'Recherche/Bien', 'Derniere action', 'Ajoute par', 'Horodatage'],
    headerBg: '#F1ECF5',
    map: ['id', 'nom', 'type', 'statut', 'tel', 'recherche', 'action', 'par', '_date'],
    prefix: 'C',
    defaults: { type: 'A qualifier', statut: 'Nouveau', action: 'Fiche creee', par: 'Li.La (app)' }
  },
  calendrier: {
    sheet: 'Calendrier',
    headers: ['ID', 'Date', 'Canal', 'Sujet', 'Statut', 'Contenu', 'Ajoute par', 'Horodatage'],
    headerBg: '#FDEEE4',
    map: ['id', 'date', 'canal', 'sujet', 'statut', 'contenu', 'par', '_date'],
    prefix: 'E',
    defaults: { canal: 'LinkedIn', statut: 'A faire', par: 'Li.La (app)' }
  },
  production: {
    sheet: 'Production',
    headers: ['ID', 'Type', 'Canal', 'Titre', 'Texte', 'Bien lie', 'Ajoute par', 'Horodatage'],
    headerBg: '#EBF0E7',
    map: ['id', 'type', 'canal', 'titre', 'texte', 'bien', 'par', '_date'],
    prefix: 'P',
    defaults: { type: 'Annonce', par: 'Li.La (app)' }
  },
  biens: {
    sheet: 'Biens',
    headers: ['ID', 'Sens', 'Type bien', 'Secteur', 'Prix/Budget', 'Surface', 'Statut', 'Notes', 'Ajoute par', 'Horodatage'],
    headerBg: '#F1ECF5',
    map: ['id', 'sens', 'typebien', 'secteur', 'prix', 'surface', 'statut', 'notes', 'par', '_date'],
    prefix: 'B',
    defaults: { sens: 'Vente', statut: 'Actif', par: 'Hub' }
  },
  galerie: {
    sheet: 'Galerie',
    headers: ['ID', 'URL', 'Legende', 'Bien lie', 'Ajoute par', 'Horodatage'],
    headerBg: '#EBF0E7',
    map: ['id', 'url', 'legende', 'bien', 'par', '_date'],
    prefix: 'G',
    defaults: { par: 'Li.La (app)' }
  },
  recherches: {
    sheet: 'Recherches',
    headers: ['ID', 'Type', 'Client', 'Secteur', 'Budget', 'Criteres', 'Statut', 'Ajoute par', 'Horodatage'],
    headerBg: '#FDEEE4',
    map: ['id', 'type', 'client', 'secteur', 'budget', 'criteres', 'statut', 'par', '_date'],
    prefix: 'R',
    defaults: { type: 'Acquereur', statut: 'Active', par: 'Li.La (app)' }
  },
  reglages: {
    sheet: 'Réglages',
    headers: ['Cle', 'Valeur', 'Categorie', 'Mis a jour'],
    headerBg: '#F1ECF5',
    map: ['cle', 'valeur', 'categorie', '_date'],
    prefix: 'S',
    defaults: { categorie: 'Installation' }
  },
  statistiques: {
    sheet: 'Statistiques',
    headers: ['ID', 'Indicateur', 'Valeur', 'Periode', 'Mis a jour'],
    headerBg: '#EBF0E7',
    map: ['id', 'indicateur', 'valeur', 'periode', '_date'],
    prefix: 'ST',
    defaults: { periode: 'Global' }
  }
};

/* =========================================================
 * 2) ROUTAGE — rien a toucher en dessous
 * ========================================================= */

/** Renvoie la config du type demande (Contacts par defaut -> compat app actuelle). */
function table_(e) {
  const t = (e && e.parameter && e.parameter.type ? String(e.parameter.type) : 'contacts').toLowerCase();
  return TABLES[t] || TABLES.contacts;
}

/** Recupere (ou cree) un onglet avec son entete. */
function sheetFor_(cfg) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  return sheetForSpreadsheet_(ss, cfg);
}

function sheetForSpreadsheet_(ss, cfg) {
  let sh = ss.getSheetByName(cfg.sheet);
  if (!sh) sh = ss.insertSheet(cfg.sheet);
  if (sh.getLastRow() === 0) {
    sh.appendRow(cfg.headers);
    sh.getRange(1, 1, 1, cfg.headers.length).setFontWeight('bold').setBackground(cfg.headerBg || '#F1ECF5');
    sh.setFrozenRows(1);
  }
  return sh;
}

/** Sheet -> App. JSONP si ?callback=, sinon JSON brut. */
function doGet(e) {
  if (TOKEN && (!e || !e.parameter || e.parameter.token !== TOKEN)) {
    return out_(e, { ok: false, error: 'jeton invalide' });
  }
  if (e && e.parameter && String(e.parameter.type || '').toLowerCase() === 'installationstatus') {
    return out_(e, getInstallationStatus());
  }
  const cfg = table_(e);
  const rows = readRows_(cfg);
  return out_(e, { ok: true, type: cfg.sheet, count: rows.length, rows: rows });
}

/** App -> Sheet. Ajoute, ou met a jour si l'ID existe. Galerie : ecrit l'image dans Drive. */
function doPost(e) {
  try {
    const data = JSON.parse((e && e.postData && e.postData.contents) || '{}');
    if (TOKEN && data.token !== TOKEN) {
      return out_(e, { ok: false, error: 'jeton invalide' });
    }
    const cfg = table_(e);

    if (cfg.sheet === 'Galerie' && data.image) {
      data.url = saveImage_(data);
    }

    const sh = sheetFor_(cfg);
    const id = data.id || (cfg.prefix + '-' + Utilities.formatDate(new Date(), 'GMT+2', 'yyMMdd-HHmmss'));
    data.id = id;
    data._date = new Date();
    const d = cfg.defaults || {};

    const row = cfg.map.map(function (k) {
      if (k === '_date') return data._date;
      if (data[k] !== undefined && data[k] !== '') return data[k];
      return d[k] !== undefined ? d[k] : '';
    });

    const last = sh.getLastRow();
    const ids = last > 1 ? sh.getRange(2, 1, last - 1, 1).getValues().flat() : [];
    const idx = ids.indexOf(id);
    if (idx >= 0) sh.getRange(idx + 2, 1, 1, row.length).setValues([row]);
    else sh.appendRow(row);

    return out_(e, { ok: true, type: cfg.sheet, id: id, url: data.url || '', mode: idx >= 0 ? 'update' : 'insert' });
  } catch (err) {
    return out_(e, { ok: false, error: String(err) });
  }
}

/** Lit toutes les fiches non vides d'un onglet. */
function readRows_(cfg) {
  const sh = sheetFor_(cfg);
  const values = sh.getDataRange().getValues();
  const head = values.shift() || cfg.headers;
  return values
    .filter(function (r) { return r[1] || r[0]; })
    .map(function (r) {
      const o = {};
      head.forEach(function (h, i) { o[h] = r[i]; });
      return o;
    });
}

/** Galerie : enregistre une image base64 dans le dossier Drive, renvoie une URL affichable. */
function saveImage_(data) {
  if (!GALERIE_FOLDER_ID) throw new Error('GALERIE_FOLDER_ID non configure');
  const raw = String(data.image).replace(/^data:[^;]+;base64,/, '');
  const mime = (String(data.image).match(/^data:([^;]+);base64,/) || [null, 'image/jpeg'])[1];
  const ext = mime.split('/')[1] || 'jpg';
  const name = (data.legende ? String(data.legende).slice(0, 40).replace(/[^\w\- ]+/g, '') : 'photo') +
               '-' + Utilities.formatDate(new Date(), 'GMT+2', 'yyMMdd-HHmmss') + '.' + ext;
  const blob = Utilities.newBlob(Utilities.base64Decode(raw), mime, name);
  const file = DriveApp.getFolderById(GALERIE_FOLDER_ID).createFile(blob);
  file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
  return 'https://drive.google.com/uc?export=view&id=' + file.getId();
}

/** Sortie JSON ou JSONP selon ?callback=. */
function out_(e, obj) {
  const cb = e && e.parameter && e.parameter.callback;
  const body = JSON.stringify(obj);
  if (cb) {
    return ContentService.createTextOutput(cb + '(' + body + ')')
      .setMimeType(ContentService.MimeType.JAVASCRIPT);
  }
  return ContentService.createTextOutput(body)
    .setMimeType(ContentService.MimeType.JSON);
}

/* =========================================================
 * 3) SETUP — a lancer UNE fois depuis l'editeur
 * ========================================================= */

/** Cree tous les onglets d'un coup + quelques lignes d'exemple pour la demo. */
function setup() {
  Object.keys(TABLES).forEach(function (k) { sheetFor_(TABLES[k]); });

  seed_('Contacts', [
    ['C-001', 'Camille Fournier', 'Acquereur', 'Recherche active', '06 14 22 80 55', 'T3 + exterieur . Le Bouscat', 'Memo vocal . ce matin', 'Li.La (vocal)', new Date()],
    ['C-002', 'Sophie Marchand', 'Vendeur', 'A relancer', '06 78 54 32 10', 'Maison Eysines . 110 m2', 'Mail recu', 'Office', new Date()]
  ]);

  seed_('Calendrier', [
    ['E-001', '2026-06-23', 'LinkedIn', 'Cafe IA + Immo - retour event', 'A faire', '', 'Li.La (app)', new Date()],
    ['E-002', '2026-06-25', 'Instagram', 'Avant/apres home staging T3', 'A valider', '', 'Studio', new Date()]
  ]);

  seed_('Biens', [
    ['B-001', 'Vente', 'Appartement', 'Merignac centre', '249 000 EUR', '68 m2', 'Actif', 'Offre recue a valider', 'Hub', new Date()]
  ]);

  seed_('Production', [
    ['P-001', 'Post', 'LinkedIn', 'T3 Merignac centre', 'Nouveau T3 lumineux a Merignac centre. 68 m2, proche tram, ideal premier achat.', 'B-001', 'Studio', new Date()]
  ]);

  seed_('Galerie', [
    ['G-001', '', 'Salon lumineux - T3 Merignac', 'B-001', 'Li.La (app)', new Date()]
  ]);
}

/** Helper : ajoute des lignes d'exemple seulement si l'onglet est vide. */
function seed_(name, rows) {
  const sh = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(name);
  if (sh && sh.getLastRow() <= 1) rows.forEach(function (r) { sh.appendRow(r); });
}

/* =========================================================
 * 4) INSTALLATION LI.LA
 * ========================================================= */

function runLindaInstallation() {
  return installLilaEnvironment({
    name: 'Linda',
    companyName: "La Maison d'AVA",
    job: 'Agent immobilier',
    style: 'Premium, professionnel et chaleureux',
    parentFolderId: LILA_INSTALL_PARENT_FOLDER_ID
  });
}

function installLilaEnvironment(profile) {
  profile = profile || {};
  const now = new Date();
  const ss = getActiveInstallationSpreadsheet_();
  const company = cleanInstallValue_(profile.companyName || profile.company || profile.societe || "La Maison d'AVA");
  const name = cleanInstallValue_(profile.name || 'Linda');
  const parentFolderId = cleanInstallValue_(profile.parentFolderId || LILA_INSTALL_PARENT_FOLDER_ID || '');
  const rootName = 'Li.La - ' + company;
  const sheetInfo = { id: ss.getId(), url: ss.getUrl(), name: ss.getName() };
  const created = { folders: [], sheet: false, tabs: [] };

  try {
    const root = getOrCreateFolder_(rootName, parentFolderId, created);
    const folders = {};
    folders[rootName] = root;

    LILA_INSTALL_SUBFOLDERS.forEach(function (path) {
      let parent = root;
      let currentPath = '';
      path.split('/').forEach(function (part) {
        currentPath = currentPath ? currentPath + '/' + part : part;
        const key = rootName + '/' + currentPath;
        if (!folders[key]) folders[key] = getOrCreateFolder_(part, parent.id, created);
        parent = folders[key];
      });
    });

    ensureInstallationTabs_(ss, created);
    seedDemoData_(ss, created.tabs);

    const settings = {
      drive_root_id: root.id,
      drive_root_url: root.url,
      sheet_id: sheetInfo.id,
      sheet_url: sheetInfo.url,
      installation_status: 'Connecte',
      installation_date: now,
      profile_name: name,
      company_name: company,
      job: cleanInstallValue_(profile.job || profile.metier || ''),
      style: cleanInstallValue_(profile.style || '')
    };
    writeSettings_(ss, settings);

    PropertiesService.getScriptProperties().setProperties({
      lila_sheet_id: sheetInfo.id,
      lila_drive_root_id: root.id,
      lila_company_name: company,
      lila_profile_name: name
    }, true);

    return {
      ok: true,
      status: 'Connecte',
      profile: settings,
      drive: root,
      sheet: sheetInfo,
      folders: folders,
      created: created
    };
  } catch (err) {
    return {
      ok: false,
      status: 'Erreur',
      error: installErrorMessage_(err)
    };
  }
}

function getInstallationStatus() {
  try {
    const settings = readClientSettings_();
    if (!settings.drive_root_id) {
      const sheet = checkSheet_(settings.sheet_id);
      const tabs = sheet.ok ? hasRequiredTabs_(settings.sheet_id) : false;
      return {
        ok: false,
        installation_status: 'A configurer',
        installation_date: settings.installation_date || '',
        profile_name: settings.profile_name || '',
        company_name: settings.company_name || '',
        drive_root_id: '',
        drive_root_url: '',
        sheet_id: settings.sheet_id || '',
        sheet_url: settings.sheet_url || '',
        statuses: {
          drive: 'A configurer',
          sheet: sheet.ok ? 'Connecte' : 'Erreur',
          environment: tabs ? 'A configurer' : 'Erreur',
          gmail: 'A connecter',
          agenda: 'A connecter',
          ai: 'A connecter'
        },
        checks: {
          sheet: sheet,
          tabs: { ok: tabs }
        },
        error: tabs ? 'Installation Drive non lancee.' : 'Onglets Li.La manquants.'
      };
    }
    if (!settings.sheet_id) {
      return installStatusPayload_('A configurer', {}, 'Installation non lancee.');
    }

    const drive = checkDrive_(settings.drive_root_id);
    const sheet = checkSheet_(settings.sheet_id);
    const tabs = sheet.ok ? hasRequiredTabs_(settings.sheet_id) : false;
    const folders = drive.ok ? checkRequiredFolders_(settings.drive_root_id) : { ok: false, missing: LILA_INSTALL_SUBFOLDERS };
    const envOk = drive.ok && sheet.ok && tabs && folders.ok;
    const status = envOk ? 'Connecte' : 'Erreur';

    return {
      ok: envOk,
      installation_status: status,
      installation_date: settings.installation_date || '',
      profile_name: settings.profile_name || '',
      company_name: settings.company_name || '',
      drive_root_id: settings.drive_root_id || '',
      drive_root_url: settings.drive_root_url || '',
      sheet_id: settings.sheet_id || '',
      sheet_url: settings.sheet_url || '',
      statuses: {
        drive: drive.ok ? 'Connecte' : 'Erreur',
        sheet: sheet.ok ? 'Connecte' : 'Erreur',
        environment: envOk ? 'Connecte' : 'Erreur',
        gmail: 'A connecter',
        agenda: 'A connecter',
        ai: 'A connecter'
      },
      checks: {
        drive: drive,
        sheet: sheet,
        tabs: { ok: tabs },
        folders: folders
      },
      error: envOk ? '' : [drive.error, sheet.error, folders.error, folders.missing && folders.missing.length ? 'Dossiers manquants: ' + folders.missing.join(', ') : ''].filter(Boolean).join(' | ')
    };
  } catch (err) {
    return installStatusPayload_('Erreur', {}, installErrorMessage_(err));
  }
}

function ensureInstallationTabs_(ss, created) {
  LILA_INSTALL_TAB_KEYS.forEach(function (k) {
    const cfg = TABLES[k];
    const before = !!ss.getSheetByName(cfg.sheet);
    sheetForSpreadsheet_(ss, cfg);
    if (!before) created.tabs.push(cfg.sheet);
  });
}

function seedDemoData_(ss, createdTabs) {
  createdTabs = createdTabs || [];
  if (createdTabs.indexOf('Recherches') >= 0) {
    seedSpreadsheet_(ss, 'Recherches', [
      ['R-001', 'Acquereur', 'Camille Fournier', 'Le Bouscat', '320 000 EUR', 'T3 avec exterieur', 'Active', 'Li.La (demo)', new Date()]
    ]);
  }
  if (createdTabs.indexOf('Statistiques') >= 0) {
    seedSpreadsheet_(ss, 'Statistiques', [
      ['ST-001', 'Installation Li.La', 'Initialisee', 'Global', new Date()]
    ]);
  }
}

function seedSpreadsheet_(ss, name, rows) {
  const sh = ss.getSheetByName(name);
  if (sh && sh.getLastRow() <= 1) rows.forEach(function (r) { sh.appendRow(r); });
}

function writeSettings_(ss, data) {
  const sh = sheetForSpreadsheet_(ss, TABLES.reglages);
  const now = new Date();
  const existing = sh.getLastRow() > 1 ? sh.getRange(2, 1, sh.getLastRow() - 1, 1).getValues().flat() : [];
  Object.keys(data).forEach(function (key) {
    const row = [key, data[key], 'Installation', now];
    const idx = existing.indexOf(key);
    if (idx >= 0) sh.getRange(idx + 2, 1, 1, row.length).setValues([row]);
    else {
      sh.appendRow(row);
      existing.push(key);
    }
  });
}

function readClientSettings_() {
  const ss = getActiveInstallationSpreadsheet_();
  const sheetId = ss.getId();
  const sh = ss.getSheetByName('Réglages') || ss.getSheetByName('Reglages');
  if (!sh || sh.getLastRow() < 2) return { sheet_id: sheetId, sheet_url: ss.getUrl() };

  const values = sh.getRange(2, 1, sh.getLastRow() - 1, 2).getValues();
  const data = {};
  values.forEach(function (r) {
    if (r[0]) data[String(r[0])] = r[1];
  });
  if (!data.sheet_id) data.sheet_id = sheetId;
  if (!data.sheet_url) data.sheet_url = ss.getUrl();
  return data;
}

function getActiveInstallationSpreadsheet_() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  if (!ss) throw new Error('Le projet Apps Script doit etre lie au Google Sheet Li.La existant.');
  return ss;
}

function findInstalledSheetId_(company) {
  const name = 'Li.La - ' + cleanInstallValue_(company || "La Maison d'AVA");
  if (LILA_INSTALL_PARENT_FOLDER_ID) {
    try {
      const parent = DriveApp.getFolderById(LILA_INSTALL_PARENT_FOLDER_ID);
      const roots = parent.getFoldersByName(name);
      const existing = roots.hasNext() ? findSpreadsheetInFolder_(name, roots.next().getId()) : null;
      if (existing && existing.id) return existing.id;
    } catch (err) {}
  }
  const files = DriveApp.getFilesByName(name);
  while (files.hasNext()) {
    const f = files.next();
    if (f.getMimeType() === MimeType.GOOGLE_SHEETS) return f.getId();
  }
  return '';
}

function getOrCreateFolder_(name, parentFolderId, created) {
  try {
    const parent = parentFolderId ? DriveApp.getFolderById(parentFolderId) : DriveApp.getRootFolder();
    const folders = parent.getFoldersByName(name);
    if (folders.hasNext()) return folderInfo_(folders.next());
    const folder = parent.createFolder(name);
    created.folders.push(folder.getName());
    return folderInfo_(folder);
  } catch (err) {
    if (!parentFolderId) throw err;
    return getOrCreateFolderAdvanced_(name, parentFolderId, created, err);
  }
}

function getOrCreateFolderAdvanced_(name, parentFolderId, created, originalError) {
  if (typeof Drive === 'undefined' || !Drive.Files) throw new Error('Drive partage inaccessible avec DriveApp. Active le service Drive avance dans Apps Script, puis relance. Detail: ' + originalError);
  const q = "'" + parentFolderId + "' in parents and title = '" + escapeDriveQuery_(name) + "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
  const found = Drive.Files.list({ q: q, supportsAllDrives: true, includeItemsFromAllDrives: true, maxResults: 1 });
  if (found.items && found.items.length) return driveFileInfo_(found.items[0]);
  const file = Drive.Files.insert({
    title: name,
    mimeType: 'application/vnd.google-apps.folder',
    parents: [{ id: parentFolderId }]
  }, null, { supportsAllDrives: true });
  created.folders.push(name);
  return driveFileInfo_(file);
}

function getOrCreateSpreadsheet_(name, parentFolderId, created) {
  throw new Error('Creation de Google Sheet desactivee : Li.La utilise le Sheet actif lie au projet Apps Script.');
}

function findSpreadsheetInFolder_(name, parentFolderId) {
  try {
    const parent = DriveApp.getFolderById(parentFolderId);
    const files = parent.getFilesByName(name);
    while (files.hasNext()) {
      const file = files.next();
      if (file.getMimeType() === MimeType.GOOGLE_SHEETS) return fileInfo_(file);
    }
  } catch (err) {
    if (typeof Drive === 'undefined' || !Drive.Files) throw new Error('Recherche du Sheet impossible dans ce Drive. Active le service Drive avance si le parent est un Drive partage. Detail: ' + err);
    const q = "'" + parentFolderId + "' in parents and title = '" + escapeDriveQuery_(name) + "' and mimeType = 'application/vnd.google-apps.spreadsheet' and trashed = false";
    const found = Drive.Files.list({ q: q, supportsAllDrives: true, includeItemsFromAllDrives: true, maxResults: 1 });
    if (found.items && found.items.length) return driveFileInfo_(found.items[0]);
  }
  return null;
}

function createSpreadsheetAdvanced_(name, parentFolderId, created, originalError) {
  throw new Error('Creation de Google Sheet desactivee : Li.La utilise le Sheet actif lie au projet Apps Script.');
}

function checkDrive_(id) {
  if (!id) return { ok: false, status: 'A configurer', error: 'drive_root_id absent' };
  try {
    const folder = DriveApp.getFolderById(id);
    return { ok: true, id: id, url: folder.getUrl(), name: folder.getName() };
  } catch (err) {
    return { ok: false, id: id, error: installErrorMessage_(err) };
  }
}

function checkSheet_(id) {
  if (!id) return { ok: false, status: 'A configurer', error: 'sheet_id absent' };
  try {
    const ss = SpreadsheetApp.openById(id);
    return { ok: true, id: id, url: ss.getUrl(), name: ss.getName() };
  } catch (err) {
    return { ok: false, id: id, error: installErrorMessage_(err) };
  }
}

function hasRequiredTabs_(sheetId) {
  const ss = SpreadsheetApp.openById(sheetId);
  return Object.keys(TABLES).every(function (k) {
    return !!ss.getSheetByName(TABLES[k].sheet);
  });
}

function checkRequiredFolders_(rootId) {
  try {
    const root = DriveApp.getFolderById(rootId);
    const missing = [];
    LILA_INSTALL_SUBFOLDERS.forEach(function (path) {
      let parent = root;
      let ok = true;
      path.split('/').forEach(function (part) {
        if (!ok) return;
        const folders = parent.getFoldersByName(part);
        if (!folders.hasNext()) {
          ok = false;
          return;
        }
        parent = folders.next();
      });
      if (!ok) missing.push(path);
    });
    return { ok: missing.length === 0, missing: missing };
  } catch (err) {
    return { ok: false, missing: LILA_INSTALL_SUBFOLDERS, error: installErrorMessage_(err) };
  }
}

function installStatusPayload_(status, data, error) {
  return {
    ok: status === 'Connecte',
    installation_status: status,
    statuses: {
      drive: status,
      sheet: status,
      environment: status,
      gmail: 'A connecter',
      agenda: 'A connecter',
      ai: 'A connecter'
    },
    error: error || ''
  };
}

function cleanInstallValue_(value) {
  return String(value == null ? '' : value).trim();
}

function folderInfo_(folder) {
  return { id: folder.getId(), url: folder.getUrl(), name: folder.getName() };
}

function fileInfo_(file) {
  return { id: file.getId(), url: file.getUrl(), name: file.getName() };
}

function driveFileInfo_(file) {
  return { id: file.id, url: file.alternateLink || ('https://drive.google.com/open?id=' + file.id), name: file.title };
}

function escapeDriveQuery_(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function installErrorMessage_(err) {
  return err && err.message ? err.message : String(err);
}
