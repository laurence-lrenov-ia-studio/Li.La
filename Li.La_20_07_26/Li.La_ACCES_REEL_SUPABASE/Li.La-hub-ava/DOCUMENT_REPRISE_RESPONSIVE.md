# Li.La by La Maison d’AVA  
## Document de reprise — améliorations fonctionnelles et responsive de l’interface

**Date :** 19 juillet 2026  
**Objet :** conserver toutes les améliorations réalisées dans le fil « fonctionnalités » afin de pouvoir les reporter proprement dans la future version graphique avec Codex.

---

## 1. Règle de travail validée

Deux chantiers sont séparés :

- **Fil fonctionnalités :** fonctionnement, navigation, recherche, responsive, données.
- **Fil design :** modernisation graphique, photos, styles, ambiance visuelle.

Le fichier final produit ici constitue une **référence fonctionnelle et responsive**.  
Il ne doit pas écraser une future version graphique plus récente. Les comportements décrits ci-dessous devront être **réintégrés dans le nouveau design**, sans revenir à l’ancienne apparence.

---

## 2. Fichiers de référence

### Source reçue

`lila-by-la-maison-d-ava(2).html`

### Versions intermédiaires produites

1. `lila-by-la-maison-d-ava-moteur-recherche.html`
   - ajout du moteur de recherche des biens et demandes ;

2. `lila-by-la-maison-d-ava-mobile.html`
   - ajout du menu hamburger ;
   - optimisation mobile du moteur de recherche ;

3. `lila-by-la-maison-d-ava-responsive-mobile.html`
   - responsive global de toutes les pages ;
   - correction des débordements ;
   - réduction des cartes et boutons sur téléphone.

### Référence fonctionnelle finale

`lila-by-la-maison-d-ava-responsive-mobile.html`

Cette version est la référence à conserver pour le comportement mobile et les fonctionnalités.

---

## 3. Données du Hub préparées dans ce fil

Deux bases ont été préparées à partir des cartes Netty visibles :

### Recherches acquéreurs

- fichier : `Demandes_recherche_Netty_base.csv`
- 10 recherches saisies ;
- 7 actives ;
- 3 historiques avec `actif = FALSE` ;
- colonnes de contrôle ajoutées dans la version Excel pour compléter les liens individuels.

### Biens à vendre

- fichier : `Biens_a_vendre_Netty_base.csv`
- 24 biens au total ;
- 20 actifs ;
- 4 non actifs, sous compromis ou vendus ;
- conservation de la ligne B001 déjà présente ;
- prix non visibles laissés vides ;
- liens externes et photos à compléter.

**Important :** les fichiers ont été préparés, mais leur import réel dans le Google Sheet n’a pas été effectué dans ce fil.

---

# 4. Moteur de recherche — « Les biens du Hub »

## 4.1. Objectif

Remplacer le simple champ de recherche par un moteur utilisable pour :

- les biens à vendre ;
- les recherches acquéreurs ;
- les deux catégories réunies.

Le moteur fonctionne directement sur les données déjà chargées depuis le Google Sheet.

## 4.2. Filtres ajoutés

### Mode « À vendre »

- type de bien ;
- localisation ;
- prix minimum ;
- prix maximum ;
- mot-clé libre.

### Mode « Recherches »

- type de recherche ;
- localisation recherchée ;
- budget minimum ;
- budget maximum ;
- mot-clé libre.

### Mode « Tous »

- type de bien ou de recherche ;
- localisation ;
- prix ou budget minimum ;
- prix ou budget maximum ;
- mot-clé libre.

## 4.3. Comportement dynamique

Les libellés changent automatiquement selon le mode sélectionné :

- `Biens à vendre` ;
- `Recherches acquéreurs` ;
- `Biens et recherches du collectif`.

Les menus déroulants sont alimentés automatiquement à partir des données :

- `type_bien` pour les biens ;
- `type_recherche` pour les demandes ;
- `ville` pour les localisations.

Une nouvelle ville ou un nouveau type ajouté au Sheet doit donc apparaître sans modification manuelle du code.

## 4.4. Logique de filtrage

Le filtrage cumule les critères.

Le mot-clé recherche dans :

