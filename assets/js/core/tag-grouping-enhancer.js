/**
 * PolyTrans Tag Grouping Enhancer
 *
 * Enhances the WordPress tag autocomplete dropdown in the post editor
 * to group suggestions into PolyTrans-approved tags and other tags.
 *
 * Replaces the autocomplete source with a custom AJAX endpoint that
 * returns pre-grouped results (approved first, then others).
 */
(function ($) {
    'use strict';

    if (typeof window.polyTransTagGrouping === 'undefined') {
        return;
    }

    var config = window.polyTransTagGrouping;
    var approvedTags = (config.approvedTags || []).map(function (t) {
        return t.toLowerCase();
    });

    if (!approvedTags.length) {
        return;
    }

    var i18n = config.i18n || {};
    var labelApproved = i18n.approved || 'PolyTrans';
    var labelOther = i18n.other || 'Other';

    function isApproved(tagName) {
        return approvedTags.indexOf(tagName.toLowerCase().trim()) !== -1;
    }

    /**
     * Custom AJAX source that queries our endpoint with double-query logic.
     */
    function customSource(request, response) {
        var term = request.term;
        if (!term || term.length < 1) {
            response([]);
            return;
        }

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'polytrans_suggest_tags',
                nonce: config.nonce,
                q: term,
                lang: config.lang
            },
            success: function (res) {
                if (!res.success) {
                    response([]);
                    return;
                }
                var items = [];
                var data = res.data;

                // Approved tags first
                $.each(data.approved || [], function (i, name) {
                    items.push({ label: name, value: name, _group: 'approved' });
                });
                // Then others
                $.each(data.other || [], function (i, name) {
                    items.push({ label: name, value: name, _group: 'other' });
                });

                response(items);
            },
            error: function () {
                response([]);
            }
        });
    }

    /**
     * Wait for the autocomplete instance to be initialized on tag inputs,
     * then override its source and rendering methods.
     */
    function enhanceTagInput(input) {
        var $input = $(input);

        // jQuery UI Autocomplete stores the instance under 'ui-autocomplete'
        var instance = $input.data('ui-autocomplete') || $input.data('autocomplete');
        if (!instance) {
            return false;
        }

        // Replace source with our custom AJAX endpoint
        instance.option('source', customSource);
        instance.option('minLength', 1);

        // Override _renderMenu to inject group headers
        instance._renderMenu = function (ul, items) {
            var self = this;
            var approved = [];
            var other = [];

            $.each(items, function (i, item) {
                if (item._group === 'approved') {
                    approved.push(item);
                } else {
                    other.push(item);
                }
            });

            if (approved.length && other.length) {
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
            var tagName = item.label || item.value;
            var $li = $('<li role="option">')
                .text(tagName);

            if (item._group === 'approved') {
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
