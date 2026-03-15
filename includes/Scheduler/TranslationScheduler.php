<?php

namespace PolyTrans\Scheduler;

/**
 * Translation Scheduler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class TranslationScheduler
{

    private static $instance = null;
    private $settings;
    private $langs;
    private $lang_names;

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
        $this->settings = get_option('polytrans_settings', []);
        $this->langs = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : ['pl', 'en', 'it'];
        $this->lang_names = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'name']) : ['Polish', 'English', 'Italian'];
    }

    public function enqueue_admin_scripts($hook)
    {
        if (in_array($hook, ['post.php', 'post-new.php'])) {
            wp_enqueue_script('polytrans-scheduler', POLYTRANS_PLUGIN_URL . 'assets/js/scheduler/translation-scheduler.js', ['jquery'], POLYTRANS_VERSION, true);
            wp_enqueue_style('polytrans-scheduler', POLYTRANS_PLUGIN_URL . 'assets/css/scheduler/translation-scheduler.css', [], POLYTRANS_VERSION);

            $settings = get_option('polytrans_settings', []);
            $langs = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : ['pl', 'en', 'it'];
            $lang_names = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'name']) : ['Polish', 'English', 'Italian'];

            // Get custom field whitelist from settings (for ignoring background field changes)
            $field_whitelist = isset($settings['dirty_check_field_whitelist'])
                ? array_filter(array_map('trim', explode("\n", $settings['dirty_check_field_whitelist'])))
                : [];

            wp_localize_script('polytrans-scheduler', 'PolyTransScheduler', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'settings' => $settings,
                'langs' => $langs,
                'lang_names' => $lang_names,
                'postId' => get_the_ID(),
                'nonce' => wp_create_nonce('polytrans_schedule_translation'),
                'edit_url' => admin_url('post.php?post=__ID__&action=edit'),
                'fieldWhitelist' => $field_whitelist,
                'i18n' => [
                    'translating' => esc_html__('Translating...', 'polytrans'),
                    'translation_started' => esc_html__('Translation started!', 'polytrans'),
                    'translation_scheduled' => esc_html__('Translation scheduled successfully.', 'polytrans'),
                    'translation_completed' => esc_html__('Translation completed!', 'polytrans'),
                    'translation_failed' => esc_html__('Translation failed. Please try again.', 'polytrans'),
                    'error_occurred' => esc_html__('An error occurred. Please try again.', 'polytrans'),
                    'loading' => esc_html__('Loading...', 'polytrans'),
                    'please_wait' => esc_html__('Please wait...', 'polytrans'),
                    'confirm_clear' => esc_html__('Are you sure you want to clear this translation?', 'polytrans'),
                    'confirm_retry' => esc_html__('This will restart the translation process. Continue?', 'polytrans'),
                    'clearing' => esc_html__('Clearing...', 'polytrans'),
                    'cleared' => esc_html__('Translation cleared.', 'polytrans'),
                    'retrying' => esc_html__('Retrying translation...', 'polytrans'),
                    'retry_started' => esc_html__('Translation restarted successfully.', 'polytrans'),
                    'select_languages' => esc_html__('Please select at least one target language.', 'polytrans'),
                    'select_languages_add_more' => esc_html__('Please select at least one language to add.', 'polytrans'),
                    'add_more_started' => esc_html__('Additional translations started!', 'polytrans'),
                    'save_post_first' => esc_html__('Please save the post before scheduling translations.', 'polytrans'),
                    'connection_error' => esc_html__('Connection error. Please check your settings.', 'polytrans'),
                    'processing' => esc_html__('Processing...', 'polytrans'),
                    'edit_translation' => esc_html__('Edit Translation', 'polytrans'),
                    'view_translation' => esc_html__('View Translation', 'polytrans'),
                ]
            ]);
        }
    }

    /**
     * Render scheduler meta box
     */
    public function render($post)
    {
        $current_lang = function_exists('pll_get_post_language') ? pll_get_post_language($post->ID) : ($this->langs[0] ?? 'pl');
        $allowed_sources = $this->settings['allowed_sources'] ?? $this->langs;
        $allowed_targets = $this->settings['allowed_targets'] ?? $this->langs;
        $reviewer = $this->settings[$current_lang]['reviewer'] ?? 'none';

        // Use new per-language meta structure
        $langs_key = '_polytrans_translation_langs';
        $scheduled_langs = get_post_meta($post->ID, $langs_key, true);
        if (!is_array($scheduled_langs)) $scheduled_langs = [];

        // Block translation if this post is already a translation target
        $is_translation_target = get_post_meta($post->ID, 'polytrans_is_translation_target', true);
        if ($is_translation_target) {
            $original_id = get_post_meta($post->ID, 'polytrans_translation_source', true);
            $source_lang = get_post_meta($post->ID, 'polytrans_translation_lang', true);
            $original_url = $original_id ? get_edit_post_link($original_id, 'edit') : '';
            echo '<div style="margin-bottom:1em;">';
            echo '<p>';
            echo esc_html__('This post is already a translation', 'polytrans');
            if ($source_lang) {
                echo ' (' . esc_html($source_lang) . ')';
            }
            echo '. ';
            echo esc_html__('Original post', 'polytrans');
            if ($original_url) {
                echo ' <a href="' . esc_url($original_url) . '" target="_blank">' . esc_html__('here', 'polytrans') . '</a>';
            }
            echo '</p>';
            echo '<button type="button" class="button" id="polytrans-detach-translation" data-post-id="' . esc_attr($post->ID) . '" style="margin-top:0.5em;width:100%;color:#d63638;border-color:#d63638;">';
            echo '<span class="dashicons dashicons-editor-unlink" style="vertical-align:middle;"></span> ';
            echo esc_html__('Detach from source', 'polytrans');
            echo '</button>';
            echo '<p><small style="color:#666;">' . esc_html__('This will remove the translation link, allowing this post to be used as a source for new translations.', 'polytrans') . '</small></p>';
            echo '</div>';
            return;
        }
?>
        <div id="translation-scheduler-box">
            <div class="polytrans-controls">
                <label for="polytrans-scope"><strong><?php esc_html_e('Translation Scope', 'polytrans'); ?></strong></label><br>
                <select name="polytrans-scope" id="polytrans-scope" style="width:100%">
                    <option value="local"><?php esc_html_e('Local (no translation)', 'polytrans'); ?></option>
                    <option value="regional" <?php disabled(!in_array($current_lang, $allowed_sources)); ?>><?php esc_html_e('Regional', 'polytrans'); ?></option>
                    <option value="global" <?php disabled(!in_array($current_lang, $allowed_sources)); ?>><?php esc_html_e('Global', 'polytrans'); ?></option>
                </select>
                <div id="polytrans-scheduler-options" style="margin-top:1em;display:none;">
                    <div id="polytrans-target-langs-row" style="display:none;">
                        <label for="polytrans-target-langs"><strong><?php esc_html_e('Target Languages', 'polytrans'); ?></strong></label><br>
                        <select name="polytrans-target-langs[]" id="polytrans-target-langs" multiple style="width:100%">
                            <?php foreach ($this->langs as $i => $lang):
                                if ($lang === $current_lang || !in_array($lang, $allowed_targets)) continue; ?>
                                <option value="<?php echo esc_attr($lang); ?>"><?php echo esc_html($this->lang_names[$i] ?? strtoupper($lang)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small><?php esc_html_e('Hold Ctrl (Windows/Linux) or Cmd (Mac) to select multiple languages.', 'polytrans'); ?></small>
                    </div>
                    <div style="margin-top:1em;">
                        <label><input type="checkbox" id="polytrans-needs-review" name="polytrans-needs-review" <?php checked($reviewer !== 'none'); ?>> <?php esc_html_e('Needs review', 'polytrans'); ?></label>
                    </div>
                </div>
                <button type="button" class="button button-primary" id="polytrans-translate-btn" style="margin-top:1em;width:100%"><?php esc_html_e('Translate', 'polytrans'); ?></button>
            </div>
            <div id="polytrans-translate-status" style="margin-top:0.5em;">
                <ul id="polytrans-merged-list">
                    <?php foreach ($this->langs as $i => $lang):
                        if (!in_array($lang, $allowed_targets)) continue;
                        $lang_name = $this->lang_names[$i] ?? strtoupper($lang);
                        $is_scheduled = in_array($lang, $scheduled_langs, true);
                        $status_key = '_polytrans_translation_status_' . $lang;
                        $current_status = get_post_meta($post->ID, $status_key, true);
                        $is_started = $current_status === 'started';
                        $is_finished = $current_status === 'finished';

                        // Get edit URL for finished translations
                        $target_post_id_key = '_polytrans_translation_target_' . $lang;
                        $edit_post_id = get_post_meta($post->ID, $target_post_id_key, true);
                        $edit_url = $is_finished && $edit_post_id ? esc_url(str_replace('__ID__', $edit_post_id, admin_url('post.php?post=__ID__&action=edit'))) : '#';
                    ?>
                        <li id="polytrans-merged-<?php echo esc_attr($lang); ?>" style="display:<?php echo esc_attr(($is_scheduled && ($is_started || $is_finished)) ? 'flex' : 'none'); ?>;margin-bottom:0.5em;align-items:center;">
                            <span class="polytrans-loader" style="<?php echo esc_attr($is_started ? '' : 'display:none;'); ?>">
                                <span class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite;"></span>
                            </span>
                            <span class="polytrans-check" style="<?php echo esc_attr($is_finished ? '' : 'display:none;'); ?>">
                                <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                            </span>
                            <span class="polytrans-failed" style="display:none;">
                                <span class="dashicons dashicons-dismiss" style="color: #d63638;"></span>
                            </span>
                            <span class="polytrans-merged-label">
                                <strong><?php echo esc_html($lang_name); ?></strong>
                                <small></small>
                            </span>
                            <a href="<?php echo esc_url($edit_url); ?>" class="polytrans-edit-btn" target="_blank" style="<?php echo esc_attr($is_finished ? '' : 'display:none;'); ?>" title="<?php esc_attr_e('Edit translation', 'polytrans'); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </a>
                            <button type="button" class="polytrans-retry-translation" data-lang="<?php echo esc_attr($lang); ?>" data-post-id="<?php echo esc_attr($post->ID); ?>" title="<?php esc_attr_e('Retry translation', 'polytrans'); ?>">
                                <span class="dashicons dashicons-update"></span>
                            </button>
                            <button type="button" class="polytrans-clear-translation" data-lang="<?php echo esc_attr($lang); ?>" data-post-id="<?php echo esc_attr($post->ID); ?>" title="<?php esc_attr_e('Clear this translation', 'polytrans'); ?>">
                                <span class="dashicons dashicons-no"></span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Add More Languages Section -->
                <div id="polytrans-add-more-section" style="display:none;margin-top:1em;padding:1em;background:#f9f9f9;border-radius:4px;">
                    <label for="polytrans-add-more-langs"><strong><?php esc_html_e('Add More Languages', 'polytrans'); ?></strong></label>
                    <select id="polytrans-add-more-langs" multiple style="width:100%;margin-top:0.5em;">
                        <?php foreach ($this->langs as $i => $lang):
                            if ($lang === $current_lang || !in_array($lang, $allowed_targets)) continue;
                            $lang_name = $this->lang_names[$i] ?? strtoupper($lang);
                        ?>
                            <option value="<?php echo esc_attr($lang); ?>"><?php echo esc_html($lang_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display:block;margin-top:0.5em;"><?php esc_html_e('Hold Ctrl (Windows/Linux) or Cmd (Mac) to select multiple languages.', 'polytrans'); ?></small>
                    <div style="margin-top:0.5em;">
                        <label><input type="checkbox" id="polytrans-add-more-needs-review"> <?php esc_html_e('Needs review', 'polytrans'); ?></label>
                    </div>
                    <div style="margin-top:0.5em;display:flex;gap:0.5em;">
                        <button type="button" class="button button-primary" id="polytrans-add-more-submit" style="flex:1;"><?php esc_html_e('Start Translation', 'polytrans'); ?></button>
                        <button type="button" class="button" id="polytrans-add-more-cancel"><?php esc_html_e('Cancel', 'polytrans'); ?></button>
                    </div>
                </div>

                <button type="button" class="button button-secondary" id="polytrans-add-more-btn" style="width:100%;margin-top:0.5em;display:none;">
                    <span class="dashicons dashicons-plus-alt" style="vertical-align:middle;"></span>
                    <?php esc_html_e('Add More Languages', 'polytrans'); ?>
                </button>
            </div>
        </div>
        <?php
        // Enqueue styles for translation scheduler
        wp_enqueue_style(
            'polytrans-scheduler',
            plugin_dir_url(__FILE__) . '../../assets/css/scheduler/translation-scheduler.css',
            array(),
            '1.0.0'
        );
        ?>
<?php
    }
}
