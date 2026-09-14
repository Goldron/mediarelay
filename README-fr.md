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
- **MEDIARELAY_CLOUDFLARE_ENABLED** / **MEDIARELAY_CLOUDFLARE_CLIENT_ID** /
  **MEDIARELAY_CLOUDFLARE_CLIENT_SECRET** : nécessaire uniquement si `/admin/`
  de la boutique est protégé par Cloudflare Access (Zero Trust). Sans jeton
  de service, chaque requête est interceptée par l'écran de connexion SSO de
  Cloudflare avant même d'atteindre OpenMage, et le module ne peut pas du
  tout se connecter. Créer un Service Token dédié dans Zero Trust > Access >
  Service Auth et renseigner son Client Id/Secret ici. Le secret est chiffré
  en base de la même façon que MEDIARELAY_ADMIN_PASSWORD.

Une fois l'URL, l'identifiant et le mot de passe enregistrés, un bouton
**Tester la connexion** apparaît : il se connecte et vérifie l'accès au
dossier de stockage configuré exactement comme le ferait un vrai envoi
d'image (Cloudflare Access compris, si activé), puis affiche le résultat ou
la raison précise de l'échec — pratique pour valider la configuration sans
passer par une fiche produit.

## Corriger l'encodage d'anciennes descriptions

Accueil > Configuration > Modules > MediaRelay > onglet **Corriger
l'encodage** : détecte les descriptions produit/service dont le texte a été
encodé deux fois en UTF-8 (ex : `pensÃ©` au lieu de `pensé`) — typiquement un
reste d'un import depuis un ancien système utilisant un éditeur/encodage
différent. Cet outil n'a aucun rapport avec le fonctionnement normal du
module (qui ne touche jamais au texte de la description, seulement aux
images) : c'est une correction ponctuelle des données existantes. Chaque
ligne détectée s'affiche avec un aperçu avant/après ; rien n'est modifié
avant validation explicite.

## Fichiers

| Fichier | Rôle |
|---|---|
| `core/modules/modMediarelay.class.php` | Descripteur du module. |
| `class/actions_mediarelay.class.php` | Ajoute le bouton Image à l'éditeur de la fiche produit/service. |
| `upload.php` | Reçoit les images envoyées et les relaie à OpenMage. |
| `browser.php` | Popup "Parcourir le serveur". |
| `lib/mediarelay.openmageclient.class.php` | Client qui pilote le back-office OpenMage. |
| `lib/mediarelay.lib.php` | Onglets admin + détection/correction de l'encodage double UTF-8. |
| `admin/setup.php`, `admin/fix_encoding.php`, `admin/about.php` | Pages de configuration, correction d'encodage et "À propos". |

---

<p align="center">
  <a href="https://www.siladel.fr">
    <img src=".github/images/siladel.svg" alt="SILADEL" width="84" height="40">
  </a>
</p>

<p align="center">
  Developed by <a href="https://www.siladel.fr">SILADEL</a> — Author: IGREJA David
</p>
