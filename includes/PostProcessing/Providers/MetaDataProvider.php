<?php

namespace PolyTrans\PostProcessing\Providers;

use PolyTrans\PostProcessing\VariableProviderInterface;

/**
 * Meta Data Provider
 * 
 * Provides post metadata and custom fields to workflow execution context.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MetaDataProvider implements VariableProviderInterface
{
    /**
     * Get the provider identifier
     */
    public function get_provider_id()
    {
        return 'meta_data';
    }

    /**
     * Get the provider name
     */
    public function get_provider_name()
    {
        return __('Meta Data Provider', 'treetank-trans');
    }

    /**
     * Get variables provided by this provider
     */
    public function get_variables($context)
    {
        $variables = [];

        // Get original post meta
        if (isset($context['original_post_id'])) {
            $original_meta = $this->get_post_meta($context['original_post_id']);
            $variables['original_meta'] = $original_meta;
        }

        // Get translated post meta
        if (isset($context['translated_post_id'])) {
            $translated_meta = $this->get_post_meta($context['translated_post_id']);
            $variables['translated_meta'] = $translated_meta;
        }

        return $variables;
    }

    /**
     * Get list of variable names this provider can supply
     */
    public function get_available_variables()
    {
        return [
            'original_meta',
            'translated_meta',
            'original_meta.custom_field_name',
            'translated_meta.custom_field_name'
        ];
    }

    /**
     * Check if provider can supply variables for given context
     */
    public function can_provide($context)
    {
        return isset($context['original_post_id']) || isset($context['translated_post_id']);
    }

    /**
     * Get variable documentation for UI display
     */
    public function get_variable_documentation()
    {
        return [
            'original_meta' => [
                'description' => __('All custom fields from original post', 'treetank-trans'),
                'example' => '{original_meta.seo_title}'
            ],
            'translated_meta' => [
                'description' => __('All custom fields from translated post', 'treetank-trans'),
                'example' => '{translated_meta.seo_description}'
            ],
            'original_meta.custom_field' => [
                'description' => __('Specific custom field from original post', 'treetank-trans'),
                'example' => '{original_meta.your_custom_field_name}'
            ],
            'translated_meta.custom_field' => [
                'description' => __('Specific custom field from translated post', 'treetank-trans'),
                'example' => '{translated_meta.your_custom_field_name}'
            ]
        ];
    }

    /**
     * Get formatted post meta data
     * 
     * @param int $post_id Post ID
     * @return array Formatted meta data
     */
    private function get_post_meta($post_id)
    {
        $meta_data = get_post_meta($post_id);
        $formatted_meta = [];

        foreach ($meta_data as $key => $value) {
            // Skip private fields (starting with _) unless they are commonly used
            if (strpos($key, '_') === 0) {
                // Include some commonly used private fields
                $allowed_private_fields = [
                    '_yoast_wpseo_title',
                    '_yoast_wpseo_metadesc',
                    '_yoast_wpseo_focuskw',
                    '_thumbnail_id',
                    '_wp_page_template',
                    '_edit_lock',
                    '_edit_last'
                ];

                if (!in_array($key, $allowed_private_fields)) {
                    continue;
                }
            }

            // Handle serialized data
            if (is_array($value) && count($value) === 1) {
                $value = $value[0];
            }

            // Try to unserialize if it's serialized
            if (is_string($value) && is_serialized($value)) {
                $unserialized = maybe_unserialize($value);
                if ($unserialized !== false) {
                    $value = $unserialized;
                }
            }

            $formatted_meta[$key] = $value;
        }

        // Add some derived meta information
        $formatted_meta['_post_meta_count'] = count($formatted_meta);
        $formatted_meta['_has_custom_fields'] = !empty($formatted_meta);

        return $formatted_meta;
    }
}
