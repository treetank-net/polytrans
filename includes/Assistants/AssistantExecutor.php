<?php

/**
 * Assistant Executor - AI Assistant Execution Engine
 *
 * Executes AI assistants with prompt interpolation and API calls.
 * Part of Phase 1: AI Assistants Management System
 *
 * @package PolyTrans
 * @subpackage Assistants
 * @since 1.4.0
 */

namespace PolyTrans\Assistants;

use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Core\LogsManager;
use PolyTrans\Core\ChatClientFactory;

if (! defined('ABSPATH')) {
	exit;
}

use PolyTrans\PostProcessing\VariableManager;

/**
 * Class AssistantExecutor
 *
 * Executes AI assistants by loading configuration, interpolating prompts,
 * calling provider APIs, and processing responses.
 */
class AssistantExecutor
{

	/**
	 * Execute assistant by ID
	 *
	 * @param int   $assistant_id Assistant ID from database.
	 * @param array $context      Execution context (post data, variables).
	 * @return array|WP_Error Execution result or error.
	 */
	public static function execute($assistant_id, $context)
	{
		// Load assistant from database
		$assistant = AssistantManager::get_assistant($assistant_id);

		if (null === $assistant) {
			return new \WP_Error('assistant_not_found', __('Assistant not found', 'polytrans'));
		}

		// Check if assistant is active
		if ('active' !== $assistant['status']) {
			return new \WP_Error('assistant_inactive', __('Assistant is inactive', 'polytrans'));
		}

		// Execute using the assistant configuration
		return self::execute_with_config($assistant, $context);
	}

	/**
	 * Execute assistant with direct configuration (no database lookup)
	 *
	 * @param array $config  Assistant configuration.
	 * @param array $context Execution context.
	 * @return array|WP_Error Execution result or error.
	 */
	public static function execute_with_config($config, $context)
	{
		// Validate required fields
		if (empty($config['system_prompt'])) {
			return new \WP_Error('invalid_config', __('Missing required field: system_prompt', 'polytrans'));
		}

		if (empty($config['api_parameters'])) {
			return new \WP_Error('invalid_config', __('Missing required field: api_parameters', 'polytrans'));
		}

		// Interpolate prompts with context variables
		$prompts = self::interpolate_prompts($config, $context);

		if (is_wp_error($prompts)) {
			return $prompts;
		}

		// Call provider API
		$api_response = self::call_provider_api($config, $prompts);

		if (is_wp_error($api_response)) {
			return $api_response;
		}

		// Process response based on expected format
		$processed = self::process_response($api_response, $config);

		if (is_wp_error($processed)) {
			return $processed;
		}

		// Return standardized result (without raw_response to avoid memory issues)
		return array(
			'success'                   => true,
			'output'                    => $processed['output'],
			'provider'                  => $config['provider'] ?? 'openai',
			'model'                     => self::resolve_reported_model($api_response, $config),
			'usage'                     => $api_response['usage'] ?? array(),
			// raw_response removed - can be 50KB+ and cause memory issues
			'interpolated_system_prompt' => $prompts['system_prompt'] ?? null,
			'interpolated_user_message'  => $prompts['user_message'] ?? null,
		);
	}

	/**
	 * Determine which model actually served the request.
	 *
	 * Read from the response first, because that is the name a price lookup needs: an
	 * assistant frequently has no model of its own and inherits the provider default,
	 * and an alias resolves to a concrete dated build the caller never named. Reading
	 * the configured value instead recorded an empty string for every managed
	 * assistant, which no price list can match.
	 *
	 * Falls back through the same chain call_provider_api() used to pick the model, so
	 * a provider that reports nothing still yields the name that was sent.
	 *
	 * @param array $api_response Raw provider response.
	 * @param array $config       Assistant configuration.
	 * @return string Model name, or '' when nothing names one.
	 */
	private static function resolve_reported_model($api_response, $config)
	{
		$reported = is_array($api_response) ? ($api_response['model'] ?? '') : '';

		if (is_string($reported) && $reported !== '') {
			return $reported;
		}

		$configured = $config['api_parameters']['model'] ?? '';

		if (is_string($configured) && $configured !== '') {
			return $configured;
		}

		$provider = $config['provider'] ?? 'openai';
		$settings = get_option('polytrans_settings', array());
		$fallback = $settings[$provider . '_model'] ?? '';

		return is_string($fallback) ? $fallback : '';
	}

