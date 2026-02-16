/**
 * Rollpix ConfigurableGallery — RequireJS Configuration
 *
 * Registers mixins for both the native Magento swatch-renderer (Fotorama adapter)
 * and the Amasty swatch-renderer (Amasty adapter).
 *
 * Both mixins always load but check rollpixGalleryConfig.enabled and
 * galleryAdapter before activating their logic.
 *
 * Adapter detection is handled by ViewModel/GalleryData.php which sets
 * window.rollpixGalleryConfig.galleryAdapter.
 */
var config = {
    config: {
        mixins: {
            'Magento_Swatches/js/swatch-renderer': {
                'Rollpix_ConfigurableGallery/js/mixin/swatch-renderer-mixin': true
            },
            'Amasty_Conf/js/swatch-renderer': {
                'Rollpix_ConfigurableGallery/js/mixin/amasty-swatch-renderer-mixin': true
            }
        }
    }
};
