<?php

/**
 * Post Data Provider Unit Tests
 * 
 * Tests for Phase 0.1 Day 2 - Variable Structure Refactor
 */

use PolyTrans\PostProcessing\Providers\PostDataProvider;

beforeEach(function () {
    $this->provider = new PostDataProvider();
});

describe('Variable Aliases', function () {
    it('provides top-level aliases', function () {
        $context = [
            'translated_post' => [
                'title' => 'Test Title',
                'content' => 'Test Content',
                'excerpt' => 'Test Excerpt'
            ]
        ];
        
        $variables = $this->provider->get_variables($context);
        
        expect($variables)->toHaveKey('title');
        expect($variables)->toHaveKey('content');
        expect($variables)->toHaveKey('excerpt');
        expect($variables['title'])->toBe('Test Title');
        expect($variables['content'])->toBe('Test Content');
        expect($variables['excerpt'])->toBe('Test Excerpt');
    });
    
    it('provides original alias', function () {
        $context = [
            'original_post' => [
                'title' => 'Original Title',
                'content' => 'Original Content'
            ]
        ];
        
        $variables = $this->provider->get_variables($context);
        
        expect($variables)->toHaveKey('original');
        expect($variables['original'])->toBe($context['original_post']);
    });
    
    it('provides translated alias', function () {
        $context = [
            'translated_post' => [
                'title' => 'Translated Title',
                'content' => 'Translated Content'
            ]
        ];
        
        $variables = $this->provider->get_variables($context);
        
        expect($variables)->toHaveKey('translated');
        expect($variables['translated'])->toBe($context['translated_post']);
    });
});

describe('Available Variables', function () {
    it('lists all available variables', function () {
        $available = $this->provider->get_available_variables();
        
        expect($available)->toBeArray();
        expect($available)->toContain('title');
        expect($available)->toContain('content');
        expect($available)->toContain('original.title');
        expect($available)->toContain('translated.content');
        expect($available)->toContain('original.meta');
    });
});

describe('Variable Documentation', function () {
    it('provides documentation for variables', function () {
        $docs = $this->provider->get_variable_documentation();
        
        expect($docs)->toBeArray();
        expect($docs)->toHaveKey('title');
        expect($docs)->toHaveKey('original.title');
        expect($docs['title'])->toHaveKey('description');
        expect($docs['title'])->toHaveKey('example');
    });
});

describe('Backward Compatibility', function () {
    it('maintains original_post structure', function () {
        $context = [
            'original_post' => [
                'title' => 'Original',
                'content' => 'Content'
            ]
        ];
        
        $variables = $this->provider->get_variables($context);
        
        // Both old and new should work
        expect($variables)->toHaveKey('original_post');
        expect($variables)->toHaveKey('original');
        expect($variables['original_post'])->toBe($variables['original']);
    });
});
