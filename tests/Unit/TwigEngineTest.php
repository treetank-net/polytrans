<?php

/**
 * Twig Engine Unit Tests
 * 
 * Tests for Phase 0.1 - Twig Integration
 */

use PolyTrans\Templating\TwigEngine;

describe('Twig Engine Initialization', function () {
    it('initializes Twig environment', function () {
        TwigEngine::init(['debug' => true]);
        
        // If no exception thrown, initialization successful
        expect(true)->toBeTrue();
    });
    
    it('handles multiple initialization calls gracefully', function () {
        TwigEngine::init(['debug' => true]);
        TwigEngine::init(['debug' => true]); // Should not error
        
        expect(true)->toBeTrue();
    });
});

describe('Variable Interpolation', function () {
    beforeEach(function () {
        TwigEngine::init(['debug' => true]);
    });
    
    it('interpolates simple variables', function () {
        $template = 'Hello {{ name }}!';
        $context = ['name' => 'World'];
        
        $result = TwigEngine::render($template, $context);
        
        expect($result)->toBe('Hello World!');
    });
    
    it('interpolates nested variables', function () {
        $template = 'Title: {{ original.title }}';
        $context = [
            'original' => [
                'title' => 'Test Post'
            ]
        ];
        
        $result = TwigEngine::render($template, $context);
        
        expect($result)->toBe('Title: Test Post');
    });
    
    it('handles missing variables gracefully', function () {
        $template = 'Hello {{ missing_var }}!';
        $context = [];
        
        $result = TwigEngine::render($template, $context);
        
        // Twig with strict_variables=false returns empty string
        expect($result)->toBe('Hello !');
    });
    
    it('interpolates meta field access', function () {
        $template = 'SEO: {{ original.meta.seo_title }}';
        $context = [
            'original' => [
                'meta' => [
                    'seo_title' => 'SEO Optimized Title'
                ]
            ]
        ];
        
        $result = TwigEngine::render($template, $context);
        
        expect($result)->toBe('SEO: SEO Optimized Title');
    });
});

describe('Legacy Syntax Conversion', function () {
    beforeEach(function () {
        TwigEngine::init(['debug' => true]);
    });
    
    it('converts legacy {variable} to {{ variable }}', function () {
        $template = 'Hello {name}!';
        $context = ['name' => 'World'];
        
        $result = TwigEngine::render($template, $context);
        
        expect($result)->toBe('Hello World!');
    });
    
    it('does not double-convert Twig syntax', function () {
        $template = 'Hello {{ name }}!';
        $context = ['name' => 'World'];
        
        $result = TwigEngine::render($template, $context);
        
        // Should not become {{{ name }}}
        expect($result)->toBe('Hello World!');
    });
    
    it('preserves JSON structures', function () {
        $template = 'Config: {"key": "value"}';
        $context = [];
        
        $result = TwigEngine::render($template, $context);
        
        // JSON should not be converted
        expect($result)->toBe('Config: {"key": "value"}');
    });
});

describe('Twig Features', function () {
    beforeEach(function () {
        TwigEngine::init(['debug' => true]);
    });
    
    it('supports conditionals', function () {
        $template = '{% if title %}Title: {{ title }}{% else %}No title{% endif %}';
        
        $result1 = TwigEngine::render($template, ['title' => 'Test']);
        $result2 = TwigEngine::render($template, []);
        
        expect($result1)->toBe('Title: Test');
        expect($result2)->toBe('No title');
    });
    
    it('supports filters', function () {
        $template = '{{ text|upper }}';
        $context = ['text' => 'hello'];
        
        $result = TwigEngine::render($template, $context);
        
        expect($result)->toBe('HELLO');
    });
    
    it('supports loops', function () {
        $template = '{% for item in items %}{{ item }},{% endfor %}';
        $context = ['items' => ['a', 'b', 'c']];
        
        $result = TwigEngine::render($template, $context);
        
        expect($result)->toBe('a,b,c,');
    });
});

describe('Deprecated Variable Mappings', function () {
    beforeEach(function () {
        TwigEngine::init(['debug' => true]);
    });
    
    it('maps post_title to title', function () {
        $template = '{{ post_title }}';
        $context = ['title' => 'New Title'];
        
        $result = TwigEngine::render($template, $context);
        
        expect($result)->toBe('New Title');
    });
    
    it('maps post_content to content', function () {
        $template = '{{ post_content }}';
        $context = ['content' => 'Content here'];
        
        $result = TwigEngine::render($template, $context);
        
        expect($result)->toBe('Content here');
    });
});

describe('Error Handling', function () {
    beforeEach(function () {
        TwigEngine::init(['debug' => true]);
    });
    
    it('handles syntax errors gracefully', function () {
        $template = '{{ unclosed';
        $context = [];
        
        // Should fallback to regex or return original
        $result = TwigEngine::render($template, $context);
        
        // Should not throw exception
        expect($result)->toBeString();
    });
});