- titre ;
- ville ;
- département ;
- description ;
- type de bien ;
- type de recherche.

La recherche :

- ignore les majuscules/minuscules ;
- ignore les accents ;
- n’affiche que les lignes avec `actif = TRUE` ;
- compare `prix` pour les biens ;
- compare `budget_max` pour les recherches acquéreurs ;
- trie les résultats par `date_ajout`, du plus récent au plus ancien.

## 4.5. Éléments d’interface ajoutés

- boutons `Tous / À vendre / Recherches` ;
- bouton `Rechercher` ;
- bouton `Réinitialiser` ;
- compteur de résultats ;
- message vide adapté au contexte :
  - aucun bien correspondant ;
  - aucune recherche correspondante ;
  - aucun résultat global.

## 4.6. Fonctions JavaScript ajoutées ou modifiées

- `normalizeBibliothequeText(value)`
- `bibliothequeItems()`
- `bibliothequeItemType(item)`
- `bibliothequeItemAmount(item)`
- `updateBibliothequeLabels()`
- `populateBibliothequeFilters(preserveSelection)`
- `resetBibliothequeFilters()`
- modification de `setBiensType(t)`
- extension de `renderBibliotheque()`

## 4.7. Architecture

- filtrage côté navigateur ;
- aucune IA ;
- aucun coût API supplémentaire ;
- aucune écriture dans le Google Sheet ;
- aucun changement Apps Script requis ;
- données lues depuis les tableaux `BIENS` et `DEMANDES`.

---

# 5. Navigation mobile — menu hamburger

## 5.1. Problème corrigé

La navigation principale était horizontale et obligeait à faire défiler les onglets de gauche à droite sur téléphone.

## 5.2. Solution appliquée

Sous **768 px** :

- apparition d’un bouton hamburger dans l’en-tête ;
- masquage de la barre horizontale ;
- ouverture d’un menu vertical flottant ;
- chaque rubrique occupe toute la largeur ;
- la rubrique active est identifiable par un fond clair et un trait orange.

## 5.3. Comportements ajoutés

Le menu se ferme :

- après la sélection d’une rubrique ;
- lors d’un clic à l’extérieur ;
- avec la touche `Échap` ;
- lorsque l’écran repasse au-dessus de 768 px.

Le bouton hamburger :

- utilise `aria-expanded` ;
- change son libellé entre « Ouvrir le menu » et « Fermer le menu » ;
- s’anime en croix quand il est ouvert.

## 5.4. Fonctions ajoutées

- `toggleMobileNav(forceOpen)`
- `closeMobileNav()`

La fonction `setView(view)` ferme désormais automatiquement le menu mobile.

---

# 6. Optimisation mobile du moteur de recherche

Sous **620 px** :

- moteur réduit en hauteur ;
- titres et libellés plus petits ;
- filtres `Tous / À vendre / Recherches` répartis sur toute la largeur ;
- grille principale en deux colonnes ;
- champs d’environ 38 px de hauteur ;
- boutons compacts et tactiles ;
- champ mot-clé et compteur placés sur une ligne compacte ;
- cartes de résultats en une colonne.

Sous **370 px** :

- retour à une seule colonne pour éviter tout écrasement.

---

# 7. Responsive global de toutes les pages

Une couche responsive dédiée a été ajoutée sous **768 px** afin de ne pas modifier la version ordinateur.

## 7.1. Base générale

- suppression du débordement horizontal global ;
- `min-width: 0` sur les éléments flexibles ;
- en-tête compact ;
- logo réduit ;
- marges générales réduites ;
- titres de section plus petits ;
- boutons autorisés à revenir à la ligne ;
- tailles tactiles conservées ;
- formulaires adaptés à la largeur réelle du téléphone.

## 7.2. Accueil

- cartes principales plus petites ;
- titres, métadonnées et marges réduits ;
- actions autorisées à revenir à la ligne ;
- marché interne en une colonne ;
- cartes du marché compactes ;
- boutons « Consulter le Hub » en une colonne ;
- CTA et bandeaux réduits.

## 7.3. Annuaire

Sur téléphone :

