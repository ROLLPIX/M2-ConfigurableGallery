# CLAUDE.md — Rollpix_ConfigurableGallery

## Documento de Referencia

El PRD completo está en `PRD-Rollpix_ConfigurableGallery.md` en la raíz del proyecto. **Es la fuente de verdad.** Antes de implementar cualquier componente, leé la sección correspondiente del PRD.

## Qué es este módulo

Módulo Magento 2 que resuelve la galería de imágenes y videos de productos configurables. Permite asignar imágenes/videos a colores específicos en el configurable (padre), y que la galería filtre automáticamente al seleccionar un swatch. Compatible con múltiples sistemas de galería mediante adaptadores.

**Nombre:** `Rollpix_ConfigurableGallery`
**Package composer:** `rollpix/module-configurable-gallery`
**Compatibilidad:** PHP 8.1–8.4 / Magento 2.4.7+

## Estándares y mejores prácticas de Magento

Este módulo debe seguir las mejores prácticas oficiales de Magento 2. Ante cualquier duda de implementación, priorizar el approach recomendado por Magento.

### Service Contracts y APIs
- Usar **Service Contracts** (interfaces en `Api/`) para toda lógica de negocio expuesta.
- Definir `Api/Data/` interfaces para DTOs cuando sea necesario.
- Los Models implementan las interfaces, nunca se inyectan directamente en clases externas.
- Usar `Api/` interfaces en los constructores para favorecer desacoplamiento.

### Dependency Injection
- **Toda** dependencia se inyecta por constructor. No usar `ObjectManager::getInstance()` jamás.
- No inyectar `ObjectManager`, `Context`, ni clases concretas cuando existe una interfaz.
- Usar **Factories** (`ModelFactory`) para crear instancias de modelos.
- Usar **Proxies** para dependencias pesadas que no siempre se necesitan (ej: en commands CLI).

### Plugins
- Solo `before`, `after`, `around`. Preferir `after` sobre `around` siempre que sea posible.
- `around` plugins solo cuando se necesita condicionar la ejecución del método original.
- Documentar `sortOrder` y el motivo en cada declaración de plugin.
- No usar plugins sobre métodos privados (no funciona) ni sobre `__construct`.

### Base de datos
- Usar `db_schema.xml` (declarative schema), NO scripts `InstallSchema`/`UpgradeSchema`.
- Generar `db_schema_whitelist.json` con `bin/magento setup:db-declaration:generate-whitelist`.
- Para Data Patches: implementar `DataPatchInterface` + `PatchRevertableInterface` cuando sea posible.
- Queries complejas: usar `ResourceConnection` + `Select` objects del Zend framework. No queries raw SQL.

### Caché
- Implementar `IdentityInterface` en models que se cachean.
- Usar cache tags apropiados (`catalog_product`, etc.) para invalidación.
- Los bloques que se inyectan en layout deben declarar `cacheable="true/false"` según corresponda.
- Respetar FPC: no inyectar datos de sesión/usuario en bloques cacheables. Usar `customer-data` sections para datos dinámicos.

### Frontend (Luma/Blank)
- RequireJS `define()` para módulos.
- Mixins para extender widgets existentes. No reemplazar templates completos.
- `data-mage-init` o `x-magento-init` para inicialización de componentes.
- No incluir assets en `default.xml` si solo se necesitan en páginas específicas (usar `catalog_product_view.xml`, `catalog_category_view.xml`, etc.).

### Frontend (Hyva)
- Alpine.js puro. No jQuery, no RequireJS, no KnockoutJS.
- ViewModels para pasar datos PHP→template.
- Layout handles de Hyva específicos.
- El sub-módulo `HyvaCompat` es un módulo separado con su propio `registration.php` y `module.xml`.

### Logging
- Usar `\Psr\Log\LoggerInterface` inyectado.
- Log level apropiado: `debug()` para desarrollo, `info()` para operaciones normales, `error()` para fallos.
- En modo `debug_mode` del módulo (PRD §9.5), loguear operaciones detalladas.
- Nunca loguear datos sensibles de cliente.

