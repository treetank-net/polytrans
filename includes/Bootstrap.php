<?php
/**
 * PolyTrans Bootstrap
 * 
 * Handles autoloading and initialization of the plugin.
 * This class sets up PSR-4 autoloading and backward compatibility.
 * 
 * @package PolyTrans
 * @since 1.4.0
 */

namespace PolyTrans;

if (!defined('ABSPATH')) {
    exit;
}

class Bootstrap
{
    /**
     * Initialize the plugin
     * 
     * Sets up autoloading and backward compatibility aliases
     */
    public static function init()
    {
        // Register Composer autoloader (includes Twig and our PSR-4 classes)
        self::registerComposerAutoloader();
        
        // Register legacy autoloader (temporary, until all classes are migrated)
        self::registerLegacyAutoloader();
        
        // Load interfaces BEFORE aliases (aliases might need them)
        self::loadInterfaces();
        
        // Register backward compatibility aliases
        self::registerCompatibilityAliases();
    }

    /**
     * Load interfaces that need to be available before class definitions
     * 
     * Note: Interfaces are now PSR-4 compliant and autoloaded by Composer.
     * This method is kept for backward compatibility but is no longer needed.
     */
    private static function loadInterfaces()
    {
        // Interfaces are now autoloaded via PSR-4 (PolyTrans\Providers\*, PolyTrans\PostProcessing\*)
        // No manual loading required!
    }

    /**
     * Register Composer autoloader
     */
    private static function registerComposerAutoloader()
    {
        $autoloadFile = POLYTRANS_PLUGIN_DIR . 'vendor/autoload.php';
        
        if (file_exists($autoloadFile)) {
            require_once $autoloadFile;
        } else {
            // Log error if autoloader is missing
            if (function_exists('error_log')) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                error_log('PolyTrans: Composer autoloader not found. Run "composer install".');
            }
        }
    }

    /**
     * Register legacy autoloader for WordPress-style class names
     * 
     * Temporary solution until all classes are migrated to PSR-4.
     * This autoloader will be removed once migration is complete.
     */
    private static function registerLegacyAutoloader()
    {
        require_once __DIR__ . '/LegacyAutoloader.php';
        LegacyAutoloader::register();
    }

    /**
     * Register backward compatibility class aliases
     * 
     * Maps old WordPress-style class names to new PSR-4 namespaced classes.
     * This ensures existing code continues to work during the migration.
     */
    private static function registerCompatibilityAliases()
    {
        $compatibilityFile = __DIR__ . '/Compatibility.php';
        
        if (file_exists($compatibilityFile)) {
            require_once $compatibilityFile;
        }
    }

    /**
     * Get the plugin version
     * 
     * @return string
     */
    public static function getVersion()
    {
        return defined('POLYTRANS_VERSION') ? POLYTRANS_VERSION : '1.4.0';
    }

    /**
     * Check if plugin is properly initialized
     * 
     * @return bool
     */
    public static function isInitialized()
    {
        return class_exists('Twig\Environment');
    }
}
