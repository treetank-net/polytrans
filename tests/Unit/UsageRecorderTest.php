<?php

declare(strict_types=1);

/**
 * Unit Tests: Usage recording
 *
 * The table is the source of truth a dashboard groups over; the post meta is a
 * denormalised copy so "what did translating this article cost" is a single read.
 * The two must agree, sums must not drift, and a call whose price is unknown must
 * never be counted as free.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Core\ModelPricing;
use PolyTrans\Core\UsageRecorder;

// Same semantics as the other test files that stub these, so whichever file wins
// the function_exists() race, the backing store is read the same way.
if (!function_exists('get_post')) {
    function get_post($post_id)
    {
        return $GLOBALS['polytrans_test_posts'][$post_id] ?? null;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value)
    {
        $GLOBALS['polytrans_test_post_meta'][$post_id][$meta_key] = [$meta_value];
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        $meta = $GLOBALS['polytrans_test_post_meta'][$post_id] ?? [];

        if ($key === '') {
            return $meta;
        }

        $values = $meta[$key] ?? [];
        if ($single) {
            return $values[0] ?? '';
        }

        return $values;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0)
    {
        return '2026-08-07 12:00:00';
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args)
    {
        $GLOBALS['polytrans_test_actions'][$hook][] = $args;
        return null;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0)
    {
        $GLOBALS['polytrans_pricing_transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        return $GLOBALS['polytrans_pricing_transients'][$key] ?? false;
    }
}

/**
 * Minimal $wpdb: enough for the existence check and to capture inserted rows.
 */
final class PolyTransFakeUsageWpdb
{
    public $prefix = 'wp_';
    public $inserted = [];

    public function get_charset_collate()
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function prepare($query, ...$args)
    {
        $values = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;

        foreach ($values as $value) {
            // %d as well as %s: the rebuild queries bind post IDs as numbers.
            $query = preg_replace('/%[dsf]/', (string) $value, $query, 1);
        }

        return $query;
    }

    public function get_var($query)
    {
        // Report the table as present so record() takes its normal path.
        return 'wp_' . UsageRecorder::TABLE;
    }

    public function insert($table, $data)
    {
        $this->inserted[] = ['table' => $table, 'data' => $data];
        return 1;
    }

    /**
     * Replays the captured rows, filtered the way rebuild_post_summary() asks for
     * them, so a rebuild reads back exactly what was recorded.
     */
    public function get_results($query, $output = null)
    {
        $rows = array_map(function ($entry, $index) {
            return array_merge($entry['data'], ['id' => $index + 1]);
        }, $this->inserted, array_keys($this->inserted));

        if (preg_match('/WHERE post_id = (\d+)/', $query, $m)) {
            return array_values(array_filter($rows, function ($row) use ($m) {
                return (int) ($row['post_id'] ?? 0) === (int) $m[1];
            }));
        }

        if (preg_match('/WHERE source_post_id = (\d+)/', $query, $m)) {
            return array_values(array_filter($rows, function ($row) use ($m) {
                return (int) ($row['source_post_id'] ?? 0) === (int) $m[1]
                    && (int) ($row['post_id'] ?? 0) !== (int) $m[1];
            }));
        }

        return [];
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key, $value = '')
    {
        unset($GLOBALS['polytrans_test_post_meta'][$post_id][$key]);
        return true;
    }
}

function polytrans_seed_usage_pricing(): void
{
    set_transient(ModelPricing::TRANSIENT_KEY, [
        'openai/gpt-5.6-luna' => [
            'input' => '0.0000001',
            'output' => '0.0000006',
            'cached_read' => '0.00000001',
            'cached_write' => '0.000000125',
            'reasoning' => null,
        ],
        'google/gemini-3.5-flash' => [
            'input' => '0.0000015',
            'output' => '0.000009',
            'cached_read' => '0.00000015',
            'cached_write' => null,
            'reasoning' => '0.000009',
        ],
    ], 0);
    ModelPricing::flush_cache();
}

/**
 * A real /responses payload shape, trimmed to the fields that get priced.
 */
function polytrans_usage_payload(int $input = 1751, int $output = 300, int $reasoning = 0): array
{
    return [
        'input_tokens' => $input,
        'output_tokens' => $output,
        'output_tokens_details' => ['reasoning_tokens' => $reasoning],
    ];
}

function polytrans_recorded_summary(int $post_id): array
{
    $summary = get_post_meta($post_id, UsageRecorder::META_SUMMARY, true);

    return is_array($summary) ? $summary : [];
}

