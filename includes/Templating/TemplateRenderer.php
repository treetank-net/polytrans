<?php

/**
 * Template Renderer for PolyTrans
 * 
 * Renders HTML templates using Twig FilesystemLoader
 * Separates presentation logic from PHP code
 * 
 * @package PolyTrans
 * @subpackage Templating
 * @since 1.7.0
 */

namespace PolyTrans\Templating;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use PolyTrans\Templating\TemplateAssets;
use Twig\TwigFunction;
use Twig\TwigFilter;
use PolyTrans\Core\AdminMarkup;
use PolyTrans\Core\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

class TemplateRenderer
{
    /**
     * Twig environment instance for file templates
     *
     * @var Environment|null
     */
    private static ?Environment $twig = null;

    /**
     * Templates directory path
     *
     * @var string
     */
    private static string $templates_dir = '';

    /**
     * Cache directory path
     *
     * @var string
     */
    private static string $cache_dir = '';

    /**
     * Whether to enable debug mode
     *
     * @var bool
     */
    private static bool $debug = false;

    /**
     * Initialize Twig environment for file templates
     *
     * @param array<string, mixed> $options Configuration options
     * @return void
     */
    public static function init(array $options = []): void
    {
        if (null !== self::$twig) {
            return; // Already initialized
        }

        // Configure directories
        $plugin_dir = dirname(__DIR__, 2);
        self::$templates_dir = $options['templates_dir'] ?? $plugin_dir . '/templates';
        self::$cache_dir = $options['cache_dir'] ?? $plugin_dir . '/cache/twig-templates';
        self::$debug = $options['debug'] ?? (defined('WP_DEBUG') && WP_DEBUG); 

        // Enable caching in production
        $use_cache = !self::$debug;

        // Ensure cache directory exists
        if ($use_cache && !file_exists(self::$cache_dir)) {
            if (wp_mkdir_p(self::$cache_dir)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Cache directory management
                @chmod(self::$cache_dir, 0777);
            } else {
                $use_cache = false;
            }
        }

        // Ensure templates directory exists
        if (!file_exists(self::$templates_dir)) {
            wp_mkdir_p(self::$templates_dir);
        }

        // Initialize Twig with FilesystemLoader
        $loader = new FilesystemLoader(self::$templates_dir);
        self::$twig = new Environment(
            $loader,
            [
                'cache' =>  false,
                'debug' => self::$debug,
                'auto_reload' => self::$debug,
                'strict_variables' => false,
                'autoescape' => false, // WordPress handles escaping
            ]
        );
        // Add WordPress functions and filters
        self::add_wordpress_functions();
        self::add_wordpress_filters();
        self::add_asset_functions();
    }

    /**
     * Render a template file
     *
     * @param string $template Template file path (relative to templates directory)
     * @param array<string, mixed> $context Variables available in template
     * @param bool $enqueue_assets Whether to enqueue assets registered by template
     * @return string Rendered HTML
     */
    public static function render(string $template, array $context = [], bool $enqueue_assets = true): string
    {
        if (null === self::$twig) {
            self::init();
        }

        // Reset assets before rendering (in case of multiple renders)
        if ($enqueue_assets) {
            TemplateAssets::reset();
        }

        try {
            $return = self::$twig->render($template, $context);
            
            // Enqueue assets after rendering (they were registered during render)
            // Use admin_footer hook to ensure assets are enqueued before page output
            if ($enqueue_assets) {
                // Check if we're in admin and hook hasn't been added yet
                if (is_admin() && !has_action('admin_footer', [self::class, 'enqueue_template_assets'])) {
                    add_action('admin_footer', [self::class, 'enqueue_template_assets'], 1);
                } else {
                    // If not in admin or hook already added, enqueue immediately
                    TemplateAssets::enqueue_all();
                }
            }
            
            return $return;
        } catch (\Throwable $e) {
            Diagnostics::log(sprintf(
                '[TreeTank] Template rendering failed: %s. Template: %s',
                $e->getMessage(),
                $template
            ));
            if (self::$debug) {
                return sprintf(
                    '<!-- Template Error: %s -->',
                    esc_html($e->getMessage())
                );
            }
            return '';
        }
    }

