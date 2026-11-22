<?php
declare(strict_types=1);
/**
 * Panel principal — https://mam-com.com/admin/
 * Contraseña: admin_password_hash de api/config.php (la misma que /old_v1-02/admin/).
 * Reúne todo: visitas de las dos versiones, embudo, mensajes, enlaces reclutador, asistente IA, CV y feed en directo.
 */
require __DIR__ . '/../app/bootstrap.php';

session_set_cookie_params(['lifetime' => 0, 'path' => v2_base() . '/admin/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_name('mm_v2admin');
session_start();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$c = config();
$pdo = db();
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$error = '';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}
if (empty($_SESSION['admin']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    usleep(random_int(300000, 700000));
    if (rate_limited('admin_login', 8, 900)) {
        $error = 'Demasiados intentos. Espera 15 minutos.';
    } elseif (!empty($c['admin_password_hash']) && password_verify((string)$_POST['password'], $c['admin_password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: ./');
        exit;
    } else {
        $error = empty($c['admin_password_hash']) ? 'Falta admin_password_hash en api/config.php.' : 'Contraseña incorrecta.';
    }
}
$logged = !empty($_SESSION['admin']);

// ---------- Acciones ----------
if ($logged && $pdo && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('CSRF'); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'campaign_add') {
            $company = clean($_POST['company'] ?? '', 120);
            $s = slug($_POST['slug'] ?? '') ?: slug(iconv('UTF-8', 'ASCII//TRANSLIT', str_replace(' ', '-', $company)) ?: '');
            if ($company !== '' && $s !== '') {
                $lang = in_array($_POST['lang'] ?? '', LANGS, true) ? $_POST['lang'] : null;
                $pdo->prepare('INSERT IGNORE INTO campaigns (slug, company, contact_name, lang, note) VALUES (?,?,?,?,?)')
                    ->execute([$s, $company, clean($_POST['contact'] ?? '', 120) ?: null, $lang, clean($_POST['note'] ?? '', 255) ?: null]);
                $_SESSION['flash'] = 'Enlace creado para ' . $company . '.';
            }
        } elseif ($action === 'campaign_del') {
            $pdo->prepare('DELETE FROM campaigns WHERE id = ?')->execute([(int)$_POST['id']]);
        } elseif ($action === 'extras') {
            $slug = slug($_POST['slug'] ?? '');
            $profile = require APP_DIR . '/profile.php';
            $valid = array_merge(...array_map('array_keys', array_values($profile['skills'])));
            $skills = implode(',', array_intersect((array)($_POST['skills'] ?? []), $valid));
            $message = clean($_POST['message'] ?? '', 1000);
            $exists = $pdo->prepare('SELECT COUNT(*) FROM v2_campaign_extras WHERE slug = ?');
            $exists->execute([$slug]);
            if ((int)$exists->fetchColumn() > 0) {
                $pdo->prepare('UPDATE v2_campaign_extras SET skills = ?, message = ? WHERE slug = ?')->execute([$skills ?: null, $message ?: null, $slug]);
            } elseif ($slug !== '') {
                $pdo->prepare('INSERT INTO v2_campaign_extras (slug, skills, message) VALUES (?,?,?)')->execute([$slug, $skills ?: null, $message ?: null]);
            }
            $_SESSION['flash'] = 'Guardado.';
        } elseif ($action === 'msg_read') {
            $pdo->prepare('UPDATE messages SET is_read = 1 - is_read WHERE id = ?')->execute([(int)$_POST['id']]);
        } elseif ($action === 'msg_del') {
            $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([(int)$_POST['id']]);
        } elseif ($action === 'delete_question') {
            $pdo->prepare('DELETE FROM v2_questions WHERE id = ?')->execute([(int)$_POST['id']]);
        } elseif ($action === 'delete_cv') {
            $pdo->prepare('DELETE FROM v2_cv_requests WHERE id = ?')->execute([(int)$_POST['id']]);
        } elseif ($action === 'purge') {
            $pdo->exec('DELETE FROM visits WHERE created_at < NOW() - INTERVAL 13 MONTH');
            $pdo->exec('DELETE FROM events WHERE created_at < NOW() - INTERVAL 13 MONTH');
            $pdo->exec('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY');
            $_SESSION['flash'] = 'Estadísticas de más de 13 meses borradas.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: ./?' . http_build_query(['tab' => $_POST['tab'] ?? 'overview', 'days' => $_POST['days'] ?? 30]));
    exit;
}

$TABS = ['overview' => 'Resumen', 'live' => 'En directo', 'messages' => 'Mensajes', 'links' => 'Enlaces reclutador', 'questions' => 'Asistente IA', 'cv' => 'CV con nombre'];
$tab = array_key_exists($_GET['tab'] ?? '', $TABS) ? $_GET['tab'] : 'overview';
$days = in_array((int)($_GET['days'] ?? 30), [1, 7, 30, 90, 365], true) ? (int)($_GET['days'] ?? 30) : 30;
$since = date('Y-m-d H:i:s', time() - $days * 86400);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$B = v2_base();
$OLD = '/old_v1-02/%';   // visits of the classic version; everything else is the new site
$q = function (string $sql, array $args = []) use ($pdo): array {
    try { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); }
    catch (Throwable $e) { error_log('[admin] ' . $e->getMessage()); return []; }
};
$one = fn(string $sql, array $args = []) => (int)(array_values($q($sql, $args)[0] ?? [0])[0] ?? 0);
$bars = function (array $rows, string $empty = '—') : string {
    if (!$rows) return '<p class="empty">' . $empty . '</p>';
    $max = max(array_map(fn($r) => (int)$r['v'], $rows)) ?: 1;
    $out = '<ul class="bars">';
    foreach ($rows as $r) $out .= '<li><span title="' . h((string)($r['k'] ?? '')) . '">' . h((string)($r['k'] ?? '') ?: '(directo)') . '</span><i style="--w:' . round($r['v'] / $max * 100) . '%"></i><b>' . (int)$r['v'] . '</b></li>';
    return $out . '</ul>';
};
$fmtTime = fn(int $s) => $s >= 60 ? intdiv($s, 60) . ' min ' . ($s % 60) . ' s' : "$s s";
$EV = ['cv_download' => '📄 CV descargado', 'contact_sent' => '✉️ Mensaje enviado', 'email_click' => '✉ Clic email', 'email_copy' => '⌘C Email copiado', 'linkedin_click' => 'in LinkedIn', 'github_click' => '⌥ GitHub', 'github_eduverse' => '⌥ GitHub EduVerse', 'github_repo' => '⌥ Repo de la suite', 'v1_link' => '↩ Versión clásica',
       'section_view' => '👁 Sección', 'time_on_page' => '⏱ Tiempo', 'scroll_depth' => '↧ Scroll', 'quiz' => '🎯 Quiz', 'lang_switch' => '🌐 Idioma', 'theme' => '◐ Tema', 'code_mode' => '</> Modo código', 'palette_open' => '⌘K Paleta', 'palette_cmd' => '⌘K Comando',
       'eduverse_tab' => '🧪 Pestaña EduVerse', 'eduverse_podcast' => '🎧 Podcast', 'eduverse_flash' => '🃏 Flashcards', 'lab_gravity' => '🪐 Lab 3D', 'sticker_drag' => '✦ Sticker', 'menu_open' => '☰ Menú 3D', 'nabbu_shot' => '🖼 Captura Nabbü', 'nabbu_zoom' => '🔍 Zoom Nabbü',
       'assistant_action' => '🤖 Acción asistente', 'ask' => '🤖 Pregunta IA', 'audience' => '🎯 Público', 'skill' => '★ Competencia', 'skills_filter' => '★ Filtro skills', 'skills_spin' => '★ Constelación'];
$INTENT = ['job' => '💼 Puesto', 'freelance' => '🧩 Freelance', 'private' => '🏡 Particular', 'other' => '✉️ Otro'];
$unread = $one('SELECT COUNT(*) FROM messages WHERE is_read = 0');
$isSqlite = str_starts_with((string)($c['db']['dsn'] ?? ''), 'sqlite');
$hidden = fn(string $t) => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="tab" value="' . $t . '"><input type="hidden" name="days" value="' . $days . '">';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel · mam-com.com</title>
<link rel="icon" href="<?= $B ?>/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="admin.css?v=<?= (int)@filemtime(__DIR__ . '/admin.css') ?>">
</head>
<body>
<?php if (!$logged): ?>
<main class="login">
    <form method="post" class="card">
        <h1>Panel <em>mam-com</em></h1>
        <p class="note">Contraseña del panel (la misma que la versión clásica).</p>
        <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
        <input name="password" type="password" placeholder="Contraseña" autocomplete="current-password" required autofocus>
        <button class="btn">Entrar</button>
    </form>
</main>
<?php elseif (!$pdo): ?>
<main class="login"><div class="card"><h1>Sin base de datos</h1><p>Revisa api/config.php del sitio principal.</p></div></main>
<?php else: ?>
<header class="top">
    <a href="./" class="brand"><b>mam-com</b> · panel</a>
    <nav>
        <?php foreach ($TABS as $k => $label): ?>
        <a href="?tab=<?= $k ?>&amp;days=<?= $days ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $label ?><?= $k === 'messages' && $unread ? " <em>$unread</em>" : '' ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="top-r"><div class="seg"><?php foreach ([1 => 'Hoy', 7 => '7 d', 30 => '30 d', 90 => '90 d', 365 => '1 año'] as $d => $l): ?><a href="?tab=<?= $tab ?>&amp;days=<?= $d ?>"<?= $d === $days ? ' aria-current="true"' : '' ?>><?= $l ?></a><?php endforeach; ?></div>
        <a href="<?= $B ?>/" target="_blank">Web ↗</a> · <a href="?logout=1">Salir</a></div>
</header>
<main class="wrap">
<?php if ($flash): ?><p class="ok"><?= h($flash) ?></p><?php endif; ?>

<?php if ($tab === 'overview'):
    $visits = $one('SELECT COUNT(*) FROM visits WHERE created_at >= ?', [$since]);
    $vNew = $one('SELECT COUNT(*) FROM visits WHERE created_at >= ? AND path NOT LIKE ?', [$since, $OLD]);
    $vOld = $visits - $vNew;
    $uniq = $one('SELECT COUNT(DISTINCT visitor_hash) FROM visits WHERE created_at >= ?', [$since]);
    $sess = $one('SELECT COUNT(DISTINCT session_id) FROM visits WHERE created_at >= ?', [$since]);
    $avg = $one("SELECT COALESCE(AVG(CAST(value AS UNSIGNED)),0) FROM events WHERE name='time_on_page' AND CAST(value AS UNSIGNED) BETWEEN 1 AND 3600 AND created_at >= ?", [$since]);
    $scroll = $one("SELECT COALESCE(AVG(CAST(value AS UNSIGNED)),0) FROM events WHERE name='scroll_depth' AND created_at >= ?", [$since]);
    $cv = $one("SELECT COUNT(*) FROM events WHERE name='cv_download' AND created_at >= ?", [$since]);
    $msgs = $one('SELECT COUNT(*) FROM messages WHERE created_at >= ?', [$since]);
    $asks = $one('SELECT COUNT(*) FROM v2_questions WHERE created_at >= ?', [$since]);
    $recr = $one('SELECT COUNT(DISTINCT campaign) FROM visits WHERE campaign IS NOT NULL AND created_at >= ?', [$since]);
    $links = $one('SELECT COUNT(*) FROM campaigns');
    $daily = $q("SELECT DATE(created_at) d, SUM(CASE WHEN path LIKE ? THEN 0 ELSE 1 END) vn, SUM(CASE WHEN path LIKE ? THEN 1 ELSE 0 END) vo, COUNT(DISTINCT visitor_hash) u FROM visits WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d", [$OLD, $OLD, $since]);
    $map = []; foreach ($daily as $r) $map[$r['d']] = $r;
    $n = max(1, min($days, 90)); $pts = [];
    for ($i = $n - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $pts[] = [$d, (int)($map[$d]['vn'] ?? 0), (int)($map[$d]['vo'] ?? 0), (int)($map[$d]['u'] ?? 0)]; }
    $maxD = max(1, ...array_map(fn($p) => $p[1] + $p[2], $pts));
    $secRows = $q("SELECT value k, COUNT(DISTINCT session_id) v FROM events WHERE name='section_view' AND created_at >= ? GROUP BY value", [$since]);
    $sec = []; foreach ($secRows as $r) { $k = preg_replace('/^v2:/', '', (string)$r['k']); $sec[$k] = ($sec[$k] ?? 0) + (int)$r['v']; }
    $FUNNEL = ['top' => 'Inicio', 'manifesto' => 'Enfoque', 'services' => 'Servicios', 'nabbu' => 'Nabbü', 'eduverse' => 'EduVerse', 'suite' => 'Suite', 'work' => 'Proyectos', 'journey' => 'Trayectoria', 'method' => 'Método', 'skills' => 'Herram.', 'ask' => 'Asistente', 'contact' => 'Contacto'];
    $maxF = max(1, ...array_map(fn($k) => (int)($sec[$k] ?? ($k === 'top' ? ($sec['hero'] ?? 0) : 0)), array_keys($FUNNEL)));
    $ev = $q("SELECT name k, COUNT(*) v FROM events WHERE name NOT IN ('section_view','time_on_page','scroll_depth') AND created_at >= ? GROUP BY name ORDER BY v DESC LIMIT 16", [$since]);
?>
    <div class="kpis">
        <div class="card dark"><small>Visitas</small><b><?= $visits ?></b><span><?= $vNew ?> web nueva · <?= $vOld ?> clásica</span></div>
        <div class="card"><small>Visitantes únicos</small><b><?= $uniq ?></b><span><?= $sess ?> sesiones</span></div>
        <div class="card"><small>Tiempo medio</small><b><?= $fmtTime($avg) ?></b><span>scroll medio <?= $scroll ?>%</span></div>
        <div class="card hl"><small>CV descargados</small><b><?= $cv ?></b></div>
        <div class="card hl"><small>Mensajes</small><b><?= $msgs ?></b><span><?= $unread ?> sin leer</span></div>
        <div class="card"><small>Preguntas a la IA</small><b><?= $asks ?></b></div>
        <div class="card"><small>Reclutadores activos</small><b><?= $recr ?></b><span><?= $links ?> enlaces creados</span></div>
    </div>
    <div class="grid2">
        <section class="card">
            <h2>Visitas por día <small><i class="dot vn"></i> web nueva <i class="dot vo"></i> clásica <i class="dot vu"></i> únicos</small></h2>
            <div class="chart2">
                <?php foreach ($pts as [$d, $vn, $vo, $u]): ?>
                <div class="col" title="<?= date('d/m', strtotime($d)) ?> · nueva <?= $vn ?> · clásica <?= $vo ?> · únicos <?= $u ?>">
                    <i class="vo" style="height:<?= round($vo / $maxD * 100, 1) ?>%"></i><i class="vn" style="height:<?= round($vn / $maxD * 100, 1) ?>%"></i><s style="bottom:<?= round($u / $maxD * 100, 1) ?>%"></s>
                    <?php if ($n <= 31 || (int)date('j', strtotime($d)) === 1): ?><em><?= date($n <= 31 ? 'd' : 'd/m', strtotime($d)) ?></em><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="card">
            <h2>¿Hasta dónde leen?</h2>
            <div class="funnel"><?php foreach ($FUNNEL as $id => $label): $v = (int)($sec[$id] ?? ($id === 'top' ? ($sec['hero'] ?? 0) : 0)); ?>
                <div><b><?= $v ?></b><i style="height:<?= round($v / $maxF * 100) ?>%"></i><span><?= h($label) ?></span></div>
            <?php endforeach; ?></div>
            <p class="note">Sesiones que llegaron a cada sección.</p>
        </section>
    </div>
    <div class="grid3">
        <section class="card"><h2>De dónde vienen</h2><?= $bars($q("SELECT COALESCE(referrer_host,'(directo)') k, COUNT(*) v FROM visits WHERE created_at >= ? GROUP BY referrer_host ORDER BY v DESC LIMIT 10", [$since])) ?></section>
        <section class="card"><h2>Países (estimado)</h2><?= $bars($q("SELECT COALESCE(country,'??') k, COUNT(*) v FROM visits WHERE created_at >= ? GROUP BY country ORDER BY v DESC LIMIT 10", [$since])) ?></section>
        <section class="card"><h2>Idiomas</h2><?= $bars(array_map(fn($r) => ['k' => strtoupper((string)$r['k']), 'v' => $r['v']], $q("SELECT lang k, COUNT(*) v FROM visits WHERE created_at >= ? GROUP BY lang ORDER BY v DESC", [$since]))) ?></section>
        <section class="card"><h2>Dispositivos</h2><?= $bars($q("SELECT device k, COUNT(*) v FROM visits WHERE created_at >= ? GROUP BY device ORDER BY v DESC", [$since])) ?></section>
        <section class="card"><h2>Navegadores · SO</h2><?= $bars($q("SELECT " . ($isSqlite ? "COALESCE(browser,'?') || ' · ' || COALESCE(os,'?')" : "CONCAT(COALESCE(browser,'?'),' · ',COALESCE(os,'?'))") . " k, COUNT(*) v FROM visits WHERE created_at >= ? GROUP BY k ORDER BY v DESC LIMIT 8", [$since])) ?></section>
        <section class="card"><h2>Páginas</h2><?= $bars($q("SELECT path k, COUNT(*) v FROM visits WHERE created_at >= ? GROUP BY path ORDER BY v DESC LIMIT 10", [$since])) ?></section>
        <section class="card wide2"><h2>Interacciones</h2><?= $bars(array_map(fn($r) => ['k' => $EV[$r['k']] ?? $r['k'], 'v' => $r['v']], $ev)) ?></section>
        <section class="card"><h2>Tema elegido</h2><?= $bars($q("SELECT COALESCE(theme,'?') k, COUNT(*) v FROM visits WHERE created_at >= ? GROUP BY theme ORDER BY v DESC", [$since])) ?></section>
    </div>
    <form method="post" class="purge" onsubmit="return confirm('¿Borrar las estadísticas de más de 13 meses?')"><?= $hidden('overview') ?><input type="hidden" name="action" value="purge"><button class="link">🧹 Purgar estadísticas de más de 13 meses (RGPD)</button></form>

<?php elseif ($tab === 'live'):
    $feed = $q('SELECT created_at, name, value, campaign, lang FROM events ORDER BY id DESC LIMIT 80');
    $lastVisits = $q('SELECT created_at, path, lang, device, browser, os, country, referrer_host, campaign FROM visits ORDER BY id DESC LIMIT 40');
?>
    <h1>En directo <small class="note">se actualiza cada minuto</small></h1>
    <div class="grid2">
        <section class="card feed"><h2>Últimas interacciones</h2><ul>
            <?php foreach ($feed as $e): ?>
            <li><span><?= h($EV[$e['name']] ?? $e['name']) ?><?= $e['value'] !== null && $e['value'] !== '' ? ' <small>· ' . h((string)$e['value']) . '</small>' : '' ?><?= $e['campaign'] ? ' <span class="tag hl">★ ' . h($e['campaign']) . '</span>' : '' ?></span><time><?= date('d/m H:i', strtotime($e['created_at'])) ?></time></li>
            <?php endforeach; ?><?php if (!$feed): ?><li class="empty">Nada todavía.</li><?php endif; ?>
        </ul></section>
        <section class="card feed"><h2>Últimas visitas</h2><ul>
            <?php foreach ($lastVisits as $v): ?>
            <li><span><?= h($v['path']) ?> <small>· <?= strtoupper(h((string)$v['lang'])) ?> · <?= h((string)$v['device']) ?> · <?= h((string)$v['browser']) ?>/<?= h((string)$v['os']) ?> · <?= h((string)($v['country'] ?? '??')) ?><?= $v['referrer_host'] ? ' · ← ' . h($v['referrer_host']) : '' ?></small><?= $v['campaign'] ? ' <span class="tag hl">★ ' . h($v['campaign']) . '</span>' : '' ?></span><time><?= date('d/m H:i', strtotime($v['created_at'])) ?></time></li>
            <?php endforeach; ?><?php if (!$lastVisits): ?><li class="empty">Nada todavía.</li><?php endif; ?>
        </ul></section>
    </div>
    <script>setTimeout(() => location.reload(), 60000);</script>

<?php elseif ($tab === 'messages'):
    $messages = $q('SELECT * FROM messages ORDER BY created_at DESC LIMIT 200');
?>
    <h1>Mensajes recibidos</h1>
    <?php foreach ($messages as $m): ?>
    <article class="card msg<?= $m['is_read'] ? '' : ' status-new' ?>">
        <header><b><?= h($m['name']) ?></b> <a href="mailto:<?= h($m['email']) ?>"><?= h($m['email']) ?></a><?= $m['company'] ? ' · ' . h($m['company']) : '' ?>
            <span class="tag"><?= $INTENT[$m['intent']] ?? h((string)$m['intent']) ?></span><span class="tag"><?= strtoupper(h((string)$m['lang'])) ?></span><?= $m['campaign'] ? '<span class="tag hl">★ ' . h($m['campaign']) . '</span>' : '' ?>
            <time><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></time></header>
        <p class="body"><?= nl2br(h($m['message'])) ?></p>
        <footer><a class="btn sm" href="mailto:<?= h($m['email']) ?>?subject=<?= rawurlencode('Re: mam-com.com') ?>">Responder</a>
            <form method="post"><?= $hidden('messages') ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="link" name="action" value="msg_read"><?= $m['is_read'] ? 'Marcar no leído' : 'Marcar leído ✓' ?></button> <button class="link danger" name="action" value="msg_del" onclick="return confirm('¿Borrar este mensaje?')">Borrar</button></form></footer>
    </article>
    <?php endforeach; ?>
    <?php if (!$messages): ?><p class="empty">Aún no hay mensajes.</p><?php endif; ?>

<?php elseif ($tab === 'links'):
    $profile = require APP_DIR . '/profile.php';
    $links = $q("SELECT c.*, x.skills, x.message xmsg,
                    (SELECT COUNT(*) FROM visits v WHERE v.campaign = c.slug) visits,
                    (SELECT MAX(created_at) FROM visits v WHERE v.campaign = c.slug) last_visit,
                    (SELECT COUNT(*) FROM events e WHERE e.campaign = c.slug AND e.name = 'cv_download') cv,
                    (SELECT COUNT(*) FROM messages m WHERE m.campaign = c.slug) msgs,
                    (SELECT COUNT(*) FROM v2_questions qq WHERE qq.campaign = c.slug) asks
                 FROM campaigns c LEFT JOIN v2_campaign_extras x ON x.slug = c.slug ORDER BY c.created_at DESC");
?>
    <h1>Enlaces para reclutadores</h1>
    <p class="note">Un enlace por empresa: la web la saluda por su nombre y aquí ves cuándo visita, qué lee, si descarga el CV o pregunta a la IA.</p>
    <form method="post" class="card add"><?= $hidden('links') ?><input type="hidden" name="action" value="campaign_add">
        <input name="company" placeholder="Empresa *" required maxlength="120">
        <input name="slug" placeholder="slug (auto)" pattern="[a-z0-9-]*" maxlength="60">
        <input name="contact" placeholder="Contacto (RR. HH.…)" maxlength="120">
        <select name="lang"><option value="">Idioma auto</option><option value="es">ES</option><option value="en">EN</option><option value="fr">FR</option></select>
        <input name="note" placeholder="Nota (puesto, fuente…)" maxlength="255">
        <button class="btn">Crear enlace</button>
    </form>
    <?php foreach ($links as $l):
        $url = v2_url() . '/' . (in_array($l['lang'], LANGS, true) ? $l['lang'] . '/' : '') . '?c=' . $l['slug'];
        $old = rtrim((string)($c['site_url'] ?? ''), '/') . '/old_v1-02/?c=' . $l['slug'];
        $sel = array_filter(explode(',', (string)$l['skills']));
        $hot = $l['last_visit'] && strtotime($l['last_visit']) > time() - 86400; ?>
    <article class="card link-card">
        <header>
            <div><b><?= h($l['company']) ?></b><?= $l['contact_name'] ? ' · ' . h($l['contact_name']) : '' ?><?= $l['note'] ? ' <small class="note">· ' . h($l['note']) . '</small>' : '' ?></div>
            <div class="stats"><span class="tag<?= $hot ? ' hl' : '' ?>"><?= (int)$l['visits'] ?> visitas<?= $l['last_visit'] ? ' · última ' . date('d/m H:i', strtotime($l['last_visit'])) . ($hot ? ' ●' : '') : '' ?></span><span class="tag">📄 <?= (int)$l['cv'] ?></span><span class="tag">✉ <?= (int)$l['msgs'] ?></span><span class="tag">🤖 <?= (int)$l['asks'] ?></span></div>
        </header>
        <div class="urls"><input class="url" readonly value="<?= h($url) ?>" onclick="this.select();navigator.clipboard&&navigator.clipboard.writeText(this.value).catch(()=>{})" title="Clic para copiar"><input class="url" readonly value="<?= h($old) ?>" onclick="this.select();navigator.clipboard&&navigator.clipboard.writeText(this.value).catch(()=>{})" title="Versión clásica · clic para copiar"></div>
        <details><summary>Personalizar para la web nueva (competencias y mensaje)</summary>
        <form method="post"><?= $hidden('links') ?><input type="hidden" name="action" value="extras"><input type="hidden" name="slug" value="<?= h($l['slug']) ?>">
            <fieldset><legend>Competencias a resaltar</legend>
                <?php foreach ($profile['skills'] as $group): foreach ($group as $key => [$label]): ?>
                <label class="check"><input type="checkbox" name="skills[]" value="<?= $key ?>"<?= in_array($key, $sel, true) ? ' checked' : '' ?>> <?= h($label) ?></label>
                <?php endforeach; endforeach; ?>
            </fieldset>
            <label>Mensaje personal (opcional)<textarea name="message" rows="2" maxlength="1000"><?= h($l['xmsg']) ?></textarea></label>
            <button class="btn sm">Guardar</button>
        </form></details>
        <form method="post" class="del" onsubmit="return confirm('¿Borrar este enlace?')"><?= $hidden('links') ?><input type="hidden" name="action" value="campaign_del"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="link danger">Borrar enlace</button></form>
    </article>
    <?php endforeach; ?>
    <?php if (!$links): ?><p class="empty">Aún no hay enlaces: crea el primero arriba.</p><?php endif; ?>

<?php elseif ($tab === 'questions'): ?>
    <h1>Preguntas al asistente</h1>
    <p class="note">Lo que los visitantes quieren saber de ti: úsalo para mejorar tu perfil y tu CV.</p>
    <?php foreach ($q('SELECT * FROM v2_questions ORDER BY id DESC LIMIT 300') as $x): ?>
    <article class="card qa">
        <p class="q"><b>P</b> <?= h($x['question']) ?></p>
        <p class="a"><b>R</b> <?= nl2br(h($x['answer'])) ?></p>
        <footer><small><?= h($x['created_at']) ?> · <?= h($x['lang']) ?> · <?= $x['source'] === 'ai' ? 'IA' : 'sin IA' ?><?= $x['campaign'] ? ' · ★ ' . h($x['campaign']) : '' ?></small>
            <form method="post" onsubmit="return confirm('¿Borrar?')"><?= $hidden('questions') ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="link" name="action" value="delete_question">Borrar</button></form></footer>
    </article>
    <?php endforeach; ?>

<?php else: ?>
    <h1>CV descargados con nombre</h1>
    <p class="note">El total de descargas está en el Resumen. Aquí solo las de quien dejó su nombre o empresa.</p>
    <div class="card"><table>
        <tr><th>Fecha</th><th>CV</th><th>Nombre</th><th>Empresa</th><th>Enlace</th><th></th></tr>
        <?php foreach ($q('SELECT * FROM v2_cv_requests ORDER BY id DESC LIMIT 300') as $d): ?>
        <tr><td><?= h($d['created_at']) ?></td><td><?= strtoupper(h($d['cv_lang'])) ?></td><td><?= h($d['name']) ?></td><td><?= h($d['company']) ?></td><td><?= h($d['campaign']) ?></td>
            <td><form method="post" onsubmit="return confirm('¿Borrar?')"><?= $hidden('cv') ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="link" name="action" value="delete_cv">Borrar</button></form></td></tr>
        <?php endforeach; ?>
    </table></div>
<?php endif; ?>
</main>
<?php endif; ?>
</body>
</html>
