<?php
declare(strict_types=1);
/**
 * Panel de la v2 — https://mam-com.com/v2/admin/
 * Mismo contraseña que el panel principal (admin_password_hash de api/config.php).
 * Muestra solo lo propio de la v2; mensajes, visitas globales y creación de enlaces siguen en /admin/.
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
if ($logged && $pdo && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'extras') {
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
        } elseif ($action === 'delete_question') {
            $pdo->prepare('DELETE FROM v2_questions WHERE id = ?')->execute([(int)$_POST['id']]);
        } elseif ($action === 'delete_cv') {
            $pdo->prepare('DELETE FROM v2_cv_requests WHERE id = ?')->execute([(int)$_POST['id']]);
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage() . ' — ¿has importado sql/v2-tables.sql?';
    }
    header('Location: ./?tab=' . rawurlencode((string)($_POST['tab'] ?? 'overview')));
    exit;
}

$tab = in_array($_GET['tab'] ?? '', ['overview', 'questions', 'cv', 'links'], true) ? $_GET['tab'] : 'overview';
$days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90, 365], true) ? (int)($_GET['days'] ?? 30) : 30;
$since = date('Y-m-d H:i:s', time() - $days * 86400);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$B = v2_base();
$q = function (string $sql, array $args = []) use ($pdo): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[v2 admin] ' . $e->getMessage());
        return [];
    }
};
$one = fn(string $sql, array $args = []) => (int)(array_values($q($sql, $args)[0] ?? [0])[0] ?? 0);
$bars = function (array $rows): string {
    if (!$rows) {
        return '<p class="empty">—</p>';
    }
    $max = max(array_map(fn($r) => (int)$r['v'], $rows)) ?: 1;
    $out = '<ul class="bars">';
    foreach ($rows as $r) {
        $out .= '<li><span>' . h((string)($r['k'] ?? '') ?: '(directo)') . '</span><i style="--w:' . round($r['v'] / $max * 100) . '%"></i><b>' . (int)$r['v'] . '</b></li>';
    }
    return $out . '</ul>';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel v2 · mam-com.com</title>
<link rel="icon" href="<?= $B ?>/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="admin.css?v=<?= (int)@filemtime(__DIR__ . '/admin.css') ?>">
</head>
<body>
<?php if (!$logged): ?>
<main class="login">
    <form method="post" class="card">
        <h1>Panel <em>v2</em></h1>
        <p class="note">Misma contraseña que el panel principal.</p>
        <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
        <input name="password" type="password" placeholder="Contraseña" autocomplete="current-password" required autofocus>
        <button class="btn">Entrar</button>
    </form>
</main>
<?php elseif (!$pdo): ?>
<main class="login"><div class="card"><h1>Sin base de datos</h1><p>Revisa api/config.php del sitio principal.</p></div></main>
<?php else: ?>
<header class="top">
    <a href="./" class="brand"><b>mam-com</b> · panel v2</a>
    <nav>
        <?php foreach (['overview' => 'Resumen', 'questions' => 'Preguntas al asistente', 'cv' => 'CV con nombre', 'links' => 'Enlaces reclutador'] as $k => $label): ?>
        <a href="?tab=<?= $k ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <div><a href="/old_v1-02/admin/">Panel completo (mensajes, enlaces) ↗</a> · <a href="<?= $B ?>/" target="_blank">Ver la web ↗</a> · <a href="?logout=1">Salir</a></div>
</header>
<main class="wrap">
<?php if ($flash): ?><p class="err"><?= h($flash) ?></p><?php endif; ?>

<?php if ($tab === 'overview'):
    $v1 = $one("SELECT COUNT(*) FROM visits WHERE created_at >= ? AND path NOT LIKE ?", [$since, $B . '/%']);
    $v2 = $one("SELECT COUNT(*) FROM visits WHERE created_at >= ? AND path LIKE ?", [$since, $B . '/%']);
    $u2 = $one("SELECT COUNT(DISTINCT visitor_hash) FROM visits WHERE created_at >= ? AND path LIKE ?", [$since, $B . '/%']);
    // v2 events: sessions that visited the v2
    $v2Sessions = "SELECT DISTINCT session_id FROM visits WHERE path LIKE ? AND created_at >= ?";
    $avg = $one("SELECT COALESCE(AVG(CAST(value AS UNSIGNED)),0) FROM events WHERE name='time_on_page' AND session_id IN ($v2Sessions) AND created_at >= ?", [$B . '/%', $since, $since]);
    $scroll = $one("SELECT COALESCE(AVG(CAST(value AS UNSIGNED)),0) FROM events WHERE name='scroll_depth' AND session_id IN ($v2Sessions) AND created_at >= ?", [$B . '/%', $since, $since]);
    $cv = $one("SELECT COUNT(*) FROM events WHERE name='cv_download' AND session_id IN ($v2Sessions) AND created_at >= ?", [$B . '/%', $since, $since]);
    $asks = $one('SELECT COUNT(*) FROM v2_questions WHERE created_at >= ?', [$since]);
    $daily = $q("SELECT DATE(created_at) d, SUM(CASE WHEN path LIKE ? THEN 1 ELSE 0 END) v2, SUM(CASE WHEN path LIKE ? THEN 0 ELSE 1 END) v1 FROM visits WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d", [$B . '/%', $B . '/%', $since]);
    $maxD = max([1, ...array_map(fn($r) => (int)$r['v1'] + (int)$r['v2'], $daily)]);
?>
    <div class="head">
        <h1>Resumen de la v2</h1>
        <div class="seg"><?php foreach ([7, 30, 90, 365] as $d): ?><a href="?days=<?= $d ?>"<?= $d === $days ? ' aria-current="true"' : '' ?>><?= $d ?> d</a><?php endforeach; ?></div>
    </div>
    <div class="kpis">
        <div class="card"><small>Visitas v2</small><b><?= $v2 ?></b></div>
        <div class="card"><small>Visitas web clásica</small><b><?= $v1 ?></b></div>
        <div class="card"><small>Visitantes únicos v2</small><b><?= $u2 ?></b></div>
        <div class="card"><small>Tiempo medio v2</small><b><?= gmdate('i:s', $avg) ?></b></div>
        <div class="card"><small>Scroll medio v2</small><b><?= $scroll ?>%</b></div>
        <div class="card hl"><small>CV descargados (v2)</small><b><?= $cv ?></b></div>
        <div class="card hl"><small>Preguntas al asistente</small><b><?= $asks ?></b></div>
    </div>
    <section class="card">
        <h2>Visitas por día <small><i class="dot v1"></i> clásica <i class="dot v2"></i> v2</small></h2>
        <div class="chart">
            <?php foreach ($daily as $r): ?>
            <div class="col" title="<?= h($r['d']) ?> · clásica <?= (int)$r['v1'] ?> · v2 <?= (int)$r['v2'] ?>"><i class="v1" style="height:<?= (int)$r['v1'] / $maxD * 100 ?>%"></i><i class="v2" style="height:<?= (int)$r['v2'] / $maxD * 100 ?>%"></i></div>
            <?php endforeach; ?>
            <?php if (!$daily): ?><p class="empty">Aún no hay visitas.</p><?php endif; ?>
        </div>
    </section>
    <div class="grid">
        <section class="card"><h2>Secciones vistas (v2)</h2><?= $bars($q("SELECT REPLACE(value,'v2:','') k, COUNT(DISTINCT session_id) v FROM events WHERE name='section_view' AND value LIKE 'v2:%' AND created_at >= ? GROUP BY value ORDER BY v DESC", [$since])) ?></section>
        <section class="card"><h2>De dónde vienen (v2)</h2><?= $bars($q("SELECT COALESCE(referrer_host,'(directo)') k, COUNT(*) v FROM visits WHERE path LIKE ? AND created_at >= ? GROUP BY referrer_host ORDER BY v DESC LIMIT 10", [$B . '/%', $since])) ?></section>
        <section class="card"><h2>Países (v2)</h2><?= $bars($q("SELECT COALESCE(country,'??') k, COUNT(*) v FROM visits WHERE path LIKE ? AND created_at >= ? GROUP BY country ORDER BY v DESC LIMIT 10", [$B . '/%', $since])) ?></section>
        <section class="card"><h2>Idiomas (v2)</h2><?= $bars($q("SELECT lang k, COUNT(*) v FROM visits WHERE path LIKE ? AND created_at >= ? GROUP BY lang ORDER BY v DESC", [$B . '/%', $since])) ?></section>
        <section class="card"><h2>Dispositivos (v2)</h2><?= $bars($q("SELECT device k, COUNT(*) v FROM visits WHERE path LIKE ? AND created_at >= ? GROUP BY device ORDER BY v DESC", [$B . '/%', $since])) ?></section>
        <section class="card"><h2>Clics (v2)</h2><?= $bars($q("SELECT CASE WHEN value IS NULL OR value = '' THEN name ELSE " . (str_starts_with((string)($c['db']['dsn'] ?? ''), 'sqlite') ? "name || ' · ' || value" : "CONCAT(name, ' · ', value)") . " END k, COUNT(*) v FROM events WHERE name IN ('linkedin_click','github_click','email_click','github_eduverse','github_repo','v1_link') AND session_id IN ($v2Sessions) AND created_at >= ? GROUP BY k ORDER BY v DESC LIMIT 12", [$B . '/%', $since, $since])) ?></section>
    </div>

<?php elseif ($tab === 'questions'): ?>
    <h1>Preguntas al asistente</h1>
    <p class="note">Lo que los visitantes quieren saber de ti: úsalo para mejorar tu perfil y tu CV.</p>
    <?php foreach ($q('SELECT * FROM v2_questions ORDER BY id DESC LIMIT 300') as $x): ?>
    <article class="card qa">
        <p class="q"><b>P</b> <?= h($x['question']) ?></p>
        <p class="a"><b>R</b> <?= nl2br(h($x['answer'])) ?></p>
        <footer><small><?= h($x['created_at']) ?> · <?= h($x['lang']) ?> · <?= $x['source'] === 'ai' ? 'IA' : 'sin IA' ?><?= $x['campaign'] ? ' · ★ ' . h($x['campaign']) : '' ?></small>
            <form method="post" onsubmit="return confirm('¿Borrar?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tab" value="questions"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="link" name="action" value="delete_question">Borrar</button></form></footer>
    </article>
    <?php endforeach; ?>

<?php elseif ($tab === 'cv'): ?>
    <h1>CV descargados con nombre</h1>
    <p class="note">Todas las descargas cuentan en el panel principal (evento cv_download). Aquí solo las de quien dejó su nombre o empresa en la v2.</p>
    <div class="card"><table>
        <tr><th>Fecha</th><th>CV</th><th>Nombre</th><th>Empresa</th><th>Enlace</th><th></th></tr>
        <?php foreach ($q('SELECT * FROM v2_cv_requests ORDER BY id DESC LIMIT 300') as $d): ?>
        <tr><td><?= h($d['created_at']) ?></td><td><?= strtoupper(h($d['cv_lang'])) ?></td><td><?= h($d['name']) ?></td><td><?= h($d['company']) ?></td><td><?= h($d['campaign']) ?></td>
            <td><form method="post" onsubmit="return confirm('¿Borrar?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tab" value="cv"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="link" name="action" value="delete_cv">Borrar</button></form></td></tr>
        <?php endforeach; ?>
    </table></div>

<?php else:
    $profile = require APP_DIR . '/profile.php';
    $links = $q("SELECT c.slug, c.company, c.contact_name, c.lang, x.skills, x.message,
                        (SELECT COUNT(*) FROM visits v WHERE v.campaign = c.slug AND v.path LIKE ?) v2views,
                        (SELECT MAX(created_at) FROM visits v WHERE v.campaign = c.slug AND v.path LIKE ?) v2last
                 FROM campaigns c LEFT JOIN v2_campaign_extras x ON x.slug = c.slug ORDER BY c.created_at DESC", [$B . '/%', $B . '/%']);
?>
    <h1>Enlaces para reclutadores en la v2</h1>
    <p class="note">Los enlaces se crean en el <a href="/old_v1-02/admin/">panel completo</a> (tabla <code>campaigns</code>). El mismo enlace funciona en las dos versiones: <code>/?c=empresa</code> (web nueva) y <code>/old_v1-02/?c=empresa</code> (versión antigua). Aquí eliges qué competencias resaltar y un mensaje personal para la v2.</p>
    <?php foreach ($links as $l): $url = v2_url() . '/' . (in_array($l['lang'], LANGS, true) ? $l['lang'] . '/' : '') . '?c=' . $l['slug']; $sel = array_filter(explode(',', (string)$l['skills'])); ?>
    <form method="post" class="card link-card">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tab" value="links"><input type="hidden" name="action" value="extras"><input type="hidden" name="slug" value="<?= h($l['slug']) ?>">
        <header>
            <div><b><?= h($l['company']) ?></b><?= $l['contact_name'] ? ' · ' . h($l['contact_name']) : '' ?></div>
            <span class="tag"><?= (int)$l['v2views'] ?> visitas v2<?= $l['v2last'] ? ' · última ' . h($l['v2last']) : '' ?></span>
        </header>
        <input class="url" readonly value="<?= h($url) ?>" onclick="this.select();navigator.clipboard&&navigator.clipboard.writeText(this.value)" title="Clic para copiar">
        <fieldset><legend>Competencias a resaltar</legend>
            <?php foreach ($profile['skills'] as $group): foreach ($group as $key => [$label]): ?>
            <label class="check"><input type="checkbox" name="skills[]" value="<?= $key ?>"<?= in_array($key, $sel, true) ? ' checked' : '' ?>> <?= h($label) ?></label>
            <?php endforeach; endforeach; ?>
        </fieldset>
        <label>Mensaje personal (opcional, sustituye al texto por defecto)<textarea name="message" rows="2" maxlength="1000"><?= h($l['message']) ?></textarea></label>
        <button class="btn">Guardar</button>
    </form>
    <?php endforeach; ?>
    <?php if (!$links): ?><p class="empty">Aún no hay enlaces. Créalos en el panel principal.</p><?php endif; ?>
<?php endif; ?>
</main>
<?php endif; ?>
</body>
</html>
