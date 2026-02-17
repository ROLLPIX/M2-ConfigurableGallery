/**
 * Rollpix ConfigurableGallery — Rollpix ProductGallery Adapter (PRD §7.5)
 *
 * DOM-based integration with Rollpix_ProductGallery.
 * Unlike Fotorama (which has a load(data) API), Rollpix ProductGallery
 * renders images server-side and its JS modules (slider, thumbnails, zoom)
 * use closure-private state. This adapter therefore works by showing/hiding
 * DOM elements using a CSS class.
 *
 * Index-based filtering: window.rollpixGalleryImages[i] corresponds 1:1
 * to the i-th .rp-gallery-item in the DOM (both come from the same
 * $product->getMediaGalleryImages() collection). The adapter uses
 * colorOptionId + colorMapping to compute which indices are visible,
 * then toggles a CSS hidden class on the DOM items.
 *
 * Matching strategies (in priority order):
 *   1. Direct colorMapping — uses colorOptionId + value_ids from config
 *   2. Reference equality — GallerySwitcher returns same JS object refs
 *   3. value_id matching — compares value_id properties
 * Safety: never hides ALL items.
 *
 * Layout support:
 *   - vertical/grid/fashion: show/hide items + thumbnails via CSS class
 *   - slider: additionally manages dots, arrows, and active slide state
 *
 * Native fallback (swapImages):
 *   When no color-media mappings exist (images on child products only),
 *   swaps DOM image sources directly using simple product images from
 *   jsonConfig.images[productId]. Provides gallery updates without
 *   requiring Fotorama or associated_attributes.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    var HIDDEN_CLASS = 'rp-cg-hidden';
    var LOG_PREFIX = '[RollpixCG Adapter]';

    /**
     * @param {Object} gallerySwitcher - GallerySwitcher instance
     */
    function RollpixGalleryAdapter(gallerySwitcher) {
        this.switcher = gallerySwitcher;
        this.$gallery = null;
        this._isSlider = false;
        this._readyRetries = 0;
        this._originalState = null;
        this._swapRetries = 0;
        this._injectStyles();
        this._bindEvents();
    }

    RollpixGalleryAdapter.prototype = {
        /**
         * Inject minimal CSS for the hidden class.
         * Uses !important to override inline styles set by the slider JS.
         */
        _injectStyles: function () {
            if (document.getElementById('rp-cg-filter-styles')) {
                return;
            }
            var style = document.createElement('style');
            style.id = 'rp-cg-filter-styles';
            style.textContent = '.' + HIDDEN_CLASS + ' { display: none !important; }';
            document.head.appendChild(style);
        },

        /**
         * Listen for gallery filter events dispatched by GallerySwitcher.
         */
        _bindEvents: function () {
            var self = this;
            document.addEventListener('rollpix:gallery:filter', function (event) {
                var detail = event.detail || {};
                self._updateGallery(detail.images, detail.isInitial, detail.colorOptionId);
            });
        },

        /**
         * Update the Rollpix Product Gallery to show only filtered images.
         *
         * Uses three matching strategies in priority order:
         *
         * 1. Direct colorMapping: reads colorOptionId + config.colorMapping to
         *    determine allowed value_ids, then checks each gallery image's value_id.
         *    Most robust — doesn't depend on reference equality or filtered array.
         *
         * 2. Reference matching: GallerySwitcher returns same JS objects from
         *    window.rollpixGalleryImages, so === comparison finds indices.
         *
         * 3. value_id matching: compares value_id between filtered and full arrays.
         *
         * Safety: if no strategy finds visible items, aborts to prevent
         * hiding the entire gallery.
         *
         * @param {Array} images - Filtered gallery images
         * @param {boolean} isInitial - Whether this is the initial page load
         * @param {number|null} colorOptionId - Selected color option ID
         */
        _updateGallery: function (images, isInitial, colorOptionId) {
            if (!images) {
                return;
            }

            var $gallery = this._getGallery();
            if (!$gallery) {
                if (this._readyRetries < 20) {
                    this._readyRetries++;
                    var self = this;
                    var retryImages = images;
                    var retryIsInitial = isInitial;
                    var retryColorId = colorOptionId;
                    setTimeout(function () {
                        self._updateGallery(retryImages, retryIsInitial, retryColorId);
                    }, 200);
                } else {
                    console.warn(LOG_PREFIX, 'Gallery DOM not found after max retries');
                }
                return;
            }
            this._readyRetries = 0;

            var allImages = window.rollpixGalleryImages || [];
            var config = window.rollpixGalleryConfig || {};
            var $items = $gallery.find('.rp-gallery-item');

            // Diagnostic logging
            console.warn(LOG_PREFIX, 'Filter:', {
                colorOptionId: colorOptionId,
                filteredCount: images.length,
                galleryImagesCount: allImages.length,
                domItemsCount: $items.length,
                adapter: config.galleryAdapter
            });

            if (!allImages.length) {
                console.warn(LOG_PREFIX, 'window.rollpixGalleryImages is empty — cannot filter');
                return;
            }

            // --- Strategy 1: Direct colorMapping filtering ---
            // Uses colorOptionId + config to independently compute visible indices.
            // This bypasses any reference/value_id matching issues.
            var visibleSet = this._computeVisibleFromConfig(allImages, colorOptionId, config);
            var matchCount = this._countKeys(visibleSet);

            if (matchCount > 0) {
                console.warn(LOG_PREFIX, 'Strategy 1 (colorMapping):', matchCount, 'matches');
            }

            // --- Strategy 2: Reference matching (fallback) ---
            if (matchCount === 0 && images.length > 0) {
                visibleSet = {};
                for (var i = 0; i < images.length; i++) {
                    for (var j = 0; j < allImages.length; j++) {
                        if (images[i] === allImages[j]) {
                            visibleSet[j] = true;
                            break;
                        }
                    }
                }
                matchCount = this._countKeys(visibleSet);
                if (matchCount > 0) {
                    console.warn(LOG_PREFIX, 'Strategy 2 (reference):', matchCount, 'matches');
                }
            }

            // --- Strategy 3: value_id matching (last resort) ---
            if (matchCount === 0 && images.length > 0) {
                visibleSet = {};
                var visibleValueIds = {};
                for (var vi = 0; vi < images.length; vi++) {
                    var vid = images[vi].value_id || images[vi].valueId;
                    if (vid !== undefined && vid !== null) {
                        visibleValueIds[String(vid)] = true;
                    }
                }
                for (var ai = 0; ai < allImages.length; ai++) {
                    var itemVid = allImages[ai].value_id || allImages[ai].valueId;
                    if (itemVid !== undefined && itemVid !== null
                        && visibleValueIds[String(itemVid)]) {
                        visibleSet[ai] = true;
                    }
                }
                matchCount = this._countKeys(visibleSet);
                if (matchCount > 0) {
                    console.warn(LOG_PREFIX, 'Strategy 3 (value_id):', matchCount, 'matches');
                }
            }

            if (matchCount === 0) {
                // Log diagnostic info to help debug
                var sampleImage = allImages[0] || {};
                console.warn(LOG_PREFIX, 'No matches found. Sample image data:', {
                    value_id: sampleImage.value_id,
                    valueId: sampleImage.valueId,
                    associatedAttributes: sampleImage.associatedAttributes,
                    img: sampleImage.img ? sampleImage.img.substring(0, 80) : null
                });
                console.warn(LOG_PREFIX, 'Config colorMapping keys:', Object.keys(config.colorMapping || {}));
            }

            // Apply show/hide to DOM
            var $thumbs = $gallery.find('.rp-thumbnail-item');
            var visibleIndexes = [];

            $items.each(function (idx) {
                var isVisible = !!visibleSet[idx];

                $(this).toggleClass(HIDDEN_CLASS, !isVisible);
                if (isVisible) {
                    visibleIndexes.push(idx);
                }

                // Update corresponding thumbnail
                if (idx < $thumbs.length) {
                    $thumbs.eq(idx).toggleClass(HIDDEN_CLASS, !isVisible);
                }
            });

            // Safety: never hide ALL items — abort and restore if that would happen
            if (visibleIndexes.length === 0 && $items.length > 0) {
                console.warn(LOG_PREFIX, 'Safety guard: restoring all items (0 visible)');
                $items.removeClass(HIDDEN_CLASS);
                $thumbs.removeClass(HIDDEN_CLASS);
                return;
            }

            // Layout-specific state management
            if (this._isSlider) {
                this._updateSliderState($gallery, $items, $thumbs, visibleIndexes);
            } else {
                this._updateNonSliderState($gallery, $thumbs, visibleIndexes);
            }
        },

        /**
         * Compute visible indices directly from colorMapping config.
         *
         * Uses colorOptionId + colorMapping to determine which value_ids
         * should be visible, then checks each gallery image's value_id.
         * Falls back to associatedAttributes if value_ids are not present.
         *
         * @param {Array} allImages - Full gallery images array
         * @param {number|null} colorOptionId - Selected color, or null for all
         * @param {Object} config - rollpixGalleryConfig
         * @returns {Object} visibleSet - map of index => true
         */
        _computeVisibleFromConfig: function (allImages, colorOptionId, config) {
            var colorMapping = config.colorMapping || {};
            var showGeneric = config.showGenericImages !== false;
            var visibleSet = {};
            var allowedValueIds = [];

            if (colorOptionId === null || colorOptionId === undefined) {
                // No color selected — show all mapped images
                for (var key in colorMapping) {
                    if (!colorMapping.hasOwnProperty(key)) {
                        continue;
                    }
                    if (key === 'null' && !showGeneric) {
                        continue;
                    }
                    var info = colorMapping[key];
                    allowedValueIds = allowedValueIds.concat(info.images || [], info.videos || []);
                }
            } else {
                // Specific color — show color images + generics
                var colorKey = String(colorOptionId);
                var colorInfo = colorMapping[colorKey];
                var genericInfo = colorMapping['null'];

                if (colorInfo) {
                    allowedValueIds = allowedValueIds.concat(
                        colorInfo.images || [],
                        colorInfo.videos || []
                    );
                }
                if (genericInfo && showGeneric) {
                    allowedValueIds = allowedValueIds.concat(
                        genericInfo.images || [],
                        genericInfo.videos || []
                    );
                }
            }

            if (allowedValueIds.length === 0) {
                return visibleSet;
            }

            // Build a lookup set for O(1) checking
            var allowedSet = {};
            for (var av = 0; av < allowedValueIds.length; av++) {
                allowedSet[String(allowedValueIds[av])] = true;
            }

            for (var i = 0; i < allImages.length; i++) {
                var img = allImages[i];
                var valueId = img.value_id || img.valueId;

                if (valueId !== undefined && valueId !== null) {
                    // Match by value_id against allowed set
                    if (allowedSet[String(parseInt(valueId, 10))]) {
                        visibleSet[i] = true;
                    }
                } else {
                    // No value_id — check associatedAttributes
                    var assoc = img.associatedAttributes || img.associated_attributes;

                    if (assoc === null || assoc === undefined || assoc === '') {
                        // Generic/unassociated image
                        if (showGeneric) {
                            visibleSet[i] = true;
                        }
                    } else if (colorOptionId !== null && colorOptionId !== undefined) {
                        // Has associatedAttributes — check if it matches the color
                        if (this._matchesColor(assoc, colorOptionId, config.colorAttributeId)) {
                            visibleSet[i] = true;
                        }
                        // Also include if generic mapping allows
                    }
                }
            }

            return visibleSet;
        },

        /**
         * Check if an associatedAttributes string matches a color option.
         *
         * @param {string} associatedAttributes - e.g. "attribute93-318"
         * @param {number} optionId - Color option ID
         * @param {number} colorAttributeId - The color attribute ID
         * @returns {boolean}
         */
        _matchesColor: function (associatedAttributes, optionId, colorAttributeId) {
            if (!associatedAttributes || !colorAttributeId) {
                return false;
            }
            var needle = 'attribute' + colorAttributeId + '-' + optionId;
            var parts = associatedAttributes.split(',');
            for (var i = 0; i < parts.length; i++) {
                if (parts[i].trim() === needle) {
                    return true;
                }
            }
            return false;
        },

        /**
         * Count keys in an object (for IE compat, no Object.keys).
         */
        _countKeys: function (obj) {
            var count = 0;
            for (var k in obj) {
                if (obj.hasOwnProperty(k)) {
                    count++;
                }
            }
            return count;
        },

        /**
         * Slider layout: manage dots, arrows, and ensure first visible item is shown.
         *
         * The slider's closure-private goToSlide() cannot be called externally,
         * so we manipulate inline styles directly. Arrow buttons are hidden during
         * filtering to prevent navigation to hidden items (users navigate via
         * visible thumbnails and dots instead, which use the original DOM index
         * and navigate correctly).
         */
        _updateSliderState: function ($gallery, $items, $thumbs, visibleIndexes) {
            var $dots = $gallery.find('.rp-slider-dot');
            var allVisible = (visibleIndexes.length === $items.length);

            // Toggle dot visibility
            $dots.each(function (idx) {
                $(this).toggleClass(HIDDEN_CLASS, visibleIndexes.indexOf(idx) < 0);
            });

            if (allVisible) {
                // No filtering — restore arrows and let slider manage state
                $gallery.find('.rp-slider-prev, .rp-slider-next').removeClass(HIDDEN_CLASS);
                // Trigger first thumbnail click to reset slider internal state
                if ($thumbs.length > 0) {
                    $thumbs.eq(0).trigger('click');
                }
                return;
            }

            if (visibleIndexes.length > 0) {
                var firstIdx = visibleIndexes[0];

                // Reset all non-hidden items to hidden, then show first visible
                $items.each(function () {
                    if (!$(this).hasClass(HIDDEN_CLASS)) {
                        $(this).css({display: 'none', opacity: ''});
                    }
                });
                $items.eq(firstIdx).css({display: 'block', opacity: '1'});

                // Update active dot
                $dots.removeClass('rp-dot-active');
                if (firstIdx < $dots.length) {
                    $dots.eq(firstIdx).addClass('rp-dot-active');
                }

                // Update active thumbnail
                $thumbs.removeClass('rp-thumbnail-active');
                if (firstIdx < $thumbs.length) {
                    $thumbs.eq(firstIdx).addClass('rp-thumbnail-active');
                }
            }

            // Hide arrows when filtering is active — arrow navigation uses
            // currentIndex±1 which could land on a hidden item
            $gallery.find('.rp-slider-prev, .rp-slider-next').addClass(HIDDEN_CLASS);
        },

        /**
         * Non-slider layouts (vertical, grid, fashion): update thumbnail active state
         * and reposition the sliding highlight indicator.
         */
        _updateNonSliderState: function ($gallery, $thumbs, visibleIndexes) {
            if (visibleIndexes.length > 0) {
                $thumbs.removeClass('rp-thumbnail-active');
                $thumbs.eq(visibleIndexes[0]).addClass('rp-thumbnail-active');
            }

            // Reposition the sliding highlight indicator if present
            var $highlight = $gallery.find('.rp-thumbnail-highlight');
            if ($highlight.length && visibleIndexes.length > 0) {
                var $active = $thumbs.eq(visibleIndexes[0]);
                if ($active.length && $active.is(':visible')) {
                    $highlight.addClass('rp-highlight-no-transition');
                    $highlight.css({
                        width: $active.outerWidth() + 'px',
                        height: $active.outerHeight() + 'px',
                        transform: 'translate(' + $active.position().left + 'px, ' + $active.position().top + 'px)'
                    });
                    // Force reflow then re-enable transitions
                    $highlight[0].offsetHeight;
                    $highlight.removeClass('rp-highlight-no-transition');
                }
            }
        },

        /**
         * Swap gallery images with simple product images (native fallback).
         *
         * Called when ConfigurableGallery has no color-media mappings but
         * Rollpix Product Gallery is active (no Fotorama to delegate to).
         * Swaps img src/href attributes in the DOM using images from
         * jsonConfig.images[productId].
         *
         * @param {Array|null} images - Image objects with {thumb, img, full, position},
         *   or null/empty to restore original configurable gallery.
         */
        swapImages: function (images) {
            var $gallery = this._getGallery();
            if (!$gallery) {
                if (this._swapRetries < 20) {
                    this._swapRetries++;
                    var self = this;
                    var retryImages = images;
                    setTimeout(function () {
                        self.swapImages(retryImages);
                    }, 200);
                }
                return;
            }
            this._swapRetries = 0;

            var $items = $gallery.find('.rp-gallery-item');
            var $thumbs = $gallery.find('.rp-thumbnail-item');

            if (!$items.length) {
                return;
            }

            // Store original DOM state on first call
            if (!this._originalState) {
                this._storeOriginalState($items, $thumbs);
            }

            // No images → restore original configurable gallery
            if (!images || !images.length) {
                this._restoreOriginalState($gallery, $items, $thumbs);
                return;
            }

            // Sort by position
            var sorted = images.slice().sort(function (a, b) {
                return (a.position || 0) - (b.position || 0);
            });

            // Filter out entries without valid image URLs
            var validImages = [];
            for (var v = 0; v < sorted.length; v++) {
                if (sorted[v].img || sorted[v].full || sorted[v].thumb) {
                    validImages.push(sorted[v]);
                }
            }

            // No valid images → restore original (variant has no photos)
            if (validImages.length === 0) {
                this._restoreOriginalState($gallery, $items, $thumbs);
                return;
            }

            var domCount = $items.length;
            var newCount = validImages.length;
            var visibleIndexes = [];

            // Unhide all first (previous swap may have hidden some)
            $items.removeClass(HIDDEN_CLASS);
            $thumbs.removeClass(HIDDEN_CLASS);

            for (var i = 0; i < domCount; i++) {
                if (i < newCount) {
                    var newImg = validImages[i];
                    var $item = $items.eq(i);
                    var $itemImg = $item.find('img').first();

                    // Update main image src
                    if ($itemImg.length) {
                        $itemImg.attr('src', newImg.img || newImg.full || '');
                        if (newImg.full) {
                            $itemImg.attr('data-zoom-image', newImg.full);
                        }
                    }
                    // Update href for lightbox/zoom
                    if ($item.is('a')) {
                        $item.attr('href', newImg.full || newImg.img || '');
                    }

                    // Update corresponding thumbnail
                    if (i < $thumbs.length) {
                        var $thumbImg = $thumbs.eq(i).find('img').first();
                        if ($thumbImg.length) {
                            $thumbImg.attr('src', newImg.thumb || newImg.img || '');
                        }
                    }

                    visibleIndexes.push(i);
                } else {
                    // Hide extra DOM items when simple product has fewer images
                    $items.eq(i).addClass(HIDDEN_CLASS);
                    if (i < $thumbs.length) {
                        $thumbs.eq(i).addClass(HIDDEN_CLASS);
                    }
                }
            }

            // Update layout state (slider dots/arrows, thumbnail highlight)
            if (this._isSlider) {
                this._updateSliderState($gallery, $items, $thumbs, visibleIndexes);
            } else {
                this._updateNonSliderState($gallery, $thumbs, visibleIndexes);
            }
        },

        /**
         * Store original DOM state for later restoration on swatch deselection.
         */
        _storeOriginalState: function ($items, $thumbs) {
            var self = this;
            self._originalState = {items: [], thumbs: []};

            $items.each(function () {
                var $item = $(this);
                var $img = $item.find('img').first();
                self._originalState.items.push({
                    src: $img.attr('src') || '',
                    zoomSrc: $img.attr('data-zoom-image') || '',
                    href: $item.is('a') ? ($item.attr('href') || '') : null
                });
            });

            $thumbs.each(function () {
                var $img = $(this).find('img').first();
                self._originalState.thumbs.push({
                    src: $img.attr('src') || ''
                });
            });
        },

        /**
         * Restore original DOM state (configurable product images).
         */
        _restoreOriginalState: function ($gallery, $items, $thumbs) {
            if (!this._originalState) {
                return;
            }

            var orig = this._originalState;

            $items.each(function (idx) {
                var $item = $(this);
                $item.removeClass(HIDDEN_CLASS);

                if (idx < orig.items.length) {
                    var data = orig.items[idx];
                    var $img = $item.find('img').first();

                    if ($img.length) {
                        $img.attr('src', data.src);
                        if (data.zoomSrc) {
                            $img.attr('data-zoom-image', data.zoomSrc);
                        }
                    }
                    if (data.href !== null && $item.is('a')) {
                        $item.attr('href', data.href);
                    }
                }
            });

            $thumbs.each(function (idx) {
                $(this).removeClass(HIDDEN_CLASS);

                if (idx < orig.thumbs.length) {
                    var $img = $(this).find('img').first();
                    if ($img.length) {
                        $img.attr('src', orig.thumbs[idx].src);
                    }
                }
            });

            // Restore layout state with all items visible
            var allIndexes = [];
            for (var i = 0; i < $items.length; i++) {
                allIndexes.push(i);
            }

            if (this._isSlider) {
                this._updateSliderState($gallery, $items, $thumbs, allIndexes);
            } else {
                this._updateNonSliderState($gallery, $thumbs, allIndexes);
            }
        },

        /**
         * Find the Rollpix Product Gallery element.
         * Waits until gallery items exist in the DOM (data-mage-init has run).
         */
        _getGallery: function () {
            if (this.$gallery) {
                return this.$gallery;
            }

            var $gallery = $('[data-role="rp-gallery"]');
            if ($gallery.length && $gallery.find('.rp-gallery-item').length > 0) {
                this.$gallery = $gallery;
                this._isSlider = $gallery.hasClass('rp-layout-slider');
                return $gallery;
            }

            return null;
        }
    };

    return RollpixGalleryAdapter;
});
