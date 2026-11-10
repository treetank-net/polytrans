<?php
/**
 * Sets up a background translation the way trans.info runs one, for translate.php.
 * Run with `wp eval-file` after Polylang is active; never shipped.
 *
 * Two Polylang languages (en, de), a source post in English, a managed assistant that
 * translates en → de, internal transport (BackgroundProcessor loopback), and a workflow
 * triggered on translation completion whose one AI step writes a post meta. OpenAI is
 * answered by the stub in smoke-observer.php, which only answers requests carrying
 * TREETANK_SMOKE_STUB_KEY, so this is the only place that turns it on.
 *
 * Prints: <source post id>
 */

wp_set_current_user(1);
// Polylang sends the first admin page after activation to its setup wizard.
delete_transient('pll_activation_redirect');

$languages = [
    ['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => false, 'term_group' => 0, 'flag' => 'us'],
    ['name' => 'Deutsch', 'slug' => 'de', 'locale' => 'de_DE', 'rtl' => false, 'term_group' => 1, 'flag' => 'de'],
];
// Polylang 3.5 can add languages only through the admin model, which it does not
// load under WP-CLI; one instance, so the default language set by the first one sticks.
$admin_model = null;
if (!isset(PLL()->model->languages) || !method_exists(PLL()->model->languages, 'add')) {
    $admin_model = new PLL_Admin_Model(PLL()->options);
    $admin_model->set_languages_ready();
}
foreach ($languages as $language) {
    $added = $admin_model
        ? $admin_model->add_language($language)                     // Polylang 3.5
        : PLL()->model->languages->add($language);                  // Polylang 3.7+
    if (is_wp_error($added)) {
        fwrite(STDERR, "add language {$language['slug']}: " . $added->get_error_message() . "\n");
        exit(1);
    }
}
if ($admin_model) {
    // The running model holds a copy of the options from before the default language.
    PLL()->model->options = get_option('polylang');
}
PLL()->model->clean_languages_cache();

$post_id = wp_insert_post([
    'post_title' => 'Smoke source post',
    'post_content' => '<p>Hello from the smoke test.</p>',
    'post_excerpt' => 'Smoke excerpt',
    'post_status' => 'publish',
    'post_author' => 1,
]);
if (is_wp_error($post_id) || !$post_id) {
    fwrite(STDERR, "wp_insert_post failed\n");
    exit(1);
}
pll_set_post_language($post_id, 'en');

$assistant_id = \PolyTrans\Assistants\AssistantManager::create_assistant([
    'name' => 'Smoke translator',
    'provider' => 'openai',
    'status' => 'active',
    'system_prompt' => 'SMOKE-TRANSLATE: translate from {{ source_language }} to {{ target_language }}.',
    'user_message_template' => "Title: {{ translated.title }}\nContent: {{ translated.content }}",
    'api_parameters' => '{"model":"gpt-4o-mini"}',
    'expected_format' => 'json',
    'expected_output_schema' => '{"title":"string","content":"string","excerpt":"string"}',
]);
if (is_wp_error($assistant_id)) {
    fwrite(STDERR, 'create_assistant: ' . $assistant_id->get_error_message() . ' ' . wp_json_encode($assistant_id->get_error_data()) . "\n");
    exit(1);
}

$settings = get_option('polytrans_settings', []);
$settings = array_merge($settings, [
    'translation_transport_mode' => 'internal',
    'translation_provider' => 'openai',
    'enabled_translation_providers' => ['openai'],
    'openai_api_key' => TREETANK_SMOKE_STUB_KEY,
    'openai_model' => 'gpt-4o-mini',
    'allowed_sources' => ['en'],
    'allowed_targets' => ['de'],
    'assistants_mapping' => ['en_to_de' => 'managed_' . $assistant_id],
    'translation_path_rules' => [],
    'notification_timing' => 'after_workflows',
]);
update_option('polytrans_settings', $settings);

$storage = \PolyTrans_Workflow_Manager::get_instance()->get_storage_manager();
$result = $storage->save_workflow([
    'id' => 'smoke_after_translation',
    'name' => 'Smoke after translation',
    'language' => 'de',
    'enabled' => true,
    'triggers' => ['on_translation_complete' => true, 'manual_only' => false],
    'steps' => [[
        'id' => 'step_1',
        'type' => 'ai_assistant',
        'name' => 'Smoke step',
        'enabled' => true,
        'provider' => 'openai',
        'system_prompt' => 'SMOKE-WORKFLOW: reply with a fixed marker.',
        'user_message' => 'Mark the translated post.',
        'expected_format' => 'text',
        'output_actions' => [[
            'type' => 'update_post_meta',
            'source_variable' => '',
            'target' => 'smoke_workflow_marker',
        ]],
    ]],
]);
if (empty($result['success'])) {
    fwrite(STDERR, 'save_workflow: ' . wp_json_encode($result) . "\n");
    exit(1);
}

echo $post_id;