### Compatibilidad PHP 8.1–8.4
- `declare(strict_types=1)` en todos los archivos.
- Type hints completos: parámetros, return types, propiedades.
- Usar features de PHP 8.1+: `readonly` properties, constructor promotion, enums, `match`, `fibers` si aplica, named arguments, intersection types, `never` return type.
- Manejar deprecations de PHP 8.2+: no dynamic properties (usar `#[\AllowDynamicProperties]` solo si es estrictamente necesario), `null` handling explícito.
- NO usar features de PHP 8.4 que no existan en 8.1 (ej: property hooks). Apuntar a que compile en 8.1 como mínimo.
- `null` safety: siempre verificar null antes de operar. PHP 8.1+ es más estricto con tipos. Usar null coalescing (`??`), nullsafe operator (`?->`), y type checks explícitos.

### Compatibilidad Magento 2.4.7+
- Verificar que las clases/métodos interceptados existen en 2.4.7. No usar APIs deprecadas en 2.4.6 y removidas en 2.4.7.
- `db_schema.xml` es obligatorio (no legacy install scripts).
- Tener en cuenta que Magento 2.4.7+ usa Elasticsearch/OpenSearch 8. No afecta directamente a este módulo pero tener en cuenta si se indexan datos.
- CSP (Content Security Policy): si se inyecta JS inline, declarar en `csp_whitelist.xml`. Preferir siempre archivos JS externos.

### Testing
- Unit tests con PHPUnit para Models/Services.
- Integration tests para plugins y flujos completos.
- Los tests deben poder correrse con `bin/magento dev:tests:run unit` y `integration`.
- Mocks para dependencias externas (stock, catalog).

## Arquitectura clave (PRD §3)

Dos capas separadas:

1. **Backend (galería-agnóstico):** Modelo de datos, admin UI, plugins PHP. No sabe ni le importa qué galería usa el frontend.
2. **Frontend (adaptadores):** JS modular. Un core (`gallery-switcher.js`) + adaptadores intercambiables por galería (Fotorama, Rollpix Gallery, Amasty, Hyva).

Esta separación es crítica. El backend NUNCA debe asumir qué galería se usa. El frontend NUNCA debe hacer llamadas AJAX para obtener datos — todo llega vía JSON inyectado en el HTML.

## Restricciones absolutas (PRD §12.2)

- **NO class replacements** (`<preference>`). Solo plugins (`<plugin>`) y mixins JS.
- **NO renderizar galería.** El módulo inyecta datos, no HTML de galería.
- **NO jQuery en el core.** `gallery-switcher.js` debe ser vanilla JS. Solo los adaptadores específicos pueden usar jQuery (Fotorama) o Alpine.js (Hyva).
- **NO romper productos sin el módulo activo.** Si el módulo está deshabilitado globalmente, Magento debe funcionar 100% vanilla.
- **NO requests AJAX** desde el frontend para obtener datos de galería. Todo va en el JSON inicial.

## Base de datos (PRD §5)

**Columna existente** (ya en producción por módulo Mango):
```sql
ALTER TABLE catalog_product_entity_media_gallery_value
ADD COLUMN associated_attributes VARCHAR(255) DEFAULT NULL;
```

**Formato:** `attribute{ID}-{OPTION_ID}` → ej: `attribute92-318` (atributo color, opción Rojo).
Las imágenes/videos sin `associated_attributes` (NULL) son genéricos y se muestran siempre.

**Atributos EAV a crear:**
- `rollpix_gallery_enabled` (boolean, per product) — Flag de activación.
- `rollpix_default_color` (int, nullable, per product) — option_id del color default.

## Módulos de referencia

Estos módulos están disponibles como referencia de código. NO son dependencias del módulo, son ejemplos de cómo interactuar con cada sistema:

