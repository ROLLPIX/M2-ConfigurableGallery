# PRD: Rollpix_ConfigurableGallery

**Módulo Magento 2 — Gestión de Galería para Productos Configurables**

| Campo | Valor |
|---|---|
| Vendor | Rollpix |
| Módulo | `Rollpix_ConfigurableGallery` |
| Compatibilidad | Magento 2.4.7+, PHP 8.1–8.4 |
| Estructura | Módulo único con adaptadores internos y carga condicional |
| Fecha | 2026-02-15 |
| Versión PRD | 1.0 |

---

## 1. Problema

En tiendas de indumentaria, los productos configurables se arman típicamente por **color × talle** (ej: remera en 8 colores × 6 talles = 48 SKUs simples). La variante **visual** real es el color, no el talle, pero Magento obliga a gestionar imágenes a nivel de cada simple.

**Problemas concretos:**

- Para mostrar 8 colores hay que cargar 48 veces las mismas 8 fotos (una por cada simple).
- Gestión manual insostenible: cualquier cambio de foto implica editar N simples.
- Productos sin stock siguen mostrando sus colores en el configurable (no hay filtro visual por disponibilidad).
- Las herramientas de importación CSV no resuelven el mapping imagen→color de forma nativa.
- El módulo legacy existente (`Mango_Attributeswatches`) usa librerías obsoletas (BxSlider, FancyBox3, jQuery Zoom) y es invasivo.

**Contexto operativo:** Rollpix gestiona ~50-60 sitios Magento 2. El módulo debe funcionar en múltiples escenarios de galería y swatches sin conflictos.

---

## 2. Solución: Estrategia "Fotos en Configurable + Mapping Color"

Las imágenes se cargan **una sola vez en el producto configurable**, cada imagen se tagea con el color al que pertenece, y el módulo se encarga de filtrar la galería al seleccionar un swatch. Opcionalmente propaga imágenes a los simples para SEO/feeds.

**Principios de diseño:**

1. **Punto único de carga** — Las imágenes viven en el configurable.
2. **Tag explícito de color** — Cada imagen se asocia a un option_id de atributo.
3. **Propagación opcional** — Herencia inversa (padre→hijos) para SEO y feeds.
4. **Filtro de stock centralizado** — Los colores sin stock no muestran sus imágenes.
5. **Compatible con CSV** — El formato de mapping es importable/exportable.
6. **Galería-agnóstico** — Funciona con cualquier sistema de galería frontend vía adaptadores.

---

## 3. Arquitectura de Dos Capas

El módulo se separa en dos capas completamente desacopladas:

### 3.1 Capa 1: Backend (galería-agnóstico)

Todo lo que **no depende** de qué galería se usa en frontend:

- Columna `associated_attributes` en tabla de media gallery.
- Admin UI para mapear imagen→color dentro del editor de producto.
- Persistencia del mapping (save/load).
- Propagación de imágenes a simples (opcional).
- Filtro de stock: excluir colores sin disponibilidad.
- API/Data provider que expone el mapping al frontend como JSON.

### 3.2 Capa 2: Frontend (adaptadores por galería)

Un sistema de adaptadores donde cada galería tiene su propia implementación de "cómo filtrar imágenes al seleccionar un color". El módulo detecta automáticamente qué sistema está activo y carga solo el adaptador correspondiente.

---

## 4. Escenarios de Galería a Soportar

| ID | Galería | Swatches | JS Framework | Módulos detectados |
|---|---|---|---|---|
| **A** | Fotorama (nativa Magento) | Magento native | RequireJS + jQuery | Ninguno extra |
| **B** | Rollpix ProductGallery | Magento native | RequireJS + Vanilla JS | `Rollpix_ProductGallery` |
| **C** | Fotorama (nativa) | Amasty Color Swatches Pro | RequireJS + jQuery | `Amasty_Conf` |
| **D** | Amasty Gallery (zoom+fancybox+slick) | Amasty Color Swatches Pro | RequireJS + jQuery | `Amasty_Conf` con galería habilitada |
| **E** | Hyva Gallery | Hyva Swatches | Alpine.js (sin jQuery) | `Hyva_Theme` |

### 4.1 Detalle técnico por escenario

**Escenario A — Fotorama nativa:**
Mixin JS sobre `Magento_Swatches/js/swatch-renderer`. Intercepta selección de swatch, filtra imágenes del JSON de galería usando campo `associatedAttributes` antes de pasarlas a Fotorama vía evento `gallery:loaded`.

**Escenario B — Rollpix ProductGallery:**
Extensión JS que escucha evento de selección de swatch y llama a métodos de la galería Rollpix para filtrar/actualizar imágenes. Control total porque es código propio. Nota: este módulo elimina Fotorama completamente (`remove="true"` sobre `product.info.media.image` y `product.info.media`).

**Escenarios C/D — Amasty Color Swatches Pro:**
Mixin JS sobre `Amasty_Conf/js/swatch-renderer` (NO sobre nativo Magento, porque Amasty lo reemplaza vía `requirejs map`). Intercepta `_AmOnClick` y `_processUpdateGallery` para usar mapping `associated_attributes` del padre en vez de imágenes de los simples. Amasty NO usa DB para mapping: trabaja 100% con imágenes en simples usando mecanismo nativo. Nuestro módulo invierte esta lógica.

**Escenario E — Hyva:**
Componente Alpine.js separado. Sigue convención `vendor/module-hyva-compat`. No requiere jQuery ni RequireJS.

---

## 5. Base de Datos

### 5.1 Modificación de tabla existente

**Tabla:** `catalog_product_entity_media_gallery_value`

**Columna nueva:** `associated_attributes`
- Tipo: `TEXT`, nullable
- Formato: `attribute{ATTRIBUTE_ID}-{OPTION_ID}` (ej: `attribute92-318`)
- Múltiples valores separados por coma: `attribute92-318,attribute92-319`

**Retrocompatibilidad:** Este formato ya existe en producción en 2 clientes que usan el módulo legacy `Mango_Attributeswatches`. Los datos existentes son 100% compatibles.

### 5.2 Setup/Upgrade Schema

```
InstallSchema / db_schema.xml:
  - ALTER TABLE catalog_product_entity_media_gallery_value
    ADD COLUMN associated_attributes TEXT DEFAULT NULL
```

