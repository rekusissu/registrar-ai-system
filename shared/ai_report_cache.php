<?php
// ============================================================
//  SHARED/AI_REPORT_CACHE.PHP
//  Reuses a generated report when the data it was written from
//  has not changed.
//
//  WHY THIS EXISTS
//
//  Generating a report is one synchronous call that costs 40-65
//  seconds. Before this, every click of "Generate AI Insight" paid
//  that full cost, including the clicks where nothing had changed
//  - re-opening a report already read, showing the same month to a
//  colleague, hitting the button twice because the first seemed slow.
//  The model does not know it is being asked for something it
//  already wrote, so it writes it again.
//
//  THE KEY IS A FINGERPRINT OF THE FACTS, NOT OF THE PERIOD.
//
//  A period-keyed cache would be wrong in the way that matters here:
//  it would serve last month's reading of this month's data, and a
//  registrar reading a stale analysis as if it were current is worse
//  than no analysis at all. Fingerprinting aiInsightBuild()'s output
//  means any change to any underlying figure produces a different
//  key, so a hit can only ever be a report built from exactly the
//  numbers on screen right now. The same numbers, same model, same
//  prompt gives the same analysis, so a hit is indistinguishable
//  from a miss - except it costs about a second.
//
//  The prompt contract version is part of the key too. Rewording the
//  seven headings has to invalidate the old reports, or the page
//  would keep serving prose that no longer matches the contract it
//  was written under.
//
//  Only SUCCESSFUL reports are stored. A failure is exactly the case
//  where the user most wants a retry, and caching one would convert a
//  transient gateway outage into a permanently broken period.
//
//  Storage is a file per key rather than a table. One office, a
//  handful of periods, no migration to run on shared hosting, and
//  nothing to leave behind if the office is ever reinstalled.
// ============================================================

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Bump when the prompt contract or the facts change shape. This is the
// "cache version"; it is part of every key, so bumping it retires every
// previously stored report without touching the filesystem.
if (!defined('AI_REPORT_CACHE_VERSION')) {
    define('AI_REPORT_CACHE_VERSION', '7s-2026-04');
}

// Old files are pruned on write. 60 days is long enough to cover
// "the same period, re-read after the enrolment rush", short enough
// that the directory does not accumulate one file per historical month
// forever.
if (!defined('AI_REPORT_CACHE_TTL_DAYS')) {
    define('AI_REPORT_CACHE_TTL_DAYS', 60);
}

/**
 * Point the cache at a different root, and forget whatever was resolved
 * before. Passing null restores the normal APP_ROOT location.
 *
 * This exists for tests, and for one specific reason worth recording.
 *
 * APP_ROOT is defined by shared/config.php, which many tests pull in as a
 * side effect of loading ai_client.php. A cache test that only sets APP_ROOT
 * "if not already defined" therefore inherits the REAL application root
 * whenever it happens to run after another test, and quietly writes its
 * fixtures into the live storage/ai-insights directory.
 *
 * That is not a cosmetic problem. Those files are named by the same keying
 * scheme as genuine reports, so a fixture containing the string
 * "## 1. Executive Summary" could be served to a real registrar as a real
 * analysis of their own period. The test would pass, and the cache would be
 * poisoned.
 *
 * An explicit override makes the isolation the caller's decision rather than
 * an accident of execution order.
 */
function aiReportCacheSetRoot(?string $root): void
{
    $GLOBALS['AI_REPORT_CACHE_ROOT'] = $root;
    // Drop the memo so the next call re-resolves against the new root.
    aiReportCacheResetMemo();
}

/**
 * Clear the memoised directory. Separate from the setter so a test can
 * simply ask for a re-check after creating the directory itself.
 */
function aiReportCacheResetMemo(): void
{
    $GLOBALS['AI_REPORT_CACHE_DIR'] = null;
}

/**
 * The directory reports are stored in, created on first use.
 *
 * Returns null when the directory cannot be created - a read-only
 * filesystem, a host with open_basedir, a permissions problem. That is
 * not an error worth failing a report over: caching is an optimisation,
 * and a miss is always correct, just slow.
 */
