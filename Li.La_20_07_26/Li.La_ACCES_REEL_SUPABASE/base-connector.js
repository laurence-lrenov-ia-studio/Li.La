/*************************************************************
 * Li.La - Connecteur Base
 * --------------------------------------------------------
 * BASE_URL reste vide tant que le CORE générique n'est pas configuré.
 *
 * Compatibilite :
 *   baseAdd(fiche)        -> contacts
 *   baseList()            -> contacts
 *   baseAuto(render, ms)  -> contacts
 *
 * Multi-onglets :
 *   baseAdd(type, fiche)
 *   baseList(type)
 *   baseUpdate(type, fiche)
 *   baseAuto(type, render, ms)
 *   baseStop()
 *************************************************************/

const BASE_URL = '';

const BASE_DEFAULT_TYPE = 'contacts';
let _basePolls = [];

function _baseArgs(type, payload) {
  if (typeof type === 'string') return { type: type || BASE_DEFAULT_TYPE, payload: payload };
  return { type: BASE_DEFAULT_TYPE, payload: type };
}

function _baseUrl(type, params) {
  const q = new URLSearchParams(params || {});
  q.set('type', type || BASE_DEFAULT_TYPE);
  return BASE_URL + '?' + q.toString();
}

function _baseReady() {
  return /^https?:\/\/.+\/exec(\?|$)/.test(BASE_URL);
}

/* App -> Sheet : post en text/plain + no-cors. Le polling confirme l'arrivee. */
async function baseAdd(type, fiche) {
  const args = _baseArgs(type, fiche);
  if (!_baseReady()) return { ok: false, skipped: true, error: 'BASE_URL non configure' };
  try {
    await fetch(_baseUrl(args.type), {
      method: 'POST',
      mode: 'no-cors',
      headers: { 'Content-Type': 'text/plain;charset=utf-8' },
      body: JSON.stringify(args.payload || {})
    });
    return { ok: true };
  } catch (e) {
    return { ok: false, error: String(e) };
  }
}

/* Meme route Apps Script : si fiche.id existe, doPost met a jour la ligne. */
function baseUpdate(type, fiche) {
  return baseAdd(type, fiche);
}

/* Sheet -> App : JSONP pour contourner CORS. */
function baseList(type) {
  const t = (typeof type === 'string' && type) ? type : BASE_DEFAULT_TYPE;
  if (!_baseReady()) return Promise.resolve([]);
  return new Promise(function (resolve, reject) {
    const cb = 'lila_cb_' + t.replace(/\W/g, '_') + '_' + Date.now();
    const s = document.createElement('script');
    const to = setTimeout(function () { cleanup(); reject(new Error('timeout')); }, 8000);
    function cleanup() { clearTimeout(to); delete window[cb]; if (s.parentNode) s.remove(); }
    window[cb] = function (data) { cleanup(); resolve((data && data.rows) || []); };
    s.onerror = function () { cleanup(); reject(new Error('reseau')); };
    s.src = _baseUrl(t, { callback: cb, _: Date.now() });
    document.body.appendChild(s);
  });
}

/* Statut d'installation Li.La : lecture seule, sans lancement public. */
function baseInstallationStatus() {
  if (!_baseReady()) return Promise.resolve({ ok: false, installation_status: 'A configurer', error: 'BASE_URL non configure' });
  return new Promise(function (resolve, reject) {
    const cb = 'lila_install_status_' + Date.now();
    const s = document.createElement('script');
    const to = setTimeout(function () { cleanup(); reject(new Error('timeout')); }, 8000);
    function cleanup() { clearTimeout(to); delete window[cb]; if (s.parentNode) s.remove(); }
    window[cb] = function (data) { cleanup(); resolve(data || {}); };
    s.onerror = function () { cleanup(); reject(new Error('reseau')); };
    s.src = _baseUrl('installationStatus', { callback: cb, _: Date.now() });
    document.body.appendChild(s);
  });
}

/* Polling compatible mono-type et multi-type. */
function baseAuto(type, render, ms) {
  let t = type;
  let fn = render;
  let delay = ms;
  if (typeof type === 'function') {
    t = BASE_DEFAULT_TYPE;
    fn = type;
    delay = render;
  }
  if (typeof fn !== 'function') return;
  const tick = function () { baseList(t).then(fn).catch(function () {}); };
  tick();
  _basePolls.push(setInterval(tick, delay || 4000));
}

function baseStop() {
  _basePolls.forEach(function (id) { clearInterval(id); });
  _basePolls = [];
}
