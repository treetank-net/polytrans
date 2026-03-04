<?php

declare(strict_types=1);

namespace PolyTrans\Workflows\Steps;

use PolyTrans\Workflows\Context\WorkflowContextInterface;
use PolyTrans\PostProcessing\WorkflowStepInterface as LegacyStepInterface;

/**
 * Adapter to use legacy PostProcessing steps with new Workflow system.
 *
 * Bridges the old array-based context to the new WorkflowContextInterface.
 *
 * @since 1.8.0
 */
class LegacyStepAdapter extends AbstractWorkflowStep
{
    /**
     * @var LegacyStepInterface The wrapped legacy step
     */
    private LegacyStepInterface $legacy_step;

    /**
     * @var bool Override for external compatibility
     */
    private bool $external_compatible;

    /**
     * @var array Paths required by this step
     */
    private array $required_paths;

    /**
     * Constructor.
     *
     * @param LegacyStepInterface $legacy_step Legacy step to wrap
     * @param bool $external_compatible Whether step works with virtual context
     * @param array $required_paths Data paths required for execution
     */
    public function __construct(
        LegacyStepInterface $legacy_step,
        bool $external_compatible = true,
        array $required_paths = []
    ) {
        $this->legacy_step = $legacy_step;
        $this->external_compatible = $external_compatible;
        $this->required_paths = $required_paths;
    }

    /**
     * {@inheritdoc}
     */
    public function get_id(): string
    {
        return $this->legacy_step->get_type();
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string
    {
        return $this->legacy_step->get_name();
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string
    {
        return $this->legacy_step->get_description();
    }

    /**
     * {@inheritdoc}
     */
    public function is_external_compatible(): bool
    {
        return $this->external_compatible;
    }

    /**
     * {@inheritdoc}
     */
    public function get_required_paths(): array
    {
        return $this->required_paths;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(WorkflowContextInterface $context, array $config): void
    {
        // Convert new context to legacy array format
        $legacy_context = $this->context_to_array($context);

        // Execute legacy step
        $result = $this->legacy_step->execute($legacy_context, $config);

        // Process result
        if (!isset($result['success']) || !$result['success']) {
            $error = $result['error'] ?? 'Legacy step execution failed';
            throw new \RuntimeException(esc_html($error));
        }

        // Apply output data back to context
        if (isset($result['data']) && is_array($result['data'])) {
            $this->apply_output_to_context($context, $result['data'], $config);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validate_config(array $config): array
    {
        $validation = $this->legacy_step->validate_config($config);

        if (!isset($validation['valid']) || $validation['valid']) {
            return [];
        }

        return $validation['errors'] ?? ['Configuration validation failed'];
    }

    /**
     * Get the underlying legacy step.
     *
     * @return LegacyStepInterface
     */
    public function get_legacy_step(): LegacyStepInterface
    {
        return $this->legacy_step;
    }

    /**
     * Convert WorkflowContext to legacy array format.
     *
     * Legacy steps expect a flat array with keys like:
     * - title, content, excerpt (from post)
     * - meta_* (from meta)
     * - source_language, target_language
     *
     * @param WorkflowContextInterface $context
     * @return array Legacy-compatible context array
     */
    private function context_to_array(WorkflowContextInterface $context): array
    {
        $array = [];
        $data = $context->export();

        // Flatten post data
        if (isset($data['post']) && is_array($data['post'])) {
            foreach ($data['post'] as $key => $value) {
                $array[$key] = $value;
            }
        }

        // Flatten meta with prefix
        if (isset($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $key => $value) {
                $array['meta_' . $key] = $value;
            }
        }

        // Add language info
        $array['source_language'] = $context->get_source_language();
        $array['target_language'] = $context->get_target_language();

        // Add taxonomy (if available)
        if (isset($data['taxonomy'])) {
            $array['taxonomy'] = $data['taxonomy'];
        }

        // Add post_id if available (for database context)
        $post_id = $context->get_post_id();
        if ($post_id !== null) {
            $array['post_id'] = $post_id;
        }

        // Pass through any other top-level keys
        foreach ($data as $key => $value) {
            if (!in_array($key, ['post', 'meta', 'taxonomy', 'source_language', 'target_language'])) {
                $array[$key] = $value;
            }
        }

        return $array;
    }

    /**
     * Apply output data from legacy step back to context.
     *
     * Handles mapping output variables based on step config.
     *
     * @param WorkflowContextInterface $context
     * @param array $output Output data from legacy step
     * @param array $config Step configuration
     */
    private function apply_output_to_context(
        WorkflowContextInterface $context,
        array $output,
        array $config
    ): void {
        // Get output variable mapping from config
        $output_mapping = $config['output_mapping'] ?? [];

        foreach ($output as $key => $value) {
            // Skip internal keys
            if (str_starts_with($key, '_')) {
                continue;
            }

            // Check if there's explicit mapping
            if (isset($output_mapping[$key])) {
                $target_path = $output_mapping[$key];
            } else {
                // Default mapping: ai_response -> post.content (common pattern)
                $target_path = $this->get_default_output_path($key);
            }

            if ($target_path) {
                $context->set($target_path, $value);
            }
        }

        // Store full step output for debugging/chaining
        $step_outputs = $context->get('_step_outputs') ?? [];
        $step_outputs[$this->get_id()] = $output;
        $context->set('_step_outputs', $step_outputs);
    }

    /**
     * Get default output path for a key.
     *
     * @param string $key Output key
     * @return string|null Target path or null to skip
     */
    private function get_default_output_path(string $key): ?string
    {
        $defaults = [
            'ai_response' => 'output.ai_response',
            'processed_content' => 'post.content',
            'suggestions' => 'output.suggestions',
            'score' => 'output.score',
            'feedback' => 'output.feedback',
            'reviewed_content' => 'post.content',
            'reviewed_title' => 'post.title',
        ];

        return $defaults[$key] ?? "output.{$key}";
    }

    /**
     * Create adapter from legacy step class name.
     *
     * @param string $class_name Fully qualified legacy step class name
     * @param bool $external_compatible Whether step is external compatible
     * @param array $required_paths Required data paths
     * @return self|null Adapter or null if class not found
     */
    public static function from_class(
        string $class_name,
        bool $external_compatible = true,
        array $required_paths = []
    ): ?self {
        if (!class_exists($class_name)) {
            return null;
        }

        $legacy_step = new $class_name();

        if (!$legacy_step instanceof LegacyStepInterface) {
            return null;
        }

        return new self($legacy_step, $external_compatible, $required_paths);
    }
}
