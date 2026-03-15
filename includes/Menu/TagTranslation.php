<?php

/**
 * Tag Translation Class
 * Handles tag translation management
 */

namespace PolyTrans\Menu;

use PolyTrans\Templating\TemplateRenderer;

if (!defined('ABSPATH')) {
    exit;
}
class TagTranslation
{

    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        add_action('wp_ajax_polytrans_save_tag_translation', [$this, 'ajax_save_tag_translation']);
        add_action('wp_ajax_polytrans_search_tags', [$this, 'ajax_search_tags']);
        add_action('wp_ajax_polytrans_export_tag_csv', [$this, 'ajax_export_tag_csv']);
        add_action('wp_ajax_polytrans_import_tag_csv', [$this, 'ajax_import_tag_csv']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'polytrans',
            __('Tag Translations', 'polytrans'),
            __('Tag Translations', 'polytrans'),
            'edit_posts',
            'polytrans-tag-translation',
            [$this, 'admin_page'],
            20
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook)
    {
        // Tag translation admin page
        if ($hook === 'polytrans_page_polytrans-tag-translation') {
            $plugin_url = POLYTRANS_PLUGIN_URL;
            wp_enqueue_script('polytrans-tag-translation', $plugin_url . 'assets/js/core/tag-translation-admin.js', ['jquery'], POLYTRANS_VERSION, true);
            wp_enqueue_style('polytrans-tag-translation', $plugin_url . 'assets/css/core/tag-translation-admin.css', [], POLYTRANS_VERSION);

            wp_localize_script('polytrans-tag-translation', 'PolyTransTagTranslation', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('polytrans_tag_translation'),
                'i18n' => [
                    'translation_saved' => esc_html__('Translation saved!', 'polytrans'),
                    'please_select_file' => esc_html__('Please select a CSV file.', 'polytrans'),
                    'importing' => esc_html__('Importing...', 'polytrans'),
                    'import_complete' => esc_html__('Import complete!', 'polytrans'),
                    'import_error' => esc_html__('Import failed. Please check the CSV format.', 'polytrans'),
                    'confirm_delete' => esc_html__('Are you sure you want to delete this translation?', 'polytrans'),
                    'deleting' => esc_html__('Deleting...', 'polytrans'),
                    'delete_error' => esc_html__('Failed to delete translation.', 'polytrans'),
                    'search_placeholder' => esc_html__('Search tags...', 'polytrans'),
                    'no_results' => esc_html__('No tags found.', 'polytrans'),
                    'loading' => esc_html__('Loading...', 'polytrans'),
                    'error_occurred' => esc_html__('An error occurred. Please try again.', 'polytrans'),
                ]
            ]);
        }

