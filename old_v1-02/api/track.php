<?php
/** POST {type:'pageview'|'event', session, campaign, lang, ...} */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);
same_origin_only();
if (is_bot()) json_out(['ok' => true, 'skipped' => 'bot']);

try {
    rate_limit('track', 120, 60);
    $in       = json_in();
    $session  = clean($in['session'] ?? '', 64) ?: 'none';
    $campaign = slug($in['campaign'] ?? '') ?: null;
    $lang     = lang_code($in['lang'] ?? null);
    $hash     = visitor_hash();

    if (($in['type'] ?? '') === 'pageview') {
        [$device, $browser, $os] = parse_ua();
        $ref  = clean($in['referrer'] ?? '', 255) ?: null;
        $host = $ref ? (parse_url($ref, PHP_URL_HOST) ?: null) : null;
        if ($host && $host === ($_SERVER['HTTP_HOST'] ?? '')) { $ref = null; $host = null; }
        db()->prepare('INSERT INTO visits (visitor_hash, session_id, path, lang, referrer, referrer_host, campaign, device, browser, os, country, theme)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$hash, $session, clean($in['path'] ?? '/', 255), $lang, $ref, $host, $campaign, $device, $browser, $os, country_guess(), clean($in['theme'] ?? '', 10) ?: null]);
    } else {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($in['name'] ?? '')));
        if ($name === '') json_out(['ok' => false, 'error' => 'name'], 400);
        db()->prepare('INSERT INTO events (visitor_hash, session_id, name, value, campaign, lang) VALUES (?,?,?,?,?,?)')
            ->execute([$hash, $session, substr($name, 0, 40), clean($in['value'] ?? '', 120) ?: null, $campaign, $lang]);
    }
    json_out(['ok' => true]);
} catch (Throwable $e) {
    error_log('[track] ' . $e->getMessage());
    json_out(['ok' => false], 500);
}
