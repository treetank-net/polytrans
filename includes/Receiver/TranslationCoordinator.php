<?php

namespace PolyTrans\Receiver;

use PolyTrans\Core\LogsManager;

/**
 * Coordinates the translation process by orchestrating all translation managers.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TranslationCoordinator
{
    private $validator;
    private $post_creator;
    private $metadata_manager;
    private $taxonomy_manager;
    private $language_manager;
    private $notification_manager;
    private $status_manager;
    private $media_manager;

    public function __construct()
    {
        $this->validator = new Managers\RequestValidator();
        $this->post_creator = new Managers\PostCreator();
        $this->metadata_manager = new Managers\MetadataManager();
        $this->taxonomy_manager = new Managers\TaxonomyManager();
        $this->language_manager = new Managers\LanguageManager();
        $this->notification_manager = new Managers\NotificationManager();
        $this->status_manager = new Managers\StatusManager();
        $this->media_manager = new Managers\MediaManager();
    }

    /**
     * Processes a complete translation request.
     * 
     * @param array $params Translation request parameters
     * @return array Result with success status and data
     */
    public function process_translation(array $params)
    {
        try {
            // Validate the request
            $validation_result = $this->validator->validate($params);
            if (is_wp_error($validation_result)) {
                return [
                    'success' => false,
                    'error' => $validation_result->get_error_message(),
                    'code' => $validation_result->get_error_code()
                ];
            }

            $translated = $params['translated'];
            $source_language = $params['source_language'];
            $target_language = $params['target_language'];
            $original_post_id = $params['original_post_id'];

            // Check if this is an ephemeral post (e.g., for after_workflows dispatch mode)
            // Ephemeral posts skip notifications and status updates since they'll be deleted
            $is_ephemeral = $params['ephemeral'] ?? false;

            // Create the translated post
            $new_post_id = $this->post_creator->create_post($translated, $original_post_id);
            if (is_wp_error($new_post_id)) {
                if (!$is_ephemeral) {
                    $this->status_manager->mark_as_failed($original_post_id, $target_language, $new_post_id->get_error_message());
                }
                return [
                    'success' => false,
                    'error' => 'Could not create translated post: ' . $new_post_id->get_error_message()
                ];
            }


            // Setup all post properties and relationships
            // For ephemeral posts, skip relationship setup (receiver will handle final relationships)
            $this->setup_translated_post($new_post_id, $original_post_id, $source_language, $target_language, $translated, $is_ephemeral);

            // Set slug AFTER all setup — wp_update_post calls in setup_translated_post clear post_name
            if (!empty($translated['slug'])) {
                global $wpdb;
                $sanitized_slug = sanitize_title($translated['slug']);
                $wpdb->update($wpdb->posts, ['post_name' => $sanitized_slug], ['ID' => $new_post_id]);
                clean_post_cache($new_post_id);
                LogsManager::log("Set translated slug to '{$sanitized_slug}' for post {$new_post_id}", "info", [
                    'source' => 'translation_coordinator',
                ]);
            }

            $final_status = 'completed';

            // Skip notifications and status updates for ephemeral posts
            if (!$is_ephemeral) {
                // Check notification timing setting
                $settings = get_option('polytrans_settings', []);
                $notification_timing = $settings['notification_timing'] ?? 'after_workflows';

                if ($notification_timing === 'immediate') {
                    // Send notifications immediately (before workflows)
                    $final_status = $this->notification_manager->handle_notifications(
                        $new_post_id,
                        $original_post_id,
                        $target_language
                    );
                    LogsManager::log("Sent immediate notifications for post $new_post_id", "info");
                }
                // If 'after_workflows', notifications will be sent by TranslationReceiverExtension

                // Note: We do NOT update status to 'completed' here anymore.
                // Status will be updated by TranslationReceiverExtension AFTER workflows complete.
                // This allows the UI to show "post_processing" status while workflows run.
            } else {
                LogsManager::log("Skipping notifications and status updates for ephemeral post $new_post_id", "info");
            }

            LogsManager::log("Translation processing completed successfully for post $new_post_id", "info");

            return [
                'success' => true,
                'created_post_id' => $new_post_id,
                'status' => $final_status
            ];
        } catch (\Exception $e) {
            $error_message = 'Translation processing failed: ' . $e->getMessage();
            LogsManager::log("$error_message", "info");

            if (isset($original_post_id) && isset($target_language)) {
                $this->status_manager->mark_as_failed($original_post_id, $target_language, $error_message);
            }

            return [
                'success' => false,
                'error' => $error_message
            ];
        }
    }

    /**
     * Sets up all properties for the translated post.
     *
     * @param int $new_post_id New translated post ID
     * @param int $original_post_id Original post ID
     * @param string $source_language Source language code
     * @param string $target_language Target language code
     * @param array $translated Translated content data
     * @param bool $is_ephemeral Whether this is an ephemeral post (skip relationship setup)
     */
    private function setup_translated_post(
        $new_post_id,
        $original_post_id,
        $source_language,
        $target_language,
        $translated,
        $is_ephemeral = false
    ) {
        // Setup metadata (translation markers and copied meta)
        $this->metadata_manager->setup_metadata($new_post_id, $original_post_id, $source_language, $translated);

        // Setup taxonomies (categories and tags)
        $this->taxonomy_manager->setup_taxonomies($new_post_id, $original_post_id, $target_language);

        // Setup language and status
        // For ephemeral posts, only set language but skip relationships (receiver handles final setup)
        $this->language_manager->setup_language_and_status($new_post_id, $original_post_id, $target_language, $is_ephemeral);

        // Setup featured image with translated metadata
        $featured_image_data = $translated['featured_image'] ?? null;
        if ($featured_image_data) {
            // Handle legacy format: if featured_image is a string (ID), convert to array format
            if (is_string($featured_image_data) || is_numeric($featured_image_data)) {
                $attachment_id = (int) $featured_image_data;
                $attachment = get_post($attachment_id);
                if ($attachment) {
                    $featured_image_data = [
                        'id' => $attachment_id,
                        'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
                        'title' => $attachment->post_title,
                        'caption' => $attachment->post_excerpt,
                        'description' => $attachment->post_content,
                        'filename' => basename(get_attached_file($attachment_id))
                    ];
                } else {
                    LogsManager::log("Featured image ID $attachment_id not found, skipping", "warning", [
                        'post_id' => $new_post_id,
                        'attachment_id' => $attachment_id
                    ]);
                    $featured_image_data = null;
                }
            }
            
            if ($featured_image_data && isset($featured_image_data['id'])) {
                $this->media_manager->setup_featured_image(
                    $new_post_id,
                    $original_post_id,
                    $target_language,
                    $featured_image_data
                );
            } else {
                LogsManager::log("Invalid featured image data format for post $new_post_id", "warning", [
                    'featured_image_data' => $featured_image_data
                ]);
            }
        } else {
            LogsManager::log("No featured image data provided for post $new_post_id", "info", $translated);
        }
    }

    /**
     * Gets the status manager for external access.
     * 
     * @return PolyTrans_Translation_Status_Manager
     */
    public function get_status_manager()
    {
        return $this->status_manager;
    }
}
