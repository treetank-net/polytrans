<?php

declare(strict_types=1);

/**
 * Unit Tests: Usage report queries
 *
 * The group column of an aggregate cannot be passed as a prepared value, so it is
 * interpolated - which makes the allow-list the only thing standing between a
 * request parameter and the query. That is what most of this file is about.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Core\UsageReport;

if (!function_exists('get_the_title')) {
    function get_the_title($post_id)
    {
        return $GLOBALS['polytrans_test_titles'][$post_id] ?? '';
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($post_id, $context = 'display')
    {
        return 'https://example.com/wp-admin/post.php?post=' . $post_id . '&action=edit';
    }
}

/**
 * Records every query it is handed, so the built SQL can be asserted on.
 */
final class PolyTransRecordingWpdb
{
    public $prefix = 'wp_';
    public $queries = [];
    public $rows = [];
    public $row = null;
    public $table_present = true;

    public function prepare($query, ...$args)
    {
        // Mirrors the placeholder substitution closely enough to assert on the result;
        // the real prepare() also quotes strings, which the assertions allow for.
        $values = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;

        foreach ($values as $value) {
            $replacement = is_int($value) || is_float($value) ? (string) $value : "'" . $value . "'";
            $query = preg_replace('/%[dsf]/', $replacement, $query, 1);
        }

        return $query;
    }

    public function get_var($query)
    {
        $this->queries[] = $query;

        if (stripos($query, 'SHOW TABLES') !== false) {
            return $this->table_present ? 'wp_polytrans_usage' : null;
        }

        return null;
    }

    public function get_row($query, $output = null)
    {
        $this->queries[] = $query;

        return $this->row;
    }

    public function get_results($query, $output = null)
    {
        $this->queries[] = $query;

        return $this->rows;
    }

    public function get_col($query)
    {
        $this->queries[] = $query;

        return ['gpt-5.6-luna', 'claude-opus-4.6'];
    }

    /**
     * @return string The last query that was not the table-existence probe.
     */
    public function last_data_query()
    {
        foreach (array_reverse($this->queries) as $query) {
            if (stripos($query, 'SHOW TABLES') === false) {
                return $query;
            }
        }

        return '';
    }
}

beforeEach(function () {
    global $wpdb;

    $wpdb = new PolyTransRecordingWpdb();
    $this->wpdb = $wpdb;
    $GLOBALS['polytrans_test_titles'] = [11 => 'Artykuł o cłach'];
});

describe('grouping safety', function () {
    it('refuses a column that is not on the allow-list', function () {
        // The column arrives from a request parameter in the dashboard.
        $result = UsageReport::by('model; DROP TABLE wp_posts', ['days' => 30]);

        expect($result)->toBe([]);
        expect($this->wpdb->last_data_query())->toBe('');
    });

    it('groups on each allowed column', function () {
        foreach (['model', 'provider', 'source_language', 'target_language', 'final_language', 'translation_path', 'path_step', 'activity', 'workflow_id'] as $column) {
            $this->wpdb->queries = [];
            UsageReport::by($column);

            expect($this->wpdb->last_data_query())->toContain('GROUP BY ' . $column);
        }
    });

    it('groups on a dimension that is an expression rather than a column', function () {
        // The hop 'pl>en' is not stored as such; it is the pair of languages the call
        // read and wrote. The expression still comes from the class, never the caller.
        UsageReport::by('language_pair');

        expect($this->wpdb->last_data_query())->toContain("CONCAT(COALESCE(source_language, '?'), '>', COALESCE(target_language, '?'))");
    });

    it('keeps translation dimensions free of workflow calls', function () {
        foreach (['language_pair', 'translation_path', 'path_step'] as $column) {
            $this->wpdb->queries = [];
            UsageReport::by($column);

            expect($this->wpdb->last_data_query())->toContain("activity = 'translation'");
        }
    });

    it('does not query translation dimensions for a workflow-only filter', function () {
        $this->wpdb->queries = [];

        expect(UsageReport::by('language_pair', ['activity' => 'workflow_step']))->toBe([]);
        expect($this->wpdb->last_data_query())->toBe('');
    });

    it('keeps the workflow dimension free of translation calls', function () {
        UsageReport::by('workflow_id');

        expect($this->wpdb->last_data_query())->toContain("activity = 'workflow_step'");
    });

    it('does not query the workflow dimension for a translation-only filter', function () {
        $this->wpdb->queries = [];

        expect(UsageReport::by('workflow_id', ['activity' => 'translation']))->toBe([]);
        expect($this->wpdb->last_data_query())->toBe('');
    });

    it('lists the dimensions it accepts, expressions included', function () {
        expect(UsageReport::dimensions())->toContain('final_language')->toContain('language_pair');
    });

    it('clamps the row limit', function () {
        UsageReport::by('model', [], 100000);

        expect($this->wpdb->last_data_query())->toContain('LIMIT 500');
    });
});

