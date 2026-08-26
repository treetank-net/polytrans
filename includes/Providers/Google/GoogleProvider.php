<?php

namespace PolyTrans\Providers\Google;

use PolyTrans\Providers\TranslationProviderInterface;
use PolyTrans\Core\LogsManager;
use PolyTrans\Core\Diagnostics;

/**
 * Google Translate Provider
 * Implements Google Translate integration following the provider interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class GoogleProvider implements TranslationProviderInterface
{
    /**
     * Get the provider identifier
     */
    public function get_id()
    {
        return 'google';
    }

    /**
     * Get the provider display name
     */
    public function get_name()
    {
        return __('Google Translate', 'treetank-trans');
    }

    /**
     * Get the provider description
     */
    public function get_description()
    {
        return __('Simple, fast translation using Google Translate public API. No API key required.', 'treetank-trans');
    }

    /**
     * Check if the provider is properly configured
     */
    public function is_configured(array $settings)
    {
        // Google Translate uses the public API, so it's always available
        return true;
    }

    /**
     * Translate content using Google Translate
     */
    public function translate(array $content, string $source_lang, string $target_lang, array $settings)
    {
        LogsManager::log("Google Translate: translating from $source_lang to $target_lang", "info");

        try {
            $translated = $this->deep_translate($content, $source_lang, $target_lang);

            return [
                'success' => true,
                'translated_content' => $translated,
                'error' => null
            ];
        } catch (\Exception $e) {
            Diagnostics::log("Google Translate error: " . $e->getMessage());
            return [
                'success' => false,
                'translated_content' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get supported languages
     */
    public function get_supported_languages()
    {
        return [
            'af' => 'Afrikaans',
            'sq' => 'Albanian',
            'ar' => 'Arabic',
            'az' => 'Azerbaijani',
            'eu' => 'Basque',
            'bn' => 'Bengali',
            'be' => 'Belarusian',
            'bg' => 'Bulgarian',
            'ca' => 'Catalan',
            'zh-cn' => 'Chinese (Simplified)',
            'zh-tw' => 'Chinese (Traditional)',
            'hr' => 'Croatian',
            'cs' => 'Czech',
            'da' => 'Danish',
            'nl' => 'Dutch',
            'en' => 'English',
            'eo' => 'Esperanto',
            'et' => 'Estonian',
            'tl' => 'Filipino',
            'fi' => 'Finnish',
            'fr' => 'French',
            'gl' => 'Galician',
            'ka' => 'Georgian',
            'de' => 'German',
            'el' => 'Greek',
            'gu' => 'Gujarati',
            'ht' => 'Haitian Creole',
            'iw' => 'Hebrew',
            'hi' => 'Hindi',
            'hu' => 'Hungarian',
            'is' => 'Icelandic',
            'id' => 'Indonesian',
            'ga' => 'Irish',
            'it' => 'Italian',
            'ja' => 'Japanese',
            'kn' => 'Kannada',
            'ko' => 'Korean',
            'la' => 'Latin',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'mk' => 'Macedonian',
            'ms' => 'Malay',
            'mt' => 'Maltese',
            'no' => 'Norwegian',
            'fa' => 'Persian',
            'pl' => 'Polish',
            'pt' => 'Portuguese',
            'ro' => 'Romanian',
            'ru' => 'Russian',
            'sr' => 'Serbian',
            'sk' => 'Slovak',
            'sl' => 'Slovenian',
            'es' => 'Spanish',
            'sw' => 'Swahili',
            'sv' => 'Swedish',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'th' => 'Thai',
            'tr' => 'Turkish',
            'uk' => 'Ukrainian',
            'ur' => 'Urdu',
            'vi' => 'Vietnamese',
            'cy' => 'Welsh',
            'yi' => 'Yiddish'
        ];
    }

    /**
     * Get the settings provider class name for this translation provider
     */
    public function get_settings_provider_class()
    {
        return \PolyTrans\Providers\Google\GoogleSettingsProvider::class;
    }

    /**
     * Recursively translate all string values in the array
     */
    private function deep_translate($data, $source_lang, $target_lang)
    {
        if (is_array($data)) {
            $result = [];

            // Try to translate the whole array as JSON first
            $json_result = $this->google_translate(json_encode($data), $source_lang, $target_lang);
            if (is_string($json_result)) {
                $text_result = $json_result;
                $decoded_result = json_decode($json_result, true);
                if ($decoded_result !== null) {
                    return $decoded_result; // Return the translated array
                } else {
                    Diagnostics::log("Failed to decode JSON from Google Translate response: " . json_last_error_msg() . "\n" . $text_result);
                }
            }

            // Fallback: translate each item individually
            foreach ($data as $key => $value) {
                $result[$key] = $this->deep_translate($value, $source_lang, $target_lang);
            }
            return $result;
        } elseif (is_string($data)) {
            $translated = $this->google_translate($data, $source_lang, $target_lang);
            if (is_string($translated)) {
                return $translated; // Return the translated string
            }
            return $data;
        } else {
            return $data;
        }
    }

    /**
     * Use Google Translate API (public endpoint)
     */
    private function google_translate($text, $source_lang, $target_lang)
    {
        // Skip empty strings
        if (empty(trim($text))) {
            return $text;
        }

        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' .
            urlencode($source_lang) . '&tl=' . urlencode($target_lang) .
            '&dt=t&q=' . urlencode($text);

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);

        if (is_wp_error($response)) {
            Diagnostics::log("Google Translate API error: " . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if (is_array($json) && isset($json[0][0][0])) {
            return $json[0][0][0];
        }

        LogsManager::log("Google Translate API fallback for '$text'", "info");
        return null;
    }
}
