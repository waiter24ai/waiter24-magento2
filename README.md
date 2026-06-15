# Waiter24 — Magento 2 module (`Waiter24_Export`)

Exports the Magento 2 catalog to a Waiter24 tenant and embeds the AI chat widget
on the storefront. Unlike Shopify, Magento allows installable PHP modules, so
this mirrors the WordPress/WooCommerce plugin model: an in-store module with an
admin config screen, a cron job, a manual "Export Now" button, and widget
injection.

It uses the same shared import endpoint as the other integrations
(`POST /api/integrations/menu`, Bearer = tenant import token) and the same JSON
schema (see [`examples/menu-import-sample.json`](examples/menu-import-sample.json)).

## How it works

- **Mapping** (`Model/MenuExporter`): Magento categories are hierarchical, so the
  shallowest category → `category` and the next level → `subcategory`. Simple
  products map directly; configurable products expose their children as
  `variations`. `special_price` below `price` becomes `sale_price`. Stock drives
  `is_available` (unless Simple Stock Mode is on).
- **Sync**: a daily cron (`waiter24_export_menu`, 03:00) pushes the full catalog;
  the admin can push anytime with **Export Now**.
- **Widget**: a frontend block injects `widget.js` (with the public widget key)
  before `</body>` on every storefront page when enabled.

## Install

Two options.

### A. Manual (copy files)

1. Copy this repository's contents into `app/code/Waiter24/Export` of your
   Magento root (so `registration.php` is at `app/code/Waiter24/Export/registration.php`).
2. From the Magento root:
   ```bash
   bin/magento module:enable Waiter24_Export
   bin/magento setup:upgrade
   bin/magento setup:di:compile      # production mode only
   bin/magento cache:flush
   ```

### B. Composer (private repo / packaged)

```bash
composer require waiter24/module-export
bin/magento module:enable Waiter24_Export
bin/magento setup:upgrade
```

## Configure (merchant)

**Stores → Configuration → Waiter24 → Menu Export & Chat Widget**

1. **General → Import Token** — paste the secret from your Waiter24 dashboard
   (Site Settings → Automatic menu import).
2. **General → Widget Key** — paste the public widget key (same screen).
3. **Export → Simple Stock Mode** — leave on to always export products as
   available, or off to respect real stock.
4. Press **Export → Export Now** to push the catalog immediately and verify the
   connection. After that the daily cron keeps it in sync.
5. **Chat Widget → Enable Chat Widget = Yes** to show the assistant on the
   storefront.

Multi-store: set a different Import Token per store view to feed several Waiter24
tenants from one Magento instance — the cron exports each configured store.

## Files

```
Waiter24/Export/
  registration.php, composer.json
  etc/module.xml, acl.xml, config.xml, crontab.xml
  etc/adminhtml/system.xml, routes.xml
  Model/Config.php, Model/MenuExporter.php
  Cron/Export.php
  Controller/Adminhtml/Export/Run.php
  Block/Widget.php, Block/Adminhtml/System/Config/RunButton.php
  view/frontend/{layout/default.xml, templates/widget.phtml}
  view/adminhtml/templates/system/config/run_button.phtml
```

## Possible follow-ups

- Observer on `catalog_product_save_after` for near-real-time pushes (debounced),
  matching the Shopify webhook behaviour.
- Richer configurable-product variation attributes (option labels/values).
- Admin-configurable cron frequency (currently fixed daily + manual).