| Módulo | Ubicación | Para qué consultarlo |
|---|---|---|
| Mango_Attributeswatches | `reference/mango_module/` | Schema, formato `associated_attributes`, plugin Gallery ResourceModel |
| Amasty_Conf 2.10.x | `reference/amasty_module/` | Cómo Amasty reemplaza swatch-renderer, estructura JS |
| Rollpix_ProductGallery | `reference/M2-ProductGalleryStyle/` | API de la galería propia, cómo cambiar imágenes |
| Rollpix_HoverSlider | `reference/M2-HoverSlider/` | Plugin `afterToHtml` sobre `Image` (sortOrder=10), JSON `all-media` |
| Rollpix_ImageFlipHover | `reference/M2-ImageFlipHover/` | Plugin `afterCreate` en `ImageFactory` (sortOrder=10), plugin `afterToHtml` en `Image` (sortOrder=100) |

**Importante sobre Mango:** Reutilizar el formato de datos y la columna. DESCARTAR todo el frontend (BxSlider, FancyBox, jQuery Zoom) y la lógica invasiva de handlers.

## Fases de implementación (PRD §13)

Implementar en orden. **Cada fase debe ser funcional y testeable** antes de pasar a la siguiente. No avanzar a Fase 2 sin que Fase 1 esté completa.

### Fase 1 — Core Backend + Admin UI

**PRD:** §5, §6.1–6.9, §9, §10 (solo diagnose)

Archivos a crear en orden:
```
1.  etc/module.xml + registration.php + composer.json
2.  etc/db_schema.xml (columna associated_attributes)
3.  Setup/Patch/Data/AddGalleryEnabledAttribute.php
4.  Setup/Patch/Data/AddDefaultColorAttribute.php
5.  Model/Config.php (lee system.xml)
6.  Model/ColorMapping.php (mapea color→imágenes+videos)
7.  Model/ColorPreselect.php (lógica de preselección: manual→stock→posición)
8.  Model/StockFilter.php (filtra colores sin stock)
9.  Plugin/AddAssociatedAttributesToGallery.php (plugin sobre Gallery ResourceModel)
10. Plugin/AdminGallerySavePlugin.php (persistir mapping al guardar producto)
11. Plugin/EnrichGalleryJson.php (inyectar datos en JSON frontend)
12. Plugin/CartItemImagePlugin.php (imagen por color en cart, solo sin propagación)
13. etc/adminhtml/system.xml (14 opciones de config, PRD §9)
14. etc/config.xml (defaults)
15. etc/di.xml (registrar plugins)
16. Block/Adminhtml/Product/Gallery/ColorMapping.php + template
17. ViewModel/GalleryData.php (expone JSON al frontend)
18. Console/Command/DiagnoseCommand.php
```

**Criterios:**
- Dropdown de color funcional en admin por cada imagen y video.
- Auto-detección de color por filename al subir imágenes (JS en `gallery-color-mapping.js`): normaliza filename, matchea contra labels de colores del producto (longest-first para colores compuestos), indicador visual amarillo para auto-detectados.
- Mapping se persiste correctamente en `associated_attributes`.
- JSON de producto contiene `associatedAttributes`, `rollpixGalleryConfig` con `defaultColorOptionId` y mapping de videos.
- Cart muestra imagen del color elegido (sin propagación).
- `bin/magento rollpix:gallery:diagnose` reporta estado del catálogo.

### Fase 2 — Adaptador Fotorama

**PRD:** §7.2–7.4, §6.7, §6.8

```
1.  view/frontend/requirejs-config.js
2.  view/frontend/web/js/gallery-switcher.js (core vanilla JS)
3.  view/frontend/web/js/adapter/fotorama.js
4.  view/frontend/web/js/mixin/swatch-renderer-mixin.js
5.  view/frontend/templates/product/gallery-data.phtml (inyecta JSON)
6.  view/frontend/layout/catalog_product_view.xml
```

