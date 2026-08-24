<?php

namespace PolyTrans\Receiver\Managers;

use PolyTrans\Core\Diagnostics;

/**
 * Handles validation of translation requests.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RequestValidator
{
    /**
     * Validates the incoming translation request parameters.
     * 
     * @param array $params Request parameters
     * @return true|WP_Error True if valid, WP_Error if invalid
     */
    public function validate(array $params)
    {
        $translated = isset($params['translated']) ? $params['translated'] : [];
        $source_language = isset($params['source_language']) ? $params['source_language'] : '';
        $target_language = isset($params['target_language']) ? $params['target_language'] : '';
        $original_post_id = isset($params['original_post_id']) ? $params['original_post_id'] : 0;

        if (!$translated || !$target_language || !$original_post_id) {
            Diagnostics::log('Missing data in translation request: ' . json_encode($params));
            return new \WP_Error('missing_data', 'Missing required translation data');
        }

        // Note: We no longer require original_post_id to exist locally.
        // This supports architectures where source and receiver have separate databases.
        // Managers will check for post existence before updating meta.

        if (!$this->is_valid_language_code($source_language) || !$this->is_valid_language_code($target_language)) {
            return new \WP_Error('invalid_language', 'Invalid language code provided');
        }

        if (!$this->has_required_translation_fields($translated)) {
            return new \WP_Error('invalid_translation', 'Translation data missing required fields');
        }

        return true;
    }

    /**
     * Validates if a language code is valid.
     * 
     * @param string $language_code Language code to validate
     * @return bool True if valid language code
     */
    private function is_valid_language_code($language_code)
    {
        // Basic validation - language codes should be 2-5 characters, letters and hyphens only
        return !empty($language_code) && preg_match('/^[a-z]{2,3}(-[a-z]{2,3})?$/i', $language_code);
    }

    /**
     * Checks if translation data has required fields.
     * 
     * @param array $translated Translation data
     * @return bool True if has required fields
     */
    private function has_required_translation_fields(array $translated)
    {
        // At minimum, we need a title or content
        return !empty($translated['title']) || !empty($translated['content']);
    }
}
