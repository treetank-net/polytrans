<?php

use PolyTrans\Core\NotificationFilter;

beforeEach(function () {
    delete_option('polytrans_settings');
});

afterEach(function () {
    delete_option('polytrans_settings');
});

test('allows all users when no filters are configured', function () {
    $user = new WP_User(1, 'external@example.com', ['contributor']);

    expect(NotificationFilter::should_notify_user($user))->toBeTrue();
});

test('blocks user with disallowed email domain', function () {
    update_option('polytrans_settings', [
        'notification_allowed_domains' => ['company.com', 'internal.org']
    ]);

    $user = new WP_User(1, 'external@gmail.com', ['editor']);

    expect(NotificationFilter::should_notify_user($user))->toBeFalse();
});

test('allows user with allowed email domain', function () {
    update_option('polytrans_settings', [
        'notification_allowed_domains' => ['company.com', 'internal.org']
    ]);

    $user = new WP_User(1, 'john@company.com', ['editor']);

    expect(NotificationFilter::should_notify_user($user))->toBeTrue();
});

test('allows user when one of multiple domains matches', function () {
    update_option('polytrans_settings', [
        'notification_allowed_domains' => ['company.com', 'internal.org']
    ]);

    $user = new WP_User(2, 'editor@internal.org', ['editor']);

    expect(NotificationFilter::should_notify_user($user))->toBeTrue();
});

test('sanitizes domains correctly', function () {
    $input = 'example.com, https://www.company.org, test.net/path, invalid domain, another.com';
    $sanitized = NotificationFilter::sanitize_domains($input);

    expect($sanitized)->toBe(['example.com', 'company.org', 'test.net', 'another.com']);
});

test('sanitizes domains from array', function () {
    $input = ['example.com', 'https://www.company.org', 'test.net'];
    $sanitized = NotificationFilter::sanitize_domains($input);

    expect($sanitized)->toBe(['example.com', 'company.org', 'test.net']);
});

test('handles invalid domain input', function () {
    expect(NotificationFilter::sanitize_domains('invalid'))->toBe([]);
    expect(NotificationFilter::sanitize_domains('no spaces allowed'))->toBe([]);
    expect(NotificationFilter::sanitize_domains(123))->toBe([]);
});

test('domain matching is case-insensitive', function () {
    update_option('polytrans_settings', [
        'notification_allowed_domains' => ['Company.COM']
    ]);

    $user = new WP_User(1, 'user@company.com', ['editor']);

    expect(NotificationFilter::should_notify_user($user))->toBeTrue();
});
