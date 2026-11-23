<?php
declare(strict_types=1);
/**
 * Panel principal — https://mam-com.com/admin/
 * Contraseña: admin_password_hash de api/config.php (la misma que /old_v1-02/admin/).
 * Reúne todo: visitas de las dos versiones, embudo, mensajes, enlaces reclutador, asistente IA, CV y feed en directo.
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/analytics.php';

session_set_cookie_params(['lifetime' => 0, 'path' => v2_base() . '/admin/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_name('mm_v2admin');
session_start();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$c = config();
$pdo = db();
if ($pdo) analytics_schema($pdo);
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
            $pdo->exec('DELETE FROM v2_journeys WHERE updated_at < NOW() - INTERVAL 13 MONTH');
            $pdo->exec('DELETE FROM v2_clicks WHERE created_at < NOW() - INTERVAL 13 MONTH');
            $pdo->exec('DELETE FROM v2_geo_cache WHERE created_at < NOW() - INTERVAL 1 MONTH');
            $_SESSION['flash'] = 'Estadísticas de más de 13 meses borradas.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: ./?' . http_build_query(['tab' => $_POST['tab'] ?? 'overview', 'days' => $_POST['days'] ?? 30]));
    exit;
}

$TABS = ['overview' => 'Resumen', 'live' => 'En directo', 'journeys' => 'Recorridos', 'map' => 'Mapa', 'mouse' => 'Ratón', 'messages' => 'Mensajes', 'links' => 'Enlaces reclutador', 'questions' => 'IA', 'cv' => 'CV'];
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
$drv = $pdo ? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'mysql';
$flag = fn(?string $cc) => $cc && preg_match('/^[A-Z]{2}$/', $cc) ? mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65) : '🌐';
$SECN = ['top' => 'Inicio', 'manifesto' => 'Enfoque', 'services' => 'Servicios', 'nabbu' => 'Nabbü', 'eduverse' => 'EduVerse', 'suite' => 'Suite', 'work' => 'Proyectos', 'journey' => 'Trayectoria', 'method' => 'Método', 'skills' => 'Herramientas', 'ask' => 'Asistente', 'contact' => 'Contacto', 'footer' => 'Pie', 'menu' => 'Menú'];
$mmss = fn(int $s) => sprintf('%d:%02d', intdiv($s, 60), $s % 60);
$score = function (array $j, array $ev): int {   // 0–100 engagement score of a visit
    $s = min(40, intdiv((int)$j['seconds'], 4)) + min(25, (int)$j['sections'] * 3) + min(15, intdiv((int)$j['max_scroll'], 7));
    foreach ($ev as $e) $s += ['cv_download' => 15, 'contact_sent' => 25, 'linkedin_click' => 6, 'github_click' => 4, 'github_repo' => 4, 'email_click' => 8, 'email_copy' => 8, 'ask' => 5][$e[1] ?? ''] ?? 1;
    return min(100, $s);
};
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
    $jt = $q("SELECT COUNT(*) n, SUM(CASE WHEN seconds < 15 OR sections <= 1 THEN 1 ELSE 0 END) b, AVG(sections) s, SUM(rage) r, SUM(dead) d FROM v2_journeys WHERE updated_at >= ?", [$since])[0] ?? [];
    $jn = (int)($jt['n'] ?? 0);
    $companies = array_values(array_filter($q("SELECT org k, COUNT(DISTINCT session_id) v FROM visits WHERE created_at >= ? AND org IS NOT NULL GROUP BY org ORDER BY v DESC LIMIT 60", [$since]), fn($r) => !org_is_isp($r['k'])));
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
        <div class="card"><small>Rebote</small><b><?= $jn ? round((int)$jt['b'] / $jn * 100) : 0 ?>%</b><span>&lt; 15 s o 1 sección</span></div>
        <div class="card"><small>Secciones / visita</small><b><?= $jn ? number_format((float)$jt['s'], 1, ',', '') : '0' ?></b><span><?= $jn ?> recorridos</span></div>
        <div class="card<?= (int)($jt['r'] ?? 0) ? ' warn' : '' ?>"><small>Clics de frustración</small><b><?= (int)($jt['r'] ?? 0) ?></b><span><?= (int)($jt['d'] ?? 0) ?> clics en zonas sin acción</span></div>
        <div class="card<?= $companies ? ' hl' : '' ?>"><small>Posibles empresas</small><b><?= count($companies) ?></b><span><?= $companies ? h(implode(', ', array_slice(array_column($companies, 'k'), 0, 2))) : 'redes no domésticas' ?></span></div>
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

<?php elseif ($tab === 'journeys'):
    $f = $_GET['f'] ?? 'all';
    $where = ['j.updated_at >= ?']; $args = [$since];
    if ($f === 'engaged') $where[] = '(j.seconds >= 30 AND j.sections >= 3)';
    if ($f === 'recruiter') $where[] = 'v.campaign IS NOT NULL';
    if ($f === 'frustration') $where[] = '(j.rage > 0 OR j.dead >= 3)';
    $rows = $q('SELECT j.*, v.city, v.region, v.country, v.org, v.device, v.browser, v.os, v.referrer_host, v.campaign, v.lang, v.screen, v.path, v.created_at started
                FROM v2_journeys j LEFT JOIN visits v ON v.id = (SELECT MIN(id) FROM visits vv WHERE vv.session_id = j.session_id)
                WHERE ' . implode(' AND ', $where) . ' ORDER BY j.updated_at DESC LIMIT 120', $args);
    if ($f === 'company') $rows = array_values(array_filter($rows, fn($r) => $r['org'] && !org_is_isp($r['org'])));
?>
    <h1>Recorridos <small class="note">cada visita: de dónde viene, qué secciones vio, cuánto tiempo y qué hizo</small></h1>
    <div class="seg filters"><?php foreach (['all' => 'Todos', 'engaged' => 'Interesados (≥ 30 s, ≥ 3 secciones)', 'recruiter' => '★ Reclutadores', 'company' => '🏢 Posibles empresas', 'frustration' => '⚠ Frustración'] as $k => $l): ?><a href="?tab=journeys&amp;days=<?= $days ?>&amp;f=<?= $k ?>"<?= $f === $k ? ' aria-current="true"' : '' ?>><?= $l ?></a><?php endforeach; ?></div>
    <?php foreach ($rows as $r):
        $d = json_decode((string)$r['data'], true) ?: [];
        $seq = $d['seq'] ?? []; $ev = $d['ev'] ?? [];
        $tot = max(1, array_sum(array_column($seq, 1)));
        $sc = $score($r, $ev);
        $isCo = $r['org'] && !org_is_isp($r['org']); ?>
    <article class="card jr">
        <header>
            <div class="jr-who">
                <span class="jr-flag"><?= $flag($r['country']) ?></span>
                <div><b><?= h(trim(($r['city'] ?? '') . ($r['region'] && $r['region'] !== $r['city'] ? ', ' . $r['region'] : '')) ?: ($r['country'] ?? 'Ubicación desconocida')) ?></b>
                    <small><?= $r['org'] ? ($isCo ? '<span class="tag hl">🏢 ' . h($r['org']) . '</span>' : h($r['org'])) . ' · ' : '' ?><?= h((string)$r['device']) ?> · <?= h((string)$r['browser']) ?>/<?= h((string)$r['os']) ?><?= $r['screen'] ? ' · ' . h($r['screen']) : '' ?> · <?= strtoupper(h((string)$r['lang'])) ?></small>
                    <small><?= $r['referrer_host'] ? '← ' . h($r['referrer_host']) : 'acceso directo' ?><?= $r['campaign'] ? ' · <span class="tag hl">★ ' . h($r['campaign']) . '</span>' : '' ?> · <?= str_starts_with((string)$r['path'], '/old_v1-02/') ? 'versión clásica' : 'web nueva' ?></small></div>
            </div>
            <div class="jr-stats">
                <span class="score" style="--s:<?= $sc ?>"><b><?= $sc ?></b><small>interés</small></span>
                <span><b><?= $mmss((int)$r['seconds']) ?></b><small>duración</small></span>
                <span><b><?= (int)$r['max_scroll'] ?>%</b><small>scroll</small></span>
                <span><b><?= (int)$r['clicks'] ?></b><small>clics</small></span>
                <?php if ((int)$r['rage'] || (int)$r['dead']): ?><span class="warn"><b><?= (int)$r['rage'] ?>/<?= (int)$r['dead'] ?></b><small>frust./vacíos</small></span><?php endif; ?>
                <time><?= date('d/m H:i', strtotime((string)($r['started'] ?? $r['created_at']))) ?></time>
            </div>
        </header>
        <div class="jr-path"><?php foreach ($seq as [$id, $sec]): ?><span style="flex-grow:<?= max(1, (int)$sec) ?>" title="<?= h($SECN[$id] ?? $id) ?> · <?= (int)$sec ?> s"><b><?= h($SECN[$id] ?? $id) ?></b><small><?= (int)$sec ?> s</small></span><?php endforeach; ?><?php if (!$seq): ?><em class="note">sin datos de secciones</em><?php endif; ?></div>
        <?php if ($ev): ?><details><summary><?= count($ev) ?> acciones · salió en <?= h($SECN[$r['exit_section'] ?? ''] ?? '—') ?></summary><ol class="jr-ev">
            <?php foreach ($ev as [$t, $n, $v]): ?><li><time><?= $mmss((int)$t) ?></time> <?= h($EV[$n] ?? $n) ?><?= $v !== '' ? ' <small>· ' . h($v) . '</small>' : '' ?></li><?php endforeach; ?>
        </ol></details><?php endif; ?>
    </article>
    <?php endforeach; ?>
    <?php if (!$rows): ?><p class="empty">Aún no hay recorridos en este periodo (se registran desde esta versión del sitio).</p><?php endif; ?>

<?php elseif ($tab === 'map'):
    $pts = $q('SELECT city, region, country, lat, lon, COUNT(*) v, COUNT(DISTINCT visitor_hash) u, MAX(created_at) last, MAX(campaign) camp FROM visits WHERE created_at >= ? AND lat IS NOT NULL GROUP BY city, region, country, lat, lon ORDER BY v DESC LIMIT 600', [$since]);
    $orgs = $q('SELECT org, COUNT(DISTINCT session_id) v, MAX(created_at) last, MAX(city) city, MAX(country) country, MAX(campaign) camp FROM visits WHERE created_at >= ? AND org IS NOT NULL GROUP BY org ORDER BY v DESC LIMIT 80', [$since]);
    $hourExpr = $drv === 'sqlite' ? "CAST(strftime('%H', created_at) AS INTEGER)" : 'HOUR(created_at)';
    $dowExpr = $drv === 'sqlite' ? "CAST(strftime('%w', created_at) AS INTEGER)" : '(DAYOFWEEK(created_at) - 1)';
    $hm = []; foreach ($q("SELECT $dowExpr d, $hourExpr hh, COUNT(*) v FROM visits WHERE created_at >= ? GROUP BY d, hh", [$since]) as $r) $hm[(int)$r['d']][(int)$r['hh']] = (int)$r['v'];
    $hmMax = max(1, ...array_map(fn($row) => max($row ?: [0]), $hm ?: [[0]]));
?>
    <h1>¿Desde dónde y cuándo? <small class="note">ubicación aproximada (ciudad) · la IP no se guarda</small></h1>
    <section class="card mapcard"><div id="map"></div></section>
    <div class="grid3">
        <section class="card"><h2>Ciudades</h2><?= $bars(array_map(fn($r) => ['k' => $flag($r['country']) . ' ' . ($r['city'] ?: '?') . ($r['region'] && $r['region'] !== $r['city'] ? ' · ' . $r['region'] : ''), 'v' => $r['v']], array_slice($pts, 0, 12))) ?></section>
        <section class="card"><h2>Países</h2><?= $bars(array_map(fn($r) => ['k' => $flag($r['k']) . ' ' . ($r['k'] ?? '??'), 'v' => $r['v']], $q('SELECT country k, COUNT(*) v FROM visits WHERE created_at >= ? GROUP BY country ORDER BY v DESC LIMIT 12', [$since]))) ?></section>
        <section class="card"><h2>Pantallas</h2><?= $bars($q('SELECT screen k, COUNT(*) v FROM visits WHERE created_at >= ? AND screen IS NOT NULL GROUP BY screen ORDER BY v DESC LIMIT 10', [$since])) ?></section>
    </div>
    <div class="grid2">
        <section class="card"><h2>Redes de origen <small>🏢 = posible empresa (no es un operador doméstico)</small></h2>
            <table class="orgs"><tr><th>Red / organización</th><th>Ciudad</th><th>Visitas</th><th>Última</th></tr>
            <?php foreach ($orgs as $o): $co = !org_is_isp($o['org']); ?>
                <tr<?= $co ? ' class="co"' : '' ?>><td><?= $co ? '🏢 ' : '' ?><?= h($o['org']) ?><?= $o['camp'] ? ' <span class="tag hl">★ ' . h($o['camp']) . '</span>' : '' ?></td><td><?= $flag($o['country']) ?> <?= h((string)$o['city']) ?></td><td><?= (int)$o['v'] ?></td><td><?= date('d/m H:i', strtotime($o['last'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$orgs): ?><tr><td colspan="4" class="empty">Aún sin datos.</td></tr><?php endif; ?>
            </table></section>
        <section class="card"><h2>¿Cuándo visitan? <small>hora del servidor</small></h2>
            <div class="hm"><span></span><?php for ($hh = 0; $hh < 24; $hh++): ?><em><?= $hh % 3 ? '' : $hh ?></em><?php endfor; ?>
            <?php foreach ([1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 0 => 'Dom'] as $dw => $dl): ?>
                <span><?= $dl ?></span><?php for ($hh = 0; $hh < 24; $hh++): $v = $hm[$dw][$hh] ?? 0; ?><i style="--a:<?= round($v / $hmMax, 3) ?>" title="<?= $dl ?> <?= $hh ?>h · <?= $v ?> visitas"></i><?php endfor; ?>
            <?php endforeach; ?></div>
        </section>
    </div>
    <link rel="stylesheet" href="vendor/leaflet/leaflet.css">
    <script src="vendor/leaflet/leaflet.js"></script>
    <script>
    (() => {
        const pts = <?= json_encode(array_map(fn($p) => [(float)$p['lat'], (float)$p['lon'], (int)$p['v'], (int)$p['u'], trim(($p['city'] ?? '') . ', ' . ($p['region'] ?? '') . ' ' . ($p['country'] ?? ''), ', '), date('d/m H:i', strtotime($p['last'])), (string)$p['camp']], $pts), JSON_UNESCAPED_UNICODE) ?>;
        const map = L.map('map', { worldCopyJump: true, scrollWheelZoom: false }).setView([44, 2], 4);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 12, subdomains: 'abcd', attribution: '© OpenStreetMap · © CARTO' }).addTo(map);
        const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
        const b = [];
        pts.forEach(([la, lo, v, u, name, last, camp]) => {
            b.push([la, lo]);
            L.circleMarker([la, lo], { radius: 5 + Math.sqrt(v) * 3, color: camp ? '#ffd21f' : '#c8ff3d', weight: 1.5, fillOpacity: .35 })
                .bindPopup(`<b>${esc(name)}</b><br>${v} visitas · ${u} visitantes<br>última: ${last}${camp ? '<br>★ ' + esc(camp) : ''}`).addTo(map);
        });
        if (b.length > 1) map.fitBounds(b, { padding: [30, 30], maxZoom: 6 });
    })();
    </script>

<?php elseif ($tab === 'mouse'):
    $sec = analytics_section($_GET['s'] ?? '') ?? 'top';
    $mode = in_array($_GET['m'] ?? '', ['clicks', 'moves', 'frustration'], true) ? $_GET['m'] : 'clicks';
    $counts = []; foreach ($q('SELECT section k, COUNT(*) v FROM v2_clicks WHERE created_at >= ? GROUP BY section', [$since]) as $r) $counts[$r['k']] = (int)$r['v'];
    $clicks = $q("SELECT x, y, kind FROM v2_clicks WHERE section = ? AND created_at >= ?" . ($mode === 'frustration' ? " AND kind IN ('rage','dead')" : '') . ' ORDER BY id DESC LIMIT 4000', [$sec, $since]);
    $moves = $q('SELECT cell, n FROM v2_moves WHERE section = ?', [$sec]);
    $targets = $q("SELECT COALESCE(target, '(sin texto)') k, COUNT(*) v FROM v2_clicks WHERE section = ? AND created_at >= ? AND kind = 'ui' GROUP BY target ORDER BY v DESC LIMIT 12", [$sec, $since]);
    $deadZones = $q("SELECT section k, COUNT(*) v FROM v2_clicks WHERE created_at >= ? AND kind = 'dead' GROUP BY section ORDER BY v DESC", [$since]);
    $rageList = $q("SELECT section, COALESCE(target,'') target, COUNT(*) v FROM v2_clicks WHERE created_at >= ? AND kind = 'rage' GROUP BY section, target ORDER BY v DESC LIMIT 10", [$since]);
    $siteLang = 'es';
?>
    <h1>Ratón y clics <small class="note">mapas de calor sobre tu web real · posiciones relativas, anónimas</small></h1>
    <div class="seg filters"><?php foreach ($SECN as $k => $l): if ($k === 'menu' && empty($counts['menu'])) continue; ?><a href="?tab=mouse&amp;days=<?= $days ?>&amp;m=<?= $mode ?>&amp;s=<?= $k ?>"<?= $sec === $k ? ' aria-current="true"' : '' ?>><?= h($l) ?><?= !empty($counts[$k]) ? ' <em>' . $counts[$k] . '</em>' : '' ?></a><?php endforeach; ?></div>
    <div class="seg filters"><?php foreach (['clicks' => '🖱 Clics', 'moves' => '〰 Movimiento del ratón', 'frustration' => '⚠ Frustración (clics repetidos / sin acción)'] as $k => $l): ?><a href="?tab=mouse&amp;days=<?= $days ?>&amp;s=<?= $sec ?>&amp;m=<?= $k ?>"<?= $mode === $k ? ' aria-current="true"' : '' ?>><?= $l ?></a><?php endforeach; ?></div>
    <div class="grid2 heat-grid">
        <section class="card heat">
            <h2><?= h($SECN[$sec]) ?> · <?= $mode === 'moves' ? array_sum(array_map(fn($m) => (int)$m['n'], $moves)) . ' posiciones (todas las fechas)' : count($clicks) . ' clics' ?></h2>
            <div class="heat-stage" data-sec="<?= h($sec) ?>">
                <?php if ($sec !== 'menu'): ?><iframe src="<?= h($B) ?>/<?= $siteLang ?>/?nomotion=1&amp;notrack=1" loading="lazy" tabindex="-1" aria-hidden="true"></iframe><?php endif; ?>
                <canvas></canvas>
            </div>
            <p class="note">Vista de la sección a 1440 px de ancho. Cuanto más cálido el color, más actividad.</p>
        </section>
        <div>
            <section class="card"><h2>Lo más pulsado aquí</h2><?= $bars($targets, 'Sin clics en esta sección.') ?></section>
            <section class="card"><h2>Clics en zonas sin acción <small>¿esperaban un enlace?</small></h2><?= $bars(array_map(fn($r) => ['k' => $SECN[$r['k']] ?? $r['k'], 'v' => $r['v']], $deadZones), 'Ninguno 👍') ?></section>
            <section class="card"><h2>Clics de frustración</h2><?= $bars(array_map(fn($r) => ['k' => ($SECN[$r['section']] ?? $r['section']) . ($r['target'] ? ' · ' . $r['target'] : ''), 'v' => $r['v']], $rageList), 'Ninguno 👍') ?></section>
        </div>
    </div>
    <script>
    (() => {
        const mode = <?= json_encode($mode) ?>, sec = <?= json_encode($sec) ?>;
        const clicks = <?= json_encode(array_map(fn($c) => [(int)$c['x'], (int)$c['y'], $c['kind']], $clicks)) ?>;
        const moves = <?= json_encode(array_map(fn($m) => [(int)$m['cell'], (int)$m['n']], $moves)) ?>;
        const stage = document.querySelector('.heat-stage'), frame = stage.querySelector('iframe'), cv = stage.querySelector('canvas');
        const VW = 1440;
        let H = 900;
        const draw = () => {
            const scale = stage.clientWidth / VW;
            stage.style.height = Math.round(H * scale) + 'px';
            if (frame) { frame.style.height = H + 'px'; frame.style.transform = `scale(${scale})`; }
            cv.width = VW; cv.height = H; cv.style.height = Math.round(H * scale) + 'px';
            const g = cv.getContext('2d');
            g.clearRect(0, 0, VW, H);
            // 1) intensity in alpha
            const pts = mode === 'moves'
                ? moves.map(([cell, n]) => [((cell % 20) + .5) / 20 * VW, (Math.floor(cell / 20) + .5) / 20 * H, n, Math.max(VW / 20, H / 20) * 1.1])
                : clicks.map(([x, y]) => [x / 1000 * VW, y / 1000 * H, 1, 38]);
            if (!pts.length) return;
            const max = Math.max(...pts.map((p) => p[2]));
            pts.forEach(([x, y, n, r]) => {
                const a = mode === 'moves' ? Math.min(1, .04 + Math.pow(n / max, .8) * .8) : Math.min(1, 6 / Math.sqrt(pts.length) + .08);
                const grd = g.createRadialGradient(x, y, 0, x, y, r);
                grd.addColorStop(0, `rgba(0,0,0,${a})`); grd.addColorStop(1, 'rgba(0,0,0,0)');
                g.fillStyle = grd; g.fillRect(x - r, y - r, r * 2, r * 2);
            });
            // 2) colourise alpha → blue / green / yellow / red
            const img = g.getImageData(0, 0, VW, H), d = img.data;
            for (let i = 0; i < d.length; i += 4) {
                const a = d[i + 3] / 255;
                if (!a) continue;
                const t = Math.min(1, a * 1.25);
                const [r, gg, b] = t < .33 ? [40, 120 + t * 400, 255] : t < .66 ? [(t - .33) * 770, 255, 255 - (t - .33) * 770] : [255, 255 - (t - .66) * 700, 0];
                d[i] = r; d[i + 1] = gg; d[i + 2] = b; d[i + 3] = Math.min(230, 70 + a * 200);
            }
            g.putImageData(img, 0, 0);
            if (mode !== 'moves') clicks.forEach(([x, y, k]) => { if (k !== 'ui') { g.strokeStyle = k === 'rage' ? '#ff3d6e' : '#fff'; g.lineWidth = 2; g.beginPath(); g.arc(x / 1000 * VW, y / 1000 * H, 7, 0, 7); g.stroke(); } });
        };
        const place = () => {
            try {
                const w = frame.contentWindow, doc = frame.contentDocument;
                const el = sec === 'footer' ? doc.querySelector('footer') : doc.getElementById(sec);
                if (!el) return draw();
                H = Math.min(3200, Math.max(300, Math.round(el.getBoundingClientRect().height)));
                frame.style.height = H + 'px';
                setTimeout(() => {
                    H = Math.min(3200, Math.max(300, Math.round(el.getBoundingClientRect().height)));
                    w.scrollTo(0, el.getBoundingClientRect().top + w.scrollY);
                    draw();
                }, 700);
            } catch { draw(); }
        };
        if (frame) frame.addEventListener('load', () => setTimeout(place, 1200)); else draw();
        addEventListener('resize', draw);
        draw();
    })();
    </script>

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