Se debe usar `db_schema.xml` (declarativo) para Magento 2.4.7+.

### 5.3 Flag de activación por producto

**Atributo EAV:** `rollpix_gallery_enabled` (boolean, default: 0)

Permite activación gradual producto por producto. Solo los productos con este flag activo procesan el mapping y filtro de galería. Los demás funcionan con comportamiento estándar de Magento.

---

## 6. Backend — Especificación Funcional

### 6.1 Admin UI — Editor de Producto

Dentro del panel de imágenes del producto configurable en admin, cada imagen muestra un dropdown adicional para seleccionar el color asociado.

**Dropdown de color:**
- Opciones: las opciones del atributo visual del producto configurable (típicamente `color`).
- Opción "Sin asignar" / "Todos los colores" para imágenes genéricas.
- Soporte para multi-select si una imagen aplica a más de un color.
- El atributo visual se detecta automáticamente o se configura en sistema.

**Comportamiento:**
- Al guardar producto, se persiste el mapping en `associated_attributes`.
- Se muestra indicador visual (badge de color) sobre las imágenes ya mapeadas.
- Las imágenes sin color asignado se muestran en todos los swatches como imágenes base/genéricas.

### 6.2 Persistencia

**Plugin sobre Gallery ResourceModel:**
- `afterLoad`: incluye columna `associated_attributes` en queries de lectura.
- `beforeSave`: persiste el mapping al guardar.

Se reutiliza la lógica del módulo Mango que ya hace esto, simplificada.

### 6.3 Propagación a Simples (Opcional)

Modo configurable en admin (`Stores > Configuration`):

| Opción | Comportamiento |
|---|---|
| **Desactivada** | Las imágenes solo viven en el configurable. Los simples no se tocan. |
| **Automática** | Al guardar configurable, propaga imágenes a cada simple según su color. Elimina imágenes anteriores propagadas. |
| **Manual (CLI)** | Comando CLI para propagar bajo demanda: `bin/magento rollpix:gallery:propagate [--product-id=X]` |

La propagación marca las imágenes en los simples con un flag para distinguirlas de imágenes cargadas manualmente (para no borrar imágenes propias del simple al re-propagar).

### 6.4 Filtro de Stock

Cuando está habilitado, el módulo excluye del JSON de galería las imágenes asociadas a colores que no tienen stock disponible en ningún talle.

**Lógica:**
1. Para cada color (option_id), verificar si al menos un simple con ese color tiene `is_salable = true`.
2. Si ningún simple de ese color tiene stock → excluir imágenes de ese color del JSON.
3. Compatible con MSI (Multi Source Inventory).

**Configuración:**
- `Enable stock filter`: sí/no.
- `Behavior for out of stock`: Ocultar imágenes / Mostrar con opacidad reducida (solo CSS class).

### 6.5 JSON Output al Frontend

El módulo enriquece el JSON de galería nativo de Magento con el campo `associatedAttributes` por imagen:

```json
{
  "mediaGallery": [
    {
      "img": "/media/catalog/product/remera-roja-1.jpg",
      "thumb": "/media/catalog/product/cache/.../remera-roja-1.jpg",
      "full": "/media/catalog/product/remera-roja-1.jpg",
      "caption": "Remera Roja - Frente",
      "position": 1,
      "isMain": true,
      "type": "image",
      "videoUrl": null,
      "associatedAttributes": "attribute92-318",
      "associatedColorLabel": "Rojo"
    },
    {
      "img": "/media/catalog/product/remera-azul-1.jpg",
      "associatedAttributes": "attribute92-320",
      "associatedColorLabel": "Azul"
    },
    {
      "img": "/media/catalog/product/logo-marca.jpg",
      "associatedAttributes": null,
      "associatedColorLabel": null
    }
  ],
  "rollpixGalleryConfig": {
    "enabled": true,
    "colorAttributeId": 92,
    "colorAttributeCode": "color",
    "stockFilterEnabled": true,
    "defaultColorOptionId": 318,
    "deepLinkEnabled": true,
    "availableColors": [318, 320, 325],
    "colorMapping": {
      "318": { "label": "Rojo", "images": [0], "videos": [] },
      "320": { "label": "Azul", "images": [1], "videos": [3] },
      "null": { "label": "General", "images": [2], "videos": [] }
    }
  }
}
```

Las imágenes con `associatedAttributes: null` se muestran siempre (imágenes genéricas/de marca).

### 6.6 Video por Color

El mapping de `associated_attributes` también aplica a entradas de tipo video en la media gallery. Magento ya soporta videos (YouTube/Vimeo) como entradas en `catalog_product_entity_media_gallery` con `media_type = 'external-video'`.

**Comportamiento:**
- En el admin, los videos del configurable también muestran el dropdown de color (igual que las imágenes).
- Al seleccionar un swatch de color, el frontend filtra tanto imágenes como videos del color seleccionado.
- Los videos sin color asignado se muestran siempre (videos genéricos, ej: video institucional de la marca).
- El filtro de stock también aplica a videos: si un color no tiene stock, su video se oculta.

**JSON output adicional por entrada de video:**
```json
{
  "type": "video",
  "videoUrl": "https://www.youtube.com/watch?v=xxx",
  "associatedAttributes": "attribute92-318",
  "associatedColorLabel": "Rojo"
}
```

**No requiere cambios de schema:** La columna `associated_attributes` ya vive en `catalog_product_entity_media_gallery_value`, que aplica tanto a imágenes como a videos.

### 6.7 Preselección de Color (Variante Default)

Al ingresar a la ficha de producto, el módulo preselecciona automáticamente un color, mostrando solo sus imágenes y activando el swatch correspondiente.

**Lógica de preselección (en orden de prioridad):**
1. **URL parameter:** Si la URL contiene `#color=318` o `?color=rojo`, preselecciona ese color (ver 6.8 Deep Link).
2. **Manual por producto:** Campo `rollpix_default_color` (option_id) en el configurable. Si está seteado y tiene stock, usa este.
3. **Primer color con stock:** Recorre los colores en orden de posición del atributo y selecciona el primero que tiene al menos un simple con `is_salable = true`.
4. **Primer color (sin filtro stock):** Si el filtro de stock está desactivado, simplemente el primero en orden de posición.

