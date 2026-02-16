/**
 * Admin UI component: adds color dropdown to gallery thumbnails and Image Detail dialog.
 *
 * Each thumbnail gets a compact <select> at the top for quick color assignment.
 * The Image Detail dialog also gets a full dropdown after the Role section.
 *
 * Integrates with Magento's productGallery widget:
 * - Reads imageData.associated_attributes for initial value
 * - Writes back to imageData so it flows through media_gallery form submission
 * - Injects rollpix_color_mapping into the UI Component form data source before save
 *
 * PRD §6.1 — Admin UI for color mapping.
 */
define([
    'jquery',
    'mage/translate',
    'uiRegistry'
], function ($, $t, registry) {
    'use strict';

    return function (config) {
        var colorOptions = config.colorOptions || [];
        var existingMapping = config.existingMapping || {};
        var colorAttributeId = config.colorAttributeId || 0;
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

        // ── Form data injection (Magento 2 UI Component form) ────────

        /**
         * Hook into the product form's save to inject rollpix_color_mapping
         * into the data source. Magento 2 admin forms use UI Components —
         * traditional hidden inputs are NOT submitted.
         */
        function hookFormSave() {
            registry.get('product_form.product_form', function (form) {
                var origSave = form.save;

                form.save = function (redirect, data) {
                    this.source.set('data.rollpix_color_mapping', existingMapping);

                    return origSave.call(this, redirect, data);
                };
            });
        }

        // ── Shared: apply a color change ─────────────────────────────

        /**
         * Apply a color selection to a given value_id.
         * Updates imageData, existingMapping, hidden input, and syncs all UI elements.
         */
        function applyColorChange(valueId, newValue) {
            valueId = String(valueId);
            existingMapping[valueId] = newValue;

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
                var label = option.value === '' ? $t('Sin color') : option.label;
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

            // Stop event propagation so the dropdown works inside Magento's sortable gallery
            $select.on('mousedown touchstart click', function (e) {
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
            hookFormSave();

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
