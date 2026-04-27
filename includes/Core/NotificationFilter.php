<?php

namespace PolyTrans\Core;

/**
 * Filters email notifications based on user roles and email domains.
 * 
 * Prevents sending notifications to external users (e.g., guest authors)
 * who are not part of the internal team.
 * 
 * @package PolyTrans\Core
 * @since 1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class NotificationFilter
{
    /**
     * Check if a user should receive email notifications.
     * 
     * @param int|\WP_User $user User ID or WP_User object
     * @return bool True if user should receive notifications, false otherwise
     */
    public static function should_notify_user($user)
    {
        if (is_numeric($user)) {
            $user = get_user_by('id', $user);
        }

        if (!$user || !($user instanceof \WP_User)) {
            return false;
        }

        $settings = get_option('polytrans_settings', []);
        
        // If no domain filter configured, allow all (backward compatibility)
        $allowed_domains = $settings['notification_allowed_domains'] ?? '';
        
        if (empty($allowed_domains)) {
            return true;
        }

        // Normalize domains to array
        if (is_string($allowed_domains) || is_array($allowed_domains)) {
            $allowed_domains = self::sanitize_domains($allowed_domains);
        }
        
        if (empty($allowed_domains)) {
            return true; // Empty filter means allow all
        }

        // Check domain filter
        $user_email = $user->user_email;
        $user_domain = self::extract_domain($user_email);
        
        $domain_match = in_array($user_domain, $allowed_domains, true);
        
        if (!$domain_match) {
            LogsManager::log(
                "Notification blocked for user {$user->user_email} (ID: {$user->ID}): domain not in allowed list",
                "info",
                [
                    'user_domain' => $user_domain,
                    'allowed_domains' => $allowed_domains
                ]
            );
            return false;
        }

        // User passed the filter
        return true;
    }

    /**
     * Extract domain from email address.
     * 
     * @param string $email Email address
     * @return string Domain part (e.g., "example.com")
     */
    private static function extract_domain($email)
    {
        $parts = explode('@', $email);
        return isset($parts[1]) ? strtolower(trim($parts[1])) : '';
    }


    /**
     * Sanitize and validate domain list.
     * 
     * @param string|array $domains Comma-separated string or array of domains
     * @return array Array of sanitized domains
     */
    public static function sanitize_domains($domains)
    {
        if (is_string($domains)) {
            $domains = preg_split('/[,\s]+/', $domains, -1, PREG_SPLIT_NO_EMPTY);
        }
        
        if (!is_array($domains)) {
            return [];
        }
        
        $sanitized = [];
        foreach ($domains as $domain) {
            if (!is_scalar($domain)) {
                continue;
            }

            $domain = strtolower(trim((string) $domain));
            
            // Remove protocol if present
            $domain = preg_replace('#^https?://#i', '', $domain) ?? '';
            
            // Remove www. if present
            $domain = preg_replace('#^www\.#i', '', $domain) ?? '';

            // Remove path/query/hash fragments if present.
            $domain = strtok($domain, '/?#') ?: '';
            
            // Basic validation: must contain at least one dot and no spaces
            if (!empty($domain) && strpos($domain, '.') !== false && strpos($domain, ' ') === false) {
                $sanitized[] = $domain;
            }
        }
        
        return array_values(array_unique($sanitized));
    }
}
