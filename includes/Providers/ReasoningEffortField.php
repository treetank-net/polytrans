<?php

namespace PolyTrans\Providers;

use PolyTrans\Core\ModelCapabilities;

/**
 * Site-wide "reasoning effort" field for provider settings tabs.
 *
 * Provider tabs let the admin pick a default model but historically exposed no
 * generation parameter at all. On reasoning models that leaves effort entirely to
 * the provider's own default. This field is the global counterpart to the
 * per-assistant and per-workflow-step controls.
 *
 * The markup is shared by every provider tab; which levels exist - and whether the
 * field applies at all - comes from ModelCapabilities for the selected model, and
 * is re-derived client-side whenever the model changes.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReasoningEffortField
{
    /**
     * Build the effort selector markup for a provider tab.
     *
     * Returned as a string so both the hand-written OpenAI tab and the Twig-driven
     * universal tab render the exact same field from one implementation.
     *
     * Emitted even when the current model is classic: the row is hidden rather
     * than absent, so the JS can reveal it after a switch to a reasoning model
     * without needing a page reload.
     *
     * @param string $provider_id     Provider ID.
     * @param string $selected_model  Currently selected default model.
     * @param string $selected_effort Currently stored effort (canonical or native).
     * @return string Escaped HTML.
     */
    public static function get_html($provider_id, $selected_model, $selected_effort)
    {
        $levels = $selected_model !== ''
            ? ModelCapabilities::get_effort_levels($provider_id, $selected_model)
            : [];
        $current = $selected_model !== ''
            ? ModelCapabilities::normalize_effort($provider_id, $selected_model, $selected_effort)
            : (is_string($selected_effort) ? $selected_effort : '');
        $capabilities = $selected_model !== ''
            ? ModelCapabilities::get_model_capabilities($provider_id, $selected_model)
            : [];
        $note = $capabilities['reasoning']['note'] ?? '';

        ob_start();
        ?>
        <div class="polytrans-reasoning-effort-section"
             data-provider="<?php echo esc_attr($provider_id); ?>"
             data-field="reasoning-effort-row"
             style="margin-top:2em;<?php echo empty($levels) ? 'display:none;' : ''; ?>">
            <h3><?php esc_html_e('Reasoning Effort', 'polytrans'); ?></h3>
            <select name="<?php echo esc_attr($provider_id); ?>_reasoning_effort"
                    id="<?php echo esc_attr($provider_id); ?>-reasoning-effort"
                    data-provider="<?php echo esc_attr($provider_id); ?>"
                    data-field="reasoning-effort"
                    style="max-width:300px;">
                <option value=""><?php esc_html_e('Provider default', 'polytrans'); ?></option>
                <?php foreach ($levels as $level) : ?>
                    <option value="<?php echo esc_attr($level['value']); ?>"
                        <?php selected($current, $level['value']); ?>>
                        <?php echo esc_html($level['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br>
            <?php
            $base_description = __(
                'How hard reasoning models should think by default. Translated to the provider-native parameter. Overridden per assistant and per workflow step.',
                'polytrans'
            );
            ?>
            <small data-provider="<?php echo esc_attr($provider_id); ?>"
                   data-field="reasoning-effort-description"
                   data-base-description="<?php echo esc_attr($base_description); ?>">
                <?php
                echo esc_html($base_description);
                echo $note !== '' ? ' ' . esc_html($note) : '';
                ?>
            </small>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Sanitize a posted effort value against the model it will be used with.
     *
     * Stored canonically so the choice survives a later model or provider switch;
     * an unsupported level snaps to the nearest one rather than being dropped.
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model the value will apply to.
     * @param mixed  $value       Posted value.
     * @return string Canonical level, or '' to defer to the provider default.
     */
    public static function sanitize($provider_id, $model_id, $value)
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $value = sanitize_text_field(trim($value));

        // With no model chosen there is nothing to validate against; keep the raw
        // value so the setting is not silently lost before a model is picked.
        if (!is_string($model_id) || $model_id === '') {
            return $value;
        }

        $canonical = ModelCapabilities::normalize_effort($provider_id, $model_id, $value);

        return $canonical ?? '';
    }
}
