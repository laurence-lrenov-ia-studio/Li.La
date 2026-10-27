# Li.La V2 — Cockpit

Coque de travail indépendante. Ouvrir `index.html` via un serveur HTTP local, ou servir ce dossier sous un chemin du domaine existant. Aucun déploiement réalisé.

## Origine des briques

- Référence : `../Li.La_20_07_26/index.html` (entrée principale du projet).
- `supabase-client.js` : configuration et création du client extraites à l’identique, SDK Supabase v2 existant, même URL, même clé publique anon, mêmes options et stockage de session par défaut. Aucun projet, compte ou schéma créé ; aucune configuration distante modifiée.
- Connexion par lien magique avec `shouldCreateUser: false`, récupération de session, renouvellement automatique, suivi des changements et déconnexion repris du fonctionnement existant.
- Destination de connexion inchangée : `https://app.li-la.fr/`. Le lien reçu ouvre donc l’ancienne entrée. Sur le même domaine, revenir au chemin V2 retrouve la session. Un serveur localhost ne partage pas le stockage du domaine de production : le lien de production n’authentifie pas localhost. Un parcours de retour direct V2 devra être validé au déploiement, avec les URLs autorisées existantes.
- `profile.js` : normalisation et lecture du profil `lila_user_profile` extraites de l’existant. Le profil historique n’est pas une table Supabase : il est local au navigateur et au domaine. Lecture seule dans la V2, uniquement si son e-mail correspond au compte connecté. Sinon, seul l’e-mail du compte est repris. Aucun profil historique écrasé.
- Logo, symbole, icônes PWA copiés sans modification. Palette nuit/orange/lavande/sauge, polices Inter/Poppins et icônes Tabler identiques.
- Météo : même API Open-Meteo et géolocalisation, désormais déclenchées au clic, sans ville ou température fictive.
- Manifest adapté avec identité et périmètre V2. Service worker historique non repris : il supprime les caches des autres applications à l’activation. Aucun nouveau service worker enregistré ; pas de promesse de mode hors ligne.

## Périmètre de cette étape

Petites cartes pour Ma journée, Notes, Tâches, Mon Réseau / CRM, Biens & projets et Hub Immobilier Li.La neutre. Chaque carte explique son état « À venir ». La recherche filtre réellement les espaces et outils ; elle n’interroge pas encore de données métier. Les accès ChatGPT, Claude, Gemini, Gamma, Gmail et Agenda sont de simples liens externes.

Les Notes historiques sont une démonstration sans persistance, avec écritures désactivées : aucune fausse sauvegarde ou dictée simulée reprise. Le connecteur Google Apps Script historique n’est pas nécessaire à cette coque et reste dans l’ancienne version, avec les autres backends/API. Aucun appel de données métier effectué.

Li.La Office, Com, Pulse, Studio et toutes les variantes du Hub Maison d’AVA restent dans l’ancienne arborescence, sans modification. Le lien « Ancienne version » est uniquement un accès à la référence.

## Vérification

Les contrôles automatisés locaux vérifient le contrat de configuration, l’absence de modification de l’ancienne arborescence, et les parcours avec un client Supabase simulé. Ils ne prouvent pas une connexion réelle : aucun e-mail de connexion n’a été envoyé et aucun compte utilisateur n’a été utilisé pour les tests.

Résultats : `node --check` sur les trois scripts applicatifs ; `verify.cjs` passé dans Edge sans interface visible (connexion simulée, envoi OTP simulé sans création de compte, isolation du profil entre comptes, recherche, fenêtres, erreur de déconnexion et expiration de session). Aucun débordement horizontal à 320, 390, 768 et 1440 px. Les ressources CDN sont bloquées dans ce test ; le contrôle visuel local porte sur la disposition avec polices de repli. Le chargement réel des polices et icônes nécessite une connexion réseau.

Rejouer avec Node et Playwright disponibles : `node Li.La_V2_COCKPIT/verify.cjs` depuis la racine. Le script utilise Edge installé. Contrôle de préservation : `git diff --exit-code -- Li.La_20_07_26` réussi, 316 fichiers suivis ; seuls les fichiers du nouveau dossier sont ajoutés. `git diff --check` réussi. Pas de push ni déploiement.
