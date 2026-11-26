# Hooks and Filters - Complete Reference

All available WordPress hooks and filters for extending PolyTrans functionality.

## Overview

PolyTrans provides hooks and filters at key points in the translation and workflow process, allowing you to modify behavior, add custom logic, or integrate with other plugins.

---

## Actions

### `polytrans_register_providers`

Register custom translation providers.

**When:** During plugin initialization, before providers are loaded.

**Parameters:**
- `$registry` (ProviderRegistry) - The provider registry instance

**Example:**
```php
add_action('polytrans_register_providers', function($registry) {
    $registry->register_provider('my_provider', new MyCustomProvider());
});
```

**Location:** `includes/Providers/ProviderRegistry.php:62`

---

### `polytrans_translation_completed`

Fired after a translation is successfully completed and the translated post is created.

**When:** After translation post is created, before workflow execution.

**Parameters:**
- `$original_post_id` (int) - Original post ID
- `$translated_post_id` (int) - Created translated post ID
- `$target_language` (string) - Target language code

**Example:**
```php
add_action('polytrans_translation_completed', function($original_post_id, $translated_post_id, $target_language) {
    // Send notification
    wp_mail('admin@example.com', 'Translation Complete', "Post {$original_post_id} translated to {$target_language}");
    
    // Update custom tracking
    update_post_meta($translated_post_id, '_translation_completed_at', current_time('mysql'));
}, 10, 3);
```

**Location:** 
- `includes/Core/TranslationExtension.php:334`
- `includes/Core/BackgroundProcessor.php:626`
- `includes/Debug/WorkflowDebug.php:237`

---

### `polytrans_bg_process_{action}`

Fired during background processing for specific actions.

**When:** During background task processing.

**Parameters:**
- `$args` (array) - Action-specific arguments

**Available Actions:**
- `translate` - Translation processing
- `workflow` - Workflow execution

**Example:**
```php
add_action('polytrans_bg_process_translate', function($args) {
    $post_id = $args['post_id'] ?? 0;
    $target_lang = $args['target_language'] ?? '';
    
    // Custom logging
    error_log("Translating post {$post_id} to {$target_lang}");
}, 10, 1);
```

**Location:** `includes/Core/BackgroundProcessor.php:260`

---

### `polytrans_template_render_{template}`

Fired before rendering a Twig template.

**When:** Before template is rendered by TemplateRenderer.

**Parameters:**
- `$template` (string) - Template name
- `$context` (array) - Template context variables

**Example:**
```php
add_action('polytrans_template_render_admin_settings_page', function($template, $context) {
    // Modify context before rendering
    $context['custom_data'] = get_option('my_custom_data');
}, 10, 2);
```

**Location:** `includes/Templating/TemplateRenderer.php:253`

---

### `polytrans_settings_save`
Fires when the PolyTrans settings form is saved, after `check_admin_referer('polytrans_settings')`
and after the PolyTrans option is written. An extension tab (`polytrans_settings_tabs`) reads and
sanitises its own `$_POST` keys here and stores them in its own option.

```php
add_action('polytrans_settings_save', function (array $settings) {
    $value = sanitize_text_field(wp_unslash($_POST['my_addon_field'] ?? ''));
    update_option('my_addon_settings', ['field' => $value]);
});
```

**Location:** `includes/Core/TranslationSettings.php` (`save_settings()`)

---

### `polytrans_register_data_providers`
Fires once while the workflow manager registers its built-in variable providers. Add your own
`VariableProviderInterface` implementation to expose more variables to workflow prompts.

```php
add_action('polytrans_register_data_providers', function ($manager) {
    $manager->register_data_provider(new My_Variable_Provider());
});
```

**Location:** `includes/PostProcessing/WorkflowManager.php`

---

## Filters

### `polytrans_translation_settings`
Settings for one translation, applied at the start of `TranslationPathExecutor::execute()`. Route a
post type or a language pair to another assistant (`assistants_mapping`, keys `<src>_to_<tgt>`) or
path (`translation_path_rules`) without changing the saved option.

```php
add_filter('polytrans_translation_settings', function ($settings, $source_lang, $target_lang, $context) {
    $post_id = (int) ($context['source_post_id'] ?? 0);
    if ($post_id && get_post_type($post_id) === 'my_type') {
        $settings['translation_path_rules'] = [];            // direct, no relay
        $settings['assistants_mapping']["{$source_lang}_to_{$target_lang}"] = 'managed_7';
    }
    return $settings;
}, 10, 4);
```

`$context` holds `source_post_id` and optionally `post_id` and `run_id`. A non-array return is ignored.

**Location:** `includes/Core/TranslationPathExecutor.php` (`execute()`)

---

