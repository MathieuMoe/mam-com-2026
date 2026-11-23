<?php
declare(strict_types=1);
// v2 statistics → shared tables of the main site.
//  {type:'pageview'} → visits (path starts with /v2/, so both versions can be told apart)
//  {type:'leave'}    → events time_on_page (same name as the main site) + scroll_depth
//  {type:'event'}    → events (clicks, sections…)
//  {type:'journey'}  → v2_journeys (+ v2_clicks, v2_moves): anonymous path through the page, click & mouse maps
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/analytics.php';
require_same_origin();
$pdo = db();
if (!$pdo || is_bot()) {
    json_response(['ok' => true, 'skipped' => true]);
}
$in = read_json(24000);
analytics_schema($pdo);
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
        [$city, $region, $gcountry, $lat, $lon, $org] = geo_lookup($pdo);
        $screen = preg_match('/^\d{3,5}x\d{3,5}$/', (string)($in['screen'] ?? '')) ? $in['screen'] : null;
        $country = $gcountry ?: country_from_tz(clean($in['tz'] ?? '', 60));
        try {
            $pdo->prepare('INSERT INTO visits (visitor_hash, session_id, path, lang, referrer, referrer_host, campaign, device, browser, os, country, theme, city, region, lat, lon, org, screen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([visitor_hash(), $session, $path, $lang, $ref, $host, $campaign, $device, $browser, $os, $country, 'v2', $city, $region, $lat, $lon, $org, $screen]);
        } catch (Throwable $e) {   // schema not upgraded yet: keep the basic visit
            $pdo->prepare('INSERT INTO visits (visitor_hash, session_id, path, lang, referrer, referrer_host, campaign, device, browser, os, country, theme) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([visitor_hash(), $session, $path, $lang, $ref, $host, $campaign, $device, $browser, $os, $country, 'v2']);
        }
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
    } elseif ($type === 'journey') {
        // sections in order [[id, seconds], …] + timeline [[t, name, value], …]
        $seq = [];
        foreach (array_slice((array)($in['seq'] ?? []), 0, 40) as $p) {
            if (is_array($p) && ($id = analytics_section($p[0] ?? ''))) $seq[] = [$id, max(0, min(3600, (int)($p[1] ?? 0)))];
        }
        $ev = [];
        foreach (array_slice((array)($in['ev'] ?? []), 0, 80) as $e) {
            if (is_array($e)) $ev[] = [max(0, min(7200, (int)($e[0] ?? 0))), preg_replace('/[^a-z0-9_]/', '', strtolower((string)($e[1] ?? ''))), clean($e[2] ?? '', 60)];
        }
        $clicks = array_slice((array)($in['clicks'] ?? []), 0, 40);
        $rage = $dead = 0;
        $ins = $pdo->prepare('INSERT INTO v2_clicks (session_id, section, x, y, vw, kind, target) VALUES (?,?,?,?,?,?,?)');
        foreach ($clicks as $c) {
            if (!is_array($c) || !($sec = analytics_section($c[0] ?? ''))) continue;
            $kind = in_array($c[4] ?? '', ['ui', 'dead', 'rage'], true) ? $c[4] : 'ui';
            $rage += $kind === 'rage'; $dead += $kind === 'dead';
            $ins->execute([$session, $sec, max(0, min(1000, (int)($c[1] ?? 0))), max(0, min(1000, (int)($c[2] ?? 0))), max(0, min(9999, (int)($c[3] ?? 0))), $kind, clean($c[5] ?? '', 80) ?: null]);
        }
        // mouse movement: aggregated 20×20 grid per section, no session link at all
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $up = $pdo->prepare($sqlite ? 'INSERT INTO v2_moves (section, cell, n) VALUES (?,?,?) ON CONFLICT(section, cell) DO UPDATE SET n = n + excluded.n'
                                    : 'INSERT INTO v2_moves (section, cell, n) VALUES (?,?,?) ON DUPLICATE KEY UPDATE n = n + VALUES(n)');
        foreach (array_slice((array)($in['moves'] ?? []), 0, 14, true) as $sec => $cells) {
            if (!($sec = analytics_section($sec)) || !is_array($cells)) continue;
            foreach (array_slice($cells, 0, 400, true) as $cell => $n) {
                $cell = (int)$cell; $n = (int)$n;
                if ($cell >= 0 && $cell < 400 && $n > 0) $up->execute([$sec, $cell, min($n, 500)]);
            }
        }
        $data = json_encode(['seq' => $seq, 'ev' => $ev, 'vp' => clean($in['vp'] ?? '', 11)], JSON_UNESCAPED_UNICODE);
        $vals = [max(0, min(7200, (int)($in['seconds'] ?? 0))), max(0, min(100, (int)($in['scroll'] ?? 0))), count(array_unique(array_column($seq, 0))),
                 max(0, min(9999, (int)($in['nclicks'] ?? 0))), analytics_section(end($seq)[0] ?? '') , $data];
        $pdo->prepare($sqlite
            ? 'INSERT INTO v2_journeys (seconds, max_scroll, sections, clicks, exit_section, data, rage, dead, session_id) VALUES (?,?,?,?,?,?,?,?,?)
               ON CONFLICT(session_id) DO UPDATE SET seconds = excluded.seconds, max_scroll = excluded.max_scroll, sections = excluded.sections, clicks = excluded.clicks,
               exit_section = excluded.exit_section, data = excluded.data, rage = rage + excluded.rage, dead = dead + excluded.dead, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO v2_journeys (seconds, max_scroll, sections, clicks, exit_section, data, rage, dead, session_id) VALUES (?,?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE seconds = VALUES(seconds), max_scroll = VALUES(max_scroll), sections = VALUES(sections), clicks = VALUES(clicks),
               exit_section = VALUES(exit_section), data = VALUES(data), rage = rage + VALUES(rage), dead = dead + VALUES(dead), updated_at = CURRENT_TIMESTAMP')
            ->execute(array_merge($vals, [$rage, $dead, $session]));
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