**gallery-switcher.js** es el cerebro. Debe:
- Parsear `rollpixGalleryConfig` del JSON de producto.
- Filtrar imágenes y videos por `associatedAttributes` según color seleccionado.
- Mantener imágenes genéricas (null) siempre visibles.
- Aplicar preselección de color al init (PRD §6.7): URL param → manual → primer con stock.
- Manejar deep link: leer `#color=` o `?color=` de URL, y actualizar URL hash al cambiar swatch (PRD §6.8).
- Exponer API para que los adaptadores puedan pedir imágenes filtradas.
- NO usar jQuery. Vanilla JS puro.

**adapter/fotorama.js** se conecta con el swatch-renderer nativo de Magento vía mixin. Cuando el usuario selecciona un swatch de color:
1. Pide a gallery-switcher las imágenes filtradas.
2. Actualiza Fotorama con las nuevas imágenes usando su API nativa.
3. Actualiza URL hash.

**Criterios:**
- Seleccionar swatch → galería muestra solo imágenes+videos de ese color + genéricas.
- Página carga con color preseleccionado (default o URL).
- URL se actualiza al cambiar color → copiar URL y pegarla abre con ese color.
- Producto sin mapping → comportamiento vanilla 100%.
- Producto con `rollpix_gallery_enabled = 0` → comportamiento vanilla 100%.

### Fase 3 — PLP (Product Listing Page)

**PRD:** §8.1–8.9

```
1.  Plugin/Plp/SwatchImagePlugin.php
2.  Plugin/Plp/HoverSliderCompatPlugin.php (sortOrder=20, verifica ModuleManager)
3.  Plugin/Plp/ImageFlipCompatPlugin.php (sortOrder=110, verifica ModuleManager)
4.  ViewModel/PlpGalleryData.php (JSON mapping para PLP)
5.  view/frontend/web/js/plp/swatch-image.js
6.  view/frontend/web/js/plp/hoverslider-compat.js
7.  view/frontend/web/js/plp/imageflip-compat.js
8.  view/frontend/layout/catalog_category_view.xml (inyectar ViewModel PLP)
9.  etc/frontend/di.xml (registrar plugins PLP)
```

**Regla fundamental:** Si `propagation_mode != disabled`, los plugins PLP no hacen nada. Los simples ya tienen imágenes y todo funciona nativo.

**Plugins de compat** (HoverSlider, ImageFlipHover): Siempre se registran en di.xml pero verifican `ModuleManager::isEnabled('Rollpix_HoverSlider')` / `ModuleManager::isEnabled('Rollpix_ImageFlipHover')` antes de ejecutar lógica. Si el módulo no está instalado → return sin modificar.

**SortOrder es crítico:**
- HoverSlider original: sortOrder=10 → nuestro compat: sortOrder=20
- ImageFlipHover original: sortOrder=100 → nuestro compat: sortOrder=110

**Criterios:**
- Swatch en PLP cambia thumbnail al color seleccionado.
- HoverSlider (si activo) muestra solo imágenes del color seleccionado.
- ImageFlipHover (si activo) muestra flip image del color seleccionado.
- Todo sin AJAX — datos en JSON inyectado en página.
- Sin los módulos de compat instalados → no hay errores, no hay overhead.

### Fase 4 — Adaptador Rollpix ProductGallery

**PRD:** §7.5

```
1.  view/frontend/web/js/adapter/rollpix-gallery.js
```

Integración directa con la API JS de `Rollpix_ProductGallery`. Consultar el módulo de referencia para entender cómo actualizar las imágenes programáticamente.

### Fase 5 — Adaptador Amasty

**PRD:** §7.6

```
1.  view/frontend/web/js/adapter/amasty.js
2.  view/frontend/web/js/mixin/amasty-swatch-renderer-mixin.js
```

Amasty **reemplaza** el swatch-renderer nativo. El mixin debe ir sobre `Amasty_Conf/js/swatch-renderer`, NO sobre el nativo de Magento. Consultar el módulo de referencia para entender su estructura.

### Fase 6 — Adaptador Hyva

**PRD:** §7.7

