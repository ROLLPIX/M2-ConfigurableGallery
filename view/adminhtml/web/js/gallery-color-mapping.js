/**
 * Admin UI component: adds color dropdown to each image/video in the product gallery panel.
 * Reads color options from PHP Block and injects <select> elements.
 * On change, updates hidden inputs that will be submitted with the product save form.
 *
 * PRD §6.1 — Admin UI for color mapping.
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    return function (config) {
        var colorOptions = config.colorOptions || [];
        var existingMapping = config.existingMapping || {};
        var colorAttributeId = config.colorAttributeId || 0;
        var inputName = config.inputName || 'rollpix_color_mapping';
        var initialized = false;

        /**
         * Build <select> HTML from color options.
         *
         * @param {string} valueId - Media gallery value_id
         * @param {string} currentValue - Current associated_attributes value
         * @returns {string} Select HTML
         */
        function buildColorSelect(valueId, currentValue) {
            var html = '<div class="rollpix-color-mapping" style="margin-top:5px;">';
            html += '<label style="font-size:11px;color:#666;display:block;margin-bottom:2px;">';
            html += $t('Color asignado:');
            html += '</label>';
            html += '<select name="' + inputName + '[' + valueId + ']" ';
            html += 'class="rollpix-color-select" ';
            html += 'data-value-id="' + valueId + '" ';
            html += 'style="width:100%;max-width:200px;font-size:12px;">';

            colorOptions.forEach(function (option) {
                var optionValue = option.value;
                var selected = '';

                if (optionValue !== '' && colorAttributeId > 0) {
                    optionValue = 'attribute' + colorAttributeId + '-' + option.value;
                }

                if (optionValue === currentValue || (optionValue === '' && currentValue === '')) {
                    selected = ' selected="selected"';
                }

                html += '<option value="' + optionValue + '"' + selected + '>';
                html += option.label;
                html += '</option>';
            });

            html += '</select></div>';

            return html;
        }

        /**
         * Extract value_id from a gallery image record or DOM element.
         */
        function getValueId(element) {
            var $el = $(element);
            var record = $el.closest('.image');
            var valueId = record.find('input[name$="[value_id]"]').val();

            if (!valueId) {
                valueId = record.attr('data-role') ? record.find('[name*="value_id"]').val() : null;
            }

            return valueId;
        }

        /**
         * Inject color dropdowns into the gallery panel.
         */
        function injectDropdowns() {
            var $galleryImages = $('.image.item, [data-role="image"]');

            $galleryImages.each(function () {
                var $image = $(this);

                // Skip if already has a dropdown
                if ($image.find('.rollpix-color-mapping').length > 0) {
                    return;
                }

                var valueId = $image.find('input[name$="[value_id]"]').val();
                if (!valueId) {
                    return;
                }

                var currentValue = existingMapping[valueId] || '';
                var selectHtml = buildColorSelect(valueId, currentValue);
                var $infoBlock = $image.find('.image-fade, .image-info, .cell-label');

                if ($infoBlock.length) {
                    $infoBlock.first().after(selectHtml);
                } else {
                    $image.append(selectHtml);
                }
            });
        }

        /**
         * Initialize: observe DOM changes and inject dropdowns.
         */
        function init() {
            if (initialized) {
                return;
            }
            initialized = true;

            // Initial injection (with delay to ensure gallery is rendered)
            setTimeout(injectDropdowns, 1000);

            // Observe for dynamically added images
            var observer = new MutationObserver(function (mutations) {
                var shouldReInject = false;
                mutations.forEach(function (mutation) {
                    if (mutation.addedNodes.length > 0) {
                        shouldReInject = true;
                    }
                });
                if (shouldReInject) {
                    setTimeout(injectDropdowns, 300);
                }
            });

            var galleryContainer = document.querySelector(
                '[data-role="gallery"], .gallery.ui-sortable, #media_gallery_content'
            );

            if (galleryContainer) {
                observer.observe(galleryContainer, {
                    childList: true,
                    subtree: true
                });
            }

            // Re-inject when images tab is opened
            $(document).on('click', '[data-index="gallery"], [data-tab="image-management"]', function () {
                setTimeout(injectDropdowns, 500);
            });
        }

        // Wait for DOM ready and gallery initialization
        $(document).ready(function () {
            init();
        });
    };
});
