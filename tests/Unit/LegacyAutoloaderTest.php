<?php

/**
 * Tests for LegacyAutoloader
 * 
 * @package PolyTrans\Tests\Unit
 */

use PolyTrans\LegacyAutoloader;

describe('LegacyAutoloader', function () {
    
    test('is registered and available', function () {
        expect(class_exists('PolyTrans\LegacyAutoloader'))->toBeTrue();
    });

    test('can get pending migrations list', function () {
        $pending = LegacyAutoloader::getPendingMigrations();
        
        expect($pending)->toBeArray();
    });

    test('all legacy classes are migrated', function () {
        $pending = LegacyAutoloader::getPendingMigrations();
        
        expect($pending)->toBeEmpty();
    });

    test('autoloader ignores non-PolyTrans classes', function () {
        // Should not interfere with other classes
        $result = LegacyAutoloader::autoload('SomeOtherClass');
        
        expect($result)->toBeNull();
    });

    test('autoloader ignores unknown PolyTrans classes', function () {
        // Should not throw error for unknown classes
        $result = LegacyAutoloader::autoload('PolyTrans_NonExistent_Class');
        
        expect($result)->toBeNull();
    });

    test('has no pending migrations after PSR-4 migration', function () {
        $pending = LegacyAutoloader::getPendingMigrations();
        
        expect($pending)->toHaveCount(0);
    });
});
