<?php

declare(strict_types=1);

/**
 * Architecture Tests: Naming Conventions
 *
 * Enforces consistent naming across the codebase (basic structure validation)
 */

arch('no dangerous global functions are used')
    ->expect('PolyTrans')
    ->not()->toUse([
        'eval',
        'create_function',
    ]);

test('admin menus delegate prompt refinement execution', function () {
    $menu_files = [
        dirname(__DIR__, 2) . '/includes/Menu/AssistantsMenu.php',
        dirname(__DIR__, 2) . '/includes/Menu/PostprocessingMenu.php',
    ];

    foreach ($menu_files as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toContain('PolyTrans\\Core\\ChatClientFactory');
        expect($source)->not->toContain('PolyTrans\\Templating\\TwigEngine');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\EvaluationScoreExtractor');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\PromptChatRunner');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\PromptPackNormalizer');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\PromptPackParser');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\PromptTemplateRenderer');
        expect($source)->not->toContain('ChatClientFactory::create');
        expect($source)->not->toContain('TwigEngine::render');
        expect($source)->not->toContain('PromptChatRunner::execute');
        expect($source)->not->toContain('PromptPackParser::parse');
        expect($source)->not->toContain('PromptTemplateRenderer::render');
        expect($source)->not->toContain('EvaluationScoreExtractor::extract');
    }
});