Sub-módulo separado en `HyvaCompat/`:
```
HyvaCompat/
├── registration.php
├── etc/module.xml (sequence: Rollpix_ConfigurableGallery, Hyva_Theme)
├── view/frontend/
│   ├── layout/catalog_product_view.xml
│   └── templates/product/gallery-switcher.phtml (Alpine.js component)
```

No jQuery, no RequireJS. Alpine.js puro. Escucha eventos de swatch de Hyva.

### Fase 7 — Propagación + Migración + Clean

**PRD:** §6.3, §10, §11

```
1.  Model/Propagation.php (propagate + cleanChildren + removeAllImages)
2.  Console/Command/PropagateCommand.php
3.  Console/Command/MigrateCommand.php (3 modos: consolidate, auto-map, diagnose + --clean flag)
4.  Console/Command/CleanCommand.php (eliminar imágenes de simples hijos)
5.  Observer/ProductSaveAfterObserver.php (propagación automática on save)
6.  etc/adminhtml/events.xml (catalog_product_save_after)
```

## Convenciones de código (PRD §15.1)

Además de las mejores prácticas de Magento detalladas arriba, estas son convenciones específicas del módulo:

### PHP
- Namespace: `Rollpix\ConfigurableGallery\...`
- PHPDoc solo cuando agrega info que el type hint no da.
- NO helpers para lógica de negocio. Usar Models/Services inyectados via interfaces.

### JavaScript
- `gallery-switcher.js`: vanilla JS puro, NO jQuery.
- Adaptadores: pueden usar la librería que necesiten (jQuery para Fotorama, Alpine para Hyva).
- `'use strict'` en todos los archivos.
- Usar RequireJS `define()` para módulos (excepto Hyva que usa ESM/inline).
- Eventos de comunicación entre componentes vía `CustomEvent` o el sistema de Magento.

### XML
- No usar `<preference>`, solo `<plugin>`.
- `sortOrder` explícito en todos los plugins, documentando por qué ese valor.

### Naming
- Clases PHP: PascalCase.
- Métodos PHP: camelCase.
- Variables JS: camelCase.
- Constantes: UPPER_SNAKE_CASE.
- Archivos JS: kebab-case (`gallery-switcher.js`).
- Templates: snake_case (`gallery_data.phtml`).

### i18n
- Admin UI en español.
- Archivos de traducción: `en_US.csv`, `es_ES.csv`, `es_AR.csv`.
- Todos los strings del admin y frontend deben pasar por `__()`.

## Estructura final del módulo

```
Rollpix_ConfigurableGallery/
├── registration.php
├── composer.json
├── CLAUDE.md
├── PRD-Rollpix_ConfigurableGallery.md
├── etc/
│   ├── module.xml
│   ├── db_schema.xml
│   ├── di.xml
│   ├── config.xml
│   ├── acl.xml
│   ├── adminhtml/
│   │   ├── di.xml
│   │   ├── system.xml
│   │   └── events.xml
│   └── frontend/
│       └── di.xml
├── Setup/
│   └── Patch/Data/
│       ├── AddGalleryEnabledAttribute.php
│       ├── AddDefaultColorAttribute.php
│       └── HideGalleryProductAttributes.php
├── Model/
│   ├── Config.php
│   ├── ColorMapping.php
│   ├── ColorPreselect.php
│   ├── StockFilter.php
│   ├── Propagation.php
│   └── ResourceModel/Gallery.php
├── Observer/
│   └── ProductSaveAfterObserver.php
├── Plugin/
│   ├── AddAssociatedAttributesToGallery.php
│   ├── EnrichGalleryJson.php
│   ├── AdminGallerySavePlugin.php
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
│   ├── MigrateCommand.php
│   └── CleanCommand.php
├── view/
│   ├── adminhtml/
│   │   ├── layout/
│   │   ├── templates/
│   │   └── web/js/
│   └── frontend/
│       ├── layout/
│       │   ├── catalog_product_view.xml
│       │   └── catalog_category_view.xml
│       ├── requirejs-config.js
│       ├── templates/
│       │   └── product/gallery-data.phtml
│       └── web/
│           ├── css/
│           └── js/
│               ├── gallery-switcher.js
│               ├── adapter/
│               │   ├── fotorama.js
│               │   ├── rollpix-gallery.js
│               │   └── amasty.js
│               ├── plp/
│               │   ├── swatch-image.js
│               │   ├── hoverslider-compat.js
│               │   └── imageflip-compat.js
│               └── mixin/
│                   ├── swatch-renderer-mixin.js
│                   └── amasty-swatch-renderer-mixin.js
├── HyvaCompat/
│   ├── registration.php
│   ├── etc/module.xml
│   └── view/frontend/
│       ├── layout/catalog_product_view.xml
│       └── templates/product/gallery-switcher.phtml
├── i18n/
│   ├── en_US.csv
│   ├── es_ES.csv
│   └── es_AR.csv
└── Test/
    ├── Unit/
    └── Integration/
```

