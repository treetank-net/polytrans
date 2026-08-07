<?php

/**
 * Assistant Manager - AI Assistants CRUD Management
 *
 * Manages AI assistant configurations with database storage.
 * Part of Phase 1: AI Assistants Management System
 *
 * @package PolyTrans
 * @subpackage Assistants
 * @since 1.4.0
 */

namespace PolyTrans\Assistants;

use PolyTrans\Providers\ProviderRegistry;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class AssistantManager
 *
 * Manages CRUD operations for AI assistants with validation,
 * usage tracking, and dropdown formatting for UI integration.
 */
class AssistantManager
{

	/**
	 * Valid provider names
	 * This is a fallback list - actual validation uses ProviderRegistry
	 *
	 * @var array
	 */
	private static $valid_providers = array('openai', 'claude', 'gemini');
	
	/**
	 * Get valid providers from registry (providers that support assistants)
	 *
	 * @return array Array of provider IDs
	 */
	private static function get_valid_providers_from_registry()
	{
		$valid_providers = array();
		
		try {
			$registry = ProviderRegistry::get_instance();
			$settings = get_option('polytrans_settings', []);
			$enabled_providers = $settings['enabled_translation_providers'] ?? [];
			$all_providers = $registry->get_providers();
			
			foreach ($all_providers as $provider_id => $provider) {
				// Check if provider is enabled
				if (!in_array($provider_id, $enabled_providers)) {
					continue;
				}
				
				// Check if provider supports chat or assistants via manifest
				// Managed assistants can use providers with 'chat' capability (via system prompt)
				// or providers with 'assistants' capability (via dedicated API)
				$settings_provider_class = $provider->get_settings_provider_class();
				if ($settings_provider_class && class_exists($settings_provider_class)) {
					$settings_provider = new $settings_provider_class();
					if (method_exists($settings_provider, 'get_provider_manifest')) {
						$manifest = $settings_provider->get_provider_manifest($settings);
						$capabilities = $manifest['capabilities'] ?? [];
						// Managed assistants can use 'chat' or 'assistants' capability
						if (in_array('chat', $capabilities) || in_array('assistants', $capabilities)) {
							$valid_providers[] = $provider_id;
						}
					}
				}
			}
		} catch (\Exception $e) {
			// Fallback to hardcoded list if registry fails
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log("[PolyTrans] Failed to get providers from registry: " . $e->getMessage());
		}
		
		// Fallback to hardcoded list if no providers found
		if (empty($valid_providers)) {
			$valid_providers = self::$valid_providers;
		}
		
		return $valid_providers;
	}

	/**
	 * Valid status values
	 *
	 * @var array
	 */
	private static $valid_statuses = array('active', 'inactive');

	/**
	 * Valid expected format values
	 *
	 * @var array
	 */
	private static $valid_formats = array('text', 'json');

