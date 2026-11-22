<?php
/**
 * Private dashboard — visits, events, messages and recruiter links.
 * URL: https://mam-com.com/admin/
 */
declare(strict_types=1);
require __DIR__ . '/../api/bootstrap.php';

session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_name('mm_admin');
session_start();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'");

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('CSRF'); } }

/* ---- One-time helper to generate the password hash ---- */
if (empty($CONFIG['admin_password_hash'])) {
    if (isset($_GET['hash'])) { header('Content-Type: text/plain'); exit(password_hash((string)$_GET['hash'], PASSWORD_DEFAULT)); }
    exit('Set admin_password_hash in api/config.php (tip: open /admin/?hash=YourPassword once and paste the result).');
}

/* ---- Auth ---- */
if (isset($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }
$error = '';
if (empty($_SESSION['admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        usleep(random_int(300000, 700000)); // slow down brute force
        rate_limit('admin_login', 8, 900);
        if (password_verify((string)$_POST['password'], $CONFIG['admin_password_hash'])) {
            session_regenerate_id(true); $_SESSION['admin'] = true; header('Location: ./'); exit;
        }
        $error = 'Mot de passe incorrect';
    }
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,300..800&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#111;color:#F2EEE5;font-family:"Bricolage Grotesque",sans-serif}form{display:grid;gap:1rem;width:min(340px,90vw)}h1{font-size:3rem;margin:0;letter-spacing:-.05em}h1 i{font-family:Georgia;color:#FF5A2B}input,button{font:inherit;padding:1rem 1.2rem;border-radius:999px;border:1px solid #333;background:#1b1b18;color:inherit}button{background:#C9F25A;color:#111;font-weight:700;border:0;cursor:pointer}.e{color:#FF5A2B}</style></head>
    <body><form method="post"><h1>M<i>m</i> admin</h1><input type="password" name="password" placeholder="Mot de passe" autofocus required><button>Entrer →</button><?php if ($error) echo '<p class="e">' . h($error) . '</p>'; ?></form></body></html><?php
    exit;
}

$pdo = db();

/* ---- Actions ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'campaign_add') {
        $company = clean($_POST['company'] ?? '', 120);
        $s = slug($_POST['slug'] ?? '') ?: slug(iconv('UTF-8', 'ASCII//TRANSLIT', str_replace(' ', '-', $company)) ?: '');
        if ($company && $s) {
            $pdo->prepare('INSERT IGNORE INTO campaigns (slug, company, contact_name, lang, note) VALUES (?,?,?,?,?)')
                ->execute([$s, $company, clean($_POST['contact'] ?? '', 120) ?: null, in_array($_POST['lang'] ?? '', ['fr','en','es'], true) ? $_POST['lang'] : null, clean($_POST['note'] ?? '', 255) ?: null]);
        }
    } elseif ($a === 'campaign_del') {
        $pdo->prepare('DELETE FROM campaigns WHERE id = ?')->execute([(int)$_POST['id']]);
    } elseif ($a === 'msg_read') {
        $pdo->prepare('UPDATE messages SET is_read = 1 - is_read WHERE id = ?')->execute([(int)$_POST['id']]);
    } elseif ($a === 'msg_del') {
        $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([(int)$_POST['id']]);
    } elseif ($a === 'purge') {
        $pdo->exec('DELETE FROM visits WHERE created_at < NOW() - INTERVAL 13 MONTH');
        $pdo->exec('DELETE FROM events WHERE created_at < NOW() - INTERVAL 13 MONTH');
        $pdo->exec('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY');
    }
    header('Location: ./?' . http_build_query(['d' => $_GET['d'] ?? 30]) . ($_POST['anchor'] ?? '')); exit;
}

/* ---- Data ---- */
$dParam = (int)($_GET['d'] ?? 30);
$days = in_array($dParam, [1, 7, 30, 90, 365], true) ? $dParam : 30;
$since = "created_at >= NOW() - INTERVAL $days DAY";
$q = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $st = $pdo->prepare($sql); $st->execute($p); return $st; })();

