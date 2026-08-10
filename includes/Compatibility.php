<?php
/**
 * PolyTrans Backward Compatibility
 * 
 * Maintains backward compatibility by aliasing old WordPress-style class names
 * to new PSR-4 namespaced classes.
 * 
 * This file will be populated gradually as we migrate classes to PSR-4.
 * 
 * @package PolyTrans
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aliases for Backward Compatibility
 * 
 * Format: class_alias('New\Namespaced\Class', 'Old_WordPress_Style_Class');
 * 
 * These aliases allow existing code to continue using old class names
 * while we gradually migrate to PSR-4 namespaced classes.
 */

// ============================================================================
// TEMPLATING & DEBUG
// ============================================================================
class_alias('PolyTrans\Templating\TwigEngine', 'PolyTrans_Twig_Engine');
if (file_exists(__DIR__ . '/Debug/WorkflowDebug.php')) {
    class_alias('PolyTrans\Debug\WorkflowDebug', 'PolyTrans_Workflow_Debug');
}

// ============================================================================
// INTERFACES
// ============================================================================
class_alias('PolyTrans\Providers\TranslationProviderInterface', 'PolyTrans_Translation_Provider_Interface');
class_alias('PolyTrans\Providers\SettingsProviderInterface', 'PolyTrans_Settings_Provider_Interface');
class_alias('PolyTrans\PostProcessing\WorkflowStepInterface', 'PolyTrans_Workflow_Step_Interface');
class_alias('PolyTrans\PostProcessing\VariableProviderInterface', 'PolyTrans_Variable_Provider_Interface');

// ============================================================================
// ASSISTANTS MODULE
// ============================================================================
class_alias('PolyTrans\Assistants\AssistantManager', 'PolyTrans_Assistant_Manager');
class_alias('PolyTrans\Assistants\AssistantExecutor', 'PolyTrans_Assistant_Executor');
class_alias('PolyTrans\Assistants\AssistantMigration', 'PolyTrans_Assistant_Migration_Manager');

// ============================================================================
// POSTPROCESSING STEPS
// ============================================================================
class_alias('PolyTrans\PostProcessing\Steps\ManagedAssistantStep', 'PolyTrans_Managed_Assistant_Step');
class_alias('PolyTrans\PostProcessing\Steps\AiAssistantStep', 'PolyTrans_AI_Assistant_Step');
class_alias('PolyTrans\PostProcessing\Steps\PredefinedAssistantStep', 'PolyTrans_Predefined_Assistant_Step');

// ============================================================================
// MENU MODULE
// ============================================================================
class_alias('PolyTrans\Menu\SettingsMenu', 'PolyTrans_Settings_Menu');
class_alias('PolyTrans\Menu\LogsMenu', 'PolyTrans_Logs_Menu');
class_alias('PolyTrans\Menu\TagTranslation', 'PolyTrans_Tag_Translation');
class_alias('PolyTrans\Menu\PostprocessingMenu', 'PolyTrans_Postprocessing_Menu');
class_alias('PolyTrans\Menu\AssistantsMenu', 'PolyTrans_Assistants_Menu');

// ============================================================================
// PROVIDERS MODULE
// ============================================================================
class_alias('PolyTrans\Providers\ProviderRegistry', 'PolyTrans_Provider_Registry');
if (file_exists(__DIR__ . '/Providers/Google/GoogleProvider.php')) {
    class_alias('PolyTrans\Providers\Google\GoogleProvider', 'PolyTrans_Google_Provider');
}
class_alias('PolyTrans\Providers\OpenAI\OpenAIClient', 'PolyTrans_OpenAI_Client');
class_alias('PolyTrans\Providers\OpenAI\OpenAIProvider', 'PolyTrans_OpenAI_Provider');
class_alias('PolyTrans\Providers\OpenAI\OpenAISettingsProvider', 'PolyTrans_OpenAI_Settings_Provider');
class_alias('PolyTrans\Providers\Claude\ClaudeProvider', 'PolyTrans_Claude_Provider');
class_alias('PolyTrans\Providers\Claude\ClaudeSettingsProvider', 'PolyTrans_Claude_Settings_Provider');
// OpenAISettingsUI removed - replaced by OpenAISettingsProvider

