<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Allowed HTML for escaping the plugin's admin markup with wp_kses().
 */
class AdminMarkup
{
    /**
     * Markup the plugin's admin screens may print: the elements of a settings form.
     *
     * The allow-list for wp_kses() at every echo of generated admin markup —
     * TemplateRenderer::display() and the provider settings panels.
     *
     * Deliberately absent: script, style, iframe, object and every on* attribute. No
     * template carries them — behaviour comes from enqueued scripts — and
     * TemplateEscapingTest keeps it that way. `link` is here because wp_editor() prints
     * the editor-buttons stylesheet inline the first time it runs on a screen.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowed_html(): array
    {
        static $allowed = null;
        if (null !== $allowed) {
            return $allowed;
        }

        $global = array_fill_keys([
            'id', 'class', 'style', 'title', 'role', 'hidden', 'tabindex', 'lang', 'dir',
            'aria-label', 'aria-hidden', 'aria-disabled', 'aria-describedby', 'aria-expanded',
            'aria-controls', 'aria-live', 'aria-selected', 'aria-labelledby', 'aria-current',
            'aria-pressed', 'aria-haspopup', 'aria-checked', 'aria-required', 'aria-invalid',
            'data-*',
        ], true);

        $element_attributes = [
            'a' => ['href', 'target', 'rel', 'download'],
            'abbr' => [], 'b' => [], 'blockquote' => ['cite'], 'br' => [], 'caption' => [],
            'code' => [], 'col' => ['span'], 'colgroup' => ['span'], 'dd' => [],
            'details' => ['open'], 'div' => [], 'dl' => [], 'dt' => [], 'em' => [],
            'fieldset' => ['disabled', 'form', 'name'],
            'form' => ['action', 'method', 'enctype', 'name', 'target', 'autocomplete', 'novalidate', 'accept-charset'],
            'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'hr' => [], 'i' => [],
            'img' => ['src', 'alt', 'width', 'height', 'loading', 'srcset', 'sizes'],
            'input' => [
                'type', 'name', 'value', 'checked', 'disabled', 'readonly', 'required', 'placeholder',
                'min', 'max', 'step', 'size', 'maxlength', 'minlength', 'pattern', 'autocomplete',
                'multiple', 'accept', 'form', 'list', 'spellcheck', 'autofocus', 'inputmode',
            ],
            'kbd' => [], 'label' => ['for', 'form'], 'legend' => [], 'li' => ['value'],
            'link' => ['rel', 'href', 'media', 'type'],
            'mark' => [], 'ol' => ['start', 'reversed', 'type'],
            'optgroup' => ['label', 'disabled'],
            'option' => ['value', 'selected', 'disabled', 'label'],
            'p' => [], 'pre' => [], 'progress' => ['value', 'max'], 's' => [], 'samp' => [],
            'select' => ['name', 'multiple', 'disabled', 'required', 'size', 'form', 'autocomplete'],
            'small' => [], 'span' => [], 'strong' => [], 'sub' => [], 'summary' => [], 'sup' => [],
            'table' => ['cellspacing', 'cellpadding', 'border', 'width'],
            'tbody' => [], 'td' => ['colspan', 'rowspan', 'headers', 'width'],
            'textarea' => [
                'name', 'rows', 'cols', 'disabled', 'readonly', 'required', 'placeholder',
                'maxlength', 'wrap', 'spellcheck', 'autocomplete', 'form',
            ],
            'tfoot' => [], 'th' => ['colspan', 'rowspan', 'scope', 'headers', 'width'],
            'thead' => [], 'time' => ['datetime'], 'tr' => [], 'u' => [], 'ul' => [],
            'button' => ['type', 'name', 'value', 'disabled', 'form'],
        ];

        $allowed = [];
        foreach ($element_attributes as $element => $attributes) {
            $allowed[$element] = $global + array_fill_keys($attributes, true);
        }

        return $allowed;
    }

    /**
     * CSS properties the admin markup uses in style="" — merged into safe_style_css.
     *
     * safecss_filter_attr() drops any declaration it does not know, and its list grows
     * with WordPress: 6.0 does not know `display`, so every `display:none` tab panel
     * would show at once. Only added around our own wp_kses() call, never globally.
     *
     * @param string[] $properties
     * @return string[]
     */
    public static function safe_style_css($properties): array
    {
        return array_values(array_unique(array_merge((array) $properties, [
            'align-items', 'background', 'border', 'border-left', 'border-left-color',
            'color', 'display', 'flex', 'flex-wrap', 'float', 'font-family', 'font-size',
            'font-style', 'font-weight', 'gap', 'height',
            'list-style-type', 'margin', 'margin-bottom', 'margin-left', 'margin-top',
            'max-height', 'max-width', 'min-height', 'overflow', 'padding', 'text-align',
            'white-space', 'width',
        ])));
    }

    /**
     * Start/stop accepting safe_style_css() in wp_kses(). Bracket one echo with these.
     */
    public static function begin(): void
    {
        add_filter('safe_style_css', [self::class, 'safe_style_css']);
    }

    public static function end(): void
    {
        remove_filter('safe_style_css', [self::class, 'safe_style_css']);
    }
}