	/**
	 * Create database table on plugin activation
	 *
	 * @return void
	 */
	public static function create_table()
	{
		global $wpdb;

		$table_name      = $wpdb->prefix . 'polytrans_assistants';
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin installation/upgrade
		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY,
			name varchar(255) NOT NULL,
			description text,
			provider varchar(50) DEFAULT 'openai',
			status varchar(20) DEFAULT 'active',

			system_prompt text NOT NULL,
			user_message_template text,

			api_parameters text NOT NULL,
			expected_format varchar(20) DEFAULT 'text',
			expected_output_schema text,
			output_variables text,

			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			created_by bigint(20) unsigned,

			KEY provider (provider),
			KEY status (status),
			KEY name (name)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	/**
	 * Validate assistant data
	 *
	 * @param array $data Assistant data to validate.
	 * @return array ['valid' => bool, 'errors' => array]
	 */
	public static function validate_assistant_data($data)
	{
		$errors = array();

		// Required: name
		if (empty($data['name']) || ! is_string($data['name'])) {
			$errors['name'] = __('Name is required', 'polytrans');
		}

		// Required: system_prompt
		if (empty($data['system_prompt']) || ! is_string($data['system_prompt'])) {
			$errors['system_prompt'] = __('System prompt is required', 'polytrans');
		}

		// Required: api_parameters (must be valid JSON)
		if (empty($data['api_parameters'])) {
			$errors['api_parameters'] = __('API parameters are required', 'polytrans');
		} else {
			// Validate JSON if it's a string
			if (is_string($data['api_parameters'])) {
				$decoded = json_decode($data['api_parameters'], true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					$errors['api_parameters'] = __('Invalid JSON in api_parameters', 'polytrans');
				}
			}
		}

		// Optional: provider (validate if present)
		if (isset($data['provider'])) {
			$valid_providers = self::get_valid_providers_from_registry();
			if (!in_array($data['provider'], $valid_providers, true)) {
					/* translators: %s: comma-separated list of valid provider names */
				$errors['provider'] = sprintf(__('Invalid provider. Allowed providers: %s', 'polytrans'), implode(', ', $valid_providers));
			}
		}

		// Optional: status (validate if present)
		if (isset($data['status']) && ! in_array($data['status'], self::$valid_statuses, true)) {
			$errors['status'] = __('Invalid status', 'polytrans');
		}

		// Optional: expected_format (validate if present)
		if (isset($data['expected_format']) && ! in_array($data['expected_format'], self::$valid_formats, true)) {
			$errors['expected_format'] = __('Invalid expected format', 'polytrans');
		}

		// Optional: output_variables (validate JSON if present and not null)
		if (isset($data['output_variables']) && ! is_null($data['output_variables'])) {
			if (is_string($data['output_variables'])) {
				$decoded = json_decode($data['output_variables'], true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					$errors['output_variables'] = __('Invalid JSON in output_variables', 'polytrans');
				}
			}
		}

		// Optional: expected_output_schema (validate JSON if present and not Twig template)
		if (isset($data['expected_output_schema']) && ! is_null($data['expected_output_schema'])) {
			if (is_string($data['expected_output_schema'])) {
				$schema_str = trim($data['expected_output_schema']);
				if ($schema_str !== '') {
					// Skip JSON validation if schema contains Twig syntax (dynamic template)
					$has_twig = strpos($schema_str, '{%') !== false || strpos($schema_str, '{{') !== false;
					if (! $has_twig) {
						$decoded = json_decode($schema_str, true);
						if (json_last_error() !== JSON_ERROR_NONE) {
							$errors['expected_output_schema'] = __('Invalid JSON in expected_output_schema', 'polytrans');
						} elseif (! is_array($decoded)) {
							$errors['expected_output_schema'] = __('Expected output schema must be a JSON object', 'polytrans');
						}
					}
				}
			}
		}

		return array(
			'valid'  => empty($errors),
			'errors' => $errors,
		);
	}

	/**
	 * Create a new assistant
	 *
	 * @param array $data Assistant data.
	 * @return int|WP_Error Assistant ID on success, WP_Error on failure.
	 */
	public static function create_assistant($data)
	{
		global $wpdb;

		// Validate data
		$validation = self::validate_assistant_data($data);
		if (! $validation['valid']) {
			return new \WP_Error('invalid_data', __('Validation failed', 'polytrans'), $validation['errors']);
		}

		// Sanitize data
		$sanitized = self::sanitize_assistant_data($data);

		// Prepare insert data
		$insert_data = array(
			'name'                  => $sanitized['name'],
			'description'           => $sanitized['description'],
			'provider'              => $sanitized['provider'],
			'status'                => $sanitized['status'],
			'system_prompt'         => $sanitized['system_prompt'],
			'user_message_template' => $sanitized['user_message_template'],
			'api_parameters'        => wp_json_encode($sanitized['api_parameters']),
			'expected_format'       => $sanitized['expected_format'],
			'expected_output_schema' => ! empty($sanitized['expected_output_schema'])
				? (is_string($sanitized['expected_output_schema']) ? $sanitized['expected_output_schema'] : wp_json_encode($sanitized['expected_output_schema']))
				: null,
			'output_variables'      => ! empty($sanitized['output_variables']) ? wp_json_encode($sanitized['output_variables']) : null,
			'created_at'            => current_time('mysql'),
			'updated_at'            => current_time('mysql'),
			'created_by'            => get_current_user_id(),
		);

		$table_name = $wpdb->prefix . 'polytrans_assistants';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result     = $wpdb->insert($table_name, $insert_data);

		if (false === $result) {
			return new \WP_Error('db_error', __('Failed to create assistant', 'polytrans'), $wpdb->last_error);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get assistant by ID
	 *
	 * @param int $id Assistant ID.
	 * @return array|null Assistant data or null if not found.
	 */
	public static function get_assistant($id)
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'polytrans_assistants';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
		$assistant  = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM `{$table_name}` WHERE id = %d", $id),
			ARRAY_A
		);

		if (! $assistant) {
			return null;
		}

		// Decode JSON fields
		$assistant['api_parameters']   = json_decode($assistant['api_parameters'], true);
		$assistant['output_variables'] = ! empty($assistant['output_variables']) ? json_decode($assistant['output_variables'], true) : null;

		// Schema: decode if JSON, keep as string if Twig template
		if (! empty($assistant['expected_output_schema'])) {
			$schema_str = $assistant['expected_output_schema'];
			$has_twig = strpos($schema_str, '{%') !== false || strpos($schema_str, '{{') !== false;
			if ($has_twig) {
				$assistant['expected_output_schema'] = $schema_str;
			} else {
				$decoded = json_decode($schema_str, true);
				$assistant['expected_output_schema'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $schema_str;
			}
		} else {
			$assistant['expected_output_schema'] = null;
		}

		return $assistant;
	}

	/**
	 * Update assistant
	 *
	 * @param int   $id   Assistant ID.
	 * @param array $data Updated data.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function update_assistant($id, $data)
	{
		global $wpdb;

		// Check if assistant exists
		$existing = self::get_assistant($id);
		if (null === $existing) {
			return new \WP_Error('not_found', __('Assistant not found', 'polytrans'));
		}

		// Validate data
		$validation = self::validate_assistant_data($data);
		if (! $validation['valid']) {
			return new \WP_Error('invalid_data', __('Validation failed', 'polytrans'), $validation['errors']);
		}

		// Sanitize data
		$sanitized = self::sanitize_assistant_data($data);

		// Prepare update data
		$update_data = array(
			'name'                  => $sanitized['name'],
			'description'           => $sanitized['description'],
			'provider'              => $sanitized['provider'],
			'status'                => $sanitized['status'],
			'system_prompt'         => $sanitized['system_prompt'],
			'user_message_template' => $sanitized['user_message_template'],
			'api_parameters'        => wp_json_encode($sanitized['api_parameters']),
			'expected_format'       => $sanitized['expected_format'],
			'expected_output_schema' => ! empty($sanitized['expected_output_schema'])
				? (is_string($sanitized['expected_output_schema']) ? $sanitized['expected_output_schema'] : wp_json_encode($sanitized['expected_output_schema']))
				: null,
			'output_variables'      => ! empty($sanitized['output_variables']) ? wp_json_encode($sanitized['output_variables']) : null,
			'updated_at'            => current_time('mysql'),
		);

		$table_name = $wpdb->prefix . 'polytrans_assistants';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result     = $wpdb->update(
			$table_name,
			$update_data,
			array('id' => $id),
			array_fill(0, count($update_data), '%s'),
			array('%d')
		);

		if (false === $result) {
			return new \WP_Error('db_error', __('Failed to update assistant', 'polytrans'), $wpdb->last_error);
		}

		return true;
	}

	/**
	 * Delete assistant
	 *
	 * @param int $id Assistant ID.
	 * @return bool True on success, false if not found.
	 */
	public static function delete_assistant($id)
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'polytrans_assistants';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result     = $wpdb->delete($table_name, array('id' => $id), array('%d'));

		return $result !== false && $result > 0;
	}

	/**
	 * Get all assistants with optional filtering
	 *
	 * @param array $filters Optional filters (provider, status).
	 * @return array Array of assistants.
	 */
	public static function get_all_assistants($filters = array())
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'polytrans_assistants';
		$where      = array('1=1');
		$params     = array();

		// Filter by provider
		if (! empty($filters['provider'])) {
			$where[]  = 'provider = %s';
			$params[] = $filters['provider'];
		}

		// Filter by status
		if (! empty($filters['status'])) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}

