# Rollpix_ConfigurableGallery

Magento 2 module for managing galleries on configurable products via color mapping. Images and videos are uploaded once on the configurable parent, tagged to a color option, and the gallery is filtered client-side when a swatch is selected.

**Compatibility:** Magento 2.4.7+ &middot; PHP 8.1 &ndash; 8.4

---

## Problem

In apparel stores a configurable product is typically **color &times; size** (e.g. 8 colors &times; 6 sizes = 48 simple SKUs). The visual variant is color, not size, yet Magento forces image management at the simple-product level. This means:

- The same 8 photos must be loaded 48 times (once per simple).
- Any image change requires editing N simples.
- Out-of-stock colors still show in the gallery.
- CSV import tools don't natively handle the image-to-color mapping.

## Solution

1. **Single upload point** &mdash; images live on the configurable parent.
2. **Explicit color tag** &mdash; each image/video is mapped to an `option_id` via `associated_attributes`.
3. **Optional propagation** &mdash; parent-to-children inheritance for SEO/feeds.
4. **Centralised stock filter** &mdash; colors without stock are hidden or dimmed.
5. **Gallery-agnostic** &mdash; adapters for Fotorama, Rollpix Gallery, Amasty, and Hyva.

---

## Installation

### Via Composer (recommended)

```bash
composer require rollpix/module-configurable-gallery
bin/magento module:enable Rollpix_ConfigurableGallery
bin/magento setup:upgrade
bin/magento cache:flush
```

### Manual

Copy the module to `app/code/Rollpix/ConfigurableGallery/` then run:

```bash
bin/magento module:enable Rollpix_ConfigurableGallery
bin/magento setup:upgrade
bin/magento cache:flush
```

### Hyva compatibility

If using Hyva Theme, also enable the companion sub-module:

```bash
bin/magento module:enable Rollpix_ConfigurableGalleryHyvaCompat
bin/magento setup:upgrade
```

---

## Configuration

**Stores &gt; Configuration &gt; Rollpix &gt; Galeria Configurable**

### General

| Field | Default | Description |
|---|---|---|
| Enable Module | Yes | Global on/off switch |
| Selector Attributes | `color` | Priority-ordered list of swatch attributes used for image mapping. The module uses the first attribute that matches the product's super attributes (variants). |
| Show Generic Images | Yes | Display untagged images alongside the selected color's images |
| Preselect Variant (PDP) | Yes | Automatically select the first in-stock color on the product detail page |
| Preselect Variant (PLP) | Yes | Automatically select the first in-stock color on category listing pages |
| Deep Link by Color | Yes | Allow `#color=318` or `?color=rojo` URL parameters |
| Update URL on Select | Yes | Update the URL hash when a swatch is clicked |

### Stock

| Field | Default | Description |
|---|---|---|
| Filter by Stock | Yes | Hide/dim colors that have no salable simples |
| Out-of-Stock Behavior | Hide | `hide` removes images from the gallery; `dim` adds a CSS class |

### Propagation

| Field | Default | Description |
|---|---|---|
| Propagation Mode | Disabled | `disabled` / `automatic` / `manual` |
| Roles to Propagate | image, small_image, thumbnail | Image roles assigned on propagated images |
| Clean Before Propagate | Yes | Remove previously propagated images before re-propagating |

### Cart / Checkout

| Field | Default | Description |
|---|---|---|
| Cart Image Override | Yes | Show the selected color's image in cart (only when propagation is disabled) |

### Advanced

| Field | Default | Description |
|---|---|---|
| Debug Mode | No | Verbose logging to `var/log/rollpix_gallery.log` |
| Gallery Adapter | Auto | Force a specific adapter (`fotorama` / `rollpix` / `amasty`) or auto-detect |

---

## Usage

### 1. Map images to colors

Edit a configurable product in the admin. In the **Images and Videos** panel each media entry shows a color dropdown. Select the color option each image belongs to, or leave as "All Colors" for generic/brand images.

The module works automatically on **all configurable products** that have at least one matching selector attribute. No per-product toggle is needed.

