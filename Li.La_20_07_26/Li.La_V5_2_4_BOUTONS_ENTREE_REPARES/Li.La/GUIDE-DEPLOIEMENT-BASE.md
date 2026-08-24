# Li·La — Base CRM live (Apps Script) — Guide de déploiement

**Objectif démo chasseurs :** Linda dicte dans l'app → la ligne apparaît **pour de vrai** dans le Google Sheet d'AVA. Ils éditent le Sheet → ça remonte dans l'app (relecture auto toutes les 4 s). Message à faire passer : **la base reste la leur, ils peuvent la gérer des deux côtés.**

Architecture : `App (Netlify)` ⇄ `Apps Script Web App (/exec)` ⇄ `Google Sheet "CRM_AVA"`.

---

## 1. Créer la base (compte AVA / Linda)

| # | Action |
|---|--------|
| 1 | Sur le **compte Google d'AVA**, va sur drive.google.com → **Nouveau → Google Sheets**. |
| 2 | Renomme le fichier **`CRM_AVA`**. (Pas besoin de créer les colonnes à la main, le script le fait.) |

## 2. Coller le script

| # | Action |
|---|--------|
| 3 | Dans le Sheet : **Extensions → Apps Script**. |
| 4 | Supprime le code par défaut, colle **tout `Code.gs`**. **Enregistrer** (icône disquette). |
| 5 | En haut, sélectionne la fonction **`setup`** → **Exécuter**. Autorise les accès (écran Google : *Avancé → Accéder au projet → Autoriser*). ✅ L'onglet `Contacts` + l'entête + 2 lignes d'exemple apparaissent dans le Sheet. |

## 3. Déployer en Web App

| # | Action |
|---|--------|
| 6 | Dans l'éditeur Apps Script : **Déployer → Nouveau déploiement**. |
| 7 | Type (roue crantée) → **Application Web**. |
| 8 | **Exécuter en tant que : Moi** (le compte AVA). |
| 9 | **Qui a accès : Tout le monde**. *(obligatoire pour que l'app publique puisse lire/écrire)* |
| 10 | **Déployer** → copie l'**URL de l'application Web** (finit par **`/exec`**). |

> ⚠️ À chaque modif du `Code.gs`, refais **Déployer → Gérer les déploiements → ✏️ → Nouvelle version**, sinon l'URL sert l'ancien code.

## 4. Brancher l'app

| # | Action |
|---|--------|
| 11 | Ouvre **`base-connector.js`**, colle ton URL `/exec` dans **`BASE_URL`**. |
| 12 | Colle le contenu de `base-connector.js` dans l'app (avant `</script>`), ou en `<script src=...>`. |
| 13 | Câble **un bouton réel** (ex. *Créer le contact* du Carnet) sur `baseAdd({...})`, et la **liste** sur `baseAuto(renderBaseList)`. Voir l'exemple en bas du fichier. |

---

## 5. Test de validation (avant la démo)

| Test | Attendu |
|------|---------|
| Ouvrir l'URL `/exec` dans un navigateur | du JSON : `{"ok":true,"count":...,"rows":[...]}` |
| `baseAdd({nom:'Test'})` depuis l'app | une **nouvelle ligne** dans le Sheet en < 2 s |
| Modifier une cellule **dans le Sheet** | la liste de l'app se **met à jour seule** en ≤ 4 s |
| Couper le wifi puis réessayer | l'app ne crashe pas (le polling échoue en silence) |

---

## 6. Pourquoi ces choix techniques (au cas où on te pose la question)

- **Écriture `no-cors`** : un navigateur ne peut pas appeler un Apps Script en POST classique (blocage CORS). On poste en `text/plain` + `no-cors` : la ligne s'écrit, on ne lit pas la réponse — le polling la confirme.
- **Lecture JSONP** : même raison côté lecture ; on charge le résultat via une balise `<script>` (`?callback=`), ce qui contourne le CORS.
- **« Double sens auto » = polling 4 s** : Apps Script ne peut pas *pousser* vers l'app. On **relit** le Sheet en boucle → effet automatique sans bouton.

---

## 7. Plan B démo (tu as choisi le double sens auto, plus exposé)

| Risque | Parade |
|--------|--------|
| Wifi lieu lent / coupé | **Partage de connexion 4G** sur le tel de démo, testé la veille. |
| Latence d'écriture visible | Annoncer « ça part dans la base… » pendant la seconde d'écriture (le délai devient une preuve, pas un bug). |
| URL `/exec` qui sert l'ancien code | Re-déployer **une nouvelle version** juste avant, puis re-tester l'URL. |
| Tout tombe | **Capture vidéo** du cycle complet (dictée → ligne dans le Sheet → modif Sheet → app) en secours sur le tel. |
| 2 écrans | App sur le tel, **Sheet projeté** à côté : le public voit la ligne tomber en direct. |

---

## 8. Durcissement (après la démo, pour la V1)

- Mettre un **`TOKEN`** dans `Code.gs` (constante en haut) + l'envoyer depuis l'app (`data.token` en POST, `?token=` en GET). Sans ça, l'URL `/exec` est un point d'accès public.
- Passer **« Qui a accès »** sur un domaine restreint quand l'app aura une vraie auth.
- Démontrer le même script sur **d'autres bases** (autre Sheet) : il suffit de coller `Code.gs` dans un autre fichier et de redéployer — d'où l'intérêt de le garder **générique**.

---

### Mémo des champs d'une fiche (POST)

```
{
  "id":        "C-0001",            // optionnel — auto si absent
  "nom":       "Camille Fournier",
  "type":      "Acquéreur",
  "statut":    "Recherche active",
  "tel":       "06 14 22 80 55",
  "recherche": "T3 + extérieur · Le Bouscat",
  "action":    "Fiche dictée en live",
  "par":       "Li·La (vocal)"
}
```
