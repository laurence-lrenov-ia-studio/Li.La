# Installation — backend public particuliers AVA

Ce guide installe un backend distinct du Hub professionnel. Ne collez jamais cette source dans le projet Apps Script du Hub et ne renseignez jamais les secrets dans le dépôt.

## 1. Créer le Spreadsheet distinct

1. Créez un nouveau Google Spreadsheet réservé aux demandes publiques.
2. Ne le rattachez pas au Spreadsheet du Hub AVA.
3. Limitez le partage aux personnes AVA autorisées à traiter les demandes.
4. Relevez l'identifiant situé entre `/d/` et `/edit` dans son URL.

L'onglet est créé par le script sous le nom exact :

`Demandes_Particuliers_AVA`

## 2. Créer le projet Apps Script distinct

1. Créez un nouveau projet Apps Script autonome depuis script.google.com.
2. Collez le contenu de `apps_script_particuliers.gs` dans ce nouveau projet.
3. Vérifiez qu'aucun fichier du Hub, aucune configuration Supabase et aucun Spreadsheet Hub n'ont été importés.

## 3. Configurer les Script Properties

Dans les paramètres du projet Apps Script, ajoutez ces propriétés, sans les versionner :

| Propriété | Valeur attendue |
|---|---|
| `PARTICULIERS_ENABLED` | `false` pendant l'installation, `true` uniquement après recette |
| `PARTICULIERS_GATEWAY_SHARED_SECRET` | Secret aléatoire d'au moins 32 caractères, identique à celui de la passerelle PHP |
| `PARTICULIERS_SPREADSHEET_ID` | Identifiant du nouveau Spreadsheet uniquement |
| `PARTICULIERS_CONSENT_VERSION` | Version courte du consentement, par exemple `2026-07-v1` |

Ne mettez aucune valeur réelle dans le fichier source, dans GitHub ou dans le HTML.

## 4. Initialiser l'onglet

1. Gardez `PARTICULIERS_ENABLED=false`.
2. Exécutez manuellement `setupParticuliersSheet()`.
3. Autorisez uniquement les permissions nécessaires au nouveau Spreadsheet.
4. Vérifiez que les 25 colonnes historiques restent dans cet ordre :

```text
id, request_id, code_suivi, type_demande, prenom, nom, email, telephone,
secteur, budget_max, surface, type_bien, prix_souhaite, informations, urgence,
statut, message_suivi, consentement_rgpd, version_consentement,
date_consentement, source, date_creation, date_maj, date_cloture, actif
```

La fonction ne supprime aucune donnée. Elle conserve ces colonnes et ajoute à droite les colonnes de questionnaire manquantes : contact, projet, secteurs, budgets, financement, caractéristiques du bien, diagnostics, location et recherche. Si une colonne historique diffère, elle retourne `SHEET_SCHEMA_DIVERGENT` et s'arrête.

La limite JSON est de 32 KiB, uniquement pour ces réponses textuelles. Aucun fichier, pièce d'identité, relevé bancaire ou document financier n'est accepté.

## 5. Déployer un environnement de test

1. Travaillez avec un Spreadsheet et un projet Apps Script de test distincts.
2. Déployez l'application Web de test.
3. Configurez l'accès de manière à ce que seule la passerelle PHP de test puisse joindre l'URL.
4. La passerelle PHP devra transmettre du JSON, l'action autorisée et `gateway_secret`.
5. Ne rendez pas l'URL Apps Script visible dans le front public.

Apps Script n'accepte que `createRequest` et `getRequestStatus`. `doGet()` reste fermé. Le jeton de formulaire est créé et vérifié par PHP : il ne doit pas être envoyé à Apps Script.

## 6. Tester avant toute activation

La fonction manuelle `runParticuliersUnitTests()` ne touche ni Spreadsheet ni données réelles. Elle vérifie les validations, les quatre parcours, l'idempotence simulée, le format du code, la projection publique et la fermeture de `doGet()`.

Pour la recette complète, utilisez exclusivement l'environnement de test et vérifiez notamment :

- une création valide pour chacun des quatre parcours ;
- le rejeu d'un même `request_id` ;
- l'unicité des codes ;
- champs absents, consentement absent, enums et montants invalides ;
- code/e-mail corrects, mauvais e-mail, code inconnu et demande inactive ;
- absence de prénom, nom, téléphone, e-mail ou informations dans le suivi ;
- secret absent, backend désactivé et `doGet()` fermé.

## 7. Limites et interdictions

- Aucun e-mail n'est envoyé par ce script.
- Le script ne contacte ni Supabase ni le Hub AVA.
- Le script ne lit aucune feuille `Membres`, `Biens_a_vendre` ou `Demandes_recherche` du Hub.
- Le déploiement, la configuration Hostinger et l'activation du front nécessitent un lot et une autorisation distincts.