// ============================================================================
// SCHEDULER MODULE
// ============================================================================
class_alias('PolyTrans\Scheduler\TranslationScheduler', 'PolyTrans_Translation_Scheduler');
class_alias('PolyTrans\Scheduler\TranslationHandler', 'PolyTrans_Translation_Handler');

// ============================================================================
// CORE MODULE
// ============================================================================
class_alias('PolyTrans\Core\LogsManager', 'PolyTrans_Logs_Manager');
class_alias('PolyTrans\Core\BackgroundProcessor', 'PolyTrans_Background_Processor');
class_alias('PolyTrans\Core\TranslationSettings', 'polytrans_settings'); // lowercase!
class_alias('PolyTrans\Core\TranslationSettings', 'PolyTrans_Translation_Settings');
class_alias('PolyTrans\Core\TranslationExtension', 'PolyTrans_Translation_Extension');
class_alias('PolyTrans\Core\TranslationMetaBox', 'PolyTrans_Translation_Meta_Box');
class_alias('PolyTrans\Core\TranslationNotifications', 'PolyTrans_Translation_Notifications');
class_alias('PolyTrans\Core\UserAutocomplete', 'PolyTrans_User_Autocomplete');
class_alias('PolyTrans\Core\PostAutocomplete', 'PolyTrans_Post_Autocomplete');
class_alias('PolyTrans\Core\NotificationFilter', 'PolyTrans_Notification_Filter');

// ============================================================================
// RECEIVER MODULE
// ============================================================================
class_alias('PolyTrans\Receiver\TranslationCoordinator', 'PolyTrans_Translation_Coordinator');
class_alias('PolyTrans\Receiver\TranslationReceiverExtension', 'PolyTrans_Translation_Receiver_Extension');
class_alias('PolyTrans\Receiver\Managers\LanguageManager', 'PolyTrans_Translation_Language_Manager');
class_alias('PolyTrans\Receiver\Managers\MediaManager', 'PolyTrans_Translation_Media_Manager');
class_alias('PolyTrans\Receiver\Managers\MetadataManager', 'PolyTrans_Translation_Metadata_Manager');
class_alias('PolyTrans\Receiver\Managers\NotificationManager', 'PolyTrans_Translation_Notification_Manager');
class_alias('PolyTrans\Receiver\Managers\PostCreator', 'PolyTrans_Translation_Post_Creator');
class_alias('PolyTrans\Receiver\Managers\RequestValidator', 'PolyTrans_Translation_Request_Validator');
class_alias('PolyTrans\Receiver\Managers\SecurityManager', 'PolyTrans_Translation_Security_Manager');
class_alias('PolyTrans\Receiver\Managers\StatusManager', 'PolyTrans_Translation_Status_Manager');
class_alias('PolyTrans\Receiver\Managers\TaxonomyManager', 'PolyTrans_Translation_Taxonomy_Manager');

// ============================================================================
// POSTPROCESSING MODULE
// ============================================================================
class_alias('PolyTrans\PostProcessing\VariableManager', 'PolyTrans_Variable_Manager');
class_alias('PolyTrans\PostProcessing\JsonResponseParser', 'PolyTrans_JSON_Response_Parser');
class_alias('PolyTrans\PostProcessing\WorkflowExecutor', 'PolyTrans_Workflow_Executor');
class_alias('PolyTrans\PostProcessing\WorkflowManager', 'PolyTrans_Workflow_Manager');
class_alias('PolyTrans\PostProcessing\WorkflowMetabox', 'PolyTrans_Workflow_Metabox');
class_alias('PolyTrans\PostProcessing\WorkflowOutputProcessor', 'PolyTrans_Workflow_Output_Processor');
class_alias('PolyTrans\PostProcessing\Managers\WorkflowStorageManager', 'PolyTrans_Workflow_Storage_Manager');
class_alias('PolyTrans\PostProcessing\Providers\PostDataProvider', 'PolyTrans_Post_Data_Provider');
class_alias('PolyTrans\PostProcessing\Providers\MetaDataProvider', 'PolyTrans_Meta_Data_Provider');
class_alias('PolyTrans\PostProcessing\Providers\ContextDataProvider', 'PolyTrans_Context_Data_Provider');
class_alias('PolyTrans\PostProcessing\Providers\ArticlesDataProvider', 'PolyTrans_Articles_Data_Provider');