		$where_clause = implode(' AND ', $where);

		if (! empty($params)) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and WHERE clause from trusted sources
			$assistants = $wpdb->get_results(
				$wpdb->prepare("SELECT * FROM `{$table_name}` WHERE {$where_clause} ORDER BY name ASC", $params),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
			$assistants = $wpdb->get_results(
				"SELECT * FROM `{$table_name}` WHERE 1=1 ORDER BY name ASC",
				ARRAY_A
			);
		}

		// Decode JSON fields
		foreach ($assistants as &$assistant) {
			$assistant['api_parameters']   = json_decode($assistant['api_parameters'], true);
			$assistant['output_variables'] = ! empty($assistant['output_variables']) ? json_decode($assistant['output_variables'], true) : null;
		}

		return $assistants;
	}

	/**
	 * Get assistants formatted for dropdown
	 *
	 * @param bool $group_by_provider Whether to group by provider.
	 * @return array Formatted options for HTML select.
	 */
	public static function get_assistants_for_dropdown($group_by_provider = false)
	{
		$assistants = self::get_all_assistants(array('status' => 'active'));
		$options    = array();

		foreach ($assistants as $assistant) {
			// Extract model name from api_parameters
			$model = isset($assistant['api_parameters']['model']) ? $assistant['api_parameters']['model'] : 'unknown';

			$option = array(
				'value'    => 'system:' . $assistant['id'],
				'label'    => sprintf('%s (%s)', $assistant['name'], $model),
				'provider' => $assistant['provider'],
			);

			if ($group_by_provider) {
				$options[$assistant['provider']][] = $option;
			} else {
				$options[] = $option;
			}
		}

		return $options;
	}

	/**
	 * Check which workflows and translations use this assistant
	 *
	 * @param int $id Assistant ID.
	 * @return array ['workflows' => array, 'translations' => array]
	 */
	public static function check_assistant_usage($id)
	{
		global $wpdb;

		$usage = array(
			'workflows'    => array(),
			'translations' => array(),
		);

		// Check workflows
		$workflows_table = $wpdb->prefix . 'polytrans_workflows';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $workflows_table)) === $workflows_table) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
			$workflows = $wpdb->get_results(
				"SELECT id, name, steps FROM `{$workflows_table}`",
				ARRAY_A
			);

			foreach ($workflows as $workflow) {
				$steps = json_decode($workflow['steps'], true);
				if (! is_array($steps)) {
					continue;
				}

				// Check if any step uses this assistant
				foreach ($steps as $step) {
					if (isset($step['assistant_id']) && (int) $step['assistant_id'] === $id) {
						$usage['workflows'][] = array(
							'id'   => (int) $workflow['id'],
							'name' => $workflow['name'],
						);
						break; // Found usage, no need to check other steps
					}
				}
			}
		}

		// TODO: Check translations (OpenAI provider settings)
		// This will be implemented in Translation Integration phase

		return $usage;
	}

	/**
	 * Sanitize assistant data
	 *
	 * @param array $data Raw data.
	 * @return array Sanitized data.
	 */
	private static function sanitize_assistant_data($data)
	{
		$sanitized = array();

		// Sanitize name (strip HTML)
		$sanitized['name'] = isset($data['name']) ? sanitize_text_field($data['name']) : '';

		// Description (allow some HTML via wp_kses_post)
		$sanitized['description'] = isset($data['description']) ? wp_kses_post($data['description']) : '';

		// Provider
		$sanitized['provider'] = isset($data['provider']) ? sanitize_text_field($data['provider']) : 'openai';

		// Status
		$sanitized['status'] = isset($data['status']) ? sanitize_text_field($data['status']) : 'active';

		// System prompt (keep as-is, may contain examples)
		$sanitized['system_prompt'] = isset($data['system_prompt']) ? $data['system_prompt'] : '';

		// User message template (keep as-is)
		$sanitized['user_message_template'] = isset($data['user_message_template']) ? $data['user_message_template'] : null;

		// API parameters (decode if string, validate structure)
		if (isset($data['api_parameters'])) {
			if (is_string($data['api_parameters'])) {
				$sanitized['api_parameters'] = json_decode($data['api_parameters'], true);
			} else {
				$sanitized['api_parameters'] = $data['api_parameters'];
			}

			// Ensure correct types for numeric parameters
			if (isset($sanitized['api_parameters']['temperature'])) {
				$sanitized['api_parameters']['temperature'] = (float) $sanitized['api_parameters']['temperature'];
			}
			if (isset($sanitized['api_parameters']['top_p'])) {
				$sanitized['api_parameters']['top_p'] = (float) $sanitized['api_parameters']['top_p'];
			}
			// Reasoning effort is stored as a canonical level (none/minimal/low/medium/high)
			// and translated to the provider-native parameter at request time.
			if (isset($sanitized['api_parameters']['reasoning_effort'])) {
				$effort = sanitize_text_field((string) $sanitized['api_parameters']['reasoning_effort']);
				if ($effort === '' || !in_array($effort, \PolyTrans\Core\ModelCapabilities::LEVELS, true)) {
					unset($sanitized['api_parameters']['reasoning_effort']);
				} else {
					$sanitized['api_parameters']['reasoning_effort'] = $effort;
				}
			}
		} else {
			$sanitized['api_parameters'] = array();
		}

		// Expected format
		$sanitized['expected_format'] = isset($data['expected_format']) ? sanitize_text_field($data['expected_format']) : 'text';

		// Expected output schema (decode if JSON string, keep as-is if Twig template)
		if (isset($data['expected_output_schema']) && ! is_null($data['expected_output_schema'])) {
			if (is_string($data['expected_output_schema'])) {
				$schema_str = trim($data['expected_output_schema']);
				if ($schema_str === '') {
					$sanitized['expected_output_schema'] = null;
				} else {
					$has_twig = strpos($schema_str, '{%') !== false || strpos($schema_str, '{{') !== false;
					if ($has_twig) {
						// Twig template - store as string
						$sanitized['expected_output_schema'] = $schema_str;
					} else {
						// Pure JSON - decode to array
						$decoded = json_decode($schema_str, true);
						$sanitized['expected_output_schema'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
					}
				}
			} else {
				$sanitized['expected_output_schema'] = $data['expected_output_schema'];
			}
		} else {
			$sanitized['expected_output_schema'] = null;
		}

		// Output variables (decode if string)
		if (isset($data['output_variables']) && ! is_null($data['output_variables'])) {
			if (is_string($data['output_variables'])) {
				$sanitized['output_variables'] = json_decode($data['output_variables'], true);
			} else {
				$sanitized['output_variables'] = $data['output_variables'];
			}
		} else {
			$sanitized['output_variables'] = null;
		}

		return $sanitized;
	}
}
