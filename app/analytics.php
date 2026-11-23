<?php
declare(strict_types=1);
/*
 * Advanced, cookie-free analytics (v3):
 *  - approximate location (city / region / network organisation) from the IP, looked up once and cached
 *    under a salted hash: the IP itself is never stored
 *  - anonymous session journeys (sections in order, time in each, interactions)
 *  - click map and mouse-movement map per section (relative coordinates, no element content)
 * The schema is created / upgraded automatically the first time it is needed.
 */

const ANALYTICS_SCHEMA = 3;

function analytics_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $marker = APP_DIR . '/.schema-v' . ANALYTICS_SCHEMA;
    if (is_file($marker)) {
        return;
    }
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $ai = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    try {
        $cols = [];
        foreach ($pdo->query($sqlite ? 'PRAGMA table_info(visits)' : 'SHOW COLUMNS FROM visits')->fetchAll() as $c) {
            $cols[strtolower((string)($c['name'] ?? $c['Field']))] = true;
        }
        foreach ([
            'city' => 'VARCHAR(80) NULL', 'region' => 'VARCHAR(80) NULL', 'lat' => 'DECIMAL(6,2) NULL', 'lon' => 'DECIMAL(6,2) NULL',
            'org' => 'VARCHAR(120) NULL', 'screen' => 'VARCHAR(11) NULL',
        ] as $col => $def) {
            if (empty($cols[$col])) {
                $pdo->exec("ALTER TABLE visits ADD COLUMN $col $def");
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS v2_geo_cache (
            ip_hash CHAR(64) NOT NULL PRIMARY KEY, city VARCHAR(80) NULL, region VARCHAR(80) NULL, country CHAR(2) NULL,
            lat DECIMAL(6,2) NULL, lon DECIMAL(6,2) NULL, org VARCHAR(120) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$tail");
        $pdo->exec("CREATE TABLE IF NOT EXISTS v2_journeys (
            session_id VARCHAR(64) NOT NULL PRIMARY KEY, seconds INT NOT NULL DEFAULT 0, max_scroll INT NOT NULL DEFAULT 0,
            sections INT NOT NULL DEFAULT 0, clicks INT NOT NULL DEFAULT 0, rage INT NOT NULL DEFAULT 0, dead INT NOT NULL DEFAULT 0,
            exit_section VARCHAR(32) NULL, data TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$tail");
        $pdo->exec("CREATE TABLE IF NOT EXISTS v2_clicks (
            id $ai, session_id VARCHAR(64) NOT NULL, section VARCHAR(32) NOT NULL, x SMALLINT NOT NULL, y SMALLINT NOT NULL,
            vw SMALLINT NOT NULL DEFAULT 0, kind VARCHAR(8) NOT NULL DEFAULT 'ui', target VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$tail");
        $pdo->exec("CREATE TABLE IF NOT EXISTS v2_moves (
            section VARCHAR(32) NOT NULL, cell SMALLINT NOT NULL, n INT NOT NULL DEFAULT 0, PRIMARY KEY (section, cell))$tail");
        if (!$sqlite) {
            foreach (['CREATE INDEX idx_clicks_sec ON v2_clicks (section, created_at)', 'CREATE INDEX idx_journeys_upd ON v2_journeys (updated_at)'] as $ix) {
                try { $pdo->exec($ix); } catch (Throwable) { /* already there */ }
            }
        }
        @file_put_contents($marker, date('c'));
    } catch (Throwable $e) {
        error_log('[analytics schema] ' . $e->getMessage());
    }
}

/** Client IP (OVH passes the real one in REMOTE_ADDR). Private / local addresses are ignored. */
function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : null;
}

function http_json(string $url, float $timeout = 1.6): ?array
{
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'header' => "User-Agent: mam-com.com analytics\r\n"],
                                  'ssl' => ['verify_peer' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) ? $j : null;
}

/**
 * [city, region, country, lat, lon, org] for the current visitor, or nulls.
 * One lookup per IP every 7 days (cached under a salted hash, never the IP itself).
 * Providers: ipwho.is (HTTPS, no key) then ip-api.com as a fallback.
 */
function geo_lookup(PDO $pdo): array
{
    $none = [null, null, null, null, null, null];
    $ip = client_ip();
    if (!$ip || !(config()['geo'] ?? true)) {
        return $none;
    }
    $key = hash('sha256', 'geo|' . $ip . '|' . (config()['salt'] ?? ''));
    try {
        $st = $pdo->prepare('SELECT city, region, country, lat, lon, org, created_at FROM v2_geo_cache WHERE ip_hash = ?');
        $st->execute([$key]);
        if (($r = $st->fetch()) && strtotime((string)$r['created_at']) > time() - 7 * 86400) {
            return [$r['city'], $r['region'], $r['country'], $r['lat'] !== null ? (float)$r['lat'] : null, $r['lon'] !== null ? (float)$r['lon'] : null, $r['org']];
        }
    } catch (Throwable) {
        return $none;
    }
    $g = null;
    $j = http_json('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,city,region,country_code,latitude,longitude,connection');
    if ($j && !empty($j['success'])) {
        $g = [$j['city'] ?? null, $j['region'] ?? null, $j['country_code'] ?? null, $j['latitude'] ?? null, $j['longitude'] ?? null,
              $j['connection']['org'] ?? ($j['connection']['isp'] ?? null)];
    } else {
        $j = http_json('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,city,regionName,countryCode,lat,lon,org,isp');
        if ($j && ($j['status'] ?? '') === 'success') {
            $g = [$j['city'] ?? null, $j['regionName'] ?? null, $j['countryCode'] ?? null, $j['lat'] ?? null, $j['lon'] ?? null, ($j['org'] ?? '') ?: ($j['isp'] ?? null)];
        }
    }
    if (!$g) {
        return $none;
    }
    // city-level precision only (~10 km)
    $g = [clean($g[0] ?? '', 80) ?: null, clean($g[1] ?? '', 80) ?: null, strtoupper(substr((string)$g[2], 0, 2)) ?: null,
          is_numeric($g[3]) ? round((float)$g[3], 1) : null, is_numeric($g[4]) ? round((float)$g[4], 1) : null, clean($g[5] ?? '', 120) ?: null];
    try {
        $pdo->prepare('DELETE FROM v2_geo_cache WHERE ip_hash = ?')->execute([$key]);
        $pdo->prepare('INSERT INTO v2_geo_cache (ip_hash, city, region, country, lat, lon, org) VALUES (?,?,?,?,?,?,?)')->execute(array_merge([$key], $g));
    } catch (Throwable) { /* cache is optional */ }
    return $g;
}

/** Sections a click / move can belong to (anything else is dropped). */
function analytics_section(mixed $s): ?string
{
    $s = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$s));
    return in_array($s, ['top', 'manifesto', 'services', 'nabbu', 'eduverse', 'suite', 'work', 'journey', 'method', 'skills', 'ask', 'contact', 'footer', 'menu'], true) ? $s : null;
}

/** Heuristic: is this network organisation a consumer ISP / hosting (not interesting) or possibly a company? */
function org_is_isp(?string $org): bool
{
    if (!$org) {
        return true;
    }
    return (bool)preg_match('/telef|movistar|orange|vodafone|free ?sas|free mobile|bouygues|sfr|digi|jazztel|masmovil|yoigo|euskaltel|ono\b|proxad|comcast|verizon|at&t|t-mobile|deutsche telekom|telekom|telecom|telia|swisscom|proximus|kpn|ziggo|virgin|sky |bt\b|talktalk|cox|charter|spectrum|rogers|bell |shaw|claro|vivo\b|tim\b|iliad|fastweb|wind|tre\b|o2\b|google|amazon|aws|microsoft|azure|cloudflare|ovh|hetzner|digitalocean|linode|akamai|apple|facebook|meta|mobile|wireless|broadband|cable|fibra|fiber|fibre|internet|isp|network|net\b|hosting|datacenter|data center|vpn|private relay/i', $org);
}
