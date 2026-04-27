<?php

/**
 * Tests for Bootstrap class
 * 
 * @package PolyTrans\Tests\Unit
 */

use PolyTrans\Bootstrap;

describe('Bootstrap', function () {

    test('can be initialized', function () {
        expect(class_exists('PolyTrans\Bootstrap'))->toBeTrue();
    });

    test('getVersion returns version string', function () {
        $version = Bootstrap::getVersion();

        expect($version)->toBeString();
        expect($version)->not->toBeEmpty();
    });

    test('isInitialized checks for Twig', function () {
        $initialized = Bootstrap::isInitialized();

        expect($initialized)->toBeTrue('Twig should be loaded via Composer autoloader');
        expect(class_exists('Twig\Environment'))->toBeTrue();
    });

    test('Composer autoloader is registered', function () {
        // Twig should be available if autoloader worked
        expect(class_exists('Twig\Environment'))->toBeTrue();
        expect(class_exists('Twig\Loader\FilesystemLoader'))->toBeTrue();
    });

    test('can call init multiple times safely', function () {
        // Should not throw errors when called multiple times
        Bootstrap::init();
        Bootstrap::init();

        expect(true)->toBeTrue();
    });

    test('PSR-4 autoloader is configured in composer.json', function () {
        $composerFile = POLYTRANS_PLUGIN_DIR . 'composer.json';

        expect(file_exists($composerFile))->toBeTrue();

        $composer = json_decode(file_get_contents($composerFile), true);

        expect($composer)->toHaveKey('autoload');
        expect($composer['autoload'])->toHaveKey('psr-4');
        expect($composer['autoload']['psr-4'])->toHaveKey('PolyTrans\\');
        expect($composer['autoload']['psr-4']['PolyTrans\\'])->toBe('includes/');
    });

    test('Bootstrap class is in correct namespace', function () {
        $reflection = new \ReflectionClass('PolyTrans\Bootstrap');

        expect($reflection->getNamespaceName())->toBe('PolyTrans');
        expect($reflection->getShortName())->toBe('Bootstrap');
    });

    test('Bootstrap has expected public methods', function () {
        $methods = get_class_methods('PolyTrans\Bootstrap');

        expect($methods)->toContain('init');
        expect($methods)->toContain('getVersion');
        expect($methods)->toContain('isInitialized');
    });
});