**Atributo EAV:** `rollpix_default_color`
- Tipo: `int` (nullable)
- Scope: store view
- Valor: option_id del color, o null para auto-detección.
- Se muestra en el admin como dropdown con las opciones de color del configurable.

**Implementación frontend:**
- El JSON de `rollpixGalleryConfig` incluye `defaultColorOptionId`.
- Al inicializar, el JS de gallery-switcher dispara la selección del swatch default.
- El adaptador de galería filtra las imágenes del color default al cargar la página (sin esperar interacción del usuario).

### 6.8 Deep Link por Color

URLs que abren el producto con un color pre-seleccionado.

**Formatos soportados:**
```
/remera-basica#color=318           ← Por option_id (más robusto)
/remera-basica#color=rojo          ← Por label (case-insensitive, URL-encoded)
/remera-basica?color=318           ← Query param (para campañas/emails)
```

**Comportamiento:**
- Al cargar la página, el JS detecta el parámetro de color en URL.
- Preselecciona el swatch correspondiente.
- Filtra la galería para ese color.
- Si el color especificado no existe o no tiene stock, cae al fallback de preselección (6.7).

**Implementación:**
- Parsing de URL en `gallery-switcher.js` al inicializar.
- Actualización de URL hash al seleccionar un swatch (sin reload, vía `history.replaceState`).
- Esto permite copiar la URL del browser y compartirla con el color seleccionado.

**Uso para SEO/Marketing:**
- Emails con links directos: "Ver la remera en Azul" → URL con `#color=azul`.
- Ads por color: cada ad apunta al color específico.
- Google puede indexar variantes si se usan `?color=` como canonical variants (configurable).

### 6.9 Imagen en Cart/Checkout por Color

Cuando propagación está **desactivada**, Magento muestra en carrito y checkout la thumbnail genérica del configurable (no la del color elegido). Este feature la reemplaza con la imagen principal del color seleccionado.

**Condición de activación:**
- Solo cuando `propagation_mode = disabled`.
- Si propagación está activa, los simples ya tienen sus imágenes y Magento resuelve esto nativamente.

**Plugin PHP:**
- **Target:** `Magento\ConfigurableProduct\Block\Cart\Item\Renderer\Configurable` o `Magento\Checkout\CustomerData\AbstractItem`.
- **Lógica:** Al renderizar un item del carrito que es un simple hijo de un configurable con `rollpix_gallery_enabled`, buscar el option_id del color de ese simple, y devolver la primera imagen mapeada a ese color en el padre.

**Aplica en:**
- Minicart
- Cart page
- Checkout (review de orden)
- Emails de orden (si es posible interceptar)

---

## 7. Frontend — Adaptadores

### 7.1 Estructura de archivos

```
Rollpix_ConfigurableGallery/
├── registration.php
├── composer.json
├── etc/
│   ├── module.xml
│   ├── db_schema.xml
│   ├── di.xml                                  ← Core DI + detección de adaptador
│   ├── adminhtml/
│   │   ├── di.xml
│   │   ├── system.xml                          ← Configuración admin
│   │   └── routes.xml
│   └── frontend/
│       ├── di.xml                              ← Plugins frontend
│       └── routes.xml
├── Setup/
│   └── Patch/
│       └── Data/
│           ├── AddGalleryEnabledAttribute.php  ← Crea atributo rollpix_gallery_enabled
│           └── AddDefaultColorAttribute.php    ← Crea atributo rollpix_default_color
├── Model/
│   ├── Config.php                              ← Modelo de configuración
│   ├── ColorMapping.php                        ← Lógica de mapping color→imágenes
│   ├── ColorPreselect.php                      ← Lógica de preselección de color
│   ├── StockFilter.php                         ← Filtro de stock por color
│   ├── Propagation.php                         ← Propagación padre→hijos
│   └── ResourceModel/
│       └── Gallery.php                         ← Plugin sobre gallery resource
├── Plugin/
│   ├── AddAssociatedAttributesToGallery.php    ← Plugin ResourceModel gallery
│   ├── EnrichGalleryJson.php                   ← Plugin para inyectar datos en JSON
│   ├── AdminGallerySavePlugin.php              ← Persistir mapping al guardar
│   ├── CartItemImagePlugin.php                 ← Imagen por color en cart/checkout (sin propagación)
│   └── Plp/
│       ├── SwatchImagePlugin.php               ← Cambio de imagen por swatch en PLP
│       ├── HoverSliderCompatPlugin.php         ← Compat HoverSlider (carga condicional)
│       └── ImageFlipCompatPlugin.php           ← Compat ImageFlipHover (carga condicional)
├── Block/
│   └── Adminhtml/
│       └── Product/
│           └── Gallery/
│               └── ColorMapping.php            ← Block para UI admin
├── ViewModel/
│   └── GalleryData.php                         ← ViewModel para templates frontend
├── Console/
│   └── Command/
│       ├── PropagateCommand.php                ← bin/magento rollpix:gallery:propagate
│       └── MigrateCommand.php                  ← bin/magento rollpix:gallery:migrate
├── view/
│   ├── adminhtml/
│   │   ├── layout/
│   │   │   └── catalog_product_edit.xml
│   │   ├── templates/
│   │   │   └── product/gallery/color-mapping.phtml
│   │   ├── web/
│   │   │   ├── js/
│   │   │   │   └── gallery-color-mapping.js    ← Admin UI JS
│   │   │   └── css/
│   │   │       └── gallery-admin.css
│   │   └── ui_component/
│   │       └── (si se usa UI Component para la galería admin)
│   └── frontend/
│       ├── requirejs-config.js                 ← Carga condicional de adaptadores
│       ├── layout/
│       │   └── catalog_product_view.xml
│       ├── templates/
│       │   └── product/
│       │       └── gallery-data.phtml          ← Inyecta JSON config
│       └── web/
│           └── js/
│               ├── gallery-switcher.js                 ← Lógica core compartida
│               ├── adapter/
│               │   ├── fotorama.js                     ← Adaptador Fotorama (nativo)
│               │   ├── rollpix-gallery.js              ← Adaptador Rollpix ProductGallery
│               │   └── amasty.js                       ← Adaptador Amasty Swatches
│               ├── plp/
│               │   ├── swatch-image.js                 ← Cambio imagen swatch en PLP
│               │   ├── hoverslider-compat.js           ← Compat HoverSlider (condicional)
│               │   └── imageflip-compat.js             ← Compat ImageFlipHover (condicional)
│               └── mixin/
│                   ├── swatch-renderer-mixin.js        ← Mixin para swatch nativo
│                   └── amasty-swatch-renderer-mixin.js ← Mixin para swatch Amasty
├── HyvaCompat/                                 ← Sub-módulo Hyva (Alpine.js)
│   ├── registration.php
│   ├── etc/
│   │   └── module.xml                          ← Depende de Rollpix_ConfigurableGallery + Hyva_Theme
│   └── view/
│       └── frontend/
│           ├── layout/
│           │   └── catalog_product_view.xml
│           └── templates/
│               └── product/
│                   └── gallery-switcher.phtml  ← Alpine.js component
├── i18n/
│   ├── en_US.csv
│   └── es_AR.csv
└── Test/
    ├── Unit/
    └── Integration/
```

