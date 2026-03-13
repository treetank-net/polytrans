/**
 * PolyTrans Tag Grouping Enhancer
 *
 * Enhances the WordPress tag autocomplete dropdown in the post editor
 * to group suggestions into PolyTrans-approved tags and other tags.
 *
 * Does NOT replace the autocomplete widget — only overrides the rendering
 * (_renderMenu / _renderItem) on the existing jQuery UI Autocomplete instance.
 */
(function ($) {
    'use strict';

    if (typeof window.polyTransTagGrouping === 'undefined') {
        return;
    }

    var approvedTags = (window.polyTransTagGrouping.approvedTags || []).map(function (t) {
        return t.toLowerCase();
    });

    if (!approvedTags.length) {
        return;
    }

    var i18n = window.polyTransTagGrouping.i18n || {};
    var labelApproved = i18n.approved || 'PolyTrans';
    var labelOther = i18n.other || 'Other';

    function isApproved(tagName) {
        return approvedTags.indexOf(tagName.toLowerCase().trim()) !== -1;
    }

    /**
     * Wait for the autocomplete instance to be initialized on tag inputs,
     * then override its rendering methods.
     */
    function enhanceTagInput(input) {
        var $input = $(input);

        // jQuery UI Autocomplete stores the instance under 'ui-autocomplete'
        var instance = $input.data('ui-autocomplete') || $input.data('autocomplete');
        if (!instance) {
            return false;
        }

        // Override _renderMenu to inject group headers
        instance._renderMenu = function (ul, items) {
            var self = this;
            var approved = [];
            var other = [];

            $.each(items, function (i, item) {
                if (isApproved(item.name || item.label || item.value)) {
                    approved.push(item);
                } else {
                    other.push(item);
                }
            });

            if (approved.length && other.length) {
                // Both groups — render with headers
                ul.append('<li class="polytrans-tag-group-header polytrans-tag-group-approved">' + $('<span>').text(labelApproved).html() + '</li>');
                $.each(approved, function (i, item) {
                    self._renderItemData(ul, item);
                });
                ul.append('<li class="polytrans-tag-group-header polytrans-tag-group-other">' + $('<span>').text(labelOther).html() + '</li>');
                $.each(other, function (i, item) {
                    self._renderItemData(ul, item);
                });
            } else {
                // Only one group — render flat, no headers
                $.each(items, function (i, item) {
                    self._renderItemData(ul, item);
                });
            }

            ul.addClass('polytrans-tag-grouped');
        };

        // Override _renderItem to add visual indicator for approved tags
        instance._renderItem = function (ul, item) {
            var tagName = item.name || item.label || item.value;
            var $li = $('<li role="option">')
                .text(tagName);

            if (isApproved(tagName)) {
                $li.addClass('polytrans-tag-approved');
            }

            return $li.appendTo(ul);
        };

        return true;
    }

    /**
     * Retry enhancing inputs until autocomplete is initialized.
     * WordPress initializes it asynchronously via tags-box.js.
     */
    function init() {
        var inputs = $('input.newtag[data-wp-taxonomy="post_tag"]');
        if (!inputs.length) {
            return;
        }

        var retries = 0;
        var maxRetries = 20;
        var interval = setInterval(function () {
            var allDone = true;
            inputs.each(function () {
                if (!$(this).data('polytrans-enhanced')) {
                    if (enhanceTagInput(this)) {
                        $(this).data('polytrans-enhanced', true);
                    } else {
                        allDone = false;
                    }
                }
            });

            retries++;
            if (allDone || retries >= maxRetries) {
                clearInterval(interval);
            }
        }, 250);
    }

    $(document).ready(init);

})(jQuery);
