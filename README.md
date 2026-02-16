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

**Stores &gt; Configuration &gt; Rollpix &gt; Configurable Gallery**

### General

| Field | Default | Description |
|---|---|---|
| Enable Module | Yes | Global on/off switch |
| Color Attribute | `color` | Visual attribute used for mapping (swatch_visual or swatch_text) |
| Show Generic Images | Yes | Display untagged images alongside the selected color's images |
| Preselect Color | Yes | Automatically select a color on page load |
| Deep Link by Color | Yes | Allow `#color=318` or `?color=rojo` URL parameters |
| Update URL on Select | Yes | Update the URL hash when a swatch is clicked |

### Stock

| Field | Default | Description |
|---|---|---|
| Filter by Stock | No | Hide/dim colors that have no salable simples |
| Out-of-Stock Behavior | Hide | `hide` removes images; `dim` adds a CSS class |

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

### 1. Enable per product

Edit a configurable product in the admin and set the **Rollpix Gallery Enabled** attribute to **Yes**.

### 2. Map images to colors

In the product's **Images and Videos** panel each media entry shows a color dropdown. Select the color option each image belongs to, or leave as "All Colors" for generic/brand images.

### 3. Set a default color (optional)

Set the **Rollpix Default Color** attribute to a specific color option. If left empty the module auto-selects the first color with stock.

### Color preselection priority

1. URL parameter (`#color=318` or `?color=rojo`)
2. Manual default (`rollpix_default_color` attribute)
3. First color with stock (when stock filter is enabled)
4. First color by position order

### Deep linking

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

Reports global config, catalog statistics, color mapping status, stock status, and potential issues per product.

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
│  - HyvaCompat/ (Alpine.js)                  │
└─────────────────────────────────────────────┘
```

### Key conventions

- **Plugins only** &mdash; no `<preference>` overrides.
- **Vanilla JS core** &mdash; jQuery only in gallery-specific adapters.
- **MSI optional** &mdash; nullable constructor params with di.xml `xsi:type="null"` defaults; falls back to legacy `StockRegistryInterface`.
- **Conditional PLP plugins** &mdash; registered always, guarded by `ModuleManager::isEnabled()`.
- **Strict types** &mdash; `declare(strict_types=1)` in every PHP file.
- **Constructor promotion** &mdash; `readonly` promoted properties throughout.

### Database

One column added to `catalog_product_entity_media_gallery_value`:

| Column | Type | Format |
|---|---|---|
| `associated_attributes` | TEXT, nullable | `attribute{ID}-{OPTION_ID}` (e.g. `attribute92-318`) |

Two EAV attributes created via data patches:

| Attribute | Type | Scope | Apply to |
|---|---|---|---|
| `rollpix_gallery_enabled` | boolean | Store | configurable |
| `rollpix_default_color` | int (nullable) | Store | configurable |

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
│   └── AddDefaultColorAttribute.php
├── Model/
│   ├── Config.php
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
├── Block/Adminhtml/Product/Gallery/
│   └── ColorMapping.php
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

When a product has `rollpix_gallery_enabled = 0` or the module is globally disabled, all behaviour falls back to stock Magento with zero overhead.

---

## License

Proprietary &mdash; Rollpix
