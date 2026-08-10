<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Usage Window
 *
 * The period a usage report covers, and the resolution it is bucketed at.
 *
 * The two live together because they constrain each other: an hourly series over a
 * year is 8,760 points nobody can read and no page should render, so a requested
 * resolution is only honoured while the range still allows it.
 *
 * Every instant here is site-local wall-clock time, because that is what the table
 * holds: UsageRecorder stamps rows with current_time('mysql'). Comparing them against
 * the database server's NOW() - whatever timezone that server happens to run in,
 * commonly UTC - skews the window by the site's offset. Across thirty days the skew
 * is invisible, which is why it survived this long; across a two-hour window it is
 * the entire result.
 *
 * The range is half-open, [from, to). A row on the boundary then belongs to exactly
 * one bucket and one period, which is what makes two windows safe to compare.
 */
class UsageWindow
{
    const BUCKET_AUTO = 'auto';
    const BUCKET_HOUR = 'hour';
    const BUCKET_DAY = 'day';
    const BUCKET_WEEK = 'week';
    const BUCKET_MONTH = 'month';

    const DEFAULT_PRESET = '30d';
    const PRESET_CUSTOM = 'custom';
    const PRESET_ALL = 'all';

    /**
     * Resolutions from finest to coarsest. A range too long for one downgrades to
     * the next, so the order is load-bearing.
     *
     * @var array
     */
    private static $buckets = [
        self::BUCKET_HOUR,
        self::BUCKET_DAY,
        self::BUCKET_WEEK,
        self::BUCKET_MONTH,
    ];

    /**
     * Most points each resolution may produce.
     *
     * The hourly cap is the one that matters: a fortnight of hours is 336 bars, which
     * is about as much as a chart can carry and still be read. Past that the answer
     * is a coarser bucket, not a wider chart.
     *
     * @var array
     */
    private static $max_buckets = [
        self::BUCKET_HOUR => 336,
        self::BUCKET_DAY => 400,
        self::BUCKET_WEEK => 260,
        self::BUCKET_MONTH => 1200,
    ];

    /**
     * How PHP writes the key for each resolution. These must match what the SQL
     * bucket expressions in UsageReport return, or a filled series lines up with
     * nothing and every bucket reads as empty.
     *
     * @var array
     */
    private static $key_formats = [
        self::BUCKET_HOUR => 'Y-m-d H:i:s',
        self::BUCKET_DAY => 'Y-m-d',
        self::BUCKET_WEEK => 'Y-m-d',
        self::BUCKET_MONTH => 'Y-m-d',
    ];

    /**
     * @var \DateTimeImmutable
     */
    private $from;

    /**
     * @var \DateTimeImmutable
     */
    private $to;

    /**
     * @var string
     */
    private $preset;

    /**
     * @var string Resolution that was asked for, self::BUCKET_AUTO included.
     */
    private $requested_bucket;

    /**
     * @var string Resolution actually used.
     */
    private $bucket;

    /**
     * @param \DateTimeImmutable $from   Start, inclusive.
     * @param \DateTimeImmutable $to     End, exclusive.
     * @param string             $bucket Requested resolution.
     * @param string             $preset Preset this came from.
     */
    private function __construct($from, $to, $bucket, $preset)
    {
        $this->from = $from;
        $this->to = $to;
        $this->preset = $preset;
        $this->requested_bucket = $bucket;
        $this->bucket = $this->resolve_bucket($bucket);
    }

    /**
     * Build a window from request input.
     *
     * @param array                   $input {
     *     @type string $preset Preset key, or 'custom'.
     *     @type string $from   Start, when the preset is 'custom'.
     *     @type string $to     End, when the preset is 'custom'.
     *     @type string $bucket Requested resolution.
     * }
     * @param \DateTimeImmutable|null $earliest Oldest recorded call, for the 'all' preset.
     *                                          Without it 'all' falls back to the default length.
     * @return self
     */
    public static function from_request(array $input, $earliest = null)
    {
        $preset = isset($input['preset']) ? (string) $input['preset'] : self::DEFAULT_PRESET;

        if (!array_key_exists($preset, self::preset_labels())) {
            $preset = self::DEFAULT_PRESET;
        }

        $bucket = isset($input['bucket']) ? (string) $input['bucket'] : self::BUCKET_AUTO;

        if ($bucket !== self::BUCKET_AUTO && !in_array($bucket, self::$buckets, true)) {
            $bucket = self::BUCKET_AUTO;
        }

        $now = self::now();

        if ($preset === self::PRESET_CUSTOM) {
            [$from, $to] = self::custom_bounds($input, $now);
        } else {
            [$from, $to] = self::preset_bounds($preset, $now, $earliest);
        }

        return new self($from, $to, $bucket, $preset);
    }