function aiReportCacheDir(): ?string
{
    // Memoised via $GLOBALS rather than a `static` local so an override
    // applied later in the same request can actually invalidate it.
    if (array_key_exists('AI_REPORT_CACHE_DIR', $GLOBALS)
        && $GLOBALS['AI_REPORT_CACHE_DIR'] !== null) {
        return $GLOBALS['AI_REPORT_CACHE_DIR'] === '' ? null : $GLOBALS['AI_REPORT_CACHE_DIR'];
    }

    $root = $GLOBALS['AI_REPORT_CACHE_ROOT'] ?? null;
    $path = ($root !== null && $root !== '' ? $root : APP_ROOT . DIRECTORY_SEPARATOR . 'storage')
          . DIRECTORY_SEPARATOR . 'ai-insights';

    if (!is_dir($path)) {
        // Suppressed: a failed mkdir is reported as a cache miss below,
        // not as a warning on every single report request.
        @mkdir($path, 0775, true);
    }

    $GLOBALS['AI_REPORT_CACHE_DIR'] = (is_dir($path) && is_writable($path)) ? $path : '';

    return $GLOBALS['AI_REPORT_CACHE_DIR'] === '' ? null : $GLOBALS['AI_REPORT_CACHE_DIR'];
}

/**
 * The cache key for a report.
 *
 * Hashing the facts (not the period) is what makes a hit trustworthy:
 * it is a statement about the DATA, not about the calendar. Include the
 * model, because a report written by one model is not interchangeable
 * with another, and the badge on screen says which one answered.
 */
function aiReportCacheKey(array $period, array $facts, ?string $model = null): string
{
    $factsJson = json_encode($facts);

    // A facts shape that will not encode would produce a hash of "false"
    // and collide with every other such failure. Refuse the key instead,
    // so the caller degrades to always calling the model.
    if ($factsJson === false) {
        return '';
    }
$material = [
        'version' => AI_REPORT_CACHE_VERSION,
        'period'  => $period['start'] . '|' . $period['end'],
        'model'   => (string) ($model ?? (defined('AI_MODEL') ? AI_MODEL : '')),
        'facts'   => $factsJson,
    ];

    return substr(hash('sha256', (string) json_encode($material)), 0, 32);
}


/**
 * Store a report. Failures are silent by design - see the class note on
 * aiReportCacheDir().
 *
 * Only a completed report is stored. Caching a failure would turn a
 * transient gateway outage into a permanently broken period, which is
 * the opposite of what the user needs after waiting 60 seconds for it.
 */
function aiReportCacheSet(string $key, array $payload): void
{
    if ($key === '' || empty($payload['report']) || !is_string($payload['report'])) {
        return;
    }

    $dir = aiReportCacheDir();
    if ($dir === null) {
        return;
    }

    $payload['stored_at'] = date('Y-m-d H:i:s');

    $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    // Write to a temporary name and rename into place: rename() is atomic
    // within a filesystem, so a concurrent reader sees either the old file
    // or the new one, never a half-written one.
    $tmp = $file . '.' . getmypid() . '.tmp';

    if (@file_put_contents($tmp, json_encode($payload)) !== false) {
        @rename($tmp, $file);
        @chmod($file, 0664);
    } else {
        @unlink($tmp);
    }

    aiReportCachePrune($dir);
}

/**
 * Drop reports older than the TTL. Called after a write, so the directory
 * is swept by normal use rather than needing a cron job the host may or
 * may not run.
 */
function aiReportCachePrune(?string $dir = null): void
{
    $dir = $dir ?? aiReportCacheDir();
    if ($dir === null) {
        return;
    }

    $cutoff = time() - (AI_REPORT_CACHE_TTL_DAYS * 86400);

    foreach (@glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $mtime = @filemtime($file);
        // An unreadable mtime means something odd is in the directory; leave
        // it alone rather than deleting a file we cannot reason about.
        if ($mtime !== false && $mtime < $cutoff) {
            @unlink($file);
        }
    }

    // Sweep temp files abandoned by a request that was killed mid-write.
    foreach (@glob($dir . DIRECTORY_SEPARATOR . '*.json.*.tmp') ?: [] as $tmp) {
        $mtime = @filemtime($tmp);
        if ($mtime !== false && $mtime < $cutoff) {
            @unlink($tmp);
        }
    }
}

/**
 * Read a stored report, or null on a miss.
 *
 * Returns the payload only when it is structurally complete. A truncated
 * or hand-edited file is treated as a miss: serving half a report would
 * be indistinguishable, to the person reading it, from the model having
 * produced half a report.
 */
function aiReportCacheGet(string $key): ?array
{
    if ($key === '') {
        return null;
    }

    $dir = aiReportCacheDir();
    if ($dir === null) {
        return null;
    }

    $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    if (!is_file($file) || !is_readable($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['report']) || !is_string($data['report'])) {
        return null;
    }

    return $data;
}