beforeEach(function () {
    global $wpdb;

    $wpdb = new PolyTransFakeUsageWpdb();
    $this->wpdb = $wpdb;

    $GLOBALS['polytrans_test_post_meta'] = [];
    $GLOBALS['polytrans_test_actions'] = [];
    $GLOBALS['polytrans_test_filters'] = [];
    $GLOBALS['polytrans_test_posts'] = [
        11 => (object) ['ID' => 11, 'post_title' => 'Original'],
        22 => (object) ['ID' => 22, 'post_title' => 'German translation'],
    ];

    polytrans_seed_usage_pricing();
});

describe('table row', function () {
    it('stores the token breakdown and the frozen cost', function () {
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(1000, 500, 400),
            'activity' => 'translation',
            'post_id' => 22,
            'source_post_id' => 11,
            'target_language' => 'de',
        ]);

        expect($this->wpdb->inserted)->toHaveCount(1);
        $row = $this->wpdb->inserted[0]['data'];

        expect($this->wpdb->inserted[0]['table'])->toBe('wp_' . UsageRecorder::TABLE);
        expect($row['tokens_input'])->toBe(1000);
        expect($row['tokens_output'])->toBe(500);
        expect($row['tokens_reasoning'])->toBe(400);
        expect($row['target_language'])->toBe('de');
        expect($row['activity'])->toBe('translation');
        expect($row['pricing_source'])->toBe(ModelPricing::SOURCE_CATALOG);

        // 1000 input + 500 output; the 400 reasoning tokens are inside the output.
        $expected = (1000 * 0.0000001) + (500 * 0.0000006);
        expect((float) $row['cost_usd'])->toBe(round($expected, 10));
    });

    it('writes nothing for a call that reported no tokens', function () {
        // A failed request carries no usage; a row would only add noise.
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => [],
            'activity' => 'translation',
            'post_id' => 22,
        ]);

        expect($this->wpdb->inserted)->toBeEmpty();
        expect(polytrans_recorded_summary(22))->toBeEmpty();
    });

    it('records the workflow step it came from', function () {
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(),
            'activity' => 'workflow_step',
            'step' => 'SEO rewrite',
            'workflow_id' => 'wf_7',
            'post_id' => 22,
        ]);

        $row = $this->wpdb->inserted[0]['data'];

        expect($row['activity'])->toBe('workflow_step');
        expect($row['step'])->toBe('SEO rewrite');
        expect($row['workflow_id'])->toBe('wf_7');
    });
});

describe('post summaries', function () {
    it('records the cost of producing the translated post on that post', function () {
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(1000, 500),
            'activity' => 'translation',
            'post_id' => 22,
            'source_post_id' => 11,
            'target_language' => 'de',
        ]);

        $summary = polytrans_recorded_summary(22);

        expect($summary['calls'])->toBe(1);
        expect($summary['by_model'])->toHaveKey('gpt-5.6-luna');
        expect($summary['by_activity']['translation']['tokens_output'])->toBe(500);
        expect((float) $summary['total_usd'])->toBeGreaterThan(0.0);
        expect((float) get_post_meta(22, UsageRecorder::META_COST, true))
            ->toBe((float) $summary['total_usd']);
    });

    it('buckets the original per target language', function () {
        // The view we need is "what did the other markets cost", without querying.
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(1000, 500),
            'activity' => 'translation',
            'source_post_id' => 11,
            'target_language' => 'de',
        ]);
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(2000, 100),
            'activity' => 'translation',
            'source_post_id' => 11,
            'target_language' => 'fr',
        ]);

        $summary = polytrans_recorded_summary(11);

        expect(array_keys($summary['by_language']))->toBe(['de', 'fr']);
        expect($summary['by_language']['de']['tokens_output'])->toBe(500);
        expect($summary['by_language']['fr']['tokens_input'])->toBe(2000);
        expect($summary['by_language']['de']['by_model'])->toHaveKey('gpt-5.6-luna');
        expect($summary['calls'])->toBe(2);
    });

    it('accumulates across calls without losing precision', function () {
        // Ten identical one-token calls must total exactly ten token-prices; float
        // addition at 1e-8 magnitudes is what this guards against.
        for ($i = 0; $i < 10; $i++) {
            UsageRecorder::record([
                'provider' => 'openai',
                'model' => 'gpt-5.6-luna',
                'usage' => polytrans_usage_payload(0, 1),
                'activity' => 'translation',
                'post_id' => 22,
            ]);
        }

        $summary = polytrans_recorded_summary(22);

        expect($summary['calls'])->toBe(10);
        expect($summary['total_usd'])->toBe('0.0000060000');
    });

    it('counts an unpriced call separately instead of adding zero', function () {
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'model-nobody-lists',
            'usage' => polytrans_usage_payload(5000, 5000),
            'activity' => 'translation',
            'post_id' => 22,
        ]);

        $summary = polytrans_recorded_summary(22);

        expect($summary['unpriced_calls'])->toBe(1);
        // The tokens are still counted - only the money is unknown.
        expect($summary['tokens_input'])->toBe(5000);
        expect((float) $summary['total_usd'])->toBe(0.0);
        expect($this->wpdb->inserted[0]['data']['cost_usd'])->toBeNull();
    });

    it('adds Gemini thinking tokens on top of the output', function () {
        UsageRecorder::record([
            'provider' => 'gemini',
            'model' => 'gemini-3.5-flash',
            'usage' => [
                'promptTokenCount' => 100,
                'candidatesTokenCount' => 200,
                'thoughtsTokenCount' => 900,
            ],
            'activity' => 'translation',
            'post_id' => 22,
        ]);

        $row = $this->wpdb->inserted[0]['data'];
        $expected = (100 * 0.0000015) + (200 * 0.000009) + (900 * 0.000009);

        expect($row['tokens_reasoning'])->toBe(900);
        expect((float) $row['cost_usd'])->toBe(round($expected, 10));
    });
});

