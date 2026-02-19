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
        this._filterState = null;
        this._swapRetries = 0;
        this._lastFilterArgs = null;
        this._carouselObserver = null;
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
                console.log(LOG_PREFIX, 'filter event received', {
                    colorOptionId: detail.colorOptionId,
                    isInitial: detail.isInitial,
                    imageCount: detail.images ? detail.images.length : 0
                });
                self._lastFilterArgs = {
                    images: detail.images,
                    isInitial: detail.isInitial,
                    colorOptionId: detail.colorOptionId
                };
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

            if (!allImages.length) {
                return;
            }

            // --- Strategy 1: Direct colorMapping filtering ---
            // Uses colorOptionId + config to independently compute visible indices.
            // This bypasses any reference/value_id matching issues.
            var visibleSet = this._computeVisibleFromConfig(allImages, colorOptionId, config);
            var matchCount = this._countKeys(visibleSet);

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
            }

            // Compute visible indices from visibleSet
            var $thumbs = $gallery.find('.rp-thumbnail-item');
            var visibleIndexes = [];

            for (var vi2 = 0; vi2 < allImages.length; vi2++) {
                if (visibleSet[vi2]) {
                    visibleIndexes.push(vi2);
                }
            }

            // Safety: never hide ALL items
            if (visibleIndexes.length === 0 && $items.length > 0) {
                console.warn(LOG_PREFIX, 'No visible items — aborting filter');
                return;
            }

            console.log(LOG_PREFIX, '_updateGallery', {
                isSlider: this._isSlider,
                galleryClasses: $gallery.attr('class'),
                items: $items.length,
                thumbs: $thumbs.length,
                visibleCount: visibleIndexes.length,
                visibleIndexes: visibleIndexes,
                dotsInDom: $gallery.find('.rp-slider-dot').length
            });

            // Proactively hide carousel dots beyond visible count.
            // CSS nth-child rules auto-apply to elements created later (by
            // async carousel init), so dots are hidden even if the carousel
            // hasn't created them yet. This covers both slider and non-slider paths.
            this._setCarouselFilterCSS(visibleIndexes.length, $items.length, true);

            // Carousel/slider: swap image sources instead of CSS hiding.
            // CSS hiding creates gaps in the carousel and conflicts with the
            // carousel's closure-private state, resulting in blank slides.
            if (this._isSlider) {
                this._filterSliderBySwap($gallery, $items, $thumbs, visibleIndexes);
                return;
            }

            console.log(LOG_PREFIX, 'Using non-slider CSS path');

            // Non-slider layouts: CSS hide/show works fine
            $items.each(function (idx) {
                var isVisible = !!visibleSet[idx];
                $(this).toggleClass(HIDDEN_CLASS, !isVisible);
                if (idx < $thumbs.length) {
                    $thumbs.eq(idx).toggleClass(HIDDEN_CLASS, !isVisible);
                }
            });
            this._updateNonSliderState($gallery, $thumbs, visibleIndexes);

            // Watch for carousel activation — the gallery may transition to
            // carousel mode after our filter runs (responsive mobile layout).
            // When that happens, re-apply with the swap strategy.
            this._watchForCarouselActivation();
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
                $gallery.off('.rpCgFilter');
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

                // Sync slider internal state by clicking the first visible thumbnail.
                // Without this, mobile swipe navigates to hidden slides (blank images).
                if (firstIdx < $thumbs.length) {
                    $thumbs.eq(firstIdx).trigger('click');
                } else if (firstIdx < $dots.length) {
                    $dots.eq(firstIdx).trigger('click');
                }
            }

            // Hide arrows when filtering is active — arrow navigation uses
            // currentIndex±1 which could land on a hidden item
            $gallery.find('.rp-slider-prev, .rp-slider-next').addClass(HIDDEN_CLASS);

            // Swipe guard: after touch interaction on mobile, the slider may navigate
            // to a hidden slide. Detect this and redirect to the nearest visible slide.
            var self = this;
            $gallery.off('.rpCgFilter').on('touchend.rpCgFilter', function () {
                setTimeout(function () {
                    self._ensureVisibleSlide($items, $dots, $thumbs, visibleIndexes);
                }, 350);
            });
        },

        /**
         * After a swipe, check if the slider landed on a hidden slide
         * and redirect to the nearest visible one.
         */
        _ensureVisibleSlide: function ($items, $dots, $thumbs, visibleIndexes) {
            if (!visibleIndexes.length) {
                return;
            }

            // Find which item the slider is currently showing
            // (the slider sets inline display:block on the active item)
            var foundVisible = false;
            $items.each(function () {
                if (this.style.display === 'block' && !$(this).hasClass(HIDDEN_CLASS)) {
                    foundVisible = true;
                    return false;
                }
            });

            if (!foundVisible) {
                // Slider is on a hidden slide — navigate to first visible
                var target = visibleIndexes[0];
                if (target < $dots.length) {
                    $dots.eq(target).trigger('click');
                } else if (target < $thumbs.length) {
                    $thumbs.eq(target).trigger('click');
                }
            }
        },

        /**
         * Slider filter: swap image sources instead of CSS hiding.
         *
         * CSS hiding creates gaps in the slider carousel and conflicts with
         * its closure-private state (blank slides, wrong dot count).
         * Instead, this method packs visible images at DOM positions 0..N-1
         * by swapping their sources, then hides extras at the END only.
         * The slider's internal state stays consistent because positions
         * 0..N-1 are always contiguous and visible.
         *
         * @param {jQuery} $gallery - Gallery container
         * @param {jQuery} $items - All .rp-gallery-item elements
         * @param {jQuery} $thumbs - All .rp-thumbnail-item elements
         * @param {Array} visibleIndexes - Original indices of images to show
         */
        _filterSliderBySwap: function ($gallery, $items, $thumbs, visibleIndexes) {
            var domCount = $items.length;
            var visCount = visibleIndexes.length;
            var allVisible = (visCount >= domCount);
            var $existingDots = $gallery.find('.rp-slider-dot');

            console.log(LOG_PREFIX, '_filterSliderBySwap', {
                domCount: domCount,
                visCount: visCount,
                allVisible: allVisible,
                dotsInDom: $existingDots.length
            });

            // Store original DOM state on first filter call
            if (!this._filterState) {
                this._filterState = {items: [], thumbs: []};
                var self = this;

                $items.each(function () {
                    var $item = $(this);
                    var $img = $item.find('img').first();
                    self._filterState.items.push({
                        src: $img.attr('src') || '',
                        zoomSrc: $img.attr('data-zoom-image') || '',
                        href: $item.is('a') ? ($item.attr('href') || '') : null
                    });
                });

                $thumbs.each(function () {
                    var $img = $(this).find('img').first();
                    self._filterState.thumbs.push({
                        src: $img.attr('src') || ''
                    });
                });

            }

            // All visible → restore original state
            if (allVisible) {
                this._restoreFilterState($gallery, $items, $thumbs);
                return;
            }

            // Collect original sources for the visible items
            var visibleItemSources = [];
            var visibleThumbSources = [];

            for (var v = 0; v < visCount; v++) {
                var origIdx = visibleIndexes[v];

                if (origIdx < this._filterState.items.length) {
                    visibleItemSources.push(this._filterState.items[origIdx]);
                }
                if (origIdx < this._filterState.thumbs.length) {
                    visibleThumbSources.push(this._filterState.thumbs[origIdx]);
                }
            }

            // Unhide all first (previous filter may have hidden extras at the end)
            $items.removeClass(HIDDEN_CLASS);
            $thumbs.removeClass(HIDDEN_CLASS);

            // Pack visible images at positions 0..N-1, hide extras at the END
            for (var i = 0; i < domCount; i++) {
                var $item = $items.eq(i);
                var $img = $item.find('img').first();

                if (i < visibleItemSources.length) {
                    var data = visibleItemSources[i];

                    if ($img.length) {
                        $img.attr('src', data.src);
                        if (data.zoomSrc) {
                            $img.attr('data-zoom-image', data.zoomSrc);
                        }
                    }
                    if (data.href !== null && $item.is('a')) {
                        $item.attr('href', data.href);
                    }

                    // Update corresponding thumbnail
                    if (i < $thumbs.length && i < visibleThumbSources.length) {
                        var $thumbImg = $thumbs.eq(i).find('img').first();

                        if ($thumbImg.length) {
                            $thumbImg.attr('src', visibleThumbSources[i].src);
                        }
                    }
                } else {
                    // Hide extra items at the end (no gaps in the middle)
                    $item.addClass(HIDDEN_CLASS);
                    if (i < $thumbs.length) {
                        $thumbs.eq(i).addClass(HIDDEN_CLASS);
                    }
                }
            }

            // Update dots: show 0..N-1, hide the rest.
            // Direct toggle handles dots that already exist in the DOM:
            var $dots = $gallery.find('.rp-slider-dot');

            $dots.each(function (idx) {
                $(this).toggleClass(HIDDEN_CLASS, idx >= visCount);
            });

            // CSS rule backup: the carousel may create dots AFTER this filter
            // runs (async init). nth-child rules auto-apply to future elements.
            // Pass false = include items/thumbs rules (swap has packed them).
            this._setCarouselFilterCSS(visCount, domCount, false);

            // Activate first slide
            $dots.removeClass('rp-dot-active');
            if ($dots.length > 0) {
                $dots.eq(0).addClass('rp-dot-active');
            }

            $thumbs.removeClass('rp-thumbnail-active');
            if ($thumbs.length > 0) {
                $thumbs.eq(0).addClass('rp-thumbnail-active');
            }

            // Click first thumbnail to sync slider's internal currentIndex
            if ($thumbs.length > 0) {
                $thumbs.eq(0).trigger('click');
            }

            // Hide arrows — the slider's internal totalItems still counts
            // hidden items, so arrow navigation would reach blank slides
            $gallery.find('.rp-slider-prev, .rp-slider-next').addClass(HIDDEN_CLASS);

            // Swipe guard: if mobile swipe lands on a hidden slide, redirect
            var self2 = this;
            var guardIndexes = [];

            for (var g = 0; g < visCount; g++) {
                guardIndexes.push(g);
            }

            $gallery.off('.rpCgFilter').on('touchend.rpCgFilter', function () {
                setTimeout(function () {
                    self2._ensureVisibleSlide($items, $dots, $thumbs, guardIndexes);
                }, 350);
            });
        },

        /**
         * Restore original DOM state after slider filter swap.
         * Called when all images become visible (no color filter active).
         */
        _restoreFilterState: function ($gallery, $items, $thumbs) {
            if (!this._filterState) {
                return;
            }

            var orig = this._filterState;

            // Restore image sources
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

            // Restore thumbnail sources
            $thumbs.each(function (idx) {
                $(this).removeClass(HIDDEN_CLASS);

                if (idx < orig.thumbs.length) {
                    var $img = $(this).find('img').first();

                    if ($img.length) {
                        $img.attr('src', orig.thumbs[idx].src);
                    }
                }
            });

            // Restore dots, arrows, remove swipe guard
            var $dots = $gallery.find('.rp-slider-dot');
            $dots.removeClass(HIDDEN_CLASS).removeClass('rp-dot-active');

            if ($dots.length > 0) {
                $dots.eq(0).addClass('rp-dot-active');
            }

            $gallery.find('.rp-slider-prev, .rp-slider-next').removeClass(HIDDEN_CLASS);
            $gallery.off('.rpCgFilter');
            this._clearCarouselFilterCSS();

            // Sync slider to first slide
            if ($thumbs.length > 0) {
                $thumbs.eq(0).trigger('click');
            }
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
                // Re-check carousel state on every access — the Rollpix Gallery
                // may apply carousel classes after initial DOM render (e.g.,
                // responsive layout switch via media query or JS init timing).
                var wasSlider = this._isSlider;
                this._isSlider = this._detectCarousel(this.$gallery);
                if (this._isSlider !== wasSlider) {
                    console.log(LOG_PREFIX, '_getGallery: carousel state changed', {
                        from: wasSlider,
                        to: this._isSlider,
                        classes: this.$gallery.attr('class')
                    });
                }
                return this.$gallery;
            }

            var $gallery = $('[data-role="rp-gallery"]');
            if ($gallery.length && $gallery.find('.rp-gallery-item').length > 0) {
                this.$gallery = $gallery;
                this._isSlider = this._detectCarousel($gallery);
                console.log(LOG_PREFIX, '_getGallery: found gallery', {
                    isSlider: this._isSlider,
                    classes: $gallery.attr('class'),
                    items: $gallery.find('.rp-gallery-item').length,
                    dots: $gallery.find('.rp-slider-dot').length
                });
                return $gallery;
            }

            return null;
        },

        /**
         * Watch for carousel activation via MutationObserver.
         * When the gallery transitions to carousel mode (rp-carousel-active added
         * by Rollpix Product Gallery after responsive init), re-apply the last
         * filter using the swap strategy instead of CSS hide/show.
         */
        _watchForCarouselActivation: function () {
            // Already watching or already carousel
            if (this._carouselObserver || this._isSlider || !this.$gallery) {
                console.log(LOG_PREFIX, '_watchForCarouselActivation skip', {
                    hasObserver: !!this._carouselObserver,
                    isSlider: this._isSlider,
                    hasGallery: !!this.$gallery
                });
                return;
            }

            // Re-check: carousel may have activated between _getGallery()
            // and this point (race condition with async carousel init).
            if (this._detectCarousel(this.$gallery)) {
                console.log(LOG_PREFIX, '_watchForCarouselActivation: carousel detected on re-check — reapplying');
                this._isSlider = true;
                this._reapplyLastFilter();
                return;
            }

            console.log(LOG_PREFIX, '_watchForCarouselActivation: setting up MutationObserver', {
                galleryClasses: this.$gallery.attr('class')
            });

            if (!window.MutationObserver) {
                // Fallback: poll for carousel activation
                this._pollForCarousel();
                return;
            }

            var self = this;
            this._carouselObserver = new MutationObserver(function () {
                if (self._detectCarousel(self.$gallery)) {
                    self._carouselObserver.disconnect();
                    self._carouselObserver = null;
                    self._isSlider = true;
                    self._reapplyLastFilter();
                }
            });

            this._carouselObserver.observe(this.$gallery[0], {
                attributes: true,
                attributeFilter: ['class']
            });

            // Safety timeout: stop watching after 10 seconds
            var observer = this._carouselObserver;
            setTimeout(function () {
                if (observer) {
                    observer.disconnect();
                }
                if (self._carouselObserver === observer) {
                    self._carouselObserver = null;
                }
            }, 10000);
        },

        /**
         * Fallback polling for carousel activation (no MutationObserver).
         */
        _pollForCarousel: function () {
            var self = this;
            var attempts = 0;

            var poll = function () {
                attempts++;
                if (self._detectCarousel(self.$gallery)) {
                    self._isSlider = true;
                    self._reapplyLastFilter();
                    return;
                }
                if (attempts < 50) {
                    setTimeout(poll, 200);
                }
            };
            setTimeout(poll, 200);
        },

        /**
         * Re-apply the last filter with current _isSlider state.
         * Called when carousel activates after a non-slider filter was applied.
         */
        _reapplyLastFilter: function () {
            if (!this._lastFilterArgs) {
                console.log(LOG_PREFIX, '_reapplyLastFilter: no lastFilterArgs');
                return;
            }
            console.log(LOG_PREFIX, '_reapplyLastFilter: re-running with swap strategy');
            // Reset filter state so swap strategy stores fresh original sources
            this._filterState = null;
            var args = this._lastFilterArgs;
            this._updateGallery(args.images, args.isInitial, args.colorOptionId);
        },

        /**
         * Inject a dynamic CSS rule that hides carousel dots (and extras)
         * beyond the visible count using :nth-child. This is timing-proof:
         * CSS rules auto-apply to DOM elements created AFTER injection,
         * so dots generated by async carousel init are hidden immediately.
         *
         * @param {number} visCount - Number of visible items
         * @param {number} totalCount - Total number of gallery items
         */
        _setCarouselFilterCSS: function (visCount, totalCount, dotsOnly) {
            var styleId = 'rp-cg-carousel-filter';
            var existing = document.getElementById(styleId);

            if (visCount <= 0 || visCount >= totalCount) {
                if (existing) {
                    existing.parentNode.removeChild(existing);
                }
                return;
            }

            var n = visCount + 1; // nth-child is 1-based
            var css =
                '[data-role="rp-gallery"] .rp-slider-dots>:nth-child(n+' + n + '){display:none!important}';

            if (!dotsOnly) {
                css +=
                    '[data-role="rp-gallery"] .rp-gallery-item:nth-child(n+' + n + '){display:none!important}' +
                    '[data-role="rp-gallery"] .rp-thumbnail-item:nth-child(n+' + n + '){display:none!important}';
            }

            console.log(LOG_PREFIX, '_setCarouselFilterCSS', {
                visCount: visCount,
                totalCount: totalCount,
                dotsOnly: !!dotsOnly,
                nthChild: 'n+' + n,
                cssLength: css.length
            });

            if (existing) {
                existing.textContent = css;
            } else {
                var style = document.createElement('style');
                style.id = styleId;
                style.textContent = css;
                document.head.appendChild(style);
            }
        },

        /**
         * Remove the dynamic carousel filter CSS rule.
         */
        _clearCarouselFilterCSS: function () {
            var existing = document.getElementById('rp-cg-carousel-filter');
            if (existing) {
                existing.parentNode.removeChild(existing);
            }
        },

        /**
         * Detect if gallery is running as a carousel/slider.
         * Checks for both explicit slider layout AND grid-with-carousel mode
         * (mobile grid becomes a swipeable carousel with rp-carousel-active).
         */
        _detectCarousel: function ($gallery) {
            return $gallery.hasClass('rp-layout-slider')
                || $gallery.hasClass('rp-carousel-active');
        }
    };

    return RollpixGalleryAdapter;
});
