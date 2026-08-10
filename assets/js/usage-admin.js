/**
 * PolyTrans - AI cost dashboard
 *
 * The filter form is a plain GET form and stays one: the whole report state lives in
 * the URL, which is what makes a view shareable and the back button work. This only
 * removes the two places where that form would otherwise need explaining.
 */
(function () {
    'use strict';

    function init() {
        var form = document.querySelector('[data-polytrans-usage-filters]');

        if (!form) {
            return;
        }

        var preset = form.querySelector('[data-usage-preset]');

        // Editing a date while a named period is selected is a contradiction the form
        // has to resolve one way or the other. Taking the edit as the intent means the
        // typed dates survive; resolving it the other way would discard them on submit.
        Array.prototype.forEach.call(form.querySelectorAll('[data-usage-range]'), function (input) {
            input.addEventListener('change', function () {
                if (preset) {
                    preset.value = 'custom';
                }
            });
        });

        // Selects submit on change; the date inputs deliberately do not, since a
        // half-typed date would reload the page mid-edit.
        Array.prototype.forEach.call(form.querySelectorAll('select'), function (select) {
            select.addEventListener('change', function () {
                form.submit();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