- grille en deux colonnes ;
- cartes membres réduites ;
- avatars réduits ;
- badges et métiers compactés ;
- liste adaptée avec colonnes simplifiées.

Sous **370 px** :

- grille en une seule colonne.

## 7.4. Événements et masterclass

Corrections importantes :

- cartes fortement réduites ;
- bloc date compact ;
- titres, badges et métadonnées réduits ;
- zone d’actions placée sous la carte ;
- boutons `S’inscrire`, `Inviter`, `Partager` ou équivalents contenus dans la largeur ;
- actions organisées sur deux colonnes ;
- compteur de places centré sous les boutons ;
- aucune action ne doit dépasser horizontalement.

Sous **370 px** :

- boutons d’action en une seule colonne.

Le calendrier a également été compacté :

- marges réduites ;
- commandes plus petites ;
- titre flexible ;
- légende réduite.

## 7.5. Liens utiles

- une seule colonne ;
- cartes réduites ;
- icône réduite ;
- titres, textes et badges compactés ;
- flèche et actions repositionnées ;
- suppression des débordements.

## 7.6. Biens du Hub

- moteur compact ;
- cartes en une colonne ;
- champs et boutons réduits ;
- conservation du fonctionnement dynamique complet.

## 7.7. Profil membre

- carte profil réduite ;
- avatar plus petit ;
- identité et informations compactées ;
- informations en une seule colonne ;
- boutons de partage en une colonne ;
- boutons sociaux contenus dans la largeur.

## 7.8. Paramètres et formulaires

- cartes de paramètres plus petites ;
- formulaires sur une colonne ;
- tableaux autorisés à défiler horizontalement dans leur propre zone ;
- champs, labels et textes adaptés au téléphone.

## 7.9. Module Chasse

- largeur portée à 100 % ;
- sélecteur chasseur/acquéreur compact ;
- bloc d’accueil réduit ;
- cartes de recherches réduites ;
- boutons et grilles ramenés à une colonne ;
- récapitulatif et actions compatibles petit écran.

## 7.10. Écrans Particulier et Investisseur

- en-tête et corps réduits ;
- logo plus petit ;
- texte d’introduction compact ;
- étapes réduites ;
- cartes de choix plus petites ;
- formulaires en une colonne ;
- onglets adaptés.

Sous **370 px** :

- choix en une seule colonne.

## 7.11. Fenêtres modales

- largeur limitée à la largeur du téléphone moins 16 px ;
- hauteur limitée à la hauteur visible ;
- défilement interne ;
- en-tête collant ;
- pied de fenêtre collant ;
- boutons du pied répartis équitablement ;
- fiches membres, contacts et réseaux sociaux compactés.

## 7.12. Footer

- marges réduites ;
- texte plus petit ;
- disposition compatible avec les petits écrans.

---

# 8. Ce qui n’a pas été modifié

Dans ce chantier, il n’y a eu :

- aucune refonte graphique générale ;
- aucun ajout de photos de situation ;
- aucune modification de la charte de fond ;
- aucune modification de Supabase ;
- aucune modification d’Apps Script ;
- aucune modification des connecteurs ;
- aucune modification du Google Sheet ;
- aucun changement Netlify ;
- aucun changement GitHub ;
- aucun déploiement en production ;
- aucune création de serveur local, Node, npm ou autre environnement ;
- aucune refonte du module Chasse.

Les modifications sont limitées à :

- HTML de la vue des biens ;
- CSS du moteur et du responsive ;
- JavaScript de filtrage et de navigation mobile.

---

# 9. Décisions produit prises dans ce fil

Pour la présentation immédiate :

- pas de mode démo spécifique nécessaire ;
- Linda peut présenter Li.La by La Maison d’AVA avec son vrai profil ;
- le Hub Li.La by La Maison d’AVA est, pour le moment, le produit réellement présenté ;
- il sera ensuite intégré dans l’écran Li.La ;
- un profil de démonstration générique nommé **Li.La** pourra être créé plus tard ;
- ce profil démo n’est pas prioritaire pour la présentation immédiate.

Ne pas confondre :

- **Li.La** : l’écrin/app générique ;
- **Li.La by La Maison d’AVA** : le Hub collectif ;
- **Linda** : le profil réel de démonstration et client 0.

