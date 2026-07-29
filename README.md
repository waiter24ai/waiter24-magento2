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
- **What is exported**: enabled products that are visible individually
  (`VISIBILITY_BOTH | IN_CATALOG | IN_SEARCH`) in the store view being pushed.
  Configurable variants carry "Not Visible Individually", so they are excluded —
  the assistant must not offer a product whose page does not exist.
- **Sync**: **Export Now** pushes on demand, and a daily cron
  (`waiter24_export_menu`, 03:00) pushes on its own **once
  `export/auto_sync` is switched on** — it is off by default, so a store that has
  only just installed the module never exports until someone asks it to. Stores
  configured before 1.2.0 are switched on explicitly by
  `Setup\Patch\Data\EnableAutoSyncForConfiguredStores` (any scope with a token
  saved), so their nightly push survives the upgrade. The catalogue goes out in slices of
  200 products that share one `import_session`, ending with a `final` call —
  neither side holds the whole catalogue, and only that closing call hides what
  the store no longer sells, so an export that dies half way leaves the previous
  menu alone.
- **Widget**: a frontend block injects `widget.js` (with the public widget key)
  before `</body>` on every storefront page when enabled — except in Demo Mode,
  where the tag is rendered only on requests carrying `?waiter24_demo=1`.
- **Cart bridge** (`Controller/Cart`): two storefront endpoints implementing the
  platform-neutral contract the chat widget speaks on every CMS. They act on the
  visitor's own quote via the session cookie, so add-to-cart from chat works the
  same on Luma, Hyvä, or any custom theme:
  - `POST /waiter24/cart/add` — body `{"product_id": "123", "qty": 2}` →
    `{"success": true}`. `product_id` may be a configurable's child (the
    variation picked in chat); the simple product is added directly.
  - `GET /waiter24/cart` — → `{"items": [{"name", "qty", "price"}]}`, read-only,
    feeds the AI's cart-aware upsell.
  Both return 404 while **Enable Chat Widget** is off. The export announces them
  via `site_config` (`platform_preset: magento2`, `ajax_add_url`,
  `cart_read_url`), so the tenant panel is prefilled automatically.

## Requirements

- Magento Open Source / Adobe Commerce **2.4.x**
- PHP **8.1+** (per `composer.json`)
- Cron configured (the daily export and Magento's own indexers rely on it)
- Outbound HTTPS from the store to `https://waiter24.ai`

## Install

Two options.

### A. Manual (copy files)

1. Copy this repository's contents into `app/code/Waiter24/Export` of your
   Magento root (so `registration.php` is at `app/code/Waiter24/Export/registration.php`).
2. From the Magento root:
   ```bash
   bin/magento module:enable Waiter24_Export
   bin/magento setup:upgrade
   bin/magento setup:di:compile                 # production mode only
   bin/magento setup:static-content:deploy -f   # production mode only
   bin/magento cache:flush
   ```

### B. Composer

The package (`waiter24/module-export`, type `magento2-module`) is **not on public
Packagist** — it's a private repo. Register it as a VCS source first, then require
it. From the Magento root:

```bash
composer config repositories.waiter24 vcs git@github.com:waiter24ai/waiter24-magento2.git
composer require waiter24/module-export:dev-main
bin/magento module:enable Waiter24_Export
bin/magento setup:upgrade
```

(Composer drops the module into `app/code/Waiter24/Export` via `magento/composer`
installers — no manual copy needed.)

## Configure (merchant)

**Stores → Configuration → Waiter24 → Menu Export & Chat Widget**

1. **General → Import Token** — paste the secret from your Waiter24 dashboard
   (Site Integration → Menu auto-import).
2. **General → Widget Key** — paste the public widget key (same screen).
3. **Export → Simple Stock Mode** — leave on to always export products as
   available, or off to respect real stock.
4. Press **Export → Export Now** to push the catalog immediately and verify the
   connection. Nothing is exported before this — the module does not push a
   catalog on its own until told to.
5. **Export → Automatic Sync = Yes** once you are happy with the result: the
   daily cron then keeps the catalog in sync without you.
6. **Chat Widget → Enable Chat Widget = Yes** to show the assistant on the
   storefront.
7. *(optional)* **Chat Widget → Demo Mode = Yes** hides the chat from regular
   visitors: the `<script>` tag is not rendered at all unless the URL carries
   `?waiter24_demo=1`, so a normal shopper never loads the widget. Links the
   assistant opens keep the parameter, so the chat survives in-chat navigation;
   a page opened without it has no chat. Useful for showing the assistant to a
   client before going live. Works with FPC/Varnish — the demo URL has its own
   cache entry.

Multi-store: set a different Import Token per store view to feed several Waiter24
tenants from one Magento instance — the cron exports each store that has a token
**and** Automatic Sync on.

> **URLs.** `etc/config.xml` ships production defaults (`https://waiter24.ai/…`)
> for the endpoint and widget URLs. To point a store at another Waiter24 instance
> (e.g. an OSPanel dev host at `http://waiter.loc`), override **General →
> Endpoint URL / Widget URL** per store in the admin rather than editing the
> shipped defaults.

## Files

```
Waiter24/Export/
  registration.php, composer.json
  etc/module.xml, acl.xml, config.xml, crontab.xml
  etc/adminhtml/system.xml, routes.xml
  etc/frontend/routes.xml
  Model/Config.php, Model/MenuExporter.php
  Cron/Export.php
  Setup/Patch/Data/EnableAutoSyncForConfiguredStores.php
  Controller/Adminhtml/Export/Run.php
  Controller/Cart/Add.php, Controller/Cart/Index.php
  Block/Widget.php, Block/Adminhtml/System/Config/RunButton.php
  view/frontend/{layout/default.xml, templates/widget.phtml}
  view/adminhtml/templates/system/config/run_button.phtml
```

## Limitations

- Configurable children added through the bridge appear in the cart as their
  simple product (name/SKU of the child), not as "parent + selected options" —
  functionally identical at checkout, just rendered differently.
- The header mini-cart refresh relies on the widget's after-add snippet
  (customer-data reload on Luma, `reload-customer-section-data` event on Hyvä);
  exotic themes may need their own snippet in Site Integration → DOM selectors → After-add JavaScript.

## Possible follow-ups

- Observer on `catalog_product_save_after` for near-real-time pushes (debounced),
  matching the Shopify webhook behaviour.
- Richer configurable-product variation attributes (option labels/values).
- Admin-configurable cron frequency (currently fixed daily + manual).