// ============================================================================
// CORE MODULE
// ============================================================================
// class_alias('PolyTrans\Core\Settings\SettingsManager', 'PolyTrans_Translation_Settings');
// class_alias('PolyTrans\Core\Background\BackgroundProcessor', 'PolyTrans_Background_Processor');
// class_alias('PolyTrans\Core\TranslationMetaBox', 'PolyTrans_Translation_Meta_Box');
// class_alias('PolyTrans\Core\TranslationNotifications', 'PolyTrans_Translation_Notifications');
// class_alias('PolyTrans\Core\UserAutocomplete', 'PolyTrans_User_Autocomplete');
// class_alias('PolyTrans\Core\PostAutocomplete', 'PolyTrans_Post_Autocomplete');
// class_alias('PolyTrans\Core\LogsManager', 'PolyTrans_Logs_Manager');

// ============================================================================
// MENU MODULE
// ============================================================================
// class_alias('PolyTrans\Menu\SettingsMenu', 'PolyTrans_Settings_Menu');
// class_alias('PolyTrans\Menu\LogsMenu', 'PolyTrans_Logs_Menu');
// class_alias('PolyTrans\Menu\TagTranslation', 'PolyTrans_Tag_Translation');
// class_alias('PolyTrans\Menu\PostProcessing\PostProcessingMenu', 'PolyTrans_Postprocessing_Menu');
// class_alias('PolyTrans\Menu\Assistants\AssistantsMenu', 'PolyTrans_Assistants_Menu');

// ============================================================================
// POSTPROCESSING MODULE
// ============================================================================
// class_alias('PolyTrans\PostProcessing\Workflow\WorkflowManager', 'PolyTrans_Workflow_Manager');
// class_alias('PolyTrans\PostProcessing\Workflow\WorkflowExecutor', 'PolyTrans_Workflow_Executor');
// class_alias('PolyTrans\PostProcessing\Workflow\WorkflowStorage', 'PolyTrans_Workflow_Storage_Manager');
// class_alias('PolyTrans\PostProcessing\WorkflowMetabox', 'PolyTrans_Workflow_Metabox');
// class_alias('PolyTrans\PostProcessing\Variables\VariableManager', 'PolyTrans_Variable_Manager');
// class_alias('PolyTrans\PostProcessing\Parsers\JsonResponseParser', 'PolyTrans_JSON_Response_Parser');

// ============================================================================
// PROVIDERS MODULE
// ============================================================================
// class_alias('PolyTrans\Providers\ProviderRegistry', 'PolyTrans_Provider_Registry');
// class_alias('PolyTrans\Providers\OpenAI\OpenAIProvider', 'PolyTrans_OpenAI_Provider');
// class_alias('PolyTrans\Providers\OpenAI\OpenAIClient', 'PolyTrans_OpenAI_Client');
// class_alias('PolyTrans\Providers\OpenAI\Settings\OpenAISettingsProvider', 'PolyTrans_OpenAI_Settings_Provider');

// ============================================================================
// SCHEDULER MODULE
// ============================================================================
// class_alias('PolyTrans\Scheduler\TranslationScheduler', 'PolyTrans_Translation_Scheduler');
// class_alias('PolyTrans\Scheduler\TranslationHandler\TranslationHandler', 'PolyTrans_Translation_Handler');

// ============================================================================
// RECEIVER MODULE
// ============================================================================
// class_alias('PolyTrans\Receiver\TranslationCoordinator', 'PolyTrans_Translation_Coordinator');
// class_alias('PolyTrans\Receiver\TranslationReceiverExtension', 'PolyTrans_Translation_Receiver_Extension');
// class_alias('PolyTrans\Receiver\TranslationExtension', 'PolyTrans_Translation_Extension');

/**
 * Note: As we migrate each module, we'll uncomment the corresponding aliases.
 * This gradual approach ensures we don't break existing functionality.
 * 
 * Migration checklist:
 * 1. Create new namespaced class
 * 2. Copy functionality from old class
 * 3. Add tests
 * 4. Uncomment alias above
 * 5. Update internal references to use new class
 * 6. Verify all tests pass
 * 7. Remove old class file
 * 8. Keep alias for backward compatibility
 */