## Dependencias composer.json

```json
{
    "name": "rollpix/module-configurable-gallery",
    "description": "Configurable product gallery with color mapping, video support, and multi-gallery compatibility",
    "type": "magento2-module",
    "require": {
        "php": ">=8.1 <8.5",
        "magento/module-catalog": "*",
        "magento/module-configurable-product": "*",
        "magento/module-swatches": "*",
        "magento/module-eav": "*",
        "magento/module-checkout": "*",
        "magento/module-media-storage": "*"
    },
    "suggest": {
        "rollpix/module-product-gallery-style": "For Rollpix Gallery adapter",
        "rollpix/module-hover-slider": "For PLP slider compatibility",
        "rollpix/module-image-flip-hover": "For PLP image flip compatibility",
        "amasty/module-conf": "For Amasty Color Swatches adapter",
        "hyva-themes/magento2-default-theme": "For Hyva adapter (HyvaCompat sub-module)"
    },
    "autoload": {
        "files": ["registration.php"],
        "psr-4": {
            "Rollpix\\ConfigurableGallery\\": ""
        }
    }
}
```

## Cómo verificar después de cambios

```bash
# Compilación
rm -rf generated/code/* generated/metadata/*
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush

# Verificar que no se rompió nada
bin/magento rollpix:gallery:diagnose

# Frontend
bin/magento cache:clean full_page block_html layout
# Si cambió JS: borrar static y redeployar
rm -rf pub/static/frontend/
bin/magento setup:static-content:deploy -f
```

## Errores comunes a evitar

1. **No usar `$product->load()`** en plugins de listado. Es N+1. Usar datos del collection.
2. **No asumir que jQuery existe** en gallery-switcher.js. Es core compartido.
3. **No usar `<preference>`** para nada. Siempre plugins o mixins.
4. **No olvidar el check de `Config::isEnabled()`** en cada plugin. Si el módulo está globalmente desactivado → return sin modificar.
5. **No olvidar el check de `propagation_mode`** en plugins PLP. Si propagación activa → return sin modificar.
6. **No olvidar verificar `ModuleManager::isEnabled()`** en plugins de compat antes de ejecutar lógica.
7. **No cachear datos de stock** en el JSON de página. El stock cambia y el FPC puede servir datos desactualizados. Usar customer-data sections si es necesario.
8. **No hardcodear attribute_id 92** para color. Siempre leer de config `color_attribute_code`.
9. **Cart/checkout image override**: usar `ItemProductResolver::getFinalProduct()` como hook — es el único punto universal que cubre cart page, mini-cart y checkout. `DefaultItem::getItemData()` solo afecta la sección KO.js del mini-cart.
10. **Plugins sobre clases abstractas** pueden no generar interceptors. Siempre apuntar a clases concretas.
11. **`logger->debug()`** va a `var/log/debug.log` y requiere debug logging habilitado. Usar `->info()` para `system.log` en staging/production.
12. **Productos del quote** (cart items) no tienen atributos EAV cargados. Recargar via `ProductRepository::getById()` si se necesitan atributos.
