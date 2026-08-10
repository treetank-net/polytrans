<?php
/**
 * Legacy Autoloader for WordPress-style class names
 * 
 * Temporary autoloader that loads old WordPress-style classes
 * until we migrate them all to PSR-4 namespaces.
 * 
 * This file will be removed once all classes are migrated.
 * 
 * @package PolyTrans
 * @since 1.4.0
 */

namespace PolyTrans;

if (!defined('ABSPATH')) {
    exit;
}

class LegacyAutoloader
{
    /**
     * Class name to file path mapping
     * 
     * Maps WordPress-style class names to their file paths.
     * As we migrate classes, we remove them from this array.
     */
    private static $classMap = [
        // ✅ ALL MIGRATED TO PSR-4!
        // 'PolyTrans_Twig_Engine' => 'templating/class-twig-template-engine.php', // ✅ MIGRATED
        // 'PolyTrans_Workflow_Debug' => 'debug/class-workflow-debug.php', // ✅ MIGRATED
        
        // Provider system - ✅ MIGRATED TO PSR-4!
        // 'PolyTrans_Provider_Registry' => 'providers/class-provider-registry.php', // ✅ MIGRATED
        
        // Core - ✅ FULLY MIGRATED TO PSR-4!
        // 'PolyTrans_Translation_Meta_Box' => 'core/class-translation-meta-box.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Notifications' => 'core/class-translation-notifications.php', // ✅ MIGRATED
        // 'PolyTrans_User_Autocomplete' => 'core/class-user-autocomplete.php', // ✅ MIGRATED
        // 'PolyTrans_Post_Autocomplete' => 'core/class-post-autocomplete.php', // ✅ MIGRATED
        // 'PolyTrans_Logs_Manager' => 'core/class-logs-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Extension' => 'core/class-translation-extension.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Settings' => 'core/class-translation-settings.php', // ✅ MIGRATED
        // 'polytrans_settings' => 'core/class-translation-settings.php', // ✅ MIGRATED (lowercase class name)
        
        // Scheduler - ✅ FULLY MIGRATED TO PSR-4!
        // 'PolyTrans_Translation_Scheduler' => 'scheduler/class-translation-scheduler.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Handler' => 'scheduler/class-translation-handler.php', // ✅ MIGRATED
        
        // Receiver - ✅ FULLY MIGRATED TO PSR-4!
        // 'PolyTrans_Translation_Request_Validator' => 'receiver/managers/class-translation-request-validator.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Post_Creator' => 'receiver/managers/class-translation-post-creator.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Metadata_Manager' => 'receiver/managers/class-translation-metadata-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Media_Manager' => 'receiver/managers/class-translation-media-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Taxonomy_Manager' => 'receiver/managers/class-translation-taxonomy-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Language_Manager' => 'receiver/managers/class-translation-language-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Notification_Manager' => 'receiver/managers/class-translation-notification-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Status_Manager' => 'receiver/managers/class-translation-status-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Security_Manager' => 'receiver/managers/class-translation-security-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Coordinator' => 'receiver/class-translation-coordinator.php', // ✅ MIGRATED
        // 'PolyTrans_Translation_Receiver_Extension' => 'receiver/class-translation-receiver-extension.php', // ✅ MIGRATED
        
        // Assistants - ✅ FULLY MIGRATED TO PSR-4!
        // 'PolyTrans_Assistant_Manager' => 'assistants/class-assistant-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Assistant_Executor' => 'assistants/class-assistant-executor.php', // ✅ MIGRATED
        // 'PolyTrans_Assistant_Migration_Manager' => 'assistants/class-assistant-migration-manager.php', // ✅ MIGRATED
        
        // Menu - ✅ FULLY MIGRATED TO PSR-4!
        // 'PolyTrans_Settings_Menu' => 'menu/class-settings-menu.php', // ✅ MIGRATED
        // 'PolyTrans_Logs_Menu' => 'menu/class-logs-menu.php', // ✅ MIGRATED
        // 'PolyTrans_Tag_Translation' => 'menu/class-tag-translation.php', // ✅ MIGRATED
        // 'PolyTrans_Postprocessing_Menu' => 'menu/class-postprocessing-menu.php', // ✅ MIGRATED
        // 'PolyTrans_Assistants_Menu' => 'menu/class-assistants-menu.php', // ✅ MIGRATED
        
        // Post-processing - ✅ FULLY MIGRATED TO PSR-4!
        // 'PolyTrans_Variable_Manager' => 'postprocessing/class-variable-manager.php', // ✅ MIGRATED
        // 'PolyTrans_JSON_Response_Parser' => 'postprocessing/class-json-response-parser.php', // ✅ MIGRATED
        // 'PolyTrans_Workflow_Executor' => 'postprocessing/class-workflow-executor.php', // ✅ MIGRATED
        // 'PolyTrans_Workflow_Output_Processor' => 'postprocessing/class-workflow-output-processor.php', // ✅ MIGRATED
        // 'PolyTrans_Workflow_Storage_Manager' => 'postprocessing/managers/class-workflow-storage-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Post_Data_Provider' => 'postprocessing/providers/class-post-data-provider.php', // ✅ MIGRATED
        // 'PolyTrans_Meta_Data_Provider' => 'postprocessing/providers/class-meta-data-provider.php', // ✅ MIGRATED
        // 'PolyTrans_Context_Data_Provider' => 'postprocessing/providers/class-context-data-provider.php', // ✅ MIGRATED
        // 'PolyTrans_Articles_Data_Provider' => 'postprocessing/providers/class-articles-data-provider.php', // ✅ MIGRATED
        // 'PolyTrans_AI_Assistant_Step' => 'postprocessing/steps/class-ai-assistant-step.php', // ✅ MIGRATED
        // 'PolyTrans_Predefined_Assistant_Step' => 'postprocessing/steps/class-predefined-assistant-step.php', // ✅ MIGRATED
        // 'PolyTrans_Managed_Assistant_Step' => 'postprocessing/steps/class-managed-assistant-step.php', // ✅ MIGRATED
        // 'PolyTrans_Workflow_Manager' => 'postprocessing/class-workflow-manager.php', // ✅ MIGRATED
        // 'PolyTrans_Workflow_Metabox' => 'postprocessing/class-workflow-metabox.php', // ✅ MIGRATED
        
        // Providers - ✅ FULLY MIGRATED TO PSR-4!
        // 'PolyTrans_Google_Provider' => 'providers/google/class-google-provider.php', // ✅ MIGRATED
        // 'PolyTrans_OpenAI_Client' => 'providers/openai/class-openai-client.php', // ✅ MIGRATED
        // 'PolyTrans_OpenAI_Provider' => 'providers/openai/class-openai-provider.php', // ✅ MIGRATED
        // 'PolyTrans_OpenAI_Settings_Provider' => 'providers/openai/class-openai-settings-provider.php', // ✅ MIGRATED
    ];

    /**
     * Register the autoloader
     */
    public static function register()
    {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    /**
     * Autoload a class
     * 
     * @param string $className The class name to load
     */
    public static function autoload($className)
    {
        // Check if we have a mapping for this class
        if (!isset(self::$classMap[$className])) {
            return;
        }

        $file = POLYTRANS_PLUGIN_DIR . 'includes/' . self::$classMap[$className];

        if (file_exists($file)) {
            require_once $file;
        }
    }

    /**
     * Get list of classes that still need migration
     * 
     * @return array
     */
    public static function getPendingMigrations()
    {
        return array_keys(self::$classMap);
    }

    /**
     * Remove a class from the map (after migration)
     * 
     * @param string $className
     */
    public static function markAsMigrated($className)
    {
        unset(self::$classMap[$className]);
    }
}
