<?php
declare(strict_types=1);
// v2 statistics → shared tables of the main site.
//  {type:'pageview'} → visits (path starts with /v2/, so both versions can be told apart)
//  {type:'leave'}    → events time_on_page (same name as the main site) + scroll_depth
//  {type:'event'}    → events (clicks, sections…)
require __DIR__ . '/../app/bootstrap.php';
require_same_origin();
$pdo = db();
if (!$pdo || is_bot()) {
    json_response(['ok' => true, 'skipped' => true]);
}
$in = read_json(4000);
$session = clean($in['session'] ?? '', 64) ?: 'none';
$campaign = slug($in['for'] ?? '') ?: null;
$lang = lang_code($in['lang'] ?? null);
$type = $in['type'] ?? '';

if (rate_limited('track', 150, 60)) {
    json_response(['ok' => false, 'error' => 'rate_limited'], 429);
}
try {
    if ($type === 'pageview') {
        [$device, $browser, $os] = parse_ua();
        $ref = clean($in['referrer'] ?? '', 255) ?: null;
        $host = $ref ? (parse_url($ref, PHP_URL_HOST) ?: null) : null;
        if ($host && $host === explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]) {
            $ref = $host = null;
        }
        $path = clean($in['path'] ?? '', 255);
        if (!str_starts_with($path, v2_base() . '/')) {
            $path = v2_base() . '/' . $lang . '/';
        }
        $pdo->prepare('INSERT INTO visits (visitor_hash, session_id, path, lang, referrer, referrer_host, campaign, device, browser, os, country, theme) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([visitor_hash(), $session, $path, $lang, $ref, $host, $campaign, $device, $browser, $os, country_from_tz(clean($in['tz'] ?? '', 60)), 'v2']);
        if ($utm = clean($in['utm'] ?? '', 60)) {
            log_event('utm_source', $utm, $session, $campaign, $lang);
        }
    } elseif ($type === 'leave') {
        $seconds = max(0, min(3600, (int)($in['seconds'] ?? 0)));
        $scroll = max(0, min(100, (int)($in['scroll'] ?? 0)));
        if ($seconds > 0) {
            log_event('time_on_page', (string)$seconds, $session, $campaign, $lang);
        }
        log_event('scroll_depth', (string)$scroll, $session, $campaign, $lang);
    } else {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($in['name'] ?? '')));
        if ($name === '') {
            json_response(['ok' => false, 'error' => 'name'], 400);
        }
        log_event($name, clean($in['value'] ?? '', 120) ?: null, $session, $campaign, $lang);
    }
    json_response(['ok' => true]);
} catch (Throwable $e) {
    error_log('[v2 track] ' . $e->getMessage());
    json_response(['ok' => false], 500);
}