describe('filters', function () {
    it('binds the period as a number of days', function () {
        UsageReport::totals(['days' => 7]);

        expect($this->wpdb->last_data_query())->toContain('INTERVAL 7 DAY');
    });

    it('leaves the query unfiltered when the period is all time', function () {
        UsageReport::totals(['days' => 0]);

        expect($this->wpdb->last_data_query())->not->toContain('WHERE');
    });

    it('binds a model filter as a value', function () {
        UsageReport::totals(['model' => "gpt' OR 1=1 --"]);

        $query = $this->wpdb->last_data_query();

        // The value is quoted rather than becoming part of the statement.
        expect($query)->toContain("model = 'gpt' OR 1=1 --'");
        expect($query)->not->toContain('OR 1=1 --"');
    });

    it('filters a language by what the request was for, not by what a hop produced', function () {
        // Filtering on target_language would drop the pl→en hop from a pl→en→de
        // request, which is exactly the cost a per-market view must not lose.
        UsageReport::by('model', ['language' => 'de']);

        expect($this->wpdb->last_data_query())->toContain("final_language = 'de'");
    });

    it('can restrict the query to intermediate hops', function () {
        UsageReport::by('model', ['relay_only' => true]);

        expect($this->wpdb->last_data_query())->toContain('target_language <> final_language');
    });

    it('matches a post as either the source or the translation', function () {
        UsageReport::totals(['post_id' => 42]);

        expect($this->wpdb->last_data_query())->toContain('(post_id = 42 OR source_post_id = 42)');
    });

    it('restricts the article ranking to rows that name an original', function () {
        UsageReport::top_posts(['days' => 30]);

        expect($this->wpdb->last_data_query())->toContain('source_post_id IS NOT NULL');
    });
});

