<?php
/**
 * Seeds an assistant and a workflow whose prompts are full of markup-like text, for the
 * prompt round-trip check in crawl.php. Run with `wp eval-file`; never shipped.
 *
 * Prompts legitimately carry XML-style tags (<prompt>, <assistant>, <schema>), ampersands,
 * both kinds of quotes and Twig variables. The admin screens are escaped with wp_kses();
 * this proves that escaping never reaches the prompt text itself.
 *
 * Also stores the prompt as a prompt-refinement template (a settings <textarea>) and as a
 * log message. Prints: <assistant id> <workflow id>
 */

$prompt = file_get_contents('/smoke-tools/prompt-fixture.txt');

wp_set_current_user(1);

$assistant_id = \PolyTrans\Assistants\AssistantManager::create_assistant([
    'name' => 'Smoke prompt round-trip',
    'provider' => 'openai',
    'status' => 'active',
    'system_prompt' => $prompt,
    'user_message_template' => $prompt,
    'api_parameters' => '{"model":"gpt-4o-mini"}',
    'expected_format' => 'text',
]);
if (is_wp_error($assistant_id)) {
    fwrite(STDERR, 'create_assistant: ' . $assistant_id->get_error_message() . ' ' . wp_json_encode($assistant_id->get_error_data()) . "\n");
    exit(1);
}

$storage = \PolyTrans_Workflow_Manager::get_instance()->get_storage_manager();
$result = $storage->save_workflow([
    'id' => 'smoke_prompt_roundtrip',
    'name' => 'Smoke prompt round-trip',
    'language' => '',
    'enabled' => false,
    'steps' => [[
        'id' => 'step_1',
        'type' => 'ai_assistant',
        'name' => 'Smoke step',
        'system_prompt' => $prompt,
        'user_message' => $prompt,
    ]],
]);
if (empty($result['success'])) {
    fwrite(STDERR, 'save_workflow: ' . wp_json_encode($result) . "\n");
    exit(1);
}

// HTML contexts, not JSON: a settings <textarea> and the logs table.
$settings = get_option('polytrans_settings', []);
$settings['prompt_refinement_assistant_evaluator_template'] = $prompt;
update_option('polytrans_settings', $settings);
\PolyTrans\Core\LogsManager::log($prompt, 'info', ['source' => 'smoke']);

echo $assistant_id . ' smoke_prompt_roundtrip';
