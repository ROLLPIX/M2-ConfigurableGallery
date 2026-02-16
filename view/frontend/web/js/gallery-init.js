/**
 * Rollpix ConfigurableGallery — Gallery Init
 *
 * RequireJS initialization component loaded via x-magento-init in gallery_data.phtml.
 * Stores the gallery config globally for access by the swatch-renderer mixin.
 */
define([], function () {
    'use strict';

    return function (config) {
        if (config && config.galleryConfig) {
            window.rollpixGalleryConfig = config.galleryConfig;
        }
    };
});
