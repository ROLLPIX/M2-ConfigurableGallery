/**
 * Rollpix ConfigurableGallery — PLP ImageFlipHover Compatibility (PRD §8.5)
 *
 * Listens for swatch changes on PLP and updates the flip image
 * (hover image) with the second image of the selected color.
 *
 * Reads data-rollpix-flip-images attribute injected by ImageFlipCompatPlugin.php.
 * No AJAX — all data is pre-loaded.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    return function (config) {
        var plpConfig = (config && config.rollpixPlpConfig) || {};

        if ($.isEmptyObject(plpConfig)) {
            return;
        }

        /**
         * Update flip image for a product after swatch change.
         */
        function updateFlipImage($productItem, flipImageUrl) {
            if (!flipImageUrl) {
                return;
            }

            // Update flip image src
            var $flipImage = $productItem.find('.flip-image, [data-flip-url]');

            $flipImage.each(function () {
                var $el = $(this);
                if ($el.is('img')) {
                    $el.attr('src', flipImageUrl);
                }
                $el.attr('data-flip-url', flipImageUrl);
            });

            // Also update data attribute on container if present
            var $container = $productItem.find('[data-rollpix-flip-images]');
            if ($container.length) {
                // Already has the full mapping — JS can use it for future changes
            }
        }

        // Listen for swatch clicks on PLP.
        // Deferred with setTimeout so our update runs AFTER Magento's native
        // swatch-renderer handler, which would otherwise overwrite our changes.
        $(document).on('click', '.swatch-option', function () {
            var $swatch = $(this);
            var $swatchAttr = $swatch.closest('.swatch-attribute');
            var attributeId = $swatchAttr.attr('data-attribute-id') || $swatchAttr.attr('attribute-id');
            var $productItem = $swatch.closest('.product-item');
            var productId = $productItem.find('[data-product-id]').attr('data-product-id')
                || $productItem.attr('data-product-id');

            if (!productId || !plpConfig[productId]) {
                return;
            }

            var productConfig = plpConfig[productId];
            if (parseInt(attributeId, 10) !== productConfig.colorAttributeId) {
                return;
            }

            var optionId = $swatch.attr('data-option-id') || $swatch.attr('option-id');
            if (!optionId) {
                return;
            }

            // Get flip image from color mapping
            var colorData = productConfig.colorMapping[String(optionId)];
            if (!colorData) {
                return;
            }

            setTimeout(function () {
                updateFlipImage($productItem, colorData.flipImage);
            }, 100);
        });
    };
});
