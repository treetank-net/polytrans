<?php

declare(strict_types=1);

/**
 * Unit Tests: Usage report time window
 *
 * The window decides two things that constrain each other - which instants a report
 * covers, and how finely it is bucketed - and both are driven by request input. That
 * is what this file is about.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Core\UsageWindow;

beforeEach(function () {
    // A Monday afternoon, so week alignment has something to align to.
    $GLOBALS['polytrans_test_now'] = new DateTimeImmutable('2026-08-10 14:37:00', wp_timezone());
});

afterEach(function () {
    unset($GLOBALS['polytrans_test_now']);
});

describe('range resolution', function () {
    it('counts a day-scale period in whole days', function () {
        // From this moment minus seven days would give eight buckets, the first and
        // last of them part-full, and a chart that starts mid-morning.
        $window = UsageWindow::from_request(['preset' => '7d']);

        expect($window->sql_from())->toBe('2026-08-04 00:00:00');
        expect($window->sql_to())->toBe('2026-08-10 14:37:00');
        expect($window->bucket_starts())->toHaveCount(7);
    });

    it('closes a period that is over', function () {
        $window = UsageWindow::from_request(['preset' => 'yesterday']);

        expect($window->sql_from())->toBe('2026-08-09 00:00:00');
        expect($window->sql_to())->toBe('2026-08-10 00:00:00');
    });

    it('gives the last 24 hours 24 buckets', function () {
        $window = UsageWindow::from_request(['preset' => '24h']);

        expect($window->bucket())->toBe(UsageWindow::BUCKET_HOUR);
        expect($window->bucket_starts())->toHaveCount(24);
    });

    it('starts an all-time report where recording started', function () {
        $window = UsageWindow::from_request(
            ['preset' => 'all'],
            new DateTimeImmutable('2026-02-01 09:15:00', wp_timezone())
        );

        expect($window->sql_from())->toBe('2026-02-01 09:15:00');
    });

    it('falls back to the default length when nothing has been recorded', function () {
        $window = UsageWindow::from_request(['preset' => 'all'], null);

        expect($window->sql_from())->toBe('2026-07-12 00:00:00');
    });

    it('falls back on a preset it does not know', function () {
        // The value arrives from a query string.
        $window = UsageWindow::from_request(['preset' => '../../etc/passwd']);

        expect($window->preset())->toBe('30d');
    });
});

describe('custom ranges', function () {
    it('reads what a datetime-local input posts', function () {
        $window = UsageWindow::from_request([
            'preset' => 'custom',
            'from' => '2026-08-10T09:00',
            'to' => '2026-08-10T17:00',
        ]);

        expect($window->sql_from())->toBe('2026-08-10 09:00:00');
        expect($window->sql_to())->toBe('2026-08-10 17:00:00');
    });

    it('reads a bare date as midnight', function () {
        $window = UsageWindow::from_request([
            'preset' => 'custom',
            'from' => '2026-08-01',
            'to' => '2026-08-05',
        ]);

        expect($window->sql_from())->toBe('2026-08-01 00:00:00');
        expect($window->sql_to())->toBe('2026-08-05 00:00:00');
    });

    it('refuses an instant it cannot parse exactly', function () {
        // strtotime() would read this as some date and the report would look fine
        // while covering a period nobody asked for.
        $window = UsageWindow::from_request([
            'preset' => 'custom',
            'from' => 'last tuesday-ish',
            'to' => '2026-08-05',
        ]);

        // The unparseable end is dropped and the default length applied to the other.
        expect($window->sql_to())->toBe('2026-08-05 00:00:00');
        expect($window->sql_from())->toBe('2026-07-07 00:00:00');
    });

    it('completes a half-filled range instead of reporting nothing', function () {
        $window = UsageWindow::from_request(['preset' => 'custom', 'from' => '2026-08-09']);

        expect($window->sql_from())->toBe('2026-08-09 00:00:00');
        expect($window->sql_to())->toBe('2026-08-10 14:37:00');
    });

    it('puts a backwards range the right way round', function () {
        $window = UsageWindow::from_request([
            'preset' => 'custom',
            'from' => '2026-08-10T17:00',
            'to' => '2026-08-10T09:00',
        ]);

        expect($window->sql_from())->toBe('2026-08-10 09:00:00');
        expect($window->sql_to())->toBe('2026-08-10 17:00:00');
    });

    it('widens a range of no length at all', function () {
        // Zero length reports nothing, which reads as a broken page rather than as
        // an empty period.
        $window = UsageWindow::from_request([
            'preset' => 'custom',
            'from' => '2026-08-10T09:00',
            'to' => '2026-08-10T09:00',
        ]);

        expect($window->length_seconds())->toBe(3600);
    });
});

describe('resolution', function () {
    it('picks a resolution to suit the range', function () {
        $cases = [
            ['24h', UsageWindow::BUCKET_HOUR],
            ['7d', UsageWindow::BUCKET_DAY],
            ['30d', UsageWindow::BUCKET_DAY],
            ['12m', UsageWindow::BUCKET_WEEK],
        ];

        foreach ($cases as [$preset, $expected]) {
            expect(UsageWindow::from_request(['preset' => $preset])->bucket())->toBe($expected);
        }
    });

    it('honours a resolution the range can carry', function () {
        $window = UsageWindow::from_request(['preset' => '7d', 'bucket' => 'hour']);

        expect($window->bucket())->toBe(UsageWindow::BUCKET_HOUR);
        expect($window->bucket_downgraded())->toBeFalse();
        expect($window->bucket_starts())->toHaveCount(7 * 24 - 9);
    });

    it('steps down a resolution the range cannot carry, and says so', function () {
        // A year of hours is 8,760 points: unreadable as a chart and wasteful as a
        // query. Refusing outright would be worse - the reader gets no report at all.
        $window = UsageWindow::from_request(['preset' => '12m', 'bucket' => 'hour']);

        expect($window->bucket())->not->toBe(UsageWindow::BUCKET_HOUR);
        expect($window->bucket_downgraded())->toBeTrue();
        expect($window->requested_bucket())->toBe(UsageWindow::BUCKET_HOUR);
    });

    it('does not call an automatic choice a downgrade', function () {
        $window = UsageWindow::from_request(['preset' => '12m']);

        expect($window->bucket_downgraded())->toBeFalse();
    });

    it('keeps every resolution within its cap', function () {
        foreach (['24h', '7d', '30d', '90d', '12m', 'custom'] as $preset) {
            $window = UsageWindow::from_request([
                'preset' => $preset,
                'from' => '2016-01-01',
                'to' => '2026-08-10',
                'bucket' => 'hour',
            ]);

            expect(count($window->bucket_starts()))->toBeLessThanOrEqual(400);
        }
    });
});

describe('bucket keys', function () {
    it('writes hour keys the way the SQL term returns them', function () {
        $window = UsageWindow::create(
            new DateTimeImmutable('2026-08-10 12:00:00', wp_timezone()),
            new DateTimeImmutable('2026-08-10 15:00:00', wp_timezone()),
            'hour'
        );

        expect($window->bucket_starts())->toBe([
            '2026-08-10 12:00:00',
            '2026-08-10 13:00:00',
            '2026-08-10 14:00:00',
        ]);
    });

    it('writes day keys as dates', function () {
        $window = UsageWindow::create(
            new DateTimeImmutable('2026-08-08 13:00:00', wp_timezone()),
            new DateTimeImmutable('2026-08-10 09:00:00', wp_timezone()),
            'day'
        );

        expect($window->bucket_starts())->toBe(['2026-08-08', '2026-08-09', '2026-08-10']);
    });

    it('starts a week on Monday', function () {
        // WEEKDAY() in the SQL term is Monday-based whatever the server's week mode,
        // and the two have to agree or nothing lines up.
        $window = UsageWindow::create(
            new DateTimeImmutable('2026-08-06 13:00:00', wp_timezone()),
            new DateTimeImmutable('2026-08-18 09:00:00', wp_timezone()),
            'week'
        );

        expect($window->bucket_starts())->toBe(['2026-08-03', '2026-08-10', '2026-08-17']);
    });

    it('starts a month on the first', function () {
        $window = UsageWindow::create(
            new DateTimeImmutable('2026-06-15 13:00:00', wp_timezone()),
            new DateTimeImmutable('2026-08-10 09:00:00', wp_timezone()),
            'month'
        );

        expect($window->bucket_starts())->toBe(['2026-06-01', '2026-07-01', '2026-08-01']);
    });

    it('marks only the bucket the window ends inside as partial', function () {
        $window = UsageWindow::create(
            new DateTimeImmutable('2026-08-10 12:00:00', wp_timezone()),
            new DateTimeImmutable('2026-08-10 14:37:00', wp_timezone()),
            'hour'
        );

        expect($window->is_partial_bucket('2026-08-10 13:00:00'))->toBeFalse();
        expect($window->is_partial_bucket('2026-08-10 14:00:00'))->toBeTrue();
    });

    it('keeps one key per bucket when the clocks go back', function () {
        // 02:00 happens twice on that morning, and a wall-clock timestamp cannot tell
        // the two apart either - so one bucket is the only honest answer.
        $window = UsageWindow::create(
            new DateTimeImmutable('2026-10-25 00:00:00', wp_timezone()),
            new DateTimeImmutable('2026-10-25 06:00:00', wp_timezone()),
            'hour'
        );

        $keys = $window->bucket_starts();

        expect($keys)->toBe(array_values(array_unique($keys)));
    });
});
