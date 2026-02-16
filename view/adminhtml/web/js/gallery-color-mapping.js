/**
 * Admin UI component: adds color dropdown to the Image Detail dialog panel
 * and color badges on gallery thumbnails.
 *
 * Integrates with Magento's productGallery widget:
 * - Reads imageData.associated_attributes for initial value
 * - Writes back to imageData so it flows through media_gallery form submission
 * - Also maintains hidden inputs as fallback for AdminGallerySavePlugin
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
        var $hiddenContainer = null;
        var initialized = false;

        // Build lookup: attributeValue -> label
        var colorLabelMap = {};

        colorOptions.forEach(function (opt) {
            if (opt.value !== '') {
                var attrVal = 'attribute' + colorAttributeId + '-' + opt.value;
                colorLabelMap[attrVal] = opt.label;
            }
        });

        /**
         * Convert an option_id to the associated_attributes DB format.
         */
        function toAttributeValue(optionValue) {
            if (optionValue === '' || !colorAttributeId) {
                return '';
            }

            return 'attribute' + colorAttributeId + '-' + optionValue;
        }

        /**
         * Get the human-readable color label for an associated_attributes value.
         */
        function getColorLabel(attrValue) {
            if (!attrValue) {
                return '';
            }

            return colorLabelMap[attrValue] || '';
        }

        // ── Hidden inputs (fallback for form submission) ─────────────

        /**
         * Create the hidden input container inside the product form.
         */
        function ensureHiddenContainer() {
            if ($hiddenContainer && $hiddenContainer.closest('body').length) {
                return;
            }

            var $form = $('#product_form, form[data-form="edit-product"], #product-edit-form').first();

            if (!$form.length) {
                $form = $('[data-role="gallery"]').closest('form');
            }

            if (!$form.length) {
                $form = $('body');
            }

            $hiddenContainer = $('<div id="rollpix-color-mapping-inputs" style="display:none;"></div>');
            $form.append($hiddenContainer);

            // Seed with existing mapping from PHP
            $.each(existingMapping, function (vid, val) {
                setHiddenInput(vid, val);
            });
        }

        /**
         * Create or update a hidden input for a specific value_id.
         */
        function setHiddenInput(valueId, value) {
            ensureHiddenContainer();

            var inputId = 'rollpix_cm_' + valueId;
            var $input = $hiddenContainer.find('#' + inputId);

            if (!$input.length) {
                $input = $('<input type="hidden" />')
                    .attr('id', inputId)
                    .attr('name', inputName + '[' + valueId + ']');
                $hiddenContainer.append($input);
            }

            $input.val(value || '');
        }

        // ── Image Detail dialog injection ────────────────────────────

        /**
         * Build the color dropdown field using Magento admin markup classes.
         */
        function buildDialogField(valueId, currentValue) {
            var html = '<div class="admin__field field-rollpix-color" data-role="rollpix-color-field">';

            html += '<label class="admin__field-label" for="rollpix-color-' + valueId + '">';
            html += '<span>' + $t('Assigned Color') + '</span>';
            html += '</label>';
            html += '<div class="admin__field-control">';
            html += '<select class="admin__control-select" ';
            html += 'id="rollpix-color-' + valueId + '" ';
            html += 'data-role="rollpix-color-select" ';
            html += 'data-value-id="' + valueId + '">';

            colorOptions.forEach(function (option) {
                var attrValue = toAttributeValue(option.value);
                var selected = (attrValue === currentValue) ? ' selected="selected"' : '';

                html += '<option value="' + attrValue + '"' + selected + '>';
                html += option.label;
                html += '</option>';
            });

            html += '</select>';
            html += '</div></div>';

            return html;
        }

        /**
         * Inject the color dropdown into the currently visible Image Detail dialog.
         * Called when the slide-panel opens (detected via MutationObserver).
         */
        function injectIntoDialog() {
            var $dialog = $('[data-role="dialog"]').filter(':visible');

            if (!$dialog.length) {
                $dialog = $('.image-panel').filter(':visible');
            }

            if (!$dialog.length) {
                return;
            }

            // Already injected for this dialog instance
            if ($dialog.find('[data-role="rollpix-color-field"]').length > 0) {
                return;
            }

            // Find the active image to get its data
            var $activeImage = $('[data-role="image"].active, .image.active').first();

            if (!$activeImage.length) {
                return;
            }

            var imageData = $activeImage.data('imageData');

            if (!imageData) {
                return;
            }

            var valueId = imageData.value_id;

            if (!valueId) {
                return;
            }

            valueId = String(valueId);

            // Read current value: prefer imageData, fallback to existingMapping
            var currentValue = imageData.associated_attributes || existingMapping[valueId] || '';

            var fieldHtml = buildDialogField(valueId, currentValue);

            // Insert after the Role section
            var $roleField = $dialog.find('.field-image-role');

            if ($roleField.length) {
                $roleField.after(fieldHtml);
            } else {
                // Fallback: insert before Image Size
                var $sizeField = $dialog.find('[data-role="size"], .field-image-size');

                if ($sizeField.length) {
                    $sizeField.before(fieldHtml);
                } else {
                    // Last resort: append to fieldset
                    $dialog.find('.admin__fieldset, fieldset').first().append(fieldHtml);
                }
            }

            // Bind change event on the dropdown
            $dialog.find('[data-role="rollpix-color-select"]').on('change', function () {
                var newValue = $(this).val();
                var vid = String($(this).data('value-id'));

                // Update the imageData object directly (flows through gallery form save)
                imageData.associated_attributes = newValue;

                // Also update our tracking
                existingMapping[vid] = newValue;

                // Hidden input fallback
                setHiddenInput(vid, newValue);

                // Refresh the thumbnail badge
                updateThumbnailBadge($activeImage, newValue);
            });
        }

        // ── Thumbnail badges ─────────────────────────────────────────

        /**
         * Update the color badge on a single gallery thumbnail element.
         */
        function updateThumbnailBadge($imageEl, attrValue) {
            var label = getColorLabel(attrValue);
            var $badge = $imageEl.find('.rollpix-color-badge');

            if (label) {
                if (!$badge.length) {
                    $badge = $('<span class="rollpix-color-badge"></span>');
                    $imageEl.append($badge);
                }

                $badge.text(label).attr('title', label).show();
            } else {
                $badge.remove();
            }
        }

        /**
         * Add/refresh color badges on all gallery thumbnails.
         */
        function refreshAllBadges() {
            $('[data-role="image"], .gallery .image').not('.removed').each(function () {
                var $el = $(this);
                var imgData = $el.data('imageData');

                if (!imgData || !imgData.value_id) {
                    return;
                }

                var vid = String(imgData.value_id);
                var attrValue = imgData.associated_attributes || existingMapping[vid] || '';

                updateThumbnailBadge($el, attrValue);
            });
        }

        // ── Initialization ───────────────────────────────────────────

        function init() {
            if (initialized) {
                return;
            }

            initialized = true;
            ensureHiddenContainer();

            // MutationObserver: detect when the Image Detail dialog appears
            var dialogCheckTimeout = null;
            var badgeRefreshTimeout = null;

            var observer = new MutationObserver(function () {
                // Check for newly visible dialog
                clearTimeout(dialogCheckTimeout);
                dialogCheckTimeout = setTimeout(function () {
                    var $dialog = $('[data-role="dialog"]').filter(':visible');

                    if ($dialog.length && $dialog.find('[data-role="rollpix-color-field"]').length === 0) {
                        injectIntoDialog();
                    }
                }, 150);

                // Refresh badges when thumbnails change
                clearTimeout(badgeRefreshTimeout);
                badgeRefreshTimeout = setTimeout(refreshAllBadges, 500);
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class', 'style']
            });

            // Initial badge refresh (gallery may render after our script)
            setTimeout(refreshAllBadges, 2000);
            setTimeout(refreshAllBadges, 5000);

            // Re-check when the Images tab is opened
            $(document).on(
                'click',
                '[data-index="gallery"], [data-tab="image-management"]',
                function () {
                    setTimeout(refreshAllBadges, 800);
                }
            );
        }

        $(document).ready(function () {
            init();
        });
    };
});
