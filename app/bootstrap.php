<?php
declare(strict_types=1);

/**
 * Bootstrap of mam-com.com/v2/.
 * The v2 lives in its own folder but shares the database of the main site:
 *  - DB credentials, salt, admin password and e-mails come from the main site's api/config.php
 *  - visits, events, messages, campaigns and rate_limits are the main site's tables
 *  - v2-only data goes to the new tables v2_questions, v2_cv_requests and v2_campaign_extras
 */

const APP_DIR = __DIR__;
const LANGS = ['es', 'en', 'fr'];
define('SITE_ROOT', dirname(__DIR__, 2));   // www/ (parent of v2/)

function config(): array
{
    static $config = null;
    if ($config === null) {
        // www/api/config.php: the site's single config file (the site now lives at the root, the old version in /old/)
        $shared = dirname(APP_DIR) . '/api/config.php';
        if (!is_file($shared)) {
            $shared = SITE_ROOT . '/api/config.php';
        }
        if (!is_file($shared) && !empty($_SERVER['DOCUMENT_ROOT'])) {
            $shared = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/api/config.php';
        }
        $config = is_file($shared) ? require $shared : [];
        $local = APP_DIR . '/config.php';                       // optional: AI assistant, indexing
        $config['v2'] = is_file($local) ? require $local : (require APP_DIR . '/config.sample.php');
    }
    return $config;
}

/** Public URL of the v2, e.g. https://mam-com.com/v2 */
function v2_url(): string
{
    return rtrim(config()['site_url'] ?? '', '/') . v2_base();
}

/** Path prefix of the v2 folder (e.g. "/v2"), computed from the script location. */
function v2_base(): string
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/v2/index.php');
        $base = preg_replace('#/(api|admin)/[^/]*$|/[^/]*$#', '', $script) ?: '';
    }
    return $base;
}

function db(): ?PDO
{
    static $pdo = false;
    if ($pdo === false) {
        $c = config()['db'] ?? [];
        try {
            // 'dsn' is only used for local tests (SQLite); on OVH the main config has host/name/user/pass
            if (empty($c['dsn']) && empty($c['host'])) {
                throw new RuntimeException('main site api/config.php not found');
            }
            $dsn = $c['dsn'] ?? "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $c['user'] ?? null, $c['pass'] ?? null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $e) {
            error_log('[v2] DB connection failed: ' . $e->getMessage());
            $pdo = null; // the page still works, without statistics
        }
    }
    return $pdo;
}

/** Same anonymous visitor hash as the main site: SHA-256(IP | UA | day | salt). Unique visitors are counted across both versions. */
function visitor_hash(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . gmdate('Y-m-d') . '|' . (config()['salt'] ?? ''));
}

function is_bot(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return $ua === '' || (bool)preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse|curl|wget|python/i', $ua);
}

/** [device, browser, os] — same values as the main site. */
function parse_ua(): array
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $device = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop');
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'Other',
    };
    $os = match (true) {
        str_contains($ua, 'Windows') => 'Windows', (bool)preg_match('/iPhone|iPad|iOS/', $ua) => 'iOS', str_contains($ua, 'Mac OS') => 'macOS',
        str_contains($ua, 'Android') => 'Android', str_contains($ua, 'Linux') => 'Linux', default => 'Other',
    };
    return [$device, $browser, $os];
}

/** Country from the browser time zone (more precise than Accept-Language, still no IP lookup). */
function country_from_tz(string $tz): ?string
{
    try {
        $code = (new DateTimeZone($tz))->getLocation()['country_code'] ?? '';
        return ($code === '' || $code === '??') ? null : $code;
    } catch (Throwable) {
        if (preg_match('/^[a-z]{2}-([A-Z]{2})/', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', $m)) {
            return $m[1];
        }
        return null;
    }
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(int $max = 20000): array
{
    $raw = file_get_contents('php://input', false, null, 0, $max) ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function clean(mixed $value, int $max): string
{
    $s = is_scalar($value) ? trim((string)$value) : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr($s, 0, $max);
}

function slug(mixed $s): string
{
    return substr(preg_replace('/[^a-z0-9-]/', '', strtolower(is_scalar($s) ? (string)$s : '')) ?? '', 0, 60);
}

function lang_code(mixed $l): string
{
    return in_array($l, LANGS, true) ? $l : 'en';
}

/** POST only, from our own site (same rule as the main site). */
function require_same_origin(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => 'method'], 405);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin === '') {
        return; // sendBeacon may omit it; rate limits still apply
    }
    $host = parse_url($origin, PHP_URL_HOST);
    $own = array_filter([parse_url(config()['site_url'] ?? '', PHP_URL_HOST), explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]]);
    $own[] = 'www.' . reset($own);
    if (!in_array($host, $own, true)) {
        json_response(['ok' => false, 'error' => 'forbidden'], 403);
    }
}