        // Tag grouping enhancer on post editor screens
        if (in_array($hook, ['post.php', 'post-new.php'], true)) {
            $this->maybe_enqueue_tag_grouping();
        }
    }

    /**
     * Enqueue tag grouping enhancer if enabled and base tags exist
     */
    private function maybe_enqueue_tag_grouping()
    {
        $settings = get_option('polytrans_settings', []);

        if (($settings['enable_tag_grouping'] ?? '0') !== '1') {
            return;
        }

        $base_tags_raw = $settings['base_tags'] ?? '';
        if (empty(trim($base_tags_raw))) {
            return;
        }

        // Determine current post language
        $post_lang = '';
        $source_language = $settings['source_language'] ?? 'pl';
        if (function_exists('pll_get_post_language') && isset($_GET['post'])) {
            $post_lang = pll_get_post_language(intval($_GET['post']), 'slug');
        }
        if (empty($post_lang) && function_exists('pll_current_language')) {
            $post_lang = pll_current_language('slug');
        }
        if (empty($post_lang)) {
            $post_lang = $source_language;
        }

        // Get approved tag names for the current language
        $approved_tags = $this->get_approved_tags_for_language($base_tags_raw, $source_language, $post_lang);
        if (empty($approved_tags)) {
            return;
        }

        $plugin_url = POLYTRANS_PLUGIN_URL;
        wp_enqueue_script('polytrans-tag-grouping', $plugin_url . 'assets/js/core/tag-grouping-enhancer.js', ['jquery', 'tags-suggest'], POLYTRANS_VERSION, true);
        wp_enqueue_style('polytrans-tag-grouping', $plugin_url . 'assets/css/core/tag-grouping-enhancer.css', [], POLYTRANS_VERSION);

        wp_localize_script('polytrans-tag-grouping', 'polyTransTagGrouping', [
            'approvedTags' => $approved_tags,
            'i18n' => [
                'approved' => esc_html__('PolyTrans', 'polytrans'),
                'other' => esc_html__('Other', 'polytrans'),
            ],
        ]);
    }

    /**
     * Get approved tag names for a specific language
     */
    private function get_approved_tags_for_language($base_tags_raw, $source_language, $target_lang)
    {
        $tag_names = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $base_tags_raw)));
        if (empty($tag_names)) {
            return [];
        }

        // If target language is the source language, return base tags directly
        if ($target_lang === $source_language) {
            return array_values($tag_names);
        }

        // Get translated tag names for target language
        $approved = [];
        foreach ($tag_names as $tag_name) {
            $tag = $this->get_term_by_name_and_lang($tag_name, $source_language);
            if (!$tag) {
                continue;
            }

            if (function_exists('pll_get_term')) {
                $translated_id = pll_get_term($tag->term_id, $target_lang);
                if ($translated_id) {
                    $translated_term = get_term($translated_id, 'post_tag');
                    if ($translated_term && !is_wp_error($translated_term)) {
                        $approved[] = $translated_term->name;
                    }
                }
            } else {
                // Fallback — just use source tag names
                $approved[] = $tag_name;
            }
        }

        return $approved;
    }

    /**
     * Admin page callback
     */
    public function admin_page()
    {
        // Get languages (Polylang or fallback)
        if (function_exists('pll_languages_list')) {
            $langs = pll_languages_list(['fields' => 'slug']);
        } else {
            $langs = ['pl', 'en', 'it'];
        }

        // Load tag list from settings instead of separate option
        $settings = get_option('polytrans_settings', []);
        $tag_list_raw = $settings['base_tags'] ?? '';
        $source_language = $settings['source_language'] ?? 'pl';
        $tag_names = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $tag_list_raw)));

        // Get tag objects by name, ensure all tags from the list are present
        $tags_data = [];
        if (!empty($tag_names)) {
            foreach ($tag_names as $tag_name) {
                $tag = $this->get_term_by_name_and_lang($tag_name, $source_language);
                if (!$tag) {
                    // Create the source language tag if it doesn't exist
                    $new_tag = wp_insert_term($tag_name, 'post_tag', [
                        'slug' => sanitize_title($tag_name) . '-' . $source_language,
                        'name' => $tag_name
                    ]);
                    if (!is_wp_error($new_tag)) {
                        $tag = get_term($new_tag['term_id']);
                        // Set language to source language if Polylang is available
                        if (function_exists('pll_set_term_language')) {
                            pll_set_term_language($tag->term_id, $source_language);
                        }
                    } else {
                        // tag exists, we need to ensure it's in the correct language
                        $tag = get_term_by('name', $tag_name, 'post_tag');
                        if (function_exists('pll_set_term_language')) {
                            pll_set_term_language($tag->term_id, $source_language);
                        }
                    }
                }
                if ($tag) {
                    // Get translations for this tag
                    $terms = function_exists('pll_get_term_translations') ? pll_get_term_translations($tag->term_id) : [];
                    $translations = [];
                    foreach ($langs as $lang) {
                        if ($lang === $source_language) {
                            continue;
                        }
                        $translated_term_id = $terms[$lang] ?? null;
                        $translation = $translated_term_id ? get_term($translated_term_id, 'post_tag') : null;
                        if ($translation) {
                            $translations[$lang] = [
                                'term_id' => $translation->term_id,
                                'name' => $translation->name,
                            ];
                        }
                    }
                    $tags_data[] = [
                        'term_id' => $tag->term_id,
                        'name' => $tag->name,
                        'translations' => $translations,
                    ];
                }
            }
        }

        // Get source language name
        $source_lang_name = '';
        if (function_exists('pll_languages_list')) {
            $lang_names = pll_languages_list(['fields' => 'name']);
        } else {
            $lang_names = ['Polish', 'English', 'Italian'];
        }
        $lang_index = array_search($source_language, $langs);
        if ($lang_index !== false) {
            $source_lang_name = $lang_names[$lang_index] ?? strtoupper($source_language);
        } else {
            $source_lang_name = strtoupper($source_language);
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig template handles escaping
        echo TemplateRenderer::render('admin/tag-translation/page.twig', [
            'tags' => $tags_data,
            'langs' => $langs,
            'source_language' => $source_language,
            'source_lang_name' => $source_lang_name,
            'can_manage_options' => current_user_can('manage_options'),
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }


    /**
     * AJAX handler for saving tag translation
     */
    public function ajax_save_tag_translation()
    {
        check_ajax_referer('polytrans_tag_translation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $tag_id = intval($_POST['tag_id'] ?? 0);
        // Use target_lang if available, fallback to lang for backward compatibility
        $lang = sanitize_text_field(wp_unslash($_POST['target_lang'] ?? $_POST['lang'] ?? ''));
        $translation_name = sanitize_text_field(wp_unslash($_POST['value'] ?? ''));

        if (!$tag_id || !$lang) {
            wp_send_json_error(['message' => __('Invalid parameters.', 'polytrans')]);
        }

        // Handle translation logic based on whether Polylang is available
        if (function_exists('pll_set_term_language') && function_exists('pll_save_term_translations')) {
            // Create or update translation using Polylang
            if (empty($translation_name)) {
                // Remove translation
                $translations = pll_get_term_translations($tag_id);
                unset($translations[$lang]);
                pll_save_term_translations($translations);
            } else {
                // Create/update translation
                $existing_term = $this->get_term_by_name_and_lang($translation_name, $lang);
                if ($existing_term) {
                    $translation_id = $existing_term->term_id;
                } else {
                    $new_term = wp_insert_term($translation_name, 'post_tag', [
                        'slug' => sanitize_title($translation_name) . '-' . $lang,
                        'name' => $translation_name
                    ]);
                    if (is_wp_error($new_term)) {
                        wp_send_json_error(['message' => $new_term->get_error_message()]);
                    }
                    $translation_id = $new_term['term_id'];
                }

                pll_set_term_language($translation_id, $lang);
                $translations = pll_get_term_translations($tag_id);
                $translations[$lang] = $translation_id;
                pll_save_term_translations($translations);
            }
        } else {
            // Fallback: just create the term if it doesn't exist
            if (!empty($translation_name)) {
                $existing_term = $this->get_term_by_name_and_lang($translation_name, $lang);
                if (!$existing_term) {
                    $existing_term = wp_insert_term($translation_name, 'post_tag', [
                        'slug' => sanitize_title($translation_name) . '-' . $lang,
                        'name' => $translation_name
                    ]);
                }
            }
        }

        wp_send_json_success(['message' => __('Translation saved successfully.', 'polytrans')]);
    }

    /**
     * AJAX handler for searching tags
     */
    public function ajax_search_tags()
    {
        check_ajax_referer('polytrans_tag_translation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $search = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));
        // Use target_lang if available, fallback to lang for backward compatibility
        $lang = sanitize_text_field(wp_unslash($_POST['target_lang'] ?? $_POST['lang'] ?? ''));

        if (strlen($search) < 2) {
            wp_send_json_success(['tags' => []]);
        }

        $args = [
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'search' => $search,
            'number' => 20,
        ];

        $terms = get_terms($args);
        $results = [];

        foreach ($terms as $term) {
            $results[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }

        wp_send_json_success(['tags' => $results]);
    }

    /**
     * AJAX handler for exporting tag translations as CSV
     */
    public function ajax_export_tag_csv()
    {
        \PolyTrans_Logs_Manager::log("Export CSV called", "info");

        // Check if user is logged in and has permissions
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            \PolyTrans_Logs_Manager::log("Export CSV: Unauthorized", "info");
            wp_die('Unauthorized', 403);
        }

        // Verify nonce if provided
        if (isset($_GET['nonce']) && !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'polytrans_tag_translation')) {
            \PolyTrans_Logs_Manager::log("Export CSV: Invalid nonce", "info");
            wp_die('Invalid nonce', 403);
        }

        // Get languages and settings
        if (function_exists('pll_languages_list')) {
            $langs = pll_languages_list(['fields' => 'slug']);
        } else {
            $langs = ['pl', 'en', 'it'];
        }

        $settings = get_option('polytrans_settings', []);
        $source_language = $settings['source_language'] ?? 'pl';


        // Get tag list from settings
        $tag_list_raw = $settings['base_tags'] ?? '';
        $tag_names = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $tag_list_raw)));

        if (empty($tag_names)) {
            \PolyTrans_Logs_Manager::log("Export CSV: No tags found", "info");
            wp_die('No tags found to export', 400);
        }

        \PolyTrans_Logs_Manager::log("Export CSV: Found ' . count($tag_names) . ' tags", "info");

        $csv_data = [];
        $csv_data[] = array_merge([strtoupper($source_language)], array_map('strtoupper', array_filter($langs, function ($lang) use ($source_language) {
            return $lang !== $source_language;
        })));

        foreach ($tag_names as $tag_name) {
            $tag = $this->get_term_by_name_and_lang($tag_name, $source_language);
            if ($tag) {
                $row = [$tag->name];
                foreach ($langs as $lang) {
                    if ($lang === $source_language) continue;
                    $translated_term_id = function_exists('pll_get_term') ? pll_get_term($tag->term_id, $lang) : null;
                    $translation = $translated_term_id ? get_term($translated_term_id) : null;
                    $row[] = $translation ? $translation->name : '';
                }
                $csv_data[] = $row;
            }
        }

        // Output CSV
        \PolyTrans_Logs_Manager::log("Export CSV: Outputting CSV with ' . count($csv_data) . ' rows", "info");
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tag-translations.csv"');
        $output = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct stream output required for CSV download
        foreach ($csv_data as $row) {
            fputcsv($output, $row); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Direct stream output required for CSV download
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct file operation required
        fclose($output);
        exit;
    }

    /**
     * AJAX handler for importing tag translations from CSV
     */
    public function ajax_import_tag_csv()
    {
        check_ajax_referer('polytrans_tag_translation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $csv_content = isset($_POST['csv']) ? wp_unslash($_POST['csv']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CSV content needs to preserve formatting
        if (empty($csv_content)) {
            wp_send_json_error(['message' => __('No CSV data provided.', 'polytrans')]);
        }

        // Get source language from settings
        $settings = get_option('polytrans_settings', []);
        $source_language = $settings['source_language'] ?? 'pl';

        // Use a more robust CSV parsing that handles newlines inside cells
        $temp_data = str_replace(["\r\n", "\r"], "\n", $csv_content);
        $temp_lines = explode("\n", $temp_data);

        foreach ($temp_lines as $i => $line) {
            $temp_lines[$i] = str_getcsv($line, ",", '"', "\\");
        }


        $csv_data = $temp_lines;

        if (empty($csv_data) || count($csv_data) < 2) {
            wp_send_json_error(['message' => __('Invalid CSV format or no data rows.', 'polytrans')]);
        }

        $header = $csv_data[0];
        $imported_count = 0;

        for ($i = 1; $i < count($csv_data); $i++) {
            $row = $csv_data[$i];
            if (empty($row[0])) continue;

            $source_tag_name = trim(stripslashes($row[0]), " \t\n\r\0\x0B\"");
            $tag = $this->get_term_by_name_and_lang($source_tag_name, $source_language);

            if (!$tag) {
                $new_tag = wp_insert_term($source_tag_name, 'post_tag', [
                    'slug' => sanitize_title($source_tag_name) . '-' . $source_language,
                    'name' => $source_tag_name
                ]);
                if (is_wp_error($new_tag)) {
                    continue;
                }
                $tag = get_term($new_tag['term_id']);
                if (function_exists('pll_set_term_language')) {
                    pll_set_term_language($tag->term_id, $source_language);
                }
            }
            $translations = pll_get_term_translations($tag->term_id);

            // Process translations
            for ($j = 1; $j < count($header) && $j < count($row); $j++) {
                $lang = strtolower(trim($header[$j]));
                $translation_name = str_replace('"', '', stripslashes($row[$j]));

                // Skip if empty translation or if this is the source language column (contains " tag" at the end)
                if (empty($translation_name) || preg_match('/\s+tag\s*$/i', $header[$j])) {
                    continue;
                }

                // Create or update translation
                if (function_exists('pll_set_term_language') && function_exists('pll_save_term_translations')) {
                    $existing_term = $this->get_term_by_name_and_lang($translation_name, $lang);
                    if ($existing_term) {
                        $translation_id = $existing_term->term_id;
                    } else {
                        $new_tag = wp_insert_term($translation_name, 'post_tag', [
                            'slug' => sanitize_title($translation_name) . '-' . $lang,
                            'name' => $translation_name
                        ]);
                        if (is_wp_error($new_tag)) {
                            $translation_id = $tag->term_id; // Use the source tag ID if translation creation fails
                        } else {
                            $translation_id = $new_tag['term_id'];
                            pll_set_term_language($translation_id, $lang);
                        }
                    }

                    $translations[$lang] = $translation_id;
                }
            }
            $saved = pll_save_term_translations($translations);
            $imported_count++;
        }

        /* translators: %d: number of imported translations */
        wp_send_json_success(['message' => sprintf(__('%d tag translations imported successfully.', 'polytrans'), $imported_count)]);
    }

    private function get_term_by_name_and_lang(string $tag_name, $lang = 'pl')
    {
        $terms = get_terms([
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
            'name'       => $tag_name,
            'lang'       => $lang
        ]);

        return !empty($terms) ? $terms[0] : null;
    }
}
