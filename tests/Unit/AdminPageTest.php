<?php

use PolyTrans\Core\AdminPage;

test('recognises a submenu screen whatever the menu title is', function (string $hook) {
    expect(AdminPage::is($hook, 'polytrans-settings'))->toBeTrue();
})->with([
    'polytrans_page_polytrans-settings',
    'treetank_page_polytrans-settings',
    'translations_page_polytrans-settings',
    'tlumaczenia_page_polytrans-settings',
]);

test('recognises the top-level screen', function () {
    expect(AdminPage::is('toplevel_page_polytrans', 'polytrans'))->toBeTrue();
});

test('does not mistake another page for this one', function () {
    expect(AdminPage::is('translations_page_polytrans-settings', 'polytrans'))->toBeFalse()
        ->and(AdminPage::is('translations_page_polytrans-logs', 'polytrans-settings'))->toBeFalse()
        ->and(AdminPage::is('toplevel_page_polytrans', 'polytrans-settings'))->toBeFalse();
});
