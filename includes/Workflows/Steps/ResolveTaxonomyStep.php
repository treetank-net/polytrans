<?php

declare(strict_types=1);

namespace PolyTrans\Workflows\Steps;

use PolyTrans\Workflows\Context\WorkflowContextInterface;
use PolyTrans\Workflows\Services\TaxonomyResolverInterface;

/**
 * Workflow step that resolves taxonomy terms to target language equivalents.
 *
 * Uses TaxonomyResolver service to find Polylang translations for categories and tags.
 *
 * @since 1.8.0
 */
class ResolveTaxonomyStep extends AbstractWorkflowStep
{
    /**
     * {@inheritdoc}
     */
    public function get_id(): string
    {
        return 'resolve_taxonomy';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string
    {
        return function_exists('__') ? __('Resolve Taxonomy', 'polytrans') : 'Resolve Taxonomy';
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string
    {
        $desc = 'Resolves taxonomy terms (categories, tags) to their target language equivalents using Polylang translations.';
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic step description
        return function_exists('__') ? __($desc, 'polytrans') : $desc;
    }

    /**
     * {@inheritdoc}
     */
    public function is_external_compatible(): bool
    {
        return true; // Read-only, works with virtual context
    }

    /**
     * {@inheritdoc}
     */
    public function get_required_services(): array
    {
        return ['TaxonomyResolver'];
    }

    /**
     * {@inheritdoc}
     */
    public function execute(WorkflowContextInterface $context, array $config): void
    {
        /** @var TaxonomyResolverInterface $resolver */
        $resolver = $context->get_service('TaxonomyResolver');

        $source_lang = $context->get_source_language();
        $target_lang = $context->get_target_language();

        // Get taxonomies to process from config, default to categories and tags
        $taxonomies = $config['taxonomies'] ?? ['category', 'post_tag'];

        foreach ($taxonomies as $taxonomy) {
            $path = $this->get_taxonomy_path($taxonomy);
            $terms = $context->get($path);

            if (empty($terms) || !is_array($terms)) {
                continue;
            }

            // Skip if resolver not available for this taxonomy
            if (!$resolver->is_available($taxonomy)) {
                $this->log($context, "Resolver not available for taxonomy: {$taxonomy}", 'info');
                continue;
            }

            // Resolve terms
            $resolutions = $resolver->resolve_batch($taxonomy, $terms, $source_lang, $target_lang);

            // Convert resolutions to array format
            $resolved_terms = array_map(
                fn($resolution) => $resolution->to_array(),
                $resolutions
            );

            $context->set($path, $resolved_terms);

            // Log summary
            $matched = count(array_filter($resolutions, fn($r) => $r->is_resolved()));
            $total = count($resolutions);
            $this->log($context, "Resolved {$matched}/{$total} terms for {$taxonomy}", 'info');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validate_config(array $config): array
    {
        $errors = [];

        if (isset($config['taxonomies']) && !is_array($config['taxonomies'])) {
            $errors[] = 'taxonomies must be an array';
        }

        return $errors;
    }

    /**
     * Get the data path for a taxonomy.
     *
     * @param string $taxonomy Taxonomy name
     * @return string Dot-notation path
     */
    private function get_taxonomy_path(string $taxonomy): string
    {
        // Map WordPress taxonomy names to payload paths
        $map = [
            'category' => 'taxonomy.categories',
            'post_tag' => 'taxonomy.tags',
        ];

        return $map[$taxonomy] ?? "taxonomy.{$taxonomy}";
    }
}
