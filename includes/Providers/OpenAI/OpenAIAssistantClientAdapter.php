<?php

namespace PolyTrans\Providers\OpenAI;

use PolyTrans\Providers\AIAssistantClientInterface;
use PolyTrans\PostProcessing\JsonResponseParser;
use PolyTrans\Core\LogsManager;

/**
 * OpenAI Assistant Client Adapter
 * Adapter that makes OpenAIClient implement AIAssistantClientInterface
 */

if (!defined('ABSPATH')) {
    exit;
}

class OpenAIAssistantClientAdapter implements AIAssistantClientInterface
{
    private $client;
    
    public function __construct($api_key, $base_url = 'https://api.openai.com/v1')
    {
        $this->client = new OpenAIClient($api_key, $base_url);
    }
    
    public function get_provider_id()
    {
        return 'openai';
    }
    
    public function supports_assistant_id($assistant_id)
    {
        return strpos($assistant_id, 'asst_') === 0;
    }
    
    public function execute_assistant($assistant_id, $content, $source_lang, $target_lang)
    {
        LogsManager::log("OpenAI Assistant: executing $assistant_id ($source_lang -> $target_lang)", "info");
        
        // Prepare the content for translation as JSON
        $content_to_translate = [
            'title' => $content['title'] ?? '',
            'content' => $content['content'] ?? '',
            'excerpt' => $content['excerpt'] ?? '',
            'meta' => $content['meta'] ?? [],
            'featured_image' => $content['featured_image'] ?? null
        ];
        
        $prompt = "Please translate the following JSON content from $source_lang to $target_lang. Return only a JSON object with the same structure but translated content:\n\n" . 
                  json_encode($content_to_translate, JSON_PRETTY_PRINT);
        
        // Create thread
        $thread_result = $this->client->create_thread();
        if (!$thread_result['success']) {
            return [
                'success' => false,
                'translated_content' => null,
                'error' => 'Failed to create thread: ' . $thread_result['error']
            ];
        }
        
        $thread_id = $thread_result['thread_id'];
        
        // Add message
        $message_result = $this->client->add_message($thread_id, 'user', $prompt);
        if (!$message_result['success']) {
            return [
                'success' => false,
                'translated_content' => null,
                'error' => 'Failed to add message: ' . $message_result['error']
            ];
        }
        
        // Run assistant
        $run_result = $this->client->run_assistant($thread_id, $assistant_id);
        if (!$run_result['success']) {
            return [
                'success' => false,
                'translated_content' => null,
                'error' => 'Failed to run assistant: ' . $run_result['error']
            ];
        }
        
        $run_id = $run_result['run_id'];
        
        // Wait for completion
        sleep(10);
        $max_attempts = 30;
        $attempt = 0;
        
        while ($attempt < $max_attempts) {
            $status_result = $this->client->get_run_status($thread_id, $run_id);
            
            if (!$status_result['success']) {
                return [
                    'success' => false,
                    'translated_content' => null,
                    'error' => 'Failed to get run status: ' . $status_result['error']
                ];
            }
            
            $status = $status_result['status'];
            
            if ($status === 'completed') {
                break;
            } elseif (in_array($status, ['failed', 'cancelled', 'expired'])) {
                return [
                    'success' => false,
                    'translated_content' => null,
                    'error' => "Assistant run $status"
                ];
            }
            
            sleep(1);
            $attempt++;
        }
        
        if ($attempt >= $max_attempts) {
            return [
                'success' => false,
                'translated_content' => null,
                'error' => 'Assistant run timed out'
            ];
        }
        
        // Get the assistant's response
        $message_result = $this->client->get_latest_assistant_message($thread_id);
        if (!$message_result['success']) {
            return [
                'success' => false,
                'translated_content' => null,
                'error' => 'Failed to get messages: ' . $message_result['error']
            ];
        }
        
        $response_text = $message_result['content'];
        
        // Use JSON Response Parser for robust extraction and validation
        $parser = new JsonResponseParser();
        
        // Define expected schema for translation response
        $schema = [
            'title' => 'string',
            'content' => 'string',
            'excerpt' => 'string',
            'meta' => 'array',
            'featured_image' => 'array'
        ];
        
        $parse_result = $parser->parse_with_schema($response_text, $schema);
        
        if (!$parse_result['success']) {
            LogsManager::log(
                "Failed to parse translation response: " . $parse_result['error'],
                "error",
                ['raw_response' => substr($response_text, 0, 500)]
            );
            return [
                'success' => false,
                'translated_content' => null,
                'error' => 'Failed to parse OpenAI response: ' . $parse_result['error']
            ];
        }
        
        // Log warnings if any
        if (!empty($parse_result['warnings'])) {
            LogsManager::log(
                "Translation response parsing warnings: " . implode(', ', $parse_result['warnings']),
                "warning"
            );
        }
        
        return [
            'success' => true,
            'translated_content' => $parse_result['data'],
            'error' => null
        ];
    }
}