### `polytrans_settings_tabs`
Adds tabs to the PolyTrans settings page. Each entry is
`['id' => 'my-addon', 'label' => 'My add-on', 'render' => callable($settings)]`. The callback
echoes form fields; they sit inside the PolyTrans form and are submitted with it, so save them in
`polytrans_settings_save`. The page is filtered by `wp_kses` with `AdminMarkup::allowed_html()`,
so no `<script>`/`<style>` — enqueue those on the settings screen instead.

**Location:** `includes/Core/TranslationSettings.php` (`extension_tabs()`)

---

### `polytrans_workflow_change`
The last word on a workflow output change before it is applied (or previewed in test mode).
Return the change, possibly modified, or `false` to skip it — e.g. to protect fields edited by
hand. Arguments: `$change` (`action`, `value`, optional `meta_key`/`option_name`), `$context`,
`$test_mode`.

**Location:** `includes/PostProcessing/WorkflowOutputProcessor.php` (`process_step_outputs()`)

Output action `update_post_meta_object` writes every scalar key of an object (or JSON string) as
post meta — one action for a reply such as `{"meta": {...}}` whose keys differ between posts.

---

### `polytrans_register_providers`

Filter the provider registry (alternative to action).

**When:** During plugin initialization.

**Parameters:**
- `$providers` (array) - Array of registered providers

**Returns:** Modified array of providers

**Example:**
```php
add_filter('polytrans_register_providers', function($providers) {
    $providers['my_provider'] = new MyCustomProvider();
    return $providers;
});
```

**Location:** `includes/Providers/ProviderRegistry.php:62`

---

### `polytrans_chat_client_factory_create`

Create a custom chat client for a provider.

**When:** When ChatClientFactory needs to create a client for a provider.

**Parameters:**
- `$client` (ChatClientInterface|null) - Existing client or null
- `$provider_id` (string) - Provider ID
- `$settings` (array) - Provider settings

**Returns:** ChatClientInterface instance or null to use default

**Example:**
```php
add_filter('polytrans_chat_client_factory_create', function($client, $provider_id, $settings) {
    if ($provider_id === 'my_provider') {
        require_once __DIR__ . '/includes/MyChatClient.php';
        return new MyChatClient($settings['api_key']);
    }
    return $client;
}, 10, 3);
```

**Location:** `includes/Core/ChatClientFactory.php:31`

---

### `polytrans_assistant_client_factory_create`

Create a custom assistant client for an assistant ID.

**When:** When AIAssistantClientFactory needs to create a client for an assistant.

**Parameters:**
- `$client` (AIAssistantClientInterface|null) - Existing client or null
- `$assistant_id` (string) - Assistant ID (e.g., `asst_xxx`, `project_xxx`)
- `$settings` (array) - Provider settings

**Returns:** AIAssistantClientInterface instance or null to use default

**Example:**
```php
add_filter('polytrans_assistant_client_factory_create', function($client, $assistant_id, $settings) {
    // Detect custom assistant ID format
    if (strpos($assistant_id, 'my_provider_') === 0) {
        require_once __DIR__ . '/includes/MyAssistantClient.php';
        return new MyAssistantClient($settings['api_key']);
    }
    return $client;
}, 10, 3);
```

**Location:** `includes/Core/AIAssistantClientFactory.php:30`

---

### `polytrans_assistant_client_factory_get_provider_id`

Get the provider ID for an assistant ID.

**When:** When AIAssistantClientFactory needs to determine provider from assistant ID.

**Parameters:**
- `$provider_id` (string|null) - Existing provider ID or null
- `$assistant_id` (string) - Assistant ID

**Returns:** Provider ID string or null to use default detection

**Example:**
```php
add_filter('polytrans_assistant_client_factory_get_provider_id', function($provider_id, $assistant_id) {
    // Custom detection logic
    if (strpos($assistant_id, 'my_provider_') === 0) {
        return 'my_provider';
    }
    return $provider_id;
}, 10, 2);
```

**Location:** `includes/Core/AIAssistantClientFactory.php:71`

---

### `polytrans_validate_api_key_{provider_id}`

Validate API key for a specific provider.

**When:** When validating provider API keys in settings.

**Parameters:**
- `$is_valid` (bool) - Current validation result
- `$api_key` (string) - API key to validate

**Returns:** Boolean indicating if API key is valid

**Example:**
```php
add_filter('polytrans_validate_api_key_my_provider', function($is_valid, $api_key) {
    // Custom validation logic
    if (empty($api_key)) {
        return false;
    }
    
    // Make API call to validate
    $response = wp_remote_get('https://api.myprovider.com/validate', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key]
    ]);
    
    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
}, 10, 2);
```

**Location:** `includes/Core/TranslationSettings.php:150`

**Note:** Replace `{provider_id}` with your actual provider ID (e.g., `my_provider`).

