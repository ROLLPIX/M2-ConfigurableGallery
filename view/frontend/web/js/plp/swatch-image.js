/**
 * Rollpix ConfigurableGallery — PLP Swatch Image (PRD §8.3)
 *
 * Handles swatch click events on PLP (category pages) to update the product
 * thumbnail with the color-mapped image from the configurable parent.
 *
 * Reads rollpixPlpConfig from the page JSON to get color → image mappings.
 * No AJAX — all data is pre-loaded in the page.
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
         * Get the product ID from a swatch element.
         */
        function getProductId($swatch) {
            var $productItem = $swatch.closest('.product-item, [data-product-id]');
            return $productItem.attr('data-product-id')
                || $productItem.find('[data-product-id]').attr('data-product-id')
                || $productItem.find('form[data-product-id]').attr('data-product-id');
        }

        /**
         * Update the product thumbnail image in PLP.
         */
        function updateProductImage(productId, optionId) {
            var productConfig = plpConfig[productId];
            if (!productConfig || !productConfig.colorMapping) {
                return;
            }

            var colorData = productConfig.colorMapping[String(optionId)];
            if (!colorData || !colorData.mainImage) {
                return;
            }

            var $productItem = $('[data-product-id="' + productId + '"]')
                .closest('.product-item');

            if (!$productItem.length) {
                $productItem = $('form[data-product-id="' + productId + '"]')
                    .closest('.product-item');
            }

            if (!$productItem.length) {
                return;
            }

            // Update main product image
            var $img = $productItem.find('.product-image-photo').first();
            if ($img.length) {
                $img.attr('src', colorData.mainImage);
                if ($img.attr('data-src')) {
                    $img.attr('data-src', colorData.mainImage);
                }
            }
        }

        // Listen for swatch clicks on PLP
        $(document).on('click', '.swatch-option', function () {
            var $swatch = $(this);
            var $swatchAttr = $swatch.closest('.swatch-attribute');
            var attributeId = $swatchAttr.attr('data-attribute-id') || $swatchAttr.attr('attribute-id');

            // Find the product
            var productId = getProductId($swatch);
            if (!productId || !plpConfig[productId]) {
                return;
            }

            // Only handle color attribute
            var productConfig = plpConfig[productId];
            if (parseInt(attributeId, 10) !== productConfig.colorAttributeId) {
                return;
            }

            var optionId = $swatch.attr('data-option-id') || $swatch.attr('option-id');
            if (optionId) {
                updateProductImage(productId, optionId);
            }
        });
    };
});
