/**
 * Rollpix ConfigurableGallery — RequireJS Configuration
 *
 * Registers mixins for:
 *  - Magento_Swatches/js/swatch-renderer (visual/text swatches)
 *  - Amasty_Conf/js/swatch-renderer (Amasty Color Swatches)
 *  - Magento_ConfigurableProduct/js/configurable (native dropdown — opt-in via
 *    rollpix_configurable_gallery/general/dropdown_support)
 *
 * All mixins always load. The dropdown mixin checks
 * window.rollpixGalleryConfig.dropdownSupport at _create() time and bails
 * out immediately when OFF, so it has zero functional impact when disabled.
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
            },
            'Magento_ConfigurableProduct/js/configurable': {
                'Rollpix_ConfigurableGallery/js/mixin/configurable-mixin': true
            }
        }
    }
};