$kpi = [
    'visits'   => (int)$q("SELECT COUNT(*) FROM visits WHERE $since")->fetchColumn(),
    'uniques'  => (int)$q("SELECT COUNT(DISTINCT visitor_hash) FROM visits WHERE $since")->fetchColumn(),
    'cv'       => (int)$q("SELECT COUNT(*) FROM events WHERE name='cv_download' AND $since")->fetchColumn(),
    'messages' => (int)$q("SELECT COUNT(*) FROM messages WHERE $since")->fetchColumn(),
    'avgtime'  => (int)$q("SELECT COALESCE(AVG(CAST(value AS UNSIGNED)),0) FROM events WHERE name='time_on_page' AND CAST(value AS UNSIGNED) BETWEEN 1 AND 3600 AND $since")->fetchColumn(),
    'unread'   => (int)$q('SELECT COUNT(*) FROM messages WHERE is_read = 0')->fetchColumn(),
];

$daily = $q("SELECT DATE(created_at) d, COUNT(*) v, COUNT(DISTINCT visitor_hash) u FROM visits WHERE $since GROUP BY DATE(created_at) ORDER BY d")->fetchAll();
$byLang    = $q("SELECT lang k, COUNT(*) v FROM visits WHERE $since GROUP BY lang ORDER BY v DESC")->fetchAll();
$byDevice  = $q("SELECT device k, COUNT(*) v FROM visits WHERE $since GROUP BY device ORDER BY v DESC")->fetchAll();
$byRef     = $q("SELECT COALESCE(referrer_host,'(direct)') k, COUNT(*) v FROM visits WHERE $since GROUP BY k ORDER BY v DESC LIMIT 10")->fetchAll();
$byCountry = $q("SELECT COALESCE(country,'??') k, COUNT(*) v FROM visits WHERE $since GROUP BY k ORDER BY v DESC LIMIT 10")->fetchAll();
$byBrowser = $q("SELECT CONCAT(COALESCE(browser,'?'),' · ',COALESCE(os,'?')) k, COUNT(*) v FROM visits WHERE $since GROUP BY k ORDER BY v DESC LIMIT 8")->fetchAll();
$sections  = $q("SELECT value k, COUNT(DISTINCT session_id) v FROM events WHERE name='section_view' AND $since GROUP BY value")->fetchAll(PDO::FETCH_KEY_PAIR);
$topEvents = $q("SELECT name k, COUNT(*) v FROM events WHERE name NOT IN ('section_view','time_on_page') AND $since GROUP BY name ORDER BY v DESC LIMIT 12")->fetchAll();
$feed      = $q("SELECT e.created_at, e.name, e.value, e.campaign, e.lang FROM events e ORDER BY e.id DESC LIMIT 25")->fetchAll();
$messages  = $q('SELECT * FROM messages ORDER BY created_at DESC LIMIT 100')->fetchAll();
$campaigns = $q("SELECT c.*,
                   (SELECT COUNT(*) FROM visits v WHERE v.campaign = c.slug) visits,
                   (SELECT MAX(created_at) FROM visits v WHERE v.campaign = c.slug) last_visit,
                   (SELECT COUNT(*) FROM events e WHERE e.campaign = c.slug AND e.name = 'cv_download') cv,
                   (SELECT COUNT(*) FROM messages m WHERE m.campaign = c.slug) msgs
                 FROM campaigns c ORDER BY c.created_at DESC")->fetchAll();

$funnelOrder = ['hero' => 'Hero', 'manifesto' => 'Approche', 'services' => 'Services', 'eduverse' => 'EduVerse', 'work' => 'Réalisations', 'journey' => 'Parcours', 'contact' => 'Contact'];

function bars(array $rows, string $color = '#111'): string {
    if (!$rows) return '<p class="empty">Pas encore de données</p>';
    $max = max(array_column($rows, 'v')) ?: 1; $out = '';
    foreach ($rows as $r) {
        $w = round($r['v'] / $max * 100);
        $out .= '<div class="bar"><span class="bar__k">' . h((string)$r['k']) . '</span><span class="bar__t"><i style="width:' . $w . '%;background:' . $color . '"></i></span><b>' . (int)$r['v'] . '</b></div>';
    }
    return $out;
}
function chart(array $daily, int $days): string {
    $map = []; foreach ($daily as $r) $map[$r['d']] = $r;
    $n = min($days, 90); $pts = [];
    for ($i = $n - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $pts[] = [$d, (int)($map[$d]['v'] ?? 0), (int)($map[$d]['u'] ?? 0)]; }
    $max = max(1, ...array_column($pts, 1)); $W = 900; $H = 200; $bw = $W / count($pts);
    $svg = "<svg viewBox='0 0 $W " . ($H + 24) . "' class='chart' role='img' aria-label='Visites par jour'>";
    foreach ($pts as $i => [$d, $v, $u]) {
        $bh = $v / $max * $H; $uh = $u / $max * $H; $x = $i * $bw;
        $svg .= "<g><title>" . date('d/m', strtotime($d)) . " — $v visites, $u uniques</title>"
             . "<rect x='" . ($x + 2) . "' y='" . ($H - $bh) . "' width='" . max($bw - 4, 1) . "' height='$bh' rx='4' fill='#E5E0D4'/>"
             . "<rect x='" . ($x + 2) . "' y='" . ($H - $uh) . "' width='" . max($bw - 4, 1) . "' height='$uh' rx='4' fill='#111'/></g>";
        if (count($pts) <= 31 || $i % 7 === 0) $svg .= "<text x='" . ($x + $bw / 2) . "' y='" . ($H + 18) . "' text-anchor='middle'>" . date('d', strtotime($d)) . "</text>";
    }
    return $svg . '</svg>';
}
$fmtTime = fn(int $s) => $s >= 60 ? floor($s / 60) . ' min ' . ($s % 60) . ' s' : "$s s";
$intentLabel = ['job' => '💼 Poste', 'freelance' => '🧩 Freelance', 'private' => '🏡 Particulier', 'other' => '✉️ Autre'];
$evLabel = ['cv_download' => '📄 CV téléchargé', 'contact_sent' => '✉️ Message envoyé', 'email_click' => '✉ Clic email', 'email_copy' => '⌘C Email copié', 'linkedin_click' => 'in LinkedIn', 'github_click' => '⌥ GitHub', 'github_eduverse' => '⌥ GitHub EduVerse', 'section_view' => '👁 Section', 'time_on_page' => '⏱ Temps', 'quiz' => '🎯 Quiz', 'lang_switch' => '🌐 Langue', 'theme' => '◐ Thème', 'code_mode' => '</> Mode code', 'palette_open' => '⌘K Palette', 'palette_cmd' => '⌘K Commande', 'eduverse_tab' => '🧪 Onglet EduVerse', 'eduverse_podcast' => '🎧 Podcast', 'sticker_drag' => '✦ Sticker'];
$base = rtrim($CONFIG['site_url'], '/');
?><!doctype html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Dashboard · Mathieu Moreau</title>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,300..800&family=Instrument+Serif:ital@1&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#F2EEE5;--card:#FBF9F4;--ink:#111;--muted:#77736b;--line:rgba(0,0,0,.1);--lime:#C9F25A;--orange:#FF5A2B;--violet:#9C8CFF;--sky:#86D6FF}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:"Bricolage Grotesque",sans-serif;font-size:15px}
a{color:inherit}button{font:inherit;cursor:pointer}
header{position:sticky;top:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:1rem 2rem;background:rgba(242,238,229,.85);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.logo{font-weight:800;font-size:1.4rem;letter-spacing:-.06em}.logo i{font-family:"Instrument Serif";color:var(--orange);font-weight:400}
nav{display:flex;gap:.3rem;flex-wrap:wrap}nav a{padding:.4rem .8rem;border-radius:999px;text-decoration:none;font-size:.85rem;border:1px solid var(--line)}nav a.on{background:var(--ink);color:var(--bg)}
main{max-width:1300px;margin:0 auto;padding:2rem;display:grid;gap:1.2rem}
h2{font-size:1.6rem;letter-spacing:-.03em;margin:0 0 1rem}h2 em{font-family:"Instrument Serif";font-weight:400;color:var(--orange)}
.grid{display:grid;gap:1.2rem}.g6{grid-template-columns:repeat(6,1fr)}.g3{grid-template-columns:repeat(3,1fr)}.g2{grid-template-columns:2fr 1fr}
.card{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:1.4rem;min-width:0}
.kpi b{display:block;font-size:2.6rem;font-weight:700;letter-spacing:-.05em;line-height:1}.kpi span{color:var(--muted);font-size:.85rem}
.kpi.dark{background:var(--ink);color:var(--bg)}.kpi.lime{background:var(--lime);border:0}.kpi.dark span{color:#aaa}
.chart{width:100%;height:auto}.chart text{font:10px "JetBrains Mono";fill:var(--muted)}
.legend{display:flex;gap:1rem;font-size:.8rem;color:var(--muted)}.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:.3rem;vertical-align:middle}
.bar{display:grid;grid-template-columns:minmax(80px,140px) 1fr 40px;gap:.6rem;align-items:center;font-size:.85rem;margin:.35rem 0}
.bar__k{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.bar__t{height:10px;background:#ece8de;border-radius:6px;overflow:hidden}.bar__t i{display:block;height:100%;border-radius:6px}.bar b{text-align:right;font-family:"JetBrains Mono";font-size:.8rem}
.funnel{display:flex;gap:.4rem;align-items:flex-end;height:180px}.funnel div{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:.3rem;height:100%;font-size:.72rem;text-align:center}
.funnel i{display:block;width:100%;background:linear-gradient(var(--violet),#6f5cff);border-radius:10px 10px 4px 4px;min-height:3px}.funnel b{font-family:"JetBrains Mono"}
table{width:100%;border-collapse:collapse;font-size:.88rem}th,td{text-align:left;padding:.7rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}th{font:600 .72rem "JetBrains Mono";text-transform:uppercase;color:var(--muted)}
.tag{display:inline-block;font:.7rem "JetBrains Mono";padding:.15rem .5rem;border-radius:999px;background:#ece8de}
.unread td{background:rgba(201,242,90,.2)}.msg{white-space:pre-wrap;max-width:520px;color:#333}
.btn{border:0;background:var(--ink);color:var(--bg);padding:.55rem 1rem;border-radius:999px;font-weight:600}.btn.s{background:transparent;color:var(--ink);border:1px solid var(--line);padding:.35rem .7rem;font-size:.78rem}
form.inline{display:inline}.add{display:grid;grid-template-columns:2fr 1.4fr 1.4fr .8fr 2fr auto;gap:.5rem;margin-bottom:1rem}
.add input,.add select{font:inherit;padding:.6rem .8rem;border-radius:12px;border:1px solid var(--line);background:#fff;min-width:0}
.link{font:.78rem "JetBrains Mono";background:#ece8de;padding:.3rem .6rem;border-radius:8px;cursor:copy;border:0}
.feed li{display:flex;justify-content:space-between;gap:.6rem;padding:.45rem 0;border-bottom:1px dashed var(--line);font-size:.85rem}.feed ul{list-style:none;margin:0;padding:0;max-height:380px;overflow:auto}
.feed time{font:.72rem "JetBrains Mono";color:var(--muted);white-space:nowrap}
.empty{color:var(--muted);font-size:.9rem}
.hot{color:#1f9d3a;font-weight:700}
@media(max-width:1000px){.g6{grid-template-columns:repeat(3,1fr)}.g3,.g2{grid-template-columns:1fr}.add{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.g6{grid-template-columns:1fr 1fr}main{padding:1rem}header{padding:1rem}table{display:block;overflow:auto}}
</style></head>
<body>
<header>
  <div class="logo">M<i>m</i> dashboard</div>
  <nav>
    <?php foreach ([1 => "Aujourd'hui", 7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '1 an'] as $k => $l): ?>
      <a href="?d=<?= $k ?>" class="<?= $k === $days ? 'on' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
    <a href="#campaigns">Liens recruteurs</a><a href="#messages">Messages<?= $kpi['unread'] ? " ({$kpi['unread']})" : '' ?></a><a href="?logout">Déconnexion</a>
  </nav>
</header>
<main>
  <section class="grid g6">
    <div class="card kpi dark"><b><?= $kpi['visits'] ?></b><span>visites</span></div>
    <div class="card kpi"><b><?= $kpi['uniques'] ?></b><span>visiteurs uniques / jour</span></div>
    <div class="card kpi lime"><b><?= $kpi['cv'] ?></b><span>CV téléchargés</span></div>
    <div class="card kpi"><b><?= $kpi['messages'] ?></b><span>messages reçus</span></div>
    <div class="card kpi"><b><?= $fmtTime($kpi['avgtime']) ?></b><span>temps moyen</span></div>
    <div class="card kpi"><b><?= count($campaigns) ?></b><span>liens recruteurs</span></div>
  </section>

  <section class="grid g2">
    <div class="card"><h2>Visites <em>par jour</em></h2><?= chart($daily, $days) ?>
      <div class="legend"><span><i style="background:#E5E0D4"></i>visites</span><span><i style="background:#111"></i>uniques</span></div></div>
    <div class="card"><h2>Jusqu'où <em>lisent-ils ?</em></h2>
      <?php $maxF = max(1, ...array_values($sections ?: [0])); ?>
      <div class="funnel"><?php foreach ($funnelOrder as $id => $label): $v = (int)($sections[$id] ?? 0); ?>
        <div><b><?= $v ?></b><i style="height:<?= round($v / $maxF * 100) ?>%"></i><?= h($label) ?></div>
      <?php endforeach; ?></div>
      <p class="empty" style="margin-top:1rem">Sessions ayant atteint chaque section.</p>
    </div>
  </section>

  <section class="grid g3">
    <div class="card"><h2>Langues</h2><?= bars(array_map(fn($r) => ['k' => strtoupper($r['k']), 'v' => $r['v']], $byLang), 'var(--orange)') ?></div>
    <div class="card"><h2>Appareils</h2><?= bars($byDevice, 'var(--violet)') ?></div>
    <div class="card"><h2>Navigateurs</h2><?= bars($byBrowser, 'var(--sky)') ?></div>
    <div class="card"><h2>Provenance</h2><?= bars($byRef, '#111') ?></div>
    <div class="card"><h2>Pays <em>(estimé)</em></h2><?= bars($byCountry, 'var(--lime)') ?></div>
    <div class="card"><h2>Interactions</h2><?= bars(array_map(fn($r) => ['k' => $evLabel[$r['k']] ?? $r['k'], 'v' => $r['v']], $topEvents), 'var(--orange)') ?></div>
  </section>

  <section class="card" id="campaigns">
    <h2>Liens <em>recruteurs</em> personnalisés</h2>
    <p class="empty" style="margin:-.5rem 0 1rem">Créez un lien par entreprise : le site l'accueille par son nom, et vous voyez quand elle visite, ce qu'elle lit et si elle télécharge votre CV.</p>
    <form method="post" class="add">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="campaign_add"><input type="hidden" name="anchor" value="#campaigns">
      <input name="company" placeholder="Entreprise *" required maxlength="120">
      <input name="slug" placeholder="slug (auto)" pattern="[a-z0-9-]*" maxlength="60">
      <input name="contact" placeholder="Contact (RH…)" maxlength="120">
      <select name="lang"><option value="">Langue auto</option><option value="fr">FR</option><option value="en">EN</option><option value="es">ES</option></select>
      <input name="note" placeholder="Note (poste, source…)" maxlength="255">
      <button class="btn">Créer</button>
    </form>
    <?php if (!$campaigns): ?><p class="empty">Aucun lien pour l'instant.</p><?php else: ?>
    <table><thead><tr><th>Entreprise</th><th>Lien</th><th>Visites</th><th>Dernière visite</th><th>CV</th><th>Msg</th><th></th></tr></thead><tbody>
    <?php foreach ($campaigns as $c): $url = "$base/?c={$c['slug']}" . ($c['lang'] ? "&lang={$c['lang']}" : ''); $recent = $c['last_visit'] && strtotime($c['last_visit']) > time() - 86400; ?>
      <tr><td><b><?= h($c['company']) ?></b><br><small><?= h($c['contact_name']) ?> <?= $c['note'] ? '· ' . h($c['note']) : '' ?></small></td>
        <td><button class="link" data-copy="<?= h($url) ?>"><?= h($url) ?></button></td>
        <td><b><?= (int)$c['visits'] ?></b></td>
        <td class="<?= $recent ? 'hot' : '' ?>"><?= $c['last_visit'] ? date('d/m H:i', strtotime($c['last_visit'])) . ($recent ? ' ●' : '') : '—' ?></td>
        <td><?= (int)$c['cv'] ?></td><td><?= (int)$c['msgs'] ?></td>
        <td><form method="post" class="inline" onsubmit="return confirm('Supprimer ce lien ?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="campaign_del"><input type="hidden" name="anchor" value="#campaigns"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn s">Suppr.</button></form></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
  </section>

  <section class="grid g2">
    <div class="card" id="messages"><h2>Messages <em>reçus</em></h2>
      <?php if (!$messages): ?><p class="empty">Aucun message pour l'instant.</p><?php else: ?>
      <table><thead><tr><th>Date</th><th>De</th><th>Message</th><th></th></tr></thead><tbody>
      <?php foreach ($messages as $m): ?>
        <tr class="<?= $m['is_read'] ? '' : 'unread' ?>"><td><small><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></small><br><span class="tag"><?= $intentLabel[$m['intent']] ?? '' ?></span> <span class="tag"><?= strtoupper(h($m['lang'])) ?></span></td>
          <td><b><?= h($m['name']) ?></b><br><a href="mailto:<?= h($m['email']) ?>"><?= h($m['email']) ?></a><br><small><?= h($m['company']) ?></small><?= $m['campaign'] ? '<br><span class="tag">🔗 ' . h($m['campaign']) . '</span>' : '' ?></td>
          <td class="msg"><?= h($m['message']) ?></td>
          <td><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="anchor" value="#messages"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn s" name="action" value="msg_read"><?= $m['is_read'] ? 'Non lu' : 'Lu ✓' ?></button> <button class="btn s" name="action" value="msg_del" onclick="return confirm('Supprimer ?')">Suppr.</button></form></td></tr>
      <?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
    <div class="card feed"><h2>En <em>direct</em></h2><ul>
      <?php foreach ($feed as $e): ?>
        <li><span><?= h($evLabel[$e['name']] ?? $e['name']) ?> <?= $e['value'] ? '<small>· ' . h($e['value']) . '</small>' : '' ?><?= $e['campaign'] ? ' <span class="tag">🔗 ' . h($e['campaign']) . '</span>' : '' ?></span><time><?= date('d/m H:i', strtotime($e['created_at'])) ?></time></li>
      <?php endforeach; ?>
      <?php if (!$feed): ?><li class="empty">Rien pour l'instant.</li><?php endif; ?>
    </ul>
    <form method="post" style="margin-top:1rem" onsubmit="return confirm('Supprimer les statistiques de plus de 13 mois ?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="purge"><button class="btn s">🧹 Purger &gt; 13 mois (RGPD)</button></form>
    </div>
  </section>
</main>
<script>
document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(b.dataset.copy); const t = b.textContent; b.textContent = 'Copié ✓'; setTimeout(() => b.textContent = t, 1200); } catch (e) {}
}));
setTimeout(() => location.reload(), 120000); // auto refresh every 2 min
</script>
</body></html>