/**
 * Rate limit on the shared rate_limits table (buckets prefixed with "v2-").
 * Portable SQL (works on MySQL and on SQLite for local tests).
 */
function rate_limited(string $bucket, int $max, int $seconds): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    $key = substr('v2-' . $bucket . ':' . substr(visitor_hash(), 0, 32), 0, 100);
    $now = date('Y-m-d H:i:s');
    try {
        $st = $pdo->prepare('SELECT hits, window_start FROM rate_limits WHERE bucket = ?');
        $st->execute([$key]);
        $row = $st->fetch();
        if (!$row) {
            $pdo->prepare('INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)')->execute([$key, $now]);
            return false;
        }
        if (strtotime($row['window_start']) < time() - $seconds) {
            $pdo->prepare('UPDATE rate_limits SET hits = 1, window_start = ? WHERE bucket = ?')->execute([$now, $key]);
            return false;
        }
        $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?')->execute([$key]);
        return (int)$row['hits'] + 1 > $max;
    } catch (Throwable $e) {
        error_log('[v2] rate limit: ' . $e->getMessage());
        return false;
    }
}

/** Event in the shared events table (same names as the main site when the action is the same). */
function log_event(string $name, ?string $value = null, ?string $session = null, ?string $campaign = null, ?string $lang = null): void
{
    $pdo = db();
    if (!$pdo || is_bot()) {
        return;
    }
    try {
        $pdo->prepare('INSERT INTO events (visitor_hash, session_id, name, value, campaign, lang) VALUES (?,?,?,?,?,?)')
            ->execute([visitor_hash(), $session ?: 'none', substr($name, 0, 40), $value !== null ? mb_substr($value, 0, 120) : null, $campaign ?: null, $lang]);
    } catch (Throwable $e) {
        error_log('[v2] event: ' . $e->getMessage());
    }
}

/** E-mail with the main site's addresses (OVH: From must be an address of the domain). */
function notify(string $subject, string $body, ?string $replyTo = null): void
{
    $c = config();
    if (empty($c['mail_to']) || empty($c['mail_from'])) {
        return;
    }
    $headers = ["From: Portfolio v2 <{$c['mail_from']}>", 'Content-Type: text/plain; charset=UTF-8'];
    if ($replyTo) {
        $headers[] = 'Reply-To: ' . str_replace(["\r", "\n"], '', $replyTo);
    }
    @mail($c['mail_to'], '=?UTF-8?B?' . base64_encode('[Portfolio v2] ' . $subject) . '?=', $body, implode("\r\n", $headers));
}

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function t_all(string $lang): array
{
    static $cache = [];
    return $cache[$lang] ??= require APP_DIR . '/content/' . lang_code($lang) . '.php';
}

/**
 * Recruiter link: the main site's campaigns table (?c=slug, same links as the main site)
 * + optional v2 extras (skills to highlight, personal message) in v2_campaign_extras.
 */
function recruiter(string $slug): ?array
{
    $pdo = db();
    if (!$pdo || $slug === '') {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT c.slug, c.company, c.contact_name, c.lang, x.skills, x.message
                             FROM campaigns c LEFT JOIN v2_campaign_extras x ON x.slug = c.slug WHERE c.slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        // v2 tables not imported yet: still greet with the main table
        try {
            $st = $pdo->prepare('SELECT slug, company, contact_name, lang FROM campaigns WHERE slug = ?');
            $st->execute([$slug]);
            $row = $st->fetch();
            return $row ? $row + ['skills' => null, 'message' => null] : null;
        } catch (Throwable) {
            return null;
        }
    }
}

/** Texts of the page: ['s' => flat strings (HTML allowed, from our own files), 'ui' => strings used by JavaScript]. */
function strings(string $lang): array
{
    static $cache = [];
    return $cache[$lang] ??= require APP_DIR . '/i18n/' . lang_code($lang) . '.php';
}
