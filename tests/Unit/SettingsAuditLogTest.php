<?php

use PolyTrans\Core\SettingsAuditLog;

test('reports only the keys whose value changed', function () {
    $changes = SettingsAuditLog::diff(
        ['translation_provider' => 'openai', 'notification_timing' => 'immediate'],
        ['translation_provider' => 'claude', 'notification_timing' => 'immediate']
    );

    expect($changes)->toBe([
        ['key' => 'translation_provider', 'old' => '"openai"', 'new' => '"claude"'],
    ]);
});

test('reports nested keys with a dotted path, and added and removed keys', function () {
    $changes = SettingsAuditLog::diff(
        ['de' => ['status' => 'draft', 'reviewer' => 'none'], 'legacy' => 'x'],
        ['de' => ['status' => 'publish', 'reviewer' => 'none'], 'fr' => ['status' => 'draft']]
    );

    expect(array_column($changes, null, 'key'))->toBe([
        'de.status' => ['key' => 'de.status', 'old' => '"draft"', 'new' => '"publish"'],
        'legacy' => ['key' => 'legacy', 'old' => '"x"', 'new' => '(unset)'],
        'fr.status' => ['key' => 'fr.status', 'old' => '(unset)', 'new' => '"draft"'],
    ]);
});

test('compares a list as one value', function () {
    $changes = SettingsAuditLog::diff(
        ['notification_allowed_domains' => ['a.com']],
        ['notification_allowed_domains' => ['a.com', 'b.com']]
    );

    expect($changes)->toBe([
        ['key' => 'notification_allowed_domains', 'old' => '["a.com"]', 'new' => '["a.com","b.com"]'],
    ]);
});

test('never puts a secret into the log', function () {
    $changes = SettingsAuditLog::diff(
        ['openai_api_key' => 'sk-old-secret', 'claude_api_key' => ''],
        ['openai_api_key' => 'sk-new-secret', 'claude_api_key' => 'sk-ant-secret']
    );

    expect(wp_json_encode($changes))->not->toContain('secret-')
        ->and($changes)->toBe([
            ['key' => 'openai_api_key', 'old' => '[redacted]', 'new' => '[redacted]'],
            ['key' => 'claude_api_key', 'old' => '(empty)', 'new' => '[redacted]'],
        ]);
});

test('shortens long values such as prompts', function () {
    $changes = SettingsAuditLog::diff(['prompt' => 'a'], ['prompt' => str_repeat('b', 500)]);

    expect(mb_strlen($changes[0]['new']))->toBe(SettingsAuditLog::MAX_VALUE_LENGTH + 1)
        ->and($changes[0]['new'])->toEndWith('…');
});

test('identical values produce no changes', function () {
    expect(SettingsAuditLog::diff(['a' => ['b' => 1]], ['a' => ['b' => 1]]))->toBe([]);
});
