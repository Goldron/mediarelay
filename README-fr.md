# MediaRelay

Module Dolibarr qui relaie vers une boutique **OpenMage/Magento 1** les
images insérées dans l'éditeur des fiches **produit et service**. Ces
images ne sont pas stockées dans Dolibarr : elles sont envoyées dans la
médiathèque `media/wysiwyg/` de la boutique.

## Pourquoi ce fonctionnement

OpenMage n'a pas d'API (REST ou SOAP) pour de simples images génériques :
tout y est rattaché à un produit. Le seul endroit qui écrit dans
`media/wysiwyg/` est l'écran d'upload d'images du back-office (celui du
WYSIWYG des pages/produits), accessible uniquement avec une vraie session
admin, pas avec un jeton API. Le module se connecte donc à OpenMage avec un
compte administrateur dédié plutôt que par une API classique.

## Fonctionnement

1. Sur la fiche d'un produit ou d'un service, l'éditeur de la description
   gagne un bouton **Image**.
2. Une image envoyée (glisser-déposer, copier-coller, ou via ce bouton)
   part directement sur la boutique OpenMage, pas dans Dolibarr.
3. Le bouton "Parcourir le serveur" permet de réutiliser une image déjà
   envoyée, sans avoir à la renvoyer.

## Configuration

Accueil > Configuration > Modules > MediaRelay :

- **MEDIARELAY_ADMIN_URL** : URL de base de la boutique OpenMage.
- **MEDIARELAY_ADMIN_USER** / **MEDIARELAY_ADMIN_PASSWORD** : identifiants
  d'un compte admin OpenMage **dédié** à cette intégration, jamais le
  compte principal. Le mot de passe est chiffré en base automatiquement
  (mécanisme standard Dolibarr pour les constantes `..._PASSWORD`).
- **MEDIARELAY_ADMIN_FOLDER** : sous-dossier de `media/wysiwyg/` où stocker
  les images (`uploads` par défaut), créé automatiquement s'il n'existe pas
  encore. Laisser vide pour utiliser directement la racine `media/wysiwyg/`.

## Points fragiles / limites connues

- **Compte admin complet, pas un compte API restreint** : contrainte
  d'OpenMage, pas un choix de conception (voir "Pourquoi" ci-dessus).
- **Parsing HTML pour le listing** : la liste des images du bouton
  "Parcourir le serveur" est extraite du HTML admin d'OpenMage par
  expression régulière. Ça cassera si le thème admin OpenMage change.
- **Pas de session réutilisée entre requêtes** : chaque upload ou ouverture
  du "Parcourir le serveur" refait une connexion admin complète.
- **La création du dossier ne vérifie pas le texte des erreurs** : le
  message d'erreur "le dossier existe déjà" d'OpenMage est renvoyé traduit
  (en français sur cette boutique), donc impossible à matcher de façon
  fiable. La création du dossier configuré est faite en best-effort et ne
  bloque jamais l'upload ou le listing.

## Fichiers

| Fichier | Rôle |
|---|---|
| `core/modules/modMediarelay.class.php` | Descripteur du module. |
| `class/actions_mediarelay.class.php` | Ajoute le bouton Image à l'éditeur de la fiche produit/service. |
| `upload.php` | Reçoit les images envoyées et les relaie à OpenMage. |
| `browser.php` | Popup "Parcourir le serveur". |
| `lib/mediarelay.openmageclient.class.php` | Client qui pilote le back-office OpenMage. |
| `admin/setup.php`, `admin/about.php` | Pages de configuration et "À propos". |
