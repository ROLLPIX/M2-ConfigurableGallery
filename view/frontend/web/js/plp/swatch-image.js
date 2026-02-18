/**
 * Rollpix ConfigurableGallery — PLP Swatch Image (PRD §8.3)
 *
 * Handles swatch click events on PLP (category pages) to update the product
 * thumbnail with the color-mapped image from the configurable parent.
 *
 * Uses MutationObserver to guard against Magento's native swatch-renderer
 * overwriting our image update. Previous setTimeout approach was unreliable
 * because the timing of Magento's handler is unpredictable.
 *
 * Reads rollpixPlpConfig from the page JSON to get color → image mappings.
 * No AJAX — all data is pre-loaded in the page.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    var LOG = '[RollpixCG PLP]';

    return function (config) {
        var plpConfig = (config && config.rollpixPlpConfig) || {};

        if ($.isEmptyObject(plpConfig)) {
            console.warn(LOG, 'No rollpixPlpConfig data — swatch-image disabled');
            return;
        }

        console.warn(LOG, 'swatch-image loaded,', Object.keys(plpConfig).length, 'products');

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
         * Returns {$img, targetSrc} on success, null on failure.
         */
        function updateProductImage(productId, optionId) {
            var productConfig = plpConfig[productId];
            if (!productConfig || !productConfig.colorMapping) {
                return null;
            }

            var colorData = productConfig.colorMapping[String(optionId)];
            if (!colorData || !colorData.mainImage) {
                return null;
            }

            var $productItem = $('[data-product-id="' + productId + '"]')
                .closest('.product-item');

            if (!$productItem.length) {
                $productItem = $('form[data-product-id="' + productId + '"]')
                    .closest('.product-item');
            }

            if (!$productItem.length) {
                return null;
            }

            var $img = $productItem.find('.product-image-photo').first();
            if ($img.length) {
                $img.attr('src', colorData.mainImage);
                if ($img.attr('data-src')) {
                    $img.attr('data-src', colorData.mainImage);
                }
                return {$img: $img, targetSrc: colorData.mainImage};
            }

            return null;
        }

        // Active MutationObservers per product — disconnect previous on new click
        var activeObservers = {};

        $(document).on('click', '.swatch-option', function () {
            var $swatch = $(this);
            var $swatchAttr = $swatch.closest('.swatch-attribute');
            var attributeId = $swatchAttr.attr('data-attribute-id') || $swatchAttr.attr('attribute-id');

            var productId = getProductId($swatch);
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

            // Disconnect any previous observer for this product
            if (activeObservers[productId]) {
                activeObservers[productId].disconnect();
                delete activeObservers[productId];
            }

            // Apply immediately
            var result = updateProductImage(productId, optionId);

            if (result) {
                // Guard with MutationObserver: if Magento's swatch-renderer
                // overwrites the src, re-apply ours (up to 3 times).
                var targetSrc = result.targetSrc;
                var $img = result.$img;
                var reapplyCount = 0;

                var observer = new MutationObserver(function () {
                    var currentSrc = $img.attr('src');

                    if (currentSrc !== targetSrc && reapplyCount < 3) {
                        reapplyCount++;
                        $img.attr('src', targetSrc);
                        $img.attr('data-src', targetSrc);
                    }
                });

                observer.observe($img[0], {attributes: true, attributeFilter: ['src']});
                activeObservers[productId] = observer;

                // Auto-cleanup after 2 seconds
                setTimeout(function () {
                    observer.disconnect();
                    delete activeObservers[productId];
                }, 2000);
            }
        });
    };
});