describe('writes that must not touch a post', function () {
    it('keeps a test run out of the post totals but still bills it', function () {
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(1000, 500),
            'activity' => 'workflow_step',
            'post_id' => 22,
            'source_post_id' => 11,
            'target_language' => 'de',
            'skip_post_meta' => true,
        ]);

        expect($this->wpdb->inserted)->toHaveCount(1);
        expect(polytrans_recorded_summary(22))->toBeEmpty();
        expect(polytrans_recorded_summary(11))->toBeEmpty();
    });

    it('ignores a post ID that belongs to another site', function () {
        // The translation endpoint may be called by a remote site, whose IDs would
        // otherwise attach costs to whatever local post happens to share the number.
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(1000, 500),
            'activity' => 'translation',
            'source_post_id' => 9999,
            'target_language' => 'de',
        ]);

        expect($this->wpdb->inserted[0]['data']['source_post_id'])->toBe(9999);
        expect(polytrans_recorded_summary(9999))->toBeEmpty();
    });
});

describe('rebuilding a summary from the table', function () {
    it('reproduces what accumulating the same rows produced', function () {
        // The table is the source of truth, so a rebuild must be indistinguishable
        // from a summary that grew call by call.
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(1000, 500, 200),
            'activity' => 'translation',
            'post_id' => 22,
            'source_post_id' => 11,
            'target_language' => 'de',
        ]);
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(300, 100),
            'activity' => 'workflow_step',
            'step' => 'SEO',
            'post_id' => 22,
        ]);

        $grown = polytrans_recorded_summary(22);
        $rebuilt = UsageRecorder::rebuild_post_summary(22);

        // updated_at comes from the row, so both carry the same value.
        expect($rebuilt)->toEqual($grown);
        expect($rebuilt['calls'])->toBe(2);
        expect($rebuilt['by_activity'])->toHaveKeys(['translation', 'workflow_step']);
    });

    it('discards a summary the post has no rows for', function () {
        // This is the copied-from-another-post case: nothing in the table backs it.
        update_post_meta(22, UsageRecorder::META_SUMMARY, [
            'version' => 1,
            'total_usd' => '9.99',
            'calls' => 40,
        ]);

        $rebuilt = UsageRecorder::rebuild_post_summary(22);

        expect($rebuilt)->toBeNull();
        expect(polytrans_recorded_summary(22))->toBeEmpty();
    });

    it('keeps the per-language split when rebuilding an original', function () {
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(1000, 500),
            'activity' => 'translation',
            'source_post_id' => 11,
            'target_language' => 'de',
        ]);
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(2000, 100),
            'activity' => 'translation',
            'source_post_id' => 11,
            'target_language' => 'fr',
        ]);

        $rebuilt = UsageRecorder::rebuild_post_summary(11);

        expect(array_keys($rebuilt['by_language']))->toBe(['de', 'fr']);
        expect($rebuilt['by_language']['fr']['tokens_input'])->toBe(2000);
    });

    it('does not turn an unpriced call into a free one', function () {
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'model-nobody-lists',
            'usage' => polytrans_usage_payload(500, 500),
            'activity' => 'translation',
            'post_id' => 22,
        ]);

        $rebuilt = UsageRecorder::rebuild_post_summary(22);

        expect($rebuilt['unpriced_calls'])->toBe(1);
        expect((float) $rebuilt['total_usd'])->toBe(0.0);
    });
});

describe('notification', function () {
    it('announces each stored record', function () {
        UsageRecorder::record([
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'usage' => polytrans_usage_payload(),
            'activity' => 'translation',
            'post_id' => 22,
        ]);

        expect($GLOBALS['polytrans_test_actions']['polytrans_usage_recorded'] ?? [])->toHaveCount(1);
    });
});