### 7.2 Detección Automática de Adaptador

En `requirejs-config.js`, carga condicional basada en módulos disponibles:

```
Prioridad de detección:
1. Rollpix_ProductGallery activo → adapter/rollpix-gallery.js
2. Amasty_Conf activo → adapter/amasty.js  
3. Default → adapter/fotorama.js
4. Hyva detectado → HyvaCompat se carga automáticamente (módulo separado)
```

La detección se resuelve vía:
- **PHP:** `\Magento\Framework\Module\Manager::isEnabled()` en un ViewModel/Block que inyecta la config al frontend.
- **JS:** `requirejs-config.js` con maps condicionales generados por un Block PHP.
- **Hyva:** Módulo `HyvaCompat/` con `sequence` dependency en `module.xml`.

### 7.3 Lógica Core Compartida (`gallery-switcher.js`)

Este módulo JS contiene la lógica común que NO depende de la galería:

- Parsear `rollpixGalleryConfig` del JSON de producto.
- Dado un `option_id` seleccionado, calcular qué imágenes mostrar.
- Combinar imágenes del color seleccionado + imágenes genéricas (sin color).
- Respetar el orden de posición.
- Emitir evento custom `rollpix:gallery:filter` con las imágenes filtradas.

Cada adaptador escucha este evento y actualiza su galería específica.

### 7.4 Adaptador Fotorama

**Tipo:** Mixin JS sobre `Magento_Swatches/js/swatch-renderer`.

**Intercepta:** El método que actualiza la galería al seleccionar un swatch.

**Acción:** En vez de usar las imágenes del simple (comportamiento nativo), filtra las imágenes del configurable según `associatedAttributes` + imágenes genéricas, y las pasa a Fotorama.

**Fallback:** Si no hay mapping para un color o el módulo está desactivado, deja pasar el comportamiento nativo.

### 7.5 Adaptador Rollpix ProductGallery

**Tipo:** Extensión JS directa (no mixin, porque la galería es propia).

**Intercepta:** Evento de selección de swatch nativo de Magento.

**Acción:** Llama a los métodos internos de la galería Rollpix (`updateImages()`, `filterByColor()` o similar) para mostrar solo las imágenes del color seleccionado.

**Nota técnica:** Rollpix ProductGallery elimina el container `product.info.media` y usa su propia estructura DOM. El adaptador debe trabajar con la API de la galería Rollpix, no con selectores genéricos.

### 7.6 Adaptador Amasty

**Tipo:** Mixin JS sobre `Amasty_Conf/js/swatch-renderer`.

**Intercepta:** `_AmOnClick` y `_processUpdateGallery`.

**Acción:** Reemplaza la lógica de Amasty que busca imágenes en los simples con la lógica de nuestro mapping desde el configurable. Mantiene la galería de Amasty (zoom, lightbox, carousel) pero cambia la fuente de datos.

**Punto crítico:** Amasty reemplaza completamente `swatch-renderer.js` vía `requirejs map`. Nuestro mixin debe aplicarse sobre la versión de Amasty, no sobre la nativa.

### 7.7 Adaptador Hyva (HyvaCompat)

**Tipo:** Módulo Magento 2 separado dentro del package.

**Framework:** Alpine.js (no jQuery, no RequireJS).

**Implementación:** Componente Alpine que escucha el evento de selección de swatch de Hyva y filtra las imágenes de la galería. Sigue las convenciones de `hyva-themes/magento2-*`.

**Dependencias en module.xml:**
```xml
<sequence>
    <module name="Rollpix_ConfigurableGallery"/>
    <module name="Hyva_Theme"/>
</sequence>
```

---

## 8. Comportamiento en PLP (Product Listing Page)

### 8.1 Problema en PLP

Cuando las imágenes viven solo en el configurable (sin propagación a simples), el PLP presenta tres problemas:

1. **Cambio de imagen por swatch:** Magento nativo, al hacer click en un swatch de color en PLP, busca la imagen del simple correspondiente para actualizar el thumbnail. Si el simple no tiene imágenes, no cambia nada.
2. **HoverSlider (`Rollpix_HoverSlider`):** Carga TODAS las imágenes del configurable en el slider de PLP, mezclando todos los colores. Al seleccionar un swatch, debería filtrar solo las imágenes del color seleccionado.
3. **ImageFlipHover (`Rollpix_ImageFlipHover`):** Muestra una imagen de hover genérica. Al seleccionar un color, debería mostrar como hover la segunda imagen de ESE color específico.

### 8.2 Estrategia: Dual (propagación vs plugin)

**Si propagación está activa:** Los simples ya tienen sus imágenes correctas. No se hace nada especial en PLP. HoverSlider y ImageFlipHover funcionan con las imágenes del simple propagadas.

**Si propagación está desactivada:** Se activa un plugin PLP que intercepta la lógica de imágenes en listados para productos con `rollpix_gallery_enabled = 1`.

La detección es automática: el módulo verifica la configuración de `propagation_mode` y solo activa los plugins de PLP cuando es `disabled`.

### 8.3 Plugin PLP — Cambio de imagen por swatch

**Punto de intercepción:** El JSON de `swatch-renderer` que contiene las imágenes por option_id. En PLP, Magento arma un `jsonConfig` con las imágenes de cada simple. Nuestro plugin reemplaza estas imágenes con las del configurable según el mapping de `associated_attributes`.