### 2. Color preselection

When the page loads, the module automatically selects a color and filters the gallery. The priority order is:

1. **URL parameter** &mdash; `#color=318` or `?color=rojo`
2. **First color with stock** &mdash; when stock filter is enabled
3. **First color by position** &mdash; fallback when stock filter is disabled
4. **First visible swatch (DOM)** &mdash; leverages Magento's native stock validation for swatch visibility

When the user deselects a swatch, the gallery shows images from all in-stock colors (respecting the stock filter), not all images.

### 3. Deep linking

Share URLs with a pre-selected color:

```
/product-url#color=318          (by option_id)
/product-url#color=rojo         (by label, case-insensitive)
/product-url?color=318          (query param for campaigns/emails)
```

---

## Gallery Adapters

The module auto-detects the active gallery system and loads the appropriate adapter.

| Priority | Gallery | Swatches | Detected by |
|---|---|---|---|
| 1 | Rollpix ProductGallery | Magento native | `Rollpix_ProductGallery` enabled |
| 2 | Amasty Gallery | Amasty Swatches | `Amasty_Conf` enabled |
| 3 | Fotorama (native) | Magento native | Default fallback |
| 4 | Hyva Gallery | Hyva Swatches | `Hyva_Theme` enabled (via HyvaCompat sub-module) |

Detection can be overridden via **Configuration &gt; Advanced &gt; Gallery Adapter**.

---

## PLP (Product Listing Page)

When propagation is **disabled**, the module activates PLP plugins so that swatch clicks on category pages still show the correct color image.

| Plugin | Purpose | Conditional on |
|---|---|---|
| `SwatchImagePlugin` | Swap thumbnail on swatch click | Always active |
| `HoverSliderCompatPlugin` | Filter HoverSlider images by color | `Rollpix_HoverSlider` installed |
| `ImageFlipCompatPlugin` | Update flip-hover image by color | `Rollpix_ImageFlipHover` installed |

Compatibility plugins check `ModuleManager::isEnabled()` internally and return unmodified results when the target module is absent.

---

## CLI Commands

### Diagnose

```bash
bin/magento rollpix:gallery:diagnose --product-id=123
bin/magento rollpix:gallery:diagnose --all
```

Reports global config, catalog statistics, color mapping status, stock status, and potential issues per product. Scans all configurable products (no per-product opt-in required).

### Propagate

```bash
bin/magento rollpix:gallery:propagate --product-id=123
bin/magento rollpix:gallery:propagate --all
bin/magento rollpix:gallery:propagate --all --dry-run
bin/magento rollpix:gallery:propagate --all --clean-first
```

Copies images from configurable parents to simple children filtered by color. Propagated images are flagged so they are not confused with manually uploaded images.

### Migrate

```bash
bin/magento rollpix:gallery:migrate --mode=diagnose --all
bin/magento rollpix:gallery:migrate --mode=consolidate --all --dry-run
bin/magento rollpix:gallery:migrate --mode=auto-map --all --source=simples
```

| Mode | Description |
|---|---|
| `diagnose` | Report current state without changes |
| `consolidate` | Move unique images from simples to the configurable parent (dedup by MD5) |
| `auto-map` | Auto-assign colors to unmapped images by filename/label pattern matching |

Options: `--product-id`, `--all`, `--dry-run`, `--source` (`mango` / `simples` / `both`).

---

## Architecture

### Two-layer design

```
┌─────────────────────────────────────────────┐
│  Layer 1: Backend (gallery-agnostic)        │
│  - DB column associated_attributes          │
│  - Admin UI (color dropdown per image)      │
│  - AttributeResolver (dynamic attribute     │
│    detection per product)                   │
│  - ColorMapping / ColorPreselect / Config   │
│  - StockFilter (MSI + legacy fallback)      │
│  - Propagation engine                       │
│  - JSON data provider (ViewModel)           │
│  - Cart image override plugin               │
└─────────────────────────────────────────────┘
         ↓ dispatches rollpix:gallery:filter
┌─────────────────────────────────────────────┐
│  Layer 2: Frontend (gallery adapters)       │
│  - gallery-switcher.js (vanilla JS core)    │
│  - adapter/fotorama.js (jQuery)             │
│  - adapter/rollpix-gallery.js (jQuery)      │
│  - adapter/amasty.js (jQuery)               │
│  - swatch-renderer-mixin.js (intercepts     │
│    native swatch events, blocks native      │
│    gallery updates when module is active)   │
│  - HyvaCompat/ (Alpine.js)                  │
└─────────────────────────────────────────────┘
```

