# MediaRelay

Dolibarr module that relays to an **OpenMage/Magento 1** store the images
inserted in the editor of **product and service** cards. These images are
not stored in Dolibarr: they are sent to the store's `media/wysiwyg/`
media library.

## Why it works this way

OpenMage has no API (REST or SOAP) for plain generic images: everything
there is attached to a product. The only place that writes into
`media/wysiwyg/` is the backend's image upload screen (the one used by the
WYSIWYG editor on pages/products), reachable only with a real admin
session, not an API token. The module therefore connects to OpenMage with
a dedicated administrator account instead of a regular API.

## How it works

1. On a product or service card, the description editor gets an **Image**
   button.
2. An image sent (drag & drop, paste, or via that button) goes straight to
   the OpenMage store, not into Dolibarr.
3. The "Browse server" button lets you reuse an image already sent,
   without having to send it again.

## Setup

Home > Setup > Modules > MediaRelay:

- **MEDIARELAY_ADMIN_URL**: base URL of the OpenMage store.
- **MEDIARELAY_ADMIN_USER** / **MEDIARELAY_ADMIN_PASSWORD**: credentials of
  an OpenMage admin account **dedicated** to this integration, never the
  main account. The password is automatically encrypted at rest (standard
  Dolibarr mechanism for `..._PASSWORD` constants).
- **MEDIARELAY_ADMIN_FOLDER**: subfolder of `media/wysiwyg/` to store images
  in (default `uploads`), created automatically if it doesn't exist yet.
  Leave empty to use the `media/wysiwyg/` root directly.

## Known limitations

- **Full admin account, not a scoped API account**: this is an OpenMage
  constraint, not a design choice (see "Why" above).
- **HTML parsing for the file listing**: the image list in "Browse server"
  is scraped from OpenMage's admin HTML with a regular expression. It will
  break if the OpenMage admin theme changes.
- **No session reuse across requests**: every upload or "Browse server"
  opening redoes a full admin login.
- **Folder creation errors are not checked by message text**: OpenMage's
  "folder already exists" error comes back localized (e.g. in French on
  this store), so it can't be matched reliably. Creating the configured
  folder is treated as best-effort and never blocks the upload/listing.
- **Images could be stripped when reopening the product/service in edit
  mode**, because Dolibarr's description editor runs with CKEditor's
  Advanced Content Filter (ACF) on, and its `extraAllowedContent` list has
  no rule for `<img>`. Fixed in `class/actions_mediarelay.class.php` by
  extending that list and preserving the raw content across the editor
  reinit (`destroy(true)`) — confirmed working. **Do not** "fix" this by
  setting the global constant `FCKEDITOR_ALLOW_ANY_CONTENT` instead — it
  disables ACF for every CKEditor field in Dolibarr, and TCPDF only embeds
  locally-hosted images in invoice/quote PDFs (`core/lib/pdf.lib.php:1559`),
  so a description image hosted on the OpenMage domain can break PDF
  generation for that product. This was tried and reverted for that reason.

## Files

| File | Role |
|---|---|
| `core/modules/modMediarelay.class.php` | Module descriptor. |
| `class/actions_mediarelay.class.php` | Adds the Image button to the product/service card editor. |
| `upload.php` | Receives sent images and relays them to OpenMage. |
| `browser.php` | "Browse server" popup. |
| `lib/mediarelay.openmageclient.class.php` | Client driving the OpenMage backend. |
| `admin/setup.php`, `admin/about.php` | Setup and "About" pages. |