**Plugin PHP:**
- **Clase:** `Plugin/Plp/SwatchImagePlugin.php`
- **Target:** `Magento\Swatches\Helper\Data::afterGetProductMediaGallery` o `Magento\ConfigurableProduct\Block\Product\View\Type\Configurable::afterGetJsonConfig` (según escenario PLP).
- **Lógica:** Para productos configurables con `rollpix_gallery_enabled`, en vez de retornar las imágenes del simple, retorna las imágenes del padre filtradas por el option_id del color.

**Resultado esperado:** Al hacer click en un swatch de color en PLP, el thumbnail cambia a la primera imagen mapeada a ese color en el configurable.

### 8.4 Plugin PLP — Compatibilidad con Rollpix_HoverSlider

**Cómo funciona HoverSlider actualmente:**
- Plugin `afterToHtml` sobre `Magento\Catalog\Block\Product\Image`.
- Hace `$product->load()` y obtiene TODAS las `MediaGalleryImages`.
- Inyecta JSON con array de URLs en `<span class="product-images" all-media='[...]'>`.
- El JS frontend construye dots y flechas para navegar entre imágenes.

**Problema:** En un configurable con 8 colores × 3 fotos = 24 imágenes, el slider mostraría las 24 mezcladas.

**Solución — Dos niveles:**

**Nivel 1 (Backend):** Plugin adicional que, cuando `rollpix_gallery_enabled` está activo y propagación desactivada, filtra las imágenes inyectadas por HoverSlider para mostrar solo las del primer color disponible (o las genéricas sin color asignado) como estado inicial.

**Nivel 2 (Frontend JS):** Al seleccionar un swatch en PLP, un evento JS actualiza el JSON de `all-media` con las imágenes del color seleccionado y reconstruye los dots. Esto requiere:
- Inyectar un JSON complementario en el HTML del PLP con el mapping completo `{option_id: [urls]}`.
- Un script JS que escuche el evento de swatch change y actualice el HoverSlider.

**Implementación:**
- `Plugin/Plp/HoverSliderCompatPlugin.php` — Plugin `afterToHtml` sobre `Image` con `sortOrder` posterior al de HoverSlider (sortOrder=20, HoverSlider usa 10). Intercepta el output HTML, parsea el JSON de `all-media`, lo filtra por color, y agrega un data attribute `data-rollpix-color-images='...'` con el mapping completo.
- `view/frontend/web/js/plp/hoverslider-compat.js` — Script que escucha swatch change y actualiza el slider.

### 8.5 Plugin PLP — Compatibilidad con Rollpix_ImageFlipHover

**Cómo funciona ImageFlipHover actualmente:**
- Plugin `afterCreate` sobre `ImageFactory` → agrega `flip_image_url` al ImageBlock.
- Plugin `afterToHtml` sobre `Image` → inyecta HTML con la imagen de hover.
- Busca imagen por rol configurable (ej: `rpx_product_image_on_hover`) o por "segunda imagen de galería".
- `CollectionPlugin` → agrega el atributo del rol al select de la collection.

**Problema:** Para un configurable, la imagen de hover es siempre la misma (segunda imagen de la galería completa, que puede ser de cualquier color). Al seleccionar un swatch de color, el hover no cambia.

**Solución — Dos niveles:**

**Nivel 1 (Backend):** Cuando `rollpix_gallery_enabled` está activo y propagación desactivada, el flip image se calcula como "la segunda imagen del primer color disponible" (o la segunda genérica).

**Nivel 2 (Frontend JS):** Al seleccionar swatch, actualizar el `data-flip-url` y el `src` de la `.flip-image` con la segunda imagen del color seleccionado.

**Implementación:**
- `Plugin/Plp/ImageFlipCompatPlugin.php` — Plugin que se ejecuta después del de ImageFlipHover (`sortOrder` 110, ImageFlipHover usa 100). Recalcula la flip image según el mapping de color.
- `view/frontend/web/js/plp/imageflip-compat.js` — Script que escucha swatch change y actualiza la flip image.

### 8.6 Estructura de archivos PLP (adiciones)

```
Rollpix_ConfigurableGallery/
├── Plugin/
│   ├── Plp/
│   │   ├── SwatchImagePlugin.php          ← Cambio de imagen por swatch en PLP
│   │   ├── HoverSliderCompatPlugin.php    ← Compatibilidad HoverSlider (si módulo activo)
│   │   └── ImageFlipCompatPlugin.php      ← Compatibilidad ImageFlipHover (si módulo activo)
├── view/
│   └── frontend/
│       └── web/
│           └── js/
│               └── plp/
│                   ├── swatch-image.js            ← JS para cambio de imagen en PLP
│                   ├── hoverslider-compat.js      ← JS compat HoverSlider (carga condicional)
│                   └── imageflip-compat.js         ← JS compat ImageFlipHover (carga condicional)
```

### 8.7 Carga condicional de plugins PLP

Los plugins de compatibilidad con HoverSlider e ImageFlipHover se registran siempre en `di.xml` pero verifican internamente con `ModuleManager::isEnabled()` si el módulo target está activo. Si no está instalado, retornan el resultado sin modificar (overhead casi nulo).

```xml
<!-- etc/frontend/di.xml -->

<!-- Siempre activo -->
<type name="Magento\Swatches\Helper\Data">
    <plugin name="rollpix_cg_plp_swatch_image" 
            type="Rollpix\ConfigurableGallery\Plugin\Plp\SwatchImagePlugin"/>
</type>

<!-- Compat HoverSlider: sortOrder > 10 (HoverSlider usa 10) -->
<type name="Magento\Catalog\Block\Product\Image">
    <plugin name="rollpix_cg_plp_hoverslider_compat" 
            type="Rollpix\ConfigurableGallery\Plugin\Plp\HoverSliderCompatPlugin"
            sortOrder="20"/>
</type>

<!-- Compat ImageFlipHover: sortOrder > 100 (ImageFlipHover usa 100) -->
<type name="Magento\Catalog\Block\Product\Image">
    <plugin name="rollpix_cg_plp_imageflip_compat" 
            type="Rollpix\ConfigurableGallery\Plugin\Plp\ImageFlipCompatPlugin"
            sortOrder="110"/>
</type>
```

### 8.8 JSON de mapping para PLP

Para que los scripts JS de PLP puedan filtrar imágenes al cambiar swatch sin requests AJAX, se inyecta un bloque con el mapping completo en el listado:

```json
{
  "rollpixPlpConfig": {
    "123": {
      "colorAttributeId": 92,
      "defaultColorOptionId": 318,
      "colorMapping": {
        "318": {
          "label": "Rojo",
          "mainImage": "/media/catalog/product/cache/.../remera-roja-1.jpg",
          "flipImage": "/media/catalog/product/cache/.../remera-roja-2.jpg",
          "allImages": [
            "/media/catalog/product/cache/.../remera-roja-1.jpg",
            "/media/catalog/product/cache/.../remera-roja-2.jpg",
            "/media/catalog/product/cache/.../remera-roja-3.jpg"
          ]
        },
        "320": {
          "label": "Azul",
          "mainImage": "...",
          "flipImage": "...",
          "allImages": ["..."]
        }
      },
      "genericImages": [
        "/media/catalog/product/cache/.../logo-marca.jpg"
      ]
    }
  }
}
```

Este JSON se inyecta vía Block/ViewModel en el layout de categoría, solo para productos configurables con `rollpix_gallery_enabled = 1` presentes en la página actual.

### 8.9 Consideraciones de performance en PLP

- **Caching:** El JSON de mapping se cachea por producto + store_id.
- **Image resize:** Las URLs usan las mismas dimensiones del resize de PLP (no full-size).
- **Límite:** Si un configurable tiene más de N colores (configurable, default 12), solo incluye los que tienen stock.
- **FPC:** El bloque de mapping debe ser compatible con Full Page Cache. Al ser datos de catálogo (no personalizados por usuario), se pueden cachear normalmente con los tags de cache del producto.

---

## 9. Configuración Admin (`system.xml`)

**Path:** `Stores > Configuration > Rollpix > Configurable Gallery`

### 9.1 General

| Campo | Tipo | Default | Descripción |
|---|---|---|---|
| `enabled` | Yes/No | Yes | Habilitar módulo globalmente |
| `color_attribute_code` | Select | `color` | Atributo visual principal para mapping. Dropdown con atributos tipo `swatch_visual` o `swatch_text` del sistema |
| `show_generic_images` | Yes/No | Yes | Mostrar imágenes sin color asignado junto con las del color seleccionado |
| `preselect_color` | Yes/No | Yes | Preseleccionar un color al cargar la ficha de producto (ver 6.7) |
| `deep_link_enabled` | Yes/No | Yes | Habilitar deep link por color vía URL hash/param (ver 6.8) |
| `update_url_on_select` | Yes/No | Yes | Actualizar URL hash al seleccionar un swatch (permite copiar/compartir URL con color) |

### 9.2 Stock

| Campo | Tipo | Default | Descripción |
|---|---|---|---|
| `stock_filter_enabled` | Yes/No | No | Filtrar galería por disponibilidad de stock |
| `out_of_stock_behavior` | Select | `hide` | `hide`: ocultar imágenes / `dim`: mostrar con clase CSS de opacidad reducida |

### 9.3 Propagación

| Campo | Tipo | Default | Descripción |
|---|---|---|---|
| `propagation_mode` | Select | `disabled` | `disabled` / `automatic` / `manual` |
| `propagation_roles` | Multiselect | `image,small_image,thumbnail` | Qué roles de imagen propagar a los simples |
| `clean_before_propagate` | Yes/No | Yes | Limpiar imágenes propagadas anteriormente antes de re-propagar |

### 9.4 Cart/Checkout

| Campo | Tipo | Default | Descripción |
|---|---|---|---|
| `cart_image_override` | Yes/No | Yes | Mostrar imagen del color seleccionado en cart/checkout (solo sin propagación) |

### 9.5 Avanzado

| Campo | Tipo | Default | Descripción |
|---|---|---|---|
| `debug_mode` | Yes/No | No | Log detallado en `var/log/rollpix_gallery.log` |
| `gallery_adapter` | Select | `auto` | `auto` (detección automática) / `fotorama` / `rollpix` / `amasty` — Override manual del adaptador |

**Total: 14 opciones de configuración** (vs 35+ del módulo Mango original).

---

## 10. Comandos CLI

### 10.1 Propagación

```bash
bin/magento rollpix:gallery:propagate [options]

Opciones:
  --product-id=ID     Propagar solo un producto configurable específico
  --all               Propagar todos los productos con rollpix_gallery_enabled=1
  --dry-run           Mostrar qué haría sin ejecutar cambios
  --clean-first       Limpiar imágenes propagadas antes de re-propagar
```

### 10.2 Migración

```bash
bin/magento rollpix:gallery:migrate [options]

Opciones:
  --mode=MODE         consolidate|auto-map|diagnose
  --product-id=ID     Migrar solo un producto específico
  --all               Migrar todos los configurables
  --dry-run           Solo reporte, sin cambios
  --source=SOURCE     mango|simples|both (desde dónde tomar datos)
```

---

## 11. Migration Toolkit

### 11.1 Escenarios de Migración

**Escenario A — Datos Mango existentes (2 clientes):**
Los datos ya están en `associated_attributes` con el formato correcto. Solo requiere:
- Activar flag `rollpix_gallery_enabled` en los productos.
- Verificar integridad de datos.
- No hay migración de schema (misma columna, mismo formato).

**Escenario B — Fotos solo en simples (clientes Amasty o vanilla):**
1. Detectar imágenes en simples por color.
2. Consolidar imágenes únicas al configurable padre (dedup por hash MD5).
3. Crear mapping `associated_attributes` automáticamente.
4. Proceso en dos fases: consolidar → validar → limpiar (con aprobación).

**Escenario C — Fotos en configurable sin mapping:**
1. Auto-mapping inteligente por filename (si contiene nombre de color).
2. Auto-mapping por label de imagen (si contiene nombre de color).
3. Reporte de imágenes no mapeables para asignación manual.

### 11.2 Comando `diagnose`

Genera reporte por producto configurable:

```
Product: Remera Básica (SKU: REM-001)
  Colores configurados: Rojo(318), Azul(320), Negro(325)
  Imágenes en configurable: 12
    - Con mapping: 9 (3 Rojo, 3 Azul, 3 Negro)
    - Sin mapping: 3 (genéricas)
  Imágenes en simples: 48
    - Únicas (por hash): 12
    - Duplicadas: 36
  Stock status:
    - Rojo: 4/6 talles con stock ✓
    - Azul: 0/6 talles con stock ✗ (se ocultaría con filtro)
    - Negro: 6/6 talles con stock ✓
  Estado: LISTO PARA ACTIVAR
```

