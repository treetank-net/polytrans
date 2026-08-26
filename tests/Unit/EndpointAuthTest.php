<?php

declare(strict_types=1);

use PolyTrans\Core\EndpointAuth;

/**
 * "none" as a stored setting must never be enough on its own.
 *
 * This is the invariant a reviewer flagged: with the method set to "none" the two
 * REST endpoints used to accept unauthenticated requests that create posts and
 * start billable provider calls. The mode stays available for internal networks,
 * but only via TREETANK_TRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS in wp-config.php.
 *
 * The pre-rename name is still honoured, because it lives in the site owner's
 * wp-config.php and an upgrade cannot rewrite it.
 *
 * The constant is deliberately not defined in the test bootstrap: these tests pin
 * down the default, which is the state every ordinary site runs in.
 */
it('does not allow unauthenticated endpoints without the constant', function () {
    expect(defined(EndpointAuth::CONSTANT))->toBeFalse();
    expect(defined(EndpointAuth::LEGACY_CONSTANT))->toBeFalse();
    expect(EndpointAuth::allows_unauthenticated())->toBeFalse();
});

it('still honours the pre-rename constant name', function () {
    expect(EndpointAuth::LEGACY_CONSTANT)->toBe('POLYTRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS');
    expect(EndpointAuth::CONSTANT)->not->toBe(EndpointAuth::LEGACY_CONSTANT);
});

it('recognises the unauthenticated method', function () {
    expect(EndpointAuth::is_unauthenticated_method('none'))->toBeTrue();
    expect(EndpointAuth::is_unauthenticated_method('header_bearer'))->toBeFalse();
    expect(EndpointAuth::is_unauthenticated_method(''))->toBeFalse();
    expect(EndpointAuth::is_unauthenticated_method(null))->toBeFalse();
});

it('reports a stored "none" as refused while the constant is absent', function () {
    expect(EndpointAuth::is_refused(['translation_receiver_secret_method' => 'none']))->toBeTrue();
});

it('does not report a configuration that authenticates', function () {
    expect(EndpointAuth::is_refused(['translation_receiver_secret_method' => 'header_bearer']))->toBeFalse();
    expect(EndpointAuth::is_refused(['translation_receiver_secret_method' => 'get_param']))->toBeFalse();
    // A missing method means the default, which authenticates.
    expect(EndpointAuth::is_refused([]))->toBeFalse();
});

it('names the constant in the refusal message, so the log says how to enable it', function () {
    expect(EndpointAuth::refusal_message())->toContain('TREETANK_TRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS');
});
