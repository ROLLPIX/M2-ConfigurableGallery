/**
 * Admin UI component: adds color dropdown to gallery thumbnails and Image Detail dialog.
 *
 * Each thumbnail gets a compact <select> at the top for quick color assignment.
 * The Image Detail dialog also gets a full dropdown after the Role section.
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

        // ── Hidden inputs (fallback for form submission) ─────────────

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

            $.each(existingMapping, function (vid, val) {
                setHiddenInput(vid, val);
            });
        }

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

        // ── Shared: apply a color change ─────────────────────────────

        /**
         * Apply a color selection to a given value_id.
         * Updates imageData, existingMapping, hidden input, and syncs all UI elements.
         */
        function applyColorChange(valueId, newValue) {
            valueId = String(valueId);
            existingMapping[valueId] = newValue;
            setHiddenInput(valueId, newValue);

            // Update imageData on the thumbnail element
            var $imageEl = findImageElement(valueId);

            if ($imageEl && $imageEl.length) {
                var imgData = $imageEl.data('imageData');

                if (imgData) {
                    imgData.associated_attributes = newValue;
                }
            }

            // Sync all dropdowns with this value_id
            $('[data-role="rollpix-thumb-select"][data-value-id="' + valueId + '"]').val(newValue);
            $('[data-role="rollpix-color-select"][data-value-id="' + valueId + '"]').val(newValue);
        }

        /**
         * Find the gallery thumbnail element for a given value_id.
         */
        function findImageElement(valueId) {
            var $found = null;

            $('[data-role="image"], .gallery .image').not('.removed').each(function () {
                var imgData = $(this).data('imageData');

                if (imgData && String(imgData.value_id) === String(valueId)) {
                    $found = $(this);
                    return false;
                }
            });

            return $found;
        }

        // ── Thumbnail compact dropdowns ──────────────────────────────

        /**
         * Build a compact <select> for a thumbnail.
         */
        function buildThumbnailSelect(valueId, currentValue) {
            var $select = $('<select></select>')
                .addClass('rollpix-thumb-select')
                .attr('data-role', 'rollpix-thumb-select')
                .attr('data-value-id', valueId)
                .attr('title', $t('Assign color'));

            colorOptions.forEach(function (option) {
                var attrValue = toAttributeValue(option.value);
                var label = option.value === '' ? '\u2014' : option.label;
                var $opt = $('<option></option>').val(attrValue).text(label);

                if (attrValue === currentValue) {
                    $opt.prop('selected', true);
                }

                $select.append($opt);
            });

            return $select;
        }

        /**
         * Inject a compact dropdown into a single thumbnail element.
         */
        function injectThumbnailSelect($imageEl) {
            if ($imageEl.find('[data-role="rollpix-thumb-select"]').length) {
                return;
            }

            var imgData = $imageEl.data('imageData');

            if (!imgData || !imgData.value_id) {
                return;
            }

            var vid = String(imgData.value_id);
            var currentValue = imgData.associated_attributes || existingMapping[vid] || '';

            var $select = buildThumbnailSelect(vid, currentValue);
            $imageEl.append($select);

            // Stop click propagation so clicking the dropdown doesn't open Image Detail
            $select.on('click', function (e) {
                e.stopPropagation();
            });

            $select.on('change', function (e) {
                e.stopPropagation();
                applyColorChange(vid, $(this).val());
            });
        }

        /**
         * Inject dropdowns on all visible gallery thumbnails.
         */
        function refreshAllThumbnails() {
            $('[data-role="image"], .gallery .image').not('.removed').each(function () {
                injectThumbnailSelect($(this));
            });
        }

        // ── Image Detail dialog injection ────────────────────────────

        /**
         * Build the color dropdown field for the Image Detail dialog.
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
         */
        function injectIntoDialog() {
            var $dialog = $('[data-role="dialog"]').filter(':visible');

            if (!$dialog.length) {
                $dialog = $('.image-panel').filter(':visible');
            }

            if (!$dialog.length) {
                return;
            }

            if ($dialog.find('[data-role="rollpix-color-field"]').length > 0) {
                return;
            }

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

            var currentValue = imageData.associated_attributes || existingMapping[valueId] || '';
            var fieldHtml = buildDialogField(valueId, currentValue);

            var $roleField = $dialog.find('.field-image-role');

            if ($roleField.length) {
                $roleField.after(fieldHtml);
            } else {
                var $sizeField = $dialog.find('[data-role="size"], .field-image-size');

                if ($sizeField.length) {
                    $sizeField.before(fieldHtml);
                } else {
                    $dialog.find('.admin__fieldset, fieldset').first().append(fieldHtml);
                }
            }

            $dialog.find('[data-role="rollpix-color-select"]').on('change', function () {
                applyColorChange($(this).data('value-id'), $(this).val());
            });
        }

        // ── Initialization ───────────────────────────────────────────

        function init() {
            if (initialized) {
                return;
            }

            initialized = true;
            ensureHiddenContainer();

            var dialogCheckTimeout = null;
            var thumbRefreshTimeout = null;

            var observer = new MutationObserver(function () {
                // Check for newly visible dialog
                clearTimeout(dialogCheckTimeout);
                dialogCheckTimeout = setTimeout(function () {
                    var $dialog = $('[data-role="dialog"]').filter(':visible');

                    if ($dialog.length && $dialog.find('[data-role="rollpix-color-field"]').length === 0) {
                        injectIntoDialog();
                    }
                }, 150);

                // Refresh thumbnail dropdowns when DOM changes
                clearTimeout(thumbRefreshTimeout);
                thumbRefreshTimeout = setTimeout(refreshAllThumbnails, 500);
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class', 'style']
            });

            // Initial injection (gallery may render after our script)
            setTimeout(refreshAllThumbnails, 2000);
            setTimeout(refreshAllThumbnails, 5000);

            // Re-check when the Images tab is opened
            $(document).on(
                'click',
                '[data-index="gallery"], [data-tab="image-management"]',
                function () {
                    setTimeout(refreshAllThumbnails, 800);
                }
            );
        }

        $(document).ready(function () {
            init();
        });
    };
});
