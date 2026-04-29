<?php

declare(strict_types=1);

namespace PolyTrans\PromptRefinement;

use PolyTrans\Templating\TwigEngine;

if (!defined('ABSPATH')) {
    exit;
}

final class PromptTemplateRenderer
{
    /**
     * Render Twig template for evaluator/adjuster prompts.
     *
     * @param mixed $template Template string.
     * @param array<string,mixed> $context Rendering context.
     */
    public static function render($template, array $context): string
    {
        if (!is_string($template)) {
            return '';
        }

        try {
            if (class_exists(TwigEngine::class)) {
                return TwigEngine::render($template, $context);
            }
        } catch (\Throwable $e) {
            // Fall through to raw template if Twig rendering fails.
        }

        return $template;
    }
}