    /**
     * Build a window from two instants directly.
     *
     * @param \DateTimeImmutable $from   Start, inclusive.
     * @param \DateTimeImmutable $to     End, exclusive.
     * @param string             $bucket Requested resolution.
     * @return self
     */
    public static function create($from, $to, $bucket = self::BUCKET_AUTO)
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self($from, $to, $bucket, self::PRESET_CUSTOM);
    }

    /**
     * Bounds for a hand-entered range.
     *
     * A half-filled form is completed rather than rejected: an empty end means "up to
     * now", an empty start means the default length back from the end. Someone
     * narrowing a range one field at a time should see a report, not an error.
     *
     * @param array              $input Request input.
     * @param \DateTimeImmutable $now   Current instant.
     * @return array [\DateTimeImmutable $from, \DateTimeImmutable $to]
     */
    private static function custom_bounds(array $input, $now)
    {
        $from = self::parse($input['from'] ?? '');
        $to = self::parse($input['to'] ?? '');

        if ($from === null && $to === null) {
            return self::preset_bounds(self::DEFAULT_PRESET, $now, null);
        }

        $to = $to ?? $now;
        $from = $from ?? $to->modify('-29 days')->setTime(0, 0);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($from == $to) {
            // A zero-length range reports nothing at all, which reads as a broken page
            // rather than as an empty period. One bucket's worth is the smallest
            // answer that still means something.
            $to = $from->modify('+1 hour');
        }

        return [$from, $to];
    }

    /**
     * Bounds for a named preset.
     *
     * The day-scale presets start at midnight rather than at this moment minus N days,
     * so "last 7 days" is seven whole day buckets instead of eight, the first and last
     * of them part-full.
     *
     * @param string                  $preset   Preset key.
     * @param \DateTimeImmutable      $now      Current instant.
     * @param \DateTimeImmutable|null $earliest Oldest recorded call.
     * @return array [\DateTimeImmutable $from, \DateTimeImmutable $to]
     */
    private static function preset_bounds($preset, $now, $earliest)
    {
        $midnight = $now->setTime(0, 0);

        switch ($preset) {
            case '24h':
                return [self::floor_hour($now->modify('-23 hours')), $now];

            case 'today':
                return [$midnight, $now];

            case 'yesterday':
                return [$midnight->modify('-1 day'), $midnight];

            case '7d':
                return [$midnight->modify('-6 days'), $now];

            case '90d':
                return [$midnight->modify('-89 days'), $now];

            case '12m':
                return [$midnight->modify('first day of this month')->modify('-11 months'), $now];

            case self::PRESET_ALL:
                return [$earliest instanceof \DateTimeImmutable ? $earliest : $midnight->modify('-29 days'), $now];

            case '30d':
            default:
                return [$midnight->modify('-29 days'), $now];
        }
    }

    /**
     * Pick the resolution to actually use.
     *
     * A resolution the range cannot carry is stepped down rather than refused, and
     * bucket_downgraded() lets the page say so. Silently returning 8,000 points, or
     * silently returning nothing, are both worse.
     *
     * @param string $requested Requested resolution.
     * @return string
     */
    private function resolve_bucket($requested)
    {
        $candidate = $requested === self::BUCKET_AUTO ? $this->auto_bucket() : $requested;
        $start = array_search($candidate, self::$buckets, true);

        if ($start === false) {
            $start = array_search(self::BUCKET_DAY, self::$buckets, true);
        }

        for ($i = $start; $i < count(self::$buckets); $i++) {
            $bucket = self::$buckets[$i];

            if ($this->estimate_bucket_count($bucket) <= self::$max_buckets[$bucket]) {
                return $bucket;
            }
        }

        return self::BUCKET_MONTH;
    }

    /**
     * The resolution a range of this length reads best at.
     *
     * @return string
     */
    private function auto_bucket()
    {
        $days = $this->length_seconds() / 86400;

        if ($days <= 3) {
            return self::BUCKET_HOUR;
        }

        if ($days <= 92) {
            return self::BUCKET_DAY;
        }

        if ($days <= 730) {
            return self::BUCKET_WEEK;
        }

        return self::BUCKET_MONTH;
    }

    /**
     * Roughly how many points a resolution would produce.
     *
     * Deliberately arithmetic rather than exact: it only decides which resolution to
     * use, and a daylight-saving change being one hour out cannot move a range across
     * a cap that is hundreds of buckets wide. bucket_starts() does the real date maths.
     *
     * @param string $bucket Resolution.
     * @return int
     */
    private function estimate_bucket_count($bucket)
    {
        $seconds = max(1, $this->length_seconds());

        switch ($bucket) {
            case self::BUCKET_HOUR:
                return (int) ceil($seconds / 3600) + 1;

            case self::BUCKET_DAY:
                return (int) ceil($seconds / 86400) + 1;

            case self::BUCKET_WEEK:
                return (int) ceil($seconds / (86400 * 7)) + 1;

            case self::BUCKET_MONTH:
            default:
                return (int) ceil($seconds / (86400 * 30)) + 1;
        }
    }

    /**
     * Every bucket key in the window, in order.
     *
     * This is what turns a sparse result set into a series: the database only returns
     * buckets that contain rows, and a chart drawn from those alone compresses a quiet
     * night into nothing and misreports the shape of everything around it.
     *
     * @return array List of bucket keys.
     */
    public function bucket_starts()
    {
        $cursor = $this->align($this->from, $this->bucket);
        $keys = [];
        $format = self::$key_formats[$this->bucket];
        $limit = self::$max_buckets[$this->bucket] + 2;

        while ($cursor < $this->to && count($keys) < $limit) {
            $key = $cursor->format($format);

            // An hour repeats when the clocks go back, and the stored wall-clock time
            // cannot tell the two apart either. One bucket is the honest answer.
            if (!isset($keys[$key])) {
                $keys[$key] = true;
            }

            $cursor = $this->advance($cursor);
        }

        return array_keys($keys);
    }

    /**
     * Snap an instant back to the start of its bucket.
     *
     * @param \DateTimeImmutable $instant Instant.
     * @param string             $bucket  Resolution.
     * @return \DateTimeImmutable
     */
    private function align($instant, $bucket)
    {
        switch ($bucket) {
            case self::BUCKET_HOUR:
                return self::floor_hour($instant);

            case self::BUCKET_WEEK:
                // Monday, to match WEEKDAY() in the SQL term. YEARWEEK() would have
                // been shorter and would have depended on the server's week mode.
                return $instant->setTime(0, 0)->modify('-' . ((int) $instant->format('N') - 1) . ' days');

            case self::BUCKET_MONTH:
                return $instant->setTime(0, 0)->modify('first day of this month');

            case self::BUCKET_DAY:
            default:
                return $instant->setTime(0, 0);
        }
    }

    /**
     * Step to the next bucket.
     *
     * @param \DateTimeImmutable $cursor Current bucket start.
     * @return \DateTimeImmutable
     */
    private function advance($cursor)
    {
        switch ($this->bucket) {
            case self::BUCKET_HOUR:
                return $cursor->modify('+1 hour');

            case self::BUCKET_WEEK:
                return $cursor->modify('+7 days');

            case self::BUCKET_MONTH:
                return $cursor->modify('+1 month');

            case self::BUCKET_DAY:
            default:
                return $cursor->modify('+1 day');
        }
    }

    /**
     * Whether a bucket is the one the window ends inside.
     *
     * Its total is real but incomplete, and a chart that does not mark it invites the
     * reading that costs just collapsed.
     *
     * @param string $key Bucket key.
     * @return bool
     */
    public function is_partial_bucket($key)
    {
        $start = $this->parse_key($key);

        if ($start === null) {
            return false;
        }

        return $this->advance($start) > $this->to;
    }

    /**
     * A bucket key as a display label.
     *
     * @param string $key Bucket key.
     * @return string
     */
    public function format_label($key)
    {
        $start = $this->parse_key($key);

        if ($start === null) {
            return (string) $key;
        }

        switch ($this->bucket) {
            case self::BUCKET_HOUR:
                return self::wp_format('j M, H:i', $start);

            case self::BUCKET_MONTH:
                return self::wp_format('M Y', $start);

            case self::BUCKET_WEEK:
            case self::BUCKET_DAY:
            default:
                return self::wp_format('j M', $start);
        }
    }

    /**
     * The window itself, for a page heading.
     *
     * @return string
     */
    public function format_range()
    {
        return sprintf(
            /* translators: 1: range start, 2: range end, 3: timezone name. */
            __('%1$s to %2$s (%3$s)', 'polytrans'),
            self::wp_format('j M Y, H:i', $this->from),
            self::wp_format('j M Y, H:i', $this->to),
            self::timezone_label()
        );
    }

    /**
     * Parse a bucket key back into an instant.
     *
     * @param string $key Bucket key.
     * @return \DateTimeImmutable|null
     */
    private function parse_key($key)
    {
        $key = (string) $key;

        foreach (['!Y-m-d H:i:s', '!Y-m-d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $key, self::timezone());

            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @return array Filter arguments naming this range, for UsageReport.
     */
    public function args()
    {
        return [
            'from' => $this->sql_from(),
            'to' => $this->sql_to(),
        ];
    }

    /**
     * @return string Range start, as the table stores instants.
     */
    public function sql_from()
    {
        return $this->from->format('Y-m-d H:i:s');
    }

    /**
     * @return string Range end, exclusive.
     */
    public function sql_to()
    {
        return $this->to->format('Y-m-d H:i:s');
    }

    /**
     * @return string Range start, for a datetime-local input.
     */
    public function input_from()
    {
        return $this->from->format('Y-m-d\TH:i');
    }

    /**
     * @return string Range end, for a datetime-local input.
     */
    public function input_to()
    {
        return $this->to->format('Y-m-d\TH:i');
    }

    /**
     * @return string Preset this window came from.
     */
    public function preset()
    {
        return $this->preset;
    }

    /**
     * @return string Resolution in use.
     */
    public function bucket()
    {
        return $this->bucket;
    }

    /**
     * @return string Resolution that was asked for.
     */
    public function requested_bucket()
    {
        return $this->requested_bucket;
    }

    /**
     * @return bool Whether the range was too long for the resolution requested.
     */
    public function bucket_downgraded()
    {
        return $this->requested_bucket !== self::BUCKET_AUTO
            && $this->requested_bucket !== $this->bucket;
    }

    /**
     * @return int Length of the window in seconds.
     */
    public function length_seconds()
    {
        return max(0, $this->to->getTimestamp() - $this->from->getTimestamp());
    }

    /**
     * @return array Preset options as value => label.
     */
    public static function preset_labels()
    {
        return [
            '24h' => __('Last 24 hours', 'polytrans'),
            'today' => __('Today', 'polytrans'),
            'yesterday' => __('Yesterday', 'polytrans'),
            '7d' => __('Last 7 days', 'polytrans'),
            '30d' => __('Last 30 days', 'polytrans'),
            '90d' => __('Last 90 days', 'polytrans'),
            '12m' => __('Last 12 months', 'polytrans'),
            self::PRESET_ALL => __('All time', 'polytrans'),
            self::PRESET_CUSTOM => __('Custom range', 'polytrans'),
        ];
    }

    /**
     * @return array Resolution options as value => label.
     */
    public static function bucket_labels()
    {
        return [
            self::BUCKET_AUTO => __('Automatic resolution', 'polytrans'),
            self::BUCKET_HOUR => __('Hourly', 'polytrans'),
            self::BUCKET_DAY => __('Daily', 'polytrans'),
            self::BUCKET_WEEK => __('Weekly', 'polytrans'),
            self::BUCKET_MONTH => __('Monthly', 'polytrans'),
        ];
    }

    /**
     * Parse a user-supplied instant.
     *
     * A datetime-local input posts 'Y-m-d\TH:i'; a hand-edited URL is as likely to
     * carry a plain date. Anything that does not round-trip exactly is refused rather
     * than guessed at - strtotime() would read a typo as some date nobody asked for
     * and the report would look fine while covering the wrong week.
     *
     * @param string $value Raw value.
     * @return \DateTimeImmutable|null
     */
    private static function parse($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // The '!' resets the fields the format does not mention, so a bare date means
        // midnight rather than the current time on that date.
        $formats = [
            '!Y-m-d\TH:i:s' => 'Y-m-d\TH:i:s',
            '!Y-m-d\TH:i' => 'Y-m-d\TH:i',
            '!Y-m-d H:i:s' => 'Y-m-d H:i:s',
            '!Y-m-d H:i' => 'Y-m-d H:i',
            '!Y-m-d' => 'Y-m-d',
        ];

        foreach ($formats as $parse_format => $check_format) {
            $parsed = \DateTimeImmutable::createFromFormat($parse_format, $value, self::timezone());

            if ($parsed instanceof \DateTimeImmutable && $parsed->format($check_format) === $value) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param \DateTimeImmutable $instant Instant.
     * @return \DateTimeImmutable The same instant at the top of its hour.
     */
    private static function floor_hour($instant)
    {
        return $instant->setTime((int) $instant->format('G'), 0);
    }

    /**
     * @return \DateTimeImmutable Now, in the site's timezone.
     */
    private static function now()
    {
        if (function_exists('current_datetime')) {
            return current_datetime();
        }

        return new \DateTimeImmutable('now', self::timezone());
    }

    /**
     * @return \DateTimeZone The site's timezone.
     */
    private static function timezone()
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        return new \DateTimeZone(date_default_timezone_get());
    }

    /**
     * @return string The site's timezone, as a reader recognises it.
     */
    private static function timezone_label()
    {
        if (function_exists('wp_timezone_string')) {
            return wp_timezone_string();
        }

        return self::timezone()->getName();
    }

    /**
     * Format an instant with translated month names.
     *
     * wp_date() converts from UTC, so it is handed the timestamp together with the
     * site timezone; passing the wall-clock string alone would shift every label by
     * the site's offset.
     *
     * @param string             $format  Date format.
     * @param \DateTimeImmutable $instant Instant.
     * @return string
     */
    private static function wp_format($format, $instant)
    {
        if (function_exists('wp_date')) {
            return wp_date($format, $instant->getTimestamp(), self::timezone());
        }

        return $instant->format($format);
    }
}
