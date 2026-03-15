# PolyTrans Architecture

## Directory Structure (PSR-4 Compliant)

```
polytrans/
├── polytrans.php              # Main plugin file
├── includes/
│   ├── class-polytrans.php    # Core plugin class (legacy, no namespace)
│   ├── Bootstrap.php          # PSR-4 autoloader initialization
│   ├── Compatibility.php      # Backward-compatible class aliases
│   ├── LegacyAutoloader.php   # Temporary autoloader (will be removed)
│   ├── Assistants/            # ✅ PSR-4: PolyTrans\Assistants\
│   │   ├── AssistantManager.php
│   │   ├── AssistantExecutor.php
│   │   └── AssistantMigration.php
│   ├── Core/                  # ✅ PSR-4: PolyTrans\Core\
│   │   ├── BackgroundProcessor.php
│   │   ├── LogsManager.php
│   │   ├── TranslationSettings.php
│   │   ├── TranslationMetaBox.php
│   │   └── ...
│   ├── Debug/                 # ✅ PSR-4: PolyTrans\Debug\
│   │   ├── WorkflowDebug.php
│   │   └── WorkflowDebugMenu.php
│   ├── Menu/                  # ✅ PSR-4: PolyTrans\Menu\
│   │   ├── AssistantsMenu.php
│   │   ├── PostprocessingMenu.php
│   │   ├── SettingsMenu.php
│   │   └── ...
│   ├── PostProcessing/        # ✅ PSR-4: PolyTrans\PostProcessing\
│   │   ├── WorkflowManager.php
│   │   ├── WorkflowExecutor.php
│   │   ├── JsonResponseParser.php
│   │   ├── VariableManager.php
│   │   ├── WorkflowStepInterface.php
│   │   ├── VariableProviderInterface.php
│   │   ├── Managers/
│   │   │   └── WorkflowStorageManager.php
│   │   ├── Providers/
│   │   │   ├── PostDataProvider.php
│   │   │   ├── MetaDataProvider.php
│   │   │   └── ...
│   │   └── Steps/
│   │       ├── AiAssistantStep.php
│   │       ├── ManagedAssistantStep.php
│   │       └── PredefinedAssistantStep.php
│   ├── Providers/             # ✅ PSR-4: PolyTrans\Providers\
│   │   ├── TranslationProviderInterface.php
│   │   ├── SettingsProviderInterface.php
│   │   ├── ProviderRegistry.php
│   │   ├── Google/
│   │   │   └── GoogleProvider.php
│   │   └── OpenAI/
│   │       ├── OpenAIClient.php
│   │       ├── OpenAIProvider.php
│   │       └── OpenAISettingsProvider.php
│   ├── Receiver/              # ✅ PSR-4: PolyTrans\Receiver\
│   │   ├── TranslationCoordinator.php
│   │   ├── TranslationReceiverExtension.php
│   │   └── Managers/
│   │       ├── RequestValidator.php
│   │       ├── PostCreator.php
│   │       └── ...
│   ├── Scheduler/             # ✅ PSR-4: PolyTrans\Scheduler\
│   │   ├── TranslationScheduler.php
│   │   └── TranslationHandler.php
│   └── Templating/            # ✅ PSR-4: PolyTrans\Templating\
│       └── TwigEngine.php
├── assets/
│   ├── css/                   # Stylesheets
│   └── js/                    # JavaScript
├── tests/                     # Pest/PHPUnit tests
│   ├── Unit/                  # Unit tests
│   └── Architecture/          # Architecture tests
└── vendor/                    # Composer dependencies
```

**Note:** All classes use PSR-4 autoloading. Backward-compatible aliases (e.g., `PolyTrans_Workflow_Manager` → `PolyTrans\PostProcessing\WorkflowManager`) are provided in `Compatibility.php`.

## Core Components

### Translation Flow

1. **Scheduler** - User requests translation via meta box
2. **Background Processor** - Queues translation task
3. **Provider** - Sends content to translation service (Google/OpenAI)
4. **Receiver** - Processes translated content
5. **Managers** - Handle post creation, metadata, media, taxonomies
6. **Workflow** - Runs post-processing steps (optional)
7. **Notifications** - Notifies reviewers/users

### Provider System

**Interface:** `PolyTrans\Providers\TranslationProviderInterface` (alias: `PolyTrans_Translation_Provider_Interface`)

Providers must implement:
- `translate($content, $source_lang, $target_lang)`
- `get_supported_languages()`
- `get_settings_provider()` - Returns settings UI class

**Current Providers:**
- **OpenAI** (`PolyTrans\Providers\OpenAI\OpenAIProvider`) - Paid, high quality, context-aware, supports Managed Assistants

### Workflow System

**Interface:** `PolyTrans\PostProcessing\WorkflowStepInterface` (alias: `PolyTrans_Workflow_Step_Interface`)

**Step Types:**
- `managed_assistant` - Locally managed AI assistants (OpenAI/Claude/Gemini)
- `ai_assistant` - OpenAI Assistants API (legacy)
- `predefined_assistant` - Predefined OpenAI assistants
- `find_replace` - Text replacement
- `regex_replace` - Regex operations

