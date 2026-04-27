<?php

/**
 * Variable Manager Unit Tests
 * 
 * Tests for variable interpolation with Twig integration
 */

use PolyTrans\PostProcessing\VariableManager;

beforeEach(function () {
    $this->manager = new VariableManager();
});

describe('Template Interpolation', function () {
    it('interpolates simple templates', function () {
        $template = 'Hello {{ name }}!';
        $context = ['name' => 'World'];
        
        $result = $this->manager->interpolate_template($template, $context);
        
        expect($result)->toBe('Hello World!');
    });
    
    it('interpolates nested variables', function () {
        $template = 'Title: {{ original.title }}';
        $context = [
            'original' => [
                'title' => 'Test Post'
            ]
        ];
        
        $result = $this->manager->interpolate_template($template, $context);
        
        expect($result)->toBe('Title: Test Post');
    });
    
    it('handles non-string templates', function () {
        $template = 123;
        $context = [];
        
        $result = $this->manager->interpolate_template($template, $context);
        
        // Should return as-is
        expect($result)->toBe(123);
    });
});

describe('Legacy Syntax Support', function () {
    it('supports legacy {variable} syntax', function () {
        $template = 'Hello {name}!';
        $context = ['name' => 'World'];
        
        $result = $this->manager->interpolate_template($template, $context);
        
        expect($result)->toBe('Hello World!');
    });
});

describe('Context Building', function () {
    it('builds context from providers', function () {
        $base_context = ['post_id' => 1];
        $providers = [];
        
        $context = $this->manager->build_context($base_context, $providers);
        
        expect($context)->toBeArray();
        expect($context)->toHaveKey('post_id');
    });
});