### Frontend initialization flow

1. `gallery_data.phtml` outputs `window.rollpixGalleryConfig` (color mapping, stock data, config) and `window.rollpixGalleryImages` (enriched gallery images with `value_id` and `associatedAttributes`).
2. The swatch-renderer mixin creates a `GallerySwitcher` and `FotoramaAdapter`, then calls `init()` which resolves the default color and dispatches the first filter event.
3. `_ensureGalleryFiltered()` uses two strategies to guarantee the filter is applied:
   - **`gallery:loaded` event** &mdash; re-applies the filter after Magento resets Fotorama.
   - **Polling** &mdash; detects Fotorama readiness in case `gallery:loaded` already fired before the mixin loaded (RequireJS timing).
4. Once initialized, the mixin blocks all native gallery updates (`updateBaseImage`, `_processUpdateGallery`) so the module is the sole gallery controller.

### Key conventions

- **Plugins only** &mdash; no `<preference>` overrides.
- **Vanilla JS core** &mdash; jQuery only in gallery-specific adapters.
- **MSI optional** &mdash; nullable constructor params with di.xml `xsi:type="null"` defaults; falls back to legacy `StockRegistryInterface`.
- **Conditional PLP plugins** &mdash; registered always, guarded by `ModuleManager::isEnabled()`.
- **Strict types** &mdash; `declare(strict_types=1)` in every PHP file.
- **Constructor promotion** &mdash; `readonly` promoted properties throughout.
- **No per-product gate** &mdash; the module is active for all configurable products when globally enabled.

### Database

One column added to `catalog_product_entity_media_gallery_value`:

| Column | Type | Format |
|---|---|---|
| `associated_attributes` | TEXT, nullable | `attribute{ID}-{OPTION_ID}` (e.g. `attribute92-318`) |

Two legacy EAV attributes (created by earlier data patches, now hidden from admin via `HideGalleryProductAttributes`):

| Attribute | Status | Notes |
|---|---|---|
| `rollpix_gallery_enabled` | Hidden | No longer used; module is always active for all configurables |
| `rollpix_default_color` | Hidden | No longer used; preselection is automatic based on stock and position |

---

## File Structure