    /**
     * Render a template and print it, escaped with wp_kses() against allowed_html().
     *
     * This is the only way a template reaches the screen. The Twig environment runs with
     * autoescape off and every value is escaped inside the template, but WordPress.org
     * asks for escaping at the echo as well — "escape late" — so the finished markup goes
     * through wp_kses() here, not through a phpcs:ignore at each caller.
     *
     * @param string $template Template file path (relative to templates directory)
     * @param array<string, mixed> $context Variables available in template
     * @param bool $enqueue_assets Whether to enqueue assets registered by template
     * @return void
     */
    public static function display(string $template, array $context = [], bool $enqueue_assets = true): void
    {
        $html = self::render($template, $context, $enqueue_assets);

        AdminMarkup::begin();
        if (self::$debug) {
            self::report_stripped_markup($template, $html, wp_kses($html, self::allowed_html()));
        }

        echo wp_kses($html, self::allowed_html());
        AdminMarkup::end();
    }

    /**
     * Markup an admin template may contain; see Core\AdminMarkup::allowed_html().
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowed_html(): array
    {
        return AdminMarkup::allowed_html();
    }

    /**
     * Under WP_DEBUG, log when wp_kses() removed an element or attribute from a template.
     *
     * Stripping is silent by design, so a field missing from allowed_html() — or a style
     * safecss_filter_attr() refuses — would quietly break a form. The activation smoke
     * test fails on any of these lines in debug.log.
     */
    private static function report_stripped_markup(string $template, string $html, string $escaped): void
    {
        if ($html === $escaped) {
            return;
        }

        if (!preg_match_all(self::OPENING_TAG, $html, $tags)) {
            return;
        }

        $examples = [];
        foreach (array_unique($tags[0]) as $tag) {
            $kept = wp_kses($tag, self::allowed_html());
            if (self::markup_fingerprint($tag) !== self::markup_fingerprint($kept)) {
                $examples[] = substr($tag, 0, 200) . ' => ' . substr($kept, 0, 200);
                if (count($examples) >= 10) {
                    break;
                }
            }
        }

        if ($examples) {
            Diagnostics::log(sprintf(
                '[TreeTank] wp_kses stripped markup from %s: %s',
                $template,
                implode(' | ', $examples)
            ));
        }
    }

    /** An opening tag, with quoted attribute values that may contain ">". */
    private const OPENING_TAG = '/<[a-zA-Z][a-zA-Z0-9-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/';