describe('result handling', function () {
    it('reports an unpriced group as unknown rather than zero', function () {
        // SUM() over rows that are all NULL is NULL, and must not read as free.
        $this->wpdb->rows = [[
            'label' => 'model-nobody-lists',
            'calls' => '4',
            'total_usd' => null,
            'unpriced_calls' => '4',
            'tokens_input' => '900',
            'tokens_output' => '100',
            'tokens_cached_read' => '0',
            'tokens_reasoning' => '0',
        ]];

        $rows = UsageReport::by('model');

        expect($rows[0]['total_usd'])->toBeNull();
        expect($rows[0]['cost_display'])->toBe('—');
        expect($rows[0]['calls'])->toBe(4);
        expect($rows[0]['unpriced_calls'])->toBe(4);
    });

    it('works out what share of the output was reasoning', function () {
        $this->wpdb->rows = [[
            'label' => 'gpt-5.6-luna',
            'calls' => '1',
            'total_usd' => '0.008',
            'unpriced_calls' => '0',
            'tokens_input' => '1751',
            'tokens_output' => '13382',
            'tokens_cached_read' => '0',
            'tokens_reasoning' => '9995',
        ]];

        // The measured run: three quarters of the output was thinking.
        expect(UsageReport::by('model')[0]['reasoning_share'])->toBe(75);
    });

    it('labels a group with no value instead of leaving it blank', function () {
        $this->wpdb->rows = [[
            'label' => null,
            'calls' => '2',
            'total_usd' => '0.001',
            'unpriced_calls' => '0',
            'tokens_input' => '10',
            'tokens_output' => '10',
            'tokens_cached_read' => '0',
            'tokens_reasoning' => '0',
        ]];

        // Named rather than blank: on the model breakdown an empty group means the
        // provider reported no model, which a reader must be able to see.
        expect(UsageReport::by('workflow_id')[0]['label'])->toBe('not reported');
    });

    it('returns zeroed totals when the table is not there yet', function () {
        $this->wpdb->table_present = false;

        $totals = UsageReport::totals(['days' => 30]);

        expect($totals['calls'])->toBe(0);
        expect($totals['total_usd'])->toBeNull();
        expect(UsageReport::by('model'))->toBe([]);
        expect(UsageReport::daily())->toBe([]);
        expect(UsageReport::top_posts())->toBe([]);
    });

    it('attaches the title and edit link to ranked articles', function () {
        $this->wpdb->rows = [[
            'post_id' => '11',
            'calls' => '5',
            'total_usd' => '0.0412',
            'unpriced_calls' => '0',
            'tokens_input' => '9000',
            'tokens_output' => '4000',
            'tokens_cached_read' => '0',
            'tokens_reasoning' => '3000',
            'languages' => '3',
        ]];

        $row = UsageReport::top_posts()[0];

        expect($row['post_id'])->toBe(11);
        expect($row['title'])->toBe('Artykuł o cłach');
        expect($row['languages'])->toBe(3);
        expect($row['edit_link'])->toContain('post=11');
    });

    it('aggregates the complete process per translation run', function () {
        $this->wpdb->rows = [[
            'run_id' => '11111111-1111-4111-8111-111111111111',
            'created_at' => '2026-08-07 12:00:00',
            'completed_at' => '2026-08-07 12:03:00',
            'status' => 'completed',
            'source_post_id' => '11',
            'translated_post_id' => '22',
            'source_language' => 'pl',
            'target_language' => 'de',
            'translation_path' => 'pl>en>de',
            'source_characters' => '1000',
            'source_words' => '200',
            'calls' => '5',
            'translation_calls' => '2',
            'workflow_calls' => '3',
            'unpriced_calls' => '0',
            'total_usd' => '0.0123',
            'translation_usd' => '0.0050',
            'workflow_usd' => '0.0073',
            'tokens_input' => '9000',
            'tokens_output' => '4000',
            'tokens_cached_read' => '0',
            'tokens_reasoning' => '2000',
        ]];

        $rows = UsageReport::translation_runs(['days' => 30, 'activity' => 'workflow_step']);
        $row = $rows[0];

        expect($this->wpdb->last_data_query())
            ->toContain('LEFT JOIN wp_polytrans_usage u ON u.run_id = r.run_id')
            ->toContain('EXISTS (SELECT 1 FROM wp_polytrans_usage uf_activity')
            ->toContain('r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
        expect($row['title'])->toBe('Artykuł o cłach');
        expect($row['calls'])->toBe(5);
        expect($row['translation_calls'])->toBe(2);
        expect($row['workflow_calls'])->toBe(3);
        expect($row['source_characters'])->toBe(1000);
        expect($row['source_words'])->toBe(200);
        expect($row['total_cost_display'])->toBe('$0.0123');
        expect($row['cost_per_1000_characters_display'])->toBe('$0.0123');
        expect($row['cost_per_1000_words_display'])->toBe('$0.0615');
    });

    it('aggregates source volume once per run for process summary statistics', function () {
        $this->wpdb->row = [
            'runs' => '2',
            'completed_runs' => '2',
            'running_runs' => '0',
            'failed_runs' => '0',
            'source_characters' => '3000',
            'source_words' => '600',
            'calls' => '10',
            'translation_calls' => '4',
            'workflow_calls' => '6',
            'unpriced_calls' => '0',
            'total_usd' => '0.0246',
            'translation_usd' => '0.0100',
            'workflow_usd' => '0.0146',
            'tokens_input' => '18000',
            'tokens_output' => '8000',
            'tokens_cached_read' => '0',
            'tokens_reasoning' => '4000',
        ];

        $totals = UsageReport::translation_run_totals(['days' => 30]);

        expect($this->wpdb->last_data_query())
            ->toContain('LEFT JOIN (')
            ->toContain('GROUP BY run_id')
            ->toContain('r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
        expect($totals['runs'])->toBe(2);
        expect($totals['source_characters'])->toBe(3000);
        expect($totals['source_words'])->toBe(600);
        expect($totals['calls'])->toBe(10);
        expect($totals['cost_per_1000_characters_display'])->toBe('$0.0082');
        expect($totals['cost_per_1000_words_display'])->toBe('$0.0410');
        expect($totals['workflow_share'])->toBe(59);
    });
});

describe('display formatting', function () {
    it('keeps fractions of a cent visible', function () {
        // Two decimals would render most single calls as $0.00.
        expect(UsageReport::format_usd('0.0000006'))->toBe('$0.000001');
        expect(UsageReport::format_usd('0.00805'))->toBe('$0.00805');
    });

    it('drops to cents for amounts worth reading in cents', function () {
        expect(UsageReport::format_usd('12.8412'))->toBe('$12.84');
        expect(UsageReport::format_usd('0.5'))->toBe('$0.5000');
    });

    it('distinguishes nothing spent from nothing known', function () {
        expect(UsageReport::format_usd('0'))->toBe('$0');
        expect(UsageReport::format_usd(null))->toBe('—');
    });

    it('abbreviates large token counts', function () {
        expect(UsageReport::format_tokens(412))->toBe('412');
        expect(UsageReport::format_tokens(13382))->toBe('13.4k');
        expect(UsageReport::format_tokens(2500000))->toBe('2.5M');
    });
});