```
Rollpix_ConfigurableGallery/
├── registration.php
├── composer.json
├── etc/
│   ├── module.xml
│   ├── db_schema.xml
│   ├── di.xml
│   ├── config.xml
│   ├── acl.xml
│   ├── adminhtml/
│   │   ├── di.xml
│   │   └── system.xml
│   └── frontend/
│       └── di.xml
├── Setup/Patch/Data/
│   ├── AddGalleryEnabledAttribute.php
│   ├── AddDefaultColorAttribute.php
│   └── HideGalleryProductAttributes.php
├── Model/
│   ├── Config.php
│   ├── AttributeResolver.php
│   ├── ColorMapping.php
│   ├── ColorPreselect.php
│   ├── StockFilter.php
│   ├── Propagation.php
│   └── Config/Source/
│       ├── SwatchAttributes.php
│       ├── OutOfStockBehavior.php
│       ├── PropagationMode.php
│       ├── ImageRoles.php
│       └── GalleryAdapter.php
├── Plugin/
│   ├── AddAssociatedAttributesToGallery.php
│   ├── AdminGallerySavePlugin.php
│   ├── EnrichGalleryJson.php
│   ├── CartItemImagePlugin.php
│   └── Plp/
│       ├── SwatchImagePlugin.php
│       ├── HoverSliderCompatPlugin.php
│       └── ImageFlipCompatPlugin.php
├── Block/Adminhtml/
│   ├── Product/Gallery/
│   │   └── ColorMapping.php
│   └── System/Config/
│       └── ModuleInfo.php
├── ViewModel/
│   ├── GalleryData.php
│   └── PlpGalleryData.php
├── Console/Command/
│   ├── DiagnoseCommand.php
│   ├── PropagateCommand.php
│   └── MigrateCommand.php
├── view/
│   ├── adminhtml/
│   │   ├── layout/catalog_product_edit.xml
│   │   ├── templates/product/gallery/color_mapping.phtml
│   │   └── web/
│   │       ├── js/gallery-color-mapping.js
│   │       └── css/gallery-admin.css
│   └── frontend/
│       ├── requirejs-config.js
│       ├── layout/
│       │   ├── catalog_product_view.xml
│       │   └── catalog_category_view.xml
│       ├── templates/product/
│       │   ├── gallery_data.phtml
│       │   └── plp_gallery_data.phtml
│       └── web/js/
│           ├── gallery-switcher.js
│           ├── gallery-init.js
│           ├── adapter/
│           │   ├── fotorama.js
│           │   ├── rollpix-gallery.js
│           │   └── amasty.js
│           ├── mixin/
│           │   ├── swatch-renderer-mixin.js
│           │   └── amasty-swatch-renderer-mixin.js
│           └── plp/
│               ├── swatch-image.js
│               ├── hoverslider-compat.js
│               └── imageflip-compat.js
├── HyvaCompat/
│   ├── registration.php
│   ├── etc/module.xml
│   └── view/frontend/
│       ├── layout/catalog_product_view.xml
│       └── templates/product/gallery-switcher.phtml
└── i18n/
    ├── en_US.csv
    ├── es_AR.csv
    └── es_ES.csv
```

---

## Compatibility

| Module | Role |
|---|---|
| Magento_Swatches | Base for Fotorama adapter |
| Magento_ConfigurableProduct | Configurable product structure |
| Rollpix_ProductGallery | Dedicated gallery adapter |
| Rollpix_HoverSlider | PLP slider compatibility plugin |
| Rollpix_ImageFlipHover | PLP flip-hover compatibility plugin |
| Amasty_Conf | Amasty Color Swatches Pro adapter |
| Hyva_Theme | Alpine.js adapter (HyvaCompat sub-module) |

When the module is globally disabled, all behaviour falls back to stock Magento with zero overhead.

---

## Changelog

### v1.0.15
- Fix: deselection now respects stock filter (gallery shows only in-stock color images)
- `_isRollpixHandlingGallery` blocks native gallery updates from initialization onward

### v1.0.14
- Fix: robust gallery preselection using polling + `gallery:loaded` event
- New: DOM-based swatch detection leveraging Magento's native stock validation

### v1.0.13
- Fix: stock filter default changed to enabled
- Fix: re-apply preselection after `gallery:loaded` event

### v1.0.12
- Fix: block native `updateBaseImage` / `_processUpdateGallery` when Rollpix filter is active
- Support swatch deselection (toggle off)

### v1.0.11
- Fix: gallery filtering &mdash; inject enriched images into page via `window.rollpixGalleryImages`
- Lazy initialization with retry logic for RequireJS timing

### v1.0.10
- Refactor: remove per-product `rollpix_gallery_enabled` gate &mdash; module active on all configurables
- Replace `preselect_color` with separate PDP/PLP preselection settings
- Hide legacy EAV attributes from admin form

### v1.0.9
- Fix: frontend gallery filtering and stock filter

### v1.0.8
- Fix: color mapping persistence &mdash; inject data via uiRegistry form source

### v1.0.7
- Fix: thumbnail color dropdown opens correctly (stopPropagation fix)

### v1.0.6
- Compact color dropdown on thumbnails, remove badges

### v1.0.5
- Dynamic attribute resolver &mdash; priority-based selector attributes

---

## License

Proprietary &mdash; Rollpix
