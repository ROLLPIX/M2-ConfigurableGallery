/**
 * Rollpix ConfigurableGallery — PLP HoverSlider Compatibility (PRD §8.4)
 *
 * Listens for swatch changes on PLP and updates the HoverSlider's all-media
 * data with images filtered by the selected color.
 *
 * Reads data-rollpix-color-images attribute injected by HoverSliderCompatPlugin.php.
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
         * Update HoverSlider images for a product after swatch change.
         */
        function updateHoverSlider($productItem, colorImages) {
            var $slider = $productItem.find('.product-images, [all-media]');
            if (!$slider.length) {
                return;
            }

            var newMediaJson = JSON.stringify(colorImages);
            $slider.attr('all-media', newMediaJson);

            // Rebuild dots if the slider JS supports it
            var sliderWidget = $slider.data('rollpixHoverslider')
                || $slider.data('mageHoverslider');

            if (sliderWidget && typeof sliderWidget.rebuild === 'function') {
                sliderWidget.rebuild();
            } else {
                // Manual rebuild: update dots count
                var $dots = $slider.find('.slider-dot, .dot');
                $dots.remove();

                for (var i = 0; i < colorImages.length; i++) {
                    var dotClass = i === 0 ? 'slider-dot active' : 'slider-dot';
                    $slider.append('<span class="' + dotClass + '" data-index="' + i + '"></span>');
                }
            }
        }

        // Listen for swatch clicks on PLP
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

            // Get images from color mapping
            var colorData = productConfig.colorMapping[String(optionId)];
            if (!colorData || !colorData.allImages) {
                return;
            }

            // Combine color images with generic images
            var allImages = colorData.allImages.slice();
            if (productConfig.genericImages) {
                allImages = allImages.concat(productConfig.genericImages);
            }

            updateHoverSlider($productItem, allImages);
        });
    };
});
