<?php

namespace PolyTrans\Core;

/**
 * Translation Meta Box Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class TranslationMetaBox
{

    private static $instance = null;
    private $translation_fields;

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
        $this->translation_fields = [
            'translated_by_human' => __('Prepared by Human', 'polytrans'),
            'translated_by_machine' => __('Translation done by Machine', 'polytrans'),
        ];
    }

    /**
     * Render meta box
     */
    public function render($post)
    {
        wp_nonce_field('translation_meta_box', 'translation_meta_box_nonce');

        foreach ($this->translation_fields as $key => $field) {
            $value = get_post_meta($post->ID, $key, true) ?: false;
?>
            <label class="selectit">
                <input name="<?php echo esc_attr($key); ?>" type="checkbox" value="true" <?php checked(true, $value === "true"); ?>>
                <?php echo esc_html($field); ?>
            </label>
            <br>
<?php
        }
    }

    /**
     * Save meta box data
     */
    public function save($post_id)
    {
        if (!isset($_POST['translation_meta_box_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['translation_meta_box_nonce'])), 'translation_meta_box')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save translation fields
        foreach ($this->translation_fields as $key => $field) {
            $field_value = isset($_POST[$key]) ? "true" : "false"; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only checking isset(), value is hardcoded
            update_post_meta($post_id, $key, $field_value);
        }
    }
}