---

## Hook Priority

Default priority is `10`. Use lower priorities (e.g., `5`) to run before default handlers, or higher priorities (e.g., `20`) to run after.

**Example:**
```php
// Run before default handler
add_filter('polytrans_chat_client_factory_create', function($client, $provider_id, $settings) {
    // Custom logic
    return $client;
}, 5, 3); // Priority 5

// Run after default handler
add_action('polytrans_translation_completed', function($original_post_id, $translated_post_id, $target_language) {
    // Custom logic
}, 20, 3); // Priority 20
```

---

## Common Use Cases

### Custom Provider Registration

```php
add_action('polytrans_register_providers', function($registry) {
    require_once __DIR__ . '/includes/MyProvider.php';
    $registry->register_provider('my_provider', new MyProvider());
});
```

### Translation Completion Notifications

```php
add_action('polytrans_translation_completed', function($original_post_id, $translated_post_id, $target_language) {
    // Send Slack notification
    wp_remote_post('https://hooks.slack.com/services/YOUR/WEBHOOK/URL', [
        'body' => json_encode([
            'text' => "Translation complete: Post {$original_post_id} → {$translated_post_id} ({$target_language})"
        ])
    ]);
}, 10, 3);
```

### Custom Chat Client

```php
add_filter('polytrans_chat_client_factory_create', function($client, $provider_id, $settings) {
    if ($provider_id === 'custom_provider') {
        return new CustomChatClient($settings['api_key'], $settings['endpoint']);
    }
    return $client;
}, 10, 3);
```

### API Key Validation

```php
add_filter('polytrans_validate_api_key_custom_provider', function($is_valid, $api_key) {
    // Validate against your API
    $response = wp_remote_get('https://api.example.com/validate', [
        'headers' => ['X-API-Key' => $api_key]
    ]);
    
    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
}, 10, 2);
```

---

## Best Practices

1. **Check hook availability** before using:
   ```php
   if (has_action('polytrans_translation_completed')) {
       // Hook exists
   }
   ```

2. **Use appropriate priorities** - Lower = earlier, Higher = later

3. **Validate parameters** before using:
   ```php
   add_action('polytrans_translation_completed', function($original_post_id, $translated_post_id, $target_language) {
       if (!$original_post_id || !$translated_post_id) {
           return; // Invalid parameters
       }
       // Your logic
   }, 10, 3);
   ```

4. **Log hook execution** for debugging:
   ```php
   add_action('polytrans_translation_completed', function($original_post_id, $translated_post_id, $target_language) {
       error_log("Translation completed: {$original_post_id} → {$translated_post_id}");
   });
   ```

5. **Don't break the chain** - Always return values from filters:
   ```php
   add_filter('polytrans_chat_client_factory_create', function($client, $provider_id, $settings) {
       if ($provider_id === 'my_provider') {
           return new MyClient();
       }
       return $client; // Important: return original if not handling
   }, 10, 3);
   ```

---

## Hook Reference Quick Sheet

```
ACTIONS:
- polytrans_register_providers($registry)
- polytrans_translation_completed($original_post_id, $translated_post_id, $target_language)
- polytrans_bg_process_{action}($args)
- polytrans_template_render_{template}($template, $context)
- polytrans_settings_save($settings)
- polytrans_register_data_providers($workflow_manager)

FILTERS:
- polytrans_register_providers($providers) → $providers
- polytrans_chat_client_factory_create($client, $provider_id, $settings) → $client
- polytrans_assistant_client_factory_create($client, $assistant_id, $settings) → $client
- polytrans_assistant_client_factory_get_provider_id($provider_id, $assistant_id) → $provider_id
- polytrans_validate_api_key_{provider_id}($is_valid, $api_key) → bool
- polytrans_translation_settings($settings, $source_lang, $target_lang, $context) → $settings
- polytrans_settings_tabs($tabs, $settings) → [['id', 'label', 'render'], …]
- polytrans_workflow_change($change, $context, $test_mode) → $change|false
```

---

## Troubleshooting

**Hook not firing:**
- Check hook name spelling
- Verify hook is registered (check source code)
- Ensure plugin is activated
- Check priority (may be running before/after your code)

**Filter not modifying value:**
- Ensure you return the modified value
- Check priority (may be overridden by later filter)
- Verify parameter count matches hook definition

**Provider not registering:**
- Use `polytrans_register_providers` action or filter
- Ensure provider implements correct interface
- Check provider is registered before it's needed

---

**See Also:**
- [Provider Extensibility Guide](PROVIDER_EXTENSIBILITY_GUIDE.md) - Adding custom providers
- [Architecture](ARCHITECTURE.md) - System design and extension points
- [API Documentation](API-DOCUMENTATION.md) - REST API hooks