---

## 12. Compatibilidad y Restricciones

### 12.1 Módulos con los que DEBE ser compatible

| Módulo | Versión testeada | Notas |
|---|---|---|
| Magento_Swatches | Core 2.4.7+ | Base para adaptador Fotorama |
| Magento_ConfigurableProduct | Core 2.4.7+ | Estructura de configurables |
| Rollpix_ProductGallery | Latest | Galería propia, adaptador dedicado |
| Rollpix_HoverSlider | 1.0.x | Slider de imágenes en PLP. Plugin compat en Plp/ |
| Rollpix_ImageFlipHover | Latest | Imagen hover en PLP. Plugin compat en Plp/ |
| Amasty_Conf | 2.10.x | Reemplaza swatch-renderer completamente |
| Hyva_Theme | 1.3.x+ | Alpine.js, sin jQuery |

### 12.2 Restricciones técnicas

- **NO reemplazar clases completas** — Solo plugins (before/after/around) y mixins JS.
- **NO incluir librerías de galería** — El módulo no renderiza galería, solo provee datos y filtra.
- **NO romper galería nativa** — Si el módulo está desactivado o el producto no tiene flag activo, comportamiento 100% vanilla.
- **NO asumir jQuery** — El core JS debe funcionar en vanilla. Solo los adaptadores específicos pueden usar jQuery si su galería target lo requiere.

### 12.3 Qué se reutiliza del módulo Mango

| Componente Mango | Acción | Motivo |
|---|---|---|
| Columna `associated_attributes` | **Reutilizar** | Datos ya existen en producción |
| Formato `attribute{ID}-{OPTION_ID}` | **Reutilizar** | Retrocompatibilidad |
| Plugin Gallery ResourceModel | **Reutilizar (simplificado)** | Incluir columna en queries |
| Admin dropdown de opciones | **Reutilizar (simplificado)** | UI para mapear colores |
| BxSlider + FancyBox + jQuery Zoom | **Descartar** | Librerías obsoletas |
| Handlers Create/Update completos | **Descartar** | Demasiado invasivos |
| Preference Framework\Config\View | **Descartar** | Override de clase innecesario |
| Configuración de dimensiones (35+ opciones) | **Descartar** | Excesiva, simplificar a 9 |
| Compatibilidad hardcodeada Mageplaza | **Descartar** | No necesaria |

---

## 13. Fases de Desarrollo

### Fase 1 — Core Backend + Admin UI
**Alcance:** Todo lo que no depende de la galería frontend.
- `db_schema.xml` con columna `associated_attributes`
- Data patch para atributos `rollpix_gallery_enabled` y `rollpix_default_color`
- Plugin ResourceModel para leer/escribir columna
- Admin UI: dropdown de color por imagen **y video** en editor de producto
- Modelo de configuración (`system.xml`)
- `ColorMapping` model con soporte para imágenes y videos
- `ColorPreselect` model con lógica de prioridad (manual → primer con stock → primero)
- ViewModel que expone JSON al frontend (incluyendo `defaultColorOptionId`)
- `CartItemImagePlugin` para imagen por color en cart/checkout (sin propagación)
- CLI `rollpix:gallery:diagnose`

**Criterio de aceptación:** Se puede asignar colores a imágenes y videos en admin, se guardan correctamente, el JSON de producto en frontend incluye mapping + default color + videos. En el carrito, el item muestra la imagen del color elegido.

### Fase 2 — Adaptador Fotorama (nativo)
**Alcance:** Módulo JS funcional para Magento vanilla.
- `gallery-switcher.js` con lógica core (filtro imágenes + videos por color)
- `adapter/fotorama.js`
- Mixin sobre `swatch-renderer`
- Filtro de stock en galería
- Preselección de color al cargar página (lógica 6.7)
- Deep link: parsing de URL hash/param + actualización de URL al seleccionar swatch (6.8)
- `requirejs-config.js` base

**Criterio de aceptación:** En un Magento vanilla con Fotorama, al cargar la ficha se preselecciona el color default (o el de la URL). Al seleccionar un swatch, la galería muestra imágenes + videos de ese color + los genéricos. La URL se actualiza con el color seleccionado. Se puede compartir la URL y abre con el color correcto.

### Fase 3 — PLP: Swatch image change + Compat HoverSlider/ImageFlipHover
**Alcance:** Comportamiento en PLP sin propagación activa.
- `Plugin/Plp/SwatchImagePlugin.php` — Cambio de thumbnail al seleccionar swatch
- `Plugin/Plp/HoverSliderCompatPlugin.php` — Filtrar slider por color
- `Plugin/Plp/ImageFlipCompatPlugin.php` — Actualizar flip image por color
- JSON de mapping para PLP (Block/ViewModel)
- Scripts JS: `plp/swatch-image.js`, `plp/hoverslider-compat.js`, `plp/imageflip-compat.js`

**Criterio de aceptación:** En PLP con propagación desactivada: al seleccionar swatch de color, el thumbnail cambia a la imagen de ese color. Si HoverSlider está activo, el slider muestra solo imágenes del color seleccionado. Si ImageFlipHover está activo, la imagen de hover corresponde al color seleccionado. Todo sin requests AJAX adicionales.

### Fase 4 — Adaptador Rollpix ProductGallery
**Alcance:** Adaptador para galería propia.
- `adapter/rollpix-gallery.js`
- Integración con API de Rollpix_ProductGallery
- Testing en sitios que usan esta galería

**Criterio de aceptación:** Mismo comportamiento que Fase 2 pero usando la galería vertical de Rollpix en vez de Fotorama.

### Fase 5 — Adaptador Amasty
**Alcance:** Adaptador para Amasty Color Swatches Pro.
- `adapter/amasty.js`
- Mixin sobre `Amasty_Conf/js/swatch-renderer`
- Testing de compatibilidad con features de Amasty (zoom, lightbox, carousel)

**Criterio de aceptación:** En sitios con Amasty instalado, la galería cambia según color seleccionado usando imágenes del configurable (no de los simples). Zoom, lightbox y carousel de Amasty siguen funcionando.