**Workflow Execution:**
1. Load workflow configuration
2. Initialize context with post data
3. Execute steps sequentially
4. Each step can modify context
5. Output actions update post
6. Log results

**Output Actions:**
- `update_post_content`
- `update_post_meta`
- `update_post_excerpt`

### Background Processing

Uses `WP_Background_Process` pattern:
- Queues tasks in wp_options
- Processes asynchronously via cron or loopback
- Handles failures with retry logic
- Prevents timeouts for long operations

### Receiver Architecture

**Coordinator:** Routes translation to appropriate managers

**Managers:**
- **Request Validator** - Validates incoming data
- **Post Creator** - Creates translated posts
- **Metadata Manager** - Copies/translates metadata
- **Media Manager** - Handles featured images, duplicates media
- **Taxonomy Manager** - Maps categories/tags
- **Language Manager** - Sets Polylang relationships
- **Status Manager** - Updates translation status
- **Notification Manager** - Sends emails

Each manager has single responsibility, can be extended independently.

## PSR-4 Autoloading

### Namespace Structure

All classes follow PSR-4 standard with the base namespace `PolyTrans`:

```
PolyTrans\
├── Assistants\          # AI Assistants management
├── Core\                # WordPress integration
├── Debug\               # Debugging tools
├── Menu\                # Admin menus
├── PostProcessing\      # Workflow system
│   ├── Managers\
│   ├── Providers\
│   └── Steps\
├── Providers\           # Translation providers
│   ├── Google\
│   └── OpenAI\
├── Receiver\            # Translation processing
│   └── Managers\
├── Scheduler\           # Translation scheduling
└── Templating\          # Twig engine
```

### Backward Compatibility

For backward compatibility, class aliases are provided in `includes/Compatibility.php`:

```php
// Old style (still works)
$manager = new PolyTrans_Workflow_Manager();

// New style (PSR-4)
$manager = new \PolyTrans\PostProcessing\WorkflowManager();
```

### Autoloader Bootstrap

The plugin uses a three-tier autoloading system:

1. **Composer Autoloader** - Loads vendor dependencies (Twig, etc.)
2. **PSR-4 Autoloader** - Loads namespaced classes (`PolyTrans\*`)
3. **LegacyAutoloader** - Temporary loader for remaining non-namespaced classes (will be removed)

Initialization happens in `includes/Bootstrap.php`, called from `polytrans.php`.

## Key Classes

### `PolyTrans_Background_Processor`
- Handles async translation processing
- Spawns background processes
- Manages task queue
- Retry logic for failures

### `PolyTrans_Translation_Coordinator`
- Receives completed translations
- Orchestrates managers
- Error handling and rollback
- Status tracking

### `PolyTrans_Workflow_Manager`
- CRUD for workflows
- Workflow configuration storage
- Validation

### `PolyTrans_Workflow_Executor`
- Executes workflow steps
- Context management
- Output processing
- Error handling

## Database

### Custom Tables

**polytrans_logs:**
- Translation logs
- Workflow execution logs
- Error tracking

### Post Meta

- `_polytrans_translation_status` - Translation state
- `_polytrans_translation_type` - human/machine/original
- `_polytrans_source_post_id` - Link to original
- `_polytrans_workflow_{id}_status` - Workflow execution state

## Hooks

### Actions
```php
do_action('polytrans_before_translate', $post_id, $lang);
do_action('polytrans_translation_complete', $post_id, $lang);
do_action('polytrans_workflow_complete', $post_id, $workflow_id);
```

### Filters
```php
apply_filters('polytrans_pre_translate', $content, $lang);
apply_filters('polytrans_post_translate', $content, $lang);
apply_filters('polytrans_workflow_context', $context, $workflow);
```

## Extension Points

### Adding a Provider

```php
class Custom_Provider implements PolyTrans_Translation_Provider_Interface {
    public function translate($content, $source, $target) {
        // Implementation
    }
    
    public function get_supported_languages() {
        return ['en', 'es', 'fr'];
    }
}

add_filter('polytrans_register_providers', function($providers) {
    $providers['custom'] = new Custom_Provider();
    return $providers;
});
```

### Adding a Workflow Step

```php
add_filter('polytrans_workflow_steps', function($steps) {
    $steps['custom_step'] = [
        'execute' => function($context, $config) {
            // Process context
            return $context;
        }
    ];
    return $steps;
});
```

### Modifying Translation

```php
add_filter('polytrans_pre_translate', function($content, $lang) {
    // Modify before translation
    return $content;
}, 10, 2);

add_filter('polytrans_post_translate', function($translated, $lang) {
    // Modify after translation
    return $translated;
}, 10, 2);
```

## Performance Considerations

- Background processing prevents timeout
- Concurrent translation limit (default: 3)
- Caching for provider responses
- Batch operations for bulk translations
- Polylang integration for efficient language queries

## Security

- Nonce verification for all forms
- Capability checks (`manage_options`)
- Input sanitization
- Output escaping
- API authentication (bearer tokens)
- Rate limiting on API endpoints