---

# 10. Consigne de reprise à donner à Codex

```text
PROJET : Li.La by La Maison d’AVA
MISSION : reporter les améliorations fonctionnelles et responsive décrites dans le document de reprise dans la version graphique la plus récente.

RÈGLES IMPÉRATIVES :
1. Confirmer le projet exact.
2. Confirmer le dossier exact.
3. Confirmer le chemin exact du fichier HTML réellement travaillé.
4. Protéger la version originale avant toute modification.
5. Ne modifier qu’un seul fichier HTML ou les zones explicitement validées.
6. Ne pas refaire l’application.
7. Ne pas modifier le design global sans demande explicite.
8. Ne pas remplacer la nouvelle version graphique par l’ancien HTML de référence.
9. Reporter les comportements, pas l’ancienne apparence.
10. Ne pas explorer ni lancer Node, npm, Nest, serveur local ou autre environnement non demandé.
11. Fournir un plan avant modification.
12. Fournir le diff complet après modification.
13. Tester sur téléphone, tablette et ordinateur.
14. Ne rien déployer sans validation.

SOURCE FONCTIONNELLE DE RÉFÉRENCE :
lila-by-la-maison-d-ava-responsive-mobile.html

FONCTIONNALITÉS À PRÉSERVER :
- moteur dynamique des biens et recherches ;
- menus type et localisation générés depuis les données ;
- prix/budget minimum et maximum ;
- mot-clé libre ;
- compteur de résultats ;
- réinitialisation ;
- filtrage actif uniquement ;
- menu hamburger mobile ;
- fermeture du menu après navigation, clic extérieur et Échap ;
- responsive complet de toutes les vues ;
- aucun débordement des boutons d’événements ou de partage ;
- cartes nettement plus petites sur téléphone ;
- modales utilisables sur petit écran.

INTERDICTIONS :
- ne pas toucher à Apps Script ;
- ne pas toucher à Supabase ;
- ne pas toucher aux clés ou connecteurs ;
- ne pas modifier les données ;
- ne pas modifier le module Chasse hors responsive ;
- ne pas déployer.
```

---

# 11. Tests d’acceptation à refaire après intégration au nouveau design

## Navigation

- [ ] le hamburger apparaît sous 768 px ;
- [ ] aucun défilement horizontal du menu ;
- [ ] le menu s’ouvre et se ferme correctement ;
- [ ] le menu se ferme après un changement de page ;
- [ ] le clic extérieur et Échap ferment le menu.

## Biens du Hub

- [ ] `Tous` affiche biens et demandes actifs ;
- [ ] `À vendre` affiche uniquement les biens actifs ;
- [ ] `Recherches` affiche uniquement les recherches actives ;
- [ ] les menus type et ville sont alimentés par les données ;
- [ ] prix minimum et maximum fonctionnent ;
- [ ] budget minimum et maximum fonctionnent ;
- [ ] le mot-clé fonctionne sans tenir compte des accents ;
- [ ] Réinitialiser vide tous les critères ;
- [ ] le compteur est correct ;
- [ ] aucun résultat affiche un message clair.

## Événements

- [ ] les cartes sont compactes ;
- [ ] les boutons ne dépassent pas ;
- [ ] les actions passent sur deux colonnes puis une colonne sous 370 px ;
- [ ] le calendrier reste utilisable.

## Autres vues

- [ ] annuaire lisible ;
- [ ] liens utiles sans débordement ;
- [ ] profil utilisable ;
- [ ] formulaires utilisables ;
- [ ] module Chasse utilisable ;
- [ ] écrans Particulier et Investisseur utilisables ;
- [ ] modales entièrement accessibles ;
- [ ] aucun défilement horizontal global.

---

## 12. Statut final

| Élément | Statut |
|---|---|
| Moteur de recherche | Fonctionnel dans la référence HTML |
| Menu hamburger | Fonctionnel dans la référence HTML |
| Responsive global | Intégré dans la référence HTML |
| Import réel des CSV | Non effectué |
| Modernisation graphique | Chantier séparé en cours |
| Fusion avec le futur design | À faire avec Codex |
| Déploiement production | Non effectué |
