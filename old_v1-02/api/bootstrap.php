<?php
/**
 * Shared helpers: config, PDO connection, JSON I/O, anonymous visitor hash,
 * rate limiting and a tiny User-Agent parser. PHP 8.0+.
 */
declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json');
    exit(json_encode(['ok' => false, 'error' => 'config.php missing — copy config.sample.php']));
}
$CONFIG = require __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $c = $CONFIG['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_in(): array {
    $raw = file_get_contents('php://input', false, null, 0, 20000) ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Only accept requests coming from our own site (basic CSRF/abuse protection). */
function same_origin_only(): void {
    global $CONFIG;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin === '') return; // sendBeacon may omit it; rate limit still applies
    $host = parse_url($origin, PHP_URL_HOST);
    $own  = [parse_url($CONFIG['site_url'], PHP_URL_HOST), $_SERVER['HTTP_HOST'] ?? ''];
    $own[] = 'www.' . $own[0];
    if (!in_array($host, $own, true)) json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Anonymous, daily-rotating visitor id — the IP itself is never stored. */
function visitor_hash(): string {
    global $CONFIG;
    return hash('sha256', client_ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . gmdate('Y-m-d') . '|' . $CONFIG['salt']);
}

function rate_limit(string $bucket, int $max, int $seconds): void {
    $key = substr($bucket . ':' . substr(visitor_hash(), 0, 32), 0, 100);
    $pdo = db();
    $pdo->prepare("INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, NOW())
                   ON DUPLICATE KEY UPDATE
                     hits = IF(window_start < NOW() - INTERVAL ? SECOND, 1, hits + 1),
                     window_start = IF(window_start < NOW() - INTERVAL ? SECOND, NOW(), window_start)")
        ->execute([$key, $seconds, $seconds]);
    $st = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ?');
    $st->execute([$key]);
    if ((int)$st->fetchColumn() > $max) json_out(['ok' => false, 'error' => 'rate_limited'], 429);
}

function clean(?string $s, int $max): string {
    $s = trim((string)$s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr($s, 0, $max);
}

function slug(?string $s): string {
    return substr(preg_replace('/[^a-z0-9-]/', '', strtolower((string)$s)) ?? '', 0, 60);
}

function lang_code(?string $l): string {
    return in_array($l, ['fr', 'en', 'es'], true) ? $l : 'fr';
}

function is_bot(): bool {
    return (bool)preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
}

/** Minimal UA parsing — good enough for a dashboard, no external library. */
function parse_ua(): array {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $device = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop');
    $browser = match (true) {
        str_contains($ua, 'Edg/')     => 'Edge',
        str_contains($ua, 'OPR/')     => 'Opera',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/')  => 'Chrome',
        str_contains($ua, 'Safari/')  => 'Safari',
        default                       => 'Other',
    };
    $os = match (true) {
        str_contains($ua, 'Windows')                            => 'Windows',
        (bool)preg_match('/iPhone|iPad|iOS/', $ua)              => 'iOS',
        str_contains($ua, 'Mac OS')                             => 'macOS',
        str_contains($ua, 'Android')                            => 'Android',
        str_contains($ua, 'Linux')                              => 'Linux',
        default                                                 => 'Other',
    };
    return [$device, $browser, $os];
}

/** Country guess from Accept-Language region (no GeoIP service, no IP lookup). */
function country_guess(): ?string {
    if (preg_match('/^[a-z]{2}-([A-Z]{2})/', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', $m)) return $m[1];
    return null;
}