### Fase 6 — Adaptador Hyva
**Alcance:** Sub-módulo HyvaCompat.
- Alpine.js component
- Layout XML para Hyva
- Template `.phtml` con componente Alpine

**Criterio de aceptación:** En un sitio Hyva, la galería responde a selección de swatches usando el mapping del configurable.

### Fase 7 — Propagación + Migración
**Alcance:** Herramientas CLI y propagación automática.
- CLI `rollpix:gallery:propagate`
- CLI `rollpix:gallery:migrate`
- Modo diagnóstico
- Auto-mapping por filename/label
- Consolidación de simples a configurable con dedup

**Criterio de aceptación:** Un cliente con 100 configurables y fotos en simples puede migrar a la nueva estrategia en un proceso guiado sin pérdida de datos.

---

## 14. Testing

### 14.1 Unit Tests

- `ColorMapping`: dado un product_id y option_id, retorna imágenes y videos correctos.
- `ColorPreselect`: dado un configurable con stock parcial, retorna el color default correcto según prioridad (manual → stock → posición).
- `StockFilter`: dado stock parcial, filtra colores correctamente.
- `Propagation`: simula propagación y verifica resultado.
- `CartItemImagePlugin`: dado un cart item de un simple hijo, retorna la imagen del color correcto del padre.

### 14.2 Integration Tests

- Crear producto configurable con imágenes y mapping → verificar JSON output.
- Crear producto configurable con videos mapeados a color → verificar JSON incluye videos.
- Guardar mapping en admin → verificar persistencia en DB.
- Seleccionar swatch → verificar que galería filtra imágenes Y videos (por adaptador).
- Cargar ficha con `rollpix_default_color` seteado → verificar preselección.
- Cargar ficha con URL `#color=318` → verificar preselección por deep link.
- Cargar ficha sin default ni URL → verificar preselección del primer color con stock.
- Agregar configurable al carrito (sin propagación) → verificar imagen del color en minicart.

### 14.3 Escenarios de compatibilidad

Cada adaptador se testea con:
- Producto con todos los colores con stock.
- Producto con algunos colores sin stock (filtro activo).
- Producto con imágenes genéricas + imágenes de color.
- Producto con videos mapeados a colores + video genérico.
- Producto sin mapping (debe comportarse como vanilla).
- Producto con flag `rollpix_gallery_enabled = 0` (debe comportarse como vanilla).
- Producto con `rollpix_default_color` seteado a un color sin stock → debe caer a fallback.
- Deep link con color inexistente → debe caer a fallback.
- Cart item con imagen de color (sin propagación).

---

## 15. Notas para Claude Code

### 15.1 Convenciones de código

- **PHP:** PSR-12, strict types, type hints en todos los métodos.
- **JS:** RequireJS modules para Luma, ES modules para Hyva.
- **Templates:** Minimal logic en `.phtml`, lógica en ViewModels.
- **Config:** Todo configurable vía `system.xml`, nada hardcodeado.

### 15.2 Orden de implementación sugerido

1. `etc/module.xml` + `registration.php` + `composer.json`
2. `etc/db_schema.xml`
3. `Setup/Patch/Data/AddGalleryEnabledAttribute.php`
4. `Setup/Patch/Data/AddDefaultColorAttribute.php`
5. `Model/Config.php`
6. `Model/ColorMapping.php` (con soporte imágenes + videos)
7. `Model/ColorPreselect.php`
8. `Plugin/AddAssociatedAttributesToGallery.php`
9. `Plugin/AdminGallerySavePlugin.php`
10. `Plugin/CartItemImagePlugin.php`
11. `etc/adminhtml/system.xml`
12. `Block/Adminhtml/Product/Gallery/ColorMapping.php` + template
13. `ViewModel/GalleryData.php`
14. `Plugin/EnrichGalleryJson.php`
15. `view/frontend/web/js/gallery-switcher.js` (con preselect + deep link + video)
16. `view/frontend/web/js/adapter/fotorama.js` + mixin
17. `Plugin/Plp/SwatchImagePlugin.php` + `plp/swatch-image.js`
18. `Plugin/Plp/HoverSliderCompatPlugin.php` + `plp/hoverslider-compat.js`
19. `Plugin/Plp/ImageFlipCompatPlugin.php` + `plp/imageflip-compat.js`
20. Adaptadores restantes en orden de prioridad

### 15.3 Archivos de referencia

Los siguientes módulos fueron analizados y están disponibles como referencia:

- **Mango_Attributeswatches:** Módulo legacy a reemplazar. Reutilizar schema y formato de datos.
- **Amasty_Conf (2.10.x):** Reference para entender cómo Amasty reemplaza swatch-renderer y maneja galería.
- **Rollpix_ProductGallery:** Galería propia (PDP). Reference para API de integración del adaptador.
- **Rollpix_HoverSlider:** Slider de imágenes en PLP. Plugin `afterToHtml` sobre `Image`, inyecta JSON con todas las MediaGalleryImages. sortOrder=10. El plugin de compat debe ejecutarse después (sortOrder=20).
- **Rollpix_ImageFlipHover:** Imagen hover en PLP. Plugin `afterCreate` sobre `ImageFactory` (sortOrder=10) + Plugin `afterToHtml` sobre `Image` (sortOrder=100). Busca flip image por rol configurable o "segunda imagen de galería". El plugin de compat debe ejecutarse después (sortOrder=110).

### 15.4 Dependencias del composer.json

```json
{
    "name": "rollpix/module-configurable-gallery",
    "description": "Configurable product gallery management with color mapping and multi-gallery adapter support",
    "type": "magento2-module",
    "require": {
        "php": "^8.1",
        "magento/framework": "^103.0",
        "magento/module-catalog": "^104.0",
        "magento/module-configurable-product": "^100.4",
        "magento/module-swatches": "^100.4",
        "magento/module-media-storage": "^100.4"
    },
    "suggest": {
        "rollpix/module-product-gallery": "For Rollpix ProductGallery adapter support",
        "amasty/module-conf": "For Amasty Color Swatches Pro adapter support",
        "hyva-themes/magento2-default-theme": "For Hyva theme adapter support"
    },
    "autoload": {
        "files": ["registration.php"],
        "psr-4": {
            "Rollpix\\ConfigurableGallery\\": ""
        }
    }
}
```