	/**
	 * Interpolate prompts using Variable Manager (Twig)
	 *
	 * @param array $config  Assistant configuration.
	 * @param array $context Execution context.
	 * @return array|WP_Error Interpolated prompts or error.
	 */
	public static function interpolate_prompts($config, $context)
	{
		$variable_manager = new VariableManager();

		try {
			// Interpolate system prompt (required)
			$system_prompt = $variable_manager->interpolate_template($config['system_prompt'], $context);

			// Interpolate user message template (optional)
			$user_message = null;
			if (! empty($config['user_message_template'])) {
				$user_message = $variable_manager->interpolate_template($config['user_message_template'], $context);
			}

			return array(
				'system_prompt' => $system_prompt,
				'user_message'  => $user_message,
			);
		} catch (\Exception $e) {
			return new \WP_Error('interpolation_error', $e->getMessage());
		}
	}

	/**
	 * Call provider API using ChatClientFactory
	 * Supports all providers that implement ChatClientInterface
	 *
	 * @param array $config  Assistant configuration.
	 * @param array $prompts Interpolated prompts.
	 * @return array|WP_Error API response or error.
	 */
	public static function call_provider_api($config, $prompts)
	{
		$provider = $config['provider'] ?? 'openai';
		$settings = get_option('polytrans_settings', array());
		
		// Create chat client using factory
		$client = ChatClientFactory::create($provider, $settings);
		
		if (!$client) {
			return new \WP_Error(
				'unsupported_provider',
				sprintf(
					// translators: %s is the provider name
					__('Provider "%s" is not supported or API key is not configured', 'polytrans'),
					$provider
				)
			);
		}
		
		// Build messages array
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $prompts['system_prompt'],
			),
		);
		
		if (!empty($prompts['user_message'])) {
			$messages[] = array(
				'role'    => 'user',
				'content' => $prompts['user_message'],
			);
		}
		
		// Get model - use assistant's model or fall back to global setting
		$model = $config['api_parameters']['model'] ?? '';
		if (empty($model)) {
			// Try to get default model from settings based on provider
			$model_setting_key = $provider . '_model';
			$model = $settings[$model_setting_key] ?? '';
		}
		
		$api_parameters = self::sanitize_api_parameters($config['api_parameters'] ?? array());

		// Build API parameters
		$parameters = array_merge(
			$api_parameters,
			array('model' => $model)
		);
		
		// Call chat completion API
		$result = $client->chat_completion($messages, $parameters);
		
		if (!$result['success']) {
			$error_code = $result['error_code'] ?? 'api_error';
			return new \WP_Error(
				$error_code,
				$result['error'] ?? __('API request failed', 'polytrans'),
				array(
					'status' => $result['status'] ?? null,
					'retry_after' => $result['retry_after'] ?? null,
				)
			);
		}
		
		return $result['data'];
	}

	/**
	 * Remove internal metadata from assistant API parameters before provider request.
	 *
	 * @param array $api_parameters Assistant API parameters.
	 * @return array Sanitized parameters.
	 */
	private static function sanitize_api_parameters($api_parameters)
	{
		if (!is_array($api_parameters)) {
			return array();
		}

		// Migration metadata is internal-only and not accepted by provider APIs.
		unset($api_parameters['migrated_from']);

		return $api_parameters;
	}

	/**
	 * Process API response based on expected format
	 *
	 * @param array $response API response.
	 * @param array $config   Assistant configuration.
	 * @return array|WP_Error Processed output or error.
	 */
	public static function process_response($response, $config)
	{
		$expected_format = $config['expected_format'] ?? 'text';
		$provider = $config['provider'] ?? 'openai';

		// Check for truncated responses (partial response due to timeout or max_tokens)
		// OpenAI uses finish_reason: 'length', Claude uses stop_reason: 'max_tokens'
		if ($provider === 'openai' && isset($response['choices'][0]['finish_reason'])) {
			$finish_reason = $response['choices'][0]['finish_reason'];
			if ($finish_reason === 'length') {
				LogsManager::log(
					'Rejecting truncated OpenAI response (finish_reason: length)',
					'error',
					array('finish_reason' => $finish_reason, 'usage' => $response['usage'] ?? null)
				);
				return new \WP_Error(
					'truncated_response',
					__('AI response was truncated due to max_tokens limit. Increase max_tokens or simplify the request.', 'polytrans')
				);
			}
		}
		if ($provider === 'claude' && isset($response['stop_reason'])) {
			if ($response['stop_reason'] === 'max_tokens') {
				LogsManager::log(
					'Rejecting truncated Claude response (stop_reason: max_tokens)',
					'error',
					array('stop_reason' => $response['stop_reason'], 'usage' => $response['usage'] ?? null)
				);
				return new \WP_Error(
					'truncated_response',
					__('AI response was truncated due to max_tokens limit. Increase max_tokens or simplify the request.', 'polytrans')
				);
			}
		}

		// Extract content from response (handle different API formats)
		$content = self::extract_content_from_response($response, $provider);

		if (null === $content) {
			return new \WP_Error('invalid_response', __('Failed to extract content from API response', 'polytrans'));
		}

		// Reject empty or whitespace-only responses
		if (trim($content) === '') {
			LogsManager::log(
				'Rejecting empty AI response',
				'error',
				array('provider' => $provider, 'raw_content_length' => strlen($content))
			);
			return new \WP_Error(
				'empty_response',
				__('AI returned an empty response. This may indicate a timeout or API issue.', 'polytrans')
			);
		}

		// Process based on expected format
		switch ($expected_format) {
			case 'text':
				return array('output' => $content);

			case 'json':
				return self::process_json_response($content, $config);

			default:
				return array('output' => $content);
		}
	}

	/**
	 * Extract content from API response using ChatClient
	 *
	 * @param array  $response API response.
	 * @param string $provider Provider name.
	 * @return string|null Content or null if not found.
	 */
	private static function extract_content_from_response($response, $provider)
	{
		$settings = get_option('polytrans_settings', array());
		
		// Create chat client using factory
		$client = ChatClientFactory::create($provider, $settings);
		
		if (!$client) {
			// Fallback to manual extraction for backward compatibility
			return self::extract_content_fallback($response, $provider);
		}
		
		// Use client's extract_content method
		$content = $client->extract_content($response);
		
		// Note: Truncation detection moved to process_response() which returns WP_Error
		
		return $content;
	}
	
	/**
	 * Fallback content extraction (for backward compatibility)
	 *
	 * @param array  $response API response.
	 * @param string $provider Provider name.
	 * @return string|null Content or null if not found.
	 */
	private static function extract_content_fallback($response, $provider)
	{
		switch ($provider) {
			case 'openai':
				return $response['choices'][0]['message']['content'] ?? null;
			case 'claude':
				// Claude format: content[0].text (for text blocks)
				if (!isset($response['content']) || !is_array($response['content'])) {
					return null;
				}
				foreach ($response['content'] as $block) {
					if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
						return $block['text'];
					}
				}
				return null;
			default:
				return null;
		}
	}

	/**
	 * Process JSON response
	 *
	 * @param string $content JSON content.
	 * @param array  $config  Assistant configuration.
	 * @return array|WP_Error Parsed JSON or error.
	 */
	private static function process_json_response($content, $config)
	{
		// Parse JSON - try direct parse first
		$decoded = json_decode($content, true);

		// If parsing failed, try to normalize escaping (common issue with code blocks)
		if (json_last_error() !== JSON_ERROR_NONE) {
			// Try to extract JSON from code blocks if present
			$normalized_content = $content;
			
			// Extract from ```json...``` or ```...``` blocks
			if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $content, $matches)) {
				$normalized_content = trim($matches[1]);
			}
			
			// Try to fix double-escaped characters
			// Common issue: \\r\\n instead of \r\n
			$normalized_content = preg_replace('/\\\\{3,}r\\\\{3,}n/', "\r\n", $normalized_content);
			$normalized_content = preg_replace('/\\\\{3,}n/', "\n", $normalized_content);
			$normalized_content = preg_replace('/\\\\{3,}r/', "\r", $normalized_content);
			$normalized_content = preg_replace('/\\\\{3,}t/', "\t", $normalized_content);
			
			// Try parsing again with normalized content
			$decoded = json_decode($normalized_content, true);
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			// Log parsing error (without full response to avoid DB/memory issues)
			LogsManager::log(
				'Assistant JSON parsing failed: ' . json_last_error_msg(),
				'error',
				array(
					'json_error'    => json_last_error_msg(),
					'json_error_code' => json_last_error(),
					'response_length' => strlen($content),
					'response_preview' => substr($content, 0, 500), // First 500 chars
					'response_end' => substr($content, -200), // Last 200 chars to see if truncated
				)
			);

			return new \WP_Error(
				'invalid_json',
				__('Failed to parse JSON response', 'polytrans'),
				array(
					'json_error' => json_last_error_msg(),
					'json_error_code' => json_last_error(),
					'content'    => substr($content, 0, 200),
					'full_length' => strlen($content),
				)
			);
		}

		// Validate output_variables if specified
		if (! empty($config['output_variables'])) {
			$missing = array();
			foreach ($config['output_variables'] as $var) {
				if (! isset($decoded[$var])) {
					$missing[] = $var;
				}
			}

			if (! empty($missing)) {
				// Return partial result with warnings
				return array(
					'output'   => $decoded,
					'warnings' => array(
						sprintf(
							// translators: %s is a comma-separated list of missing variable names
							__('Missing expected fields: %s', 'polytrans'),
							implode(', ', $missing)
						),
					),
				);
			}
		}

		return array('output' => $decoded);
	}
}