    /**
     * Element and attribute names of one opening tag, ignoring values.
     *
     * @return list<string>
     */
    private static function markup_fingerprint(string $tag): array
    {
        if (!preg_match('/^<([a-zA-Z][a-zA-Z0-9-]*)/', $tag, $element)) {
            return [];
        }
        // An empty style="" is dropped by kses and means nothing; then drop quoted values,
        // so "padding: 0" inside style= is not read as a name.
        $attributes = preg_replace('/\sstyle\s*=\s*(""|\'\')/i', '', substr($tag, strlen($element[0])));
        $attributes = preg_replace('/"[^"]*"|\'[^\']*\'/', '', (string) $attributes);
        preg_match_all('/([a-zA-Z_:][a-zA-Z0-9_.:-]*)/', (string) $attributes, $names);
        // Unique names: {{ selected() }} prints its attribute twice (it echoes and
        // returns), and kses collapsing that duplicate loses nothing.
        $fingerprint = array_values(array_unique(array_map('strtolower', $names[1])));
        // safecss_filter_attr() drops single declarations and keeps the attribute.
        if (preg_match('/\sstyle\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $style)) {
            $css = html_entity_decode($style[2] !== '' ? $style[2] : ($style[3] ?? ''));
            foreach (array_filter(array_map('trim', explode(';', $css))) as $declaration) {
                $fingerprint[] = 'style:' . strtolower(trim(strtok($declaration, ':')));
            }
        }
        sort($fingerprint);
        array_unshift($fingerprint, strtolower($element[1]));

        return $fingerprint;
    }

    /**
     * Enqueue template assets (called via admin_footer hook)
     *
     * @return void
     */
    public static function enqueue_template_assets(): void
    {
        TemplateAssets::enqueue_all();
    }

    /**
     * Add WordPress functions to Twig
     *
     * @return void
     */
    private static function add_wordpress_functions(): void
    {
        if (null === self::$twig) {
            return;
        }

        // Escaping functions
        self::$twig->addFunction(new TwigFunction('esc_html', 'esc_html'));
        self::$twig->addFunction(new TwigFunction('esc_attr', 'esc_attr'));
        self::$twig->addFunction(new TwigFunction('esc_url', 'esc_url'));
        self::$twig->addFunction(new TwigFunction('esc_js', 'esc_js'));
        self::$twig->addFunction(new TwigFunction('esc_textarea', 'esc_textarea'));
        self::$twig->addFunction(new TwigFunction('wp_kses_post', 'wp_kses_post'));

        // Translation functions
        self::$twig->addFunction(new TwigFunction('__', '__'));
        self::$twig->addFunction(new TwigFunction('_e', '_e'));
        self::$twig->addFunction(new TwigFunction('esc_html__', 'esc_html__'));
        self::$twig->addFunction(new TwigFunction('esc_html_e', 'esc_html_e'));
        self::$twig->addFunction(new TwigFunction('esc_attr__', 'esc_attr__'));
        self::$twig->addFunction(new TwigFunction('esc_attr_e', 'esc_attr_e'));

        // URL functions
        self::$twig->addFunction(new TwigFunction('admin_url', 'admin_url'));
        self::$twig->addFunction(new TwigFunction('home_url', 'home_url'));
        self::$twig->addFunction(new TwigFunction('site_url', 'site_url'));
        self::$twig->addFunction(new TwigFunction('urlencode', 'urlencode'));

        // WordPress utility functions
        self::$twig->addFunction(new TwigFunction('selected', 'selected'));
        self::$twig->addFunction(new TwigFunction('checked', 'checked'));
        self::$twig->addFunction(new TwigFunction('disabled', 'disabled'));
        self::$twig->addFunction(new TwigFunction('wp_nonce_field', 'wp_nonce_field'));
        self::$twig->addFunction(new TwigFunction('wp_create_nonce', 'wp_create_nonce'));
        
        // PHP utility functions
        self::$twig->addFunction(new TwigFunction('in_array', 'in_array'));

        // Date functions
        self::$twig->addFunction(new TwigFunction('mysql2date', 'mysql2date'));
        self::$twig->addFunction(new TwigFunction('get_option', 'get_option'));

        // User functions
        self::$twig->addFunction(new TwigFunction('get_user_by', 'get_user_by'));

        // URL functions
        self::$twig->addFunction(new TwigFunction('add_query_arg', 'add_query_arg'));
        self::$twig->addFunction(new TwigFunction('paginate_links', 'paginate_links'));
        
        // WordPress editor function (uses output buffering to capture HTML)
        self::$twig->addFunction(new TwigFunction('wp_editor', function ($content, $editor_id, $settings = []) {
            ob_start();
            wp_editor($content, $editor_id, $settings);
            return ob_get_clean();
        }, ['is_safe' => ['html']]));

        // JSON functions
        self::$twig->addFunction(new TwigFunction('wp_json_encode', function ($data, $options = 0) {
            return wp_json_encode($data, $options);
        }));
        
        // JSON constants for Twig templates
        self::$twig->addFunction(new TwigFunction('JSON_PRETTY_PRINT', function () {
            return JSON_PRETTY_PRINT; // 128
        }));
        
        self::$twig->addFunction(new TwigFunction('JSON_UNESCAPED_UNICODE', function () {
            return JSON_UNESCAPED_UNICODE; // 256
        }));

        // Custom helper functions
        self::$twig->addFunction(new TwigFunction('polytrans_admin_url', function ($path = '') {
            return admin_url('admin.php?page=' . $path);
        }));
        
        // Action hook function (for do_action in templates)
        self::$twig->addFunction(new TwigFunction('action', function ($hook, ...$args) {
            ob_start();
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Dynamic hook intentional
            do_action($hook, ...$args);
            return ob_get_clean();
        }, ['is_safe' => ['html']]));
        
        // Helper functions for language pairs (used in settings templates)
        self::$twig->addFunction(new TwigFunction('get_language_pairs', function ($langs) {
            $pairs = [];
            foreach ($langs as $source) {
                foreach ($langs as $target) {
                    if ($source !== $target) {
                        $pairs[] = [
                            'source' => $source,
                            'target' => $target,
                            'key' => $source . '_to_' . $target
                        ];
                    }
                }
            }
            return $pairs;
        }));
        
        self::$twig->addFunction(new TwigFunction('get_language_name', function ($code, $langs, $lang_names) {
            $index = array_search($code, $langs);
            if ($index !== false && isset($lang_names[$index])) {
                return $lang_names[$index];
            }
            return strtoupper($code);
        }));
    }

    /**
     * Add asset management functions to Twig
     *
     * @return void
     */
    private static function add_asset_functions(): void
    {
        if (null === self::$twig) {
            return;
        }

        // Asset management functions (return void, use {% do %} in templates)
        self::$twig->addFunction(new TwigFunction('enqueue_assets', function ($assets_config) {
            TemplateAssets::register_assets($assets_config);
        }, ['is_safe' => ['html']]));

        self::$twig->addFunction(new TwigFunction('localize_script', function ($script_handle, $object_name, $data) {
            TemplateAssets::register_localized_data($script_handle, $object_name, $data);
        }, ['is_safe' => ['html']]));

        self::$twig->addFunction(new TwigFunction('add_inline_script', function ($handle, $data, $position = 'after') {
            TemplateAssets::add_inline_script($handle, $data, $position);
        }, ['is_safe' => ['html']]));

        // Plugin constants
        self::$twig->addFunction(new TwigFunction('polytrans_plugin_url', function () {
            return POLYTRANS_PLUGIN_URL;
        }));

        self::$twig->addFunction(new TwigFunction('polytrans_version', function () {
            return POLYTRANS_VERSION;
        }));
    }

    /**
     * Add WordPress filters to Twig
     *
     * @return void
     */
    private static function add_wordpress_filters(): void
    {
        if (null === self::$twig) {
            return;
        }

        // Escaping filters
        self::$twig->addFilter(new TwigFilter('esc_html', 'esc_html'));
        self::$twig->addFilter(new TwigFilter('esc_attr', 'esc_attr'));
        self::$twig->addFilter(new TwigFilter('esc_url', 'esc_url'));
        self::$twig->addFilter(new TwigFilter('esc_js', 'esc_js'));
        self::$twig->addFilter(new TwigFilter('esc_textarea', 'esc_textarea'));

        // WordPress content filters
        self::$twig->addFilter(new TwigFilter('wp_kses', 'wp_kses'));
        self::$twig->addFilter(new TwigFilter('wp_kses_post', 'wp_kses_post'));
        self::$twig->addFilter(new TwigFilter('wpautop', 'wpautop'));
        self::$twig->addFilter(new TwigFilter('wptexturize', 'wptexturize'));

        // String manipulation filters
        self::$twig->addFilter(new TwigFilter('capitalize', function($string) {
            return ucfirst($string);
        }));
        
        // Textarea-safe filter: escapes only < and >, preserves quotes and newlines
        // Use this for JSON in textarea elements where quotes don't need escaping
        self::$twig->addFilter(new TwigFilter('textarea_safe', function($string) {
            if (empty($string)) {
                return '';
            }
            // Only escape < and >, preserve quotes and newlines for JSON formatting
            return str_replace(['<', '>'], ['&lt;', '&gt;'], $string);
        }, ['is_safe' => ['html']]));
    }

    /**
     * Get templates directory path
     *
     * @return string
     */
    public static function get_templates_dir(): string
    {
        if (empty(self::$templates_dir)) {
            self::init();
        }
        return self::$templates_dir;
    }
}
