<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$B = v2_base();                                   // "/v2"
$A = $B . '/assets';
$lang = $_GET['lang'] ?? '';
// Recruiter link: ?c=slug (same links as the main site) — ?for=slug also accepted
$slug = slug($_GET['c'] ?? $_GET['for'] ?? '');

// ---------- /v2/ → /v2/fr|en|es/ (browser language, or the language of the recruiter link) ----------
if (!in_array($lang, LANGS, true)) {
    $best = 'en';
    foreach (preg_split('/,\s*/', strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?: [] as $part) {
        if (in_array(substr($part, 0, 2), LANGS, true)) {
            $best = substr($part, 0, 2);
            break;
        }
    }
    $rec = recruiter($slug);
    if ($rec && in_array($rec['lang'] ?? '', LANGS, true)) {
        $best = $rec['lang'];
    }
    header('Location: ' . $B . '/' . $best . '/' . ($slug !== '' ? '?c=' . rawurlencode($slug) : ''), true, 302);
    exit;
}

$S = strings($lang)['s'];
$UI = strings($lang)['ui'];
$C = t_all($lang);                                // toolbox, assistant, recruiter, privacy texts
$X = (require APP_DIR . '/i18n/extra.php')[$lang];
$p = require APP_DIR . '/profile.php';
$base = v2_url();
$canonical = $base . '/' . $lang . '/';

// ---------- Recruiter link: greeting, highlighted skills, e-mail when opened (max 1 per 30 min) ----------
$rec = recruiter($slug);
$highlight = $rec ? array_values(array_filter(array_map('trim', explode(',', (string)$rec['skills'])))) : [];
if ($rec && !is_bot() && ($pdo = db())) {
    try {
        $st = $pdo->prepare('SELECT notified_at FROM v2_campaign_extras WHERE slug = ?');
        $st->execute([$rec['slug']]);
        $last = $st->fetchColumn();
        if (!$last || strtotime((string)$last) < time() - 1800) {
            $now = date('Y-m-d H:i:s');
            if ($last === false) {
                $pdo->prepare('INSERT INTO v2_campaign_extras (slug, notified_at) VALUES (?, ?)')->execute([$rec['slug'], $now]);
            } else {
                $pdo->prepare('UPDATE v2_campaign_extras SET notified_at = ? WHERE slug = ?')->execute([$now, $rec['slug']]);
            }
            notify('Link opened: ' . $rec['company'], "{$rec['company']} opened the v2 portfolio ({$lang}).\nLink: {$base}/{$lang}/?c={$rec['slug']}");
        }
    } catch (Throwable $e) {
        error_log('[v2] recruiter notify: ' . $e->getMessage());
    }
}

$e = fn(?string $s): string => h($s);
// trusted HTML from our own translation files; keeps "<em>word</em>," together when lines wrap
$s = fn(string $k): string => preg_replace('#(<em[^>]*>[^<]{1,24}</em>)([,.;:!?)])#u', '<span class="nw">$1$2</span>', $S[$k] ?? $k);
$em = fn(string $html): string => strip_tags($html, '<em>');
$recQs = $rec ? '?c=' . rawurlencode($rec['slug']) : '';
$v = fn(string $file): int => (int)@filemtime(__DIR__ . $file);
$cvUrl = $B . '/api/cv.php?cv=' . $lang;
$cvAttr = 'href="' . h($cvUrl) . '" target="_blank" rel="noopener" data-cv-link';

// Skills for the 3D constellation (from profile.php: key => [label, level 1-10])
$skills = [];
foreach ($p['skills'] as $group => $list) {
    foreach ($list as $key => [$label, $level]) {
        $skills[] = ['k' => $key, 'l' => $label, 'v' => $level, 'g' => $group, 'h' => in_array($key, $highlight, true)];
    }
}
$groupAvg = [];
foreach ($p['skills'] as $group => $list) {
    $groupAvg[$group] = round(array_sum(array_column($list, 1)) / max(1, count($list)), 1);
}
// JS strings: the main site's UI strings + assistant + 3D
$js = $UI + [
    'thinking' => $C['js']['thinking'], 'ask_error' => $C['js']['ask_error'], 'ask_limit' => $C['js']['ask_limit'],
    'level' => $s('skills.level'), 'theory' => $s('edu.theory'), 'measured' => $s('edu.measured'),
    'greetName' => $rec ? ($rec['contact_name'] ?: $rec['company']) : '',
    'groups' => ['dev' => strip_tags($s('skills.dev')), 'design' => strip_tags($s('skills.design')), 'ai' => strip_tags($s('skills.ai'))],
    'sec' => $X['sec'],
];
$jobs = [
    ['2006', 'Just Search', 'j1', null], ['2009', 'Linkeo', 'j2', null], ['2009', 'j3t', 'j3', 'key'], ['2014', 'Linkeo', 'j4', null],
    ['2017', 'j5t', 'j5', 'key'], ['2019', 'Grupo Peralada', 'j6', null], ['2024', 'Nabbü', 'j7', 'now'],
];
// spectrum colour index per card (1 red … 7 violet)
$works = [
    ['w1', 4, ['C#', 'ASP.NET', 'SQL Server', 'Docker', 'UI/UX'], 'dash'],
    ['w2', 5, ['UX', 'PHP', 'jQuery', 'JSON'], 'config'],
    ['w3', 1, ['Symfony', 'JavaScript', 'Graphisme'], 'casino'],
    ['w4', 2, ['WordPress', 'Prestashop', 'SEO', 'Marketing'], 'sites'],
    ['w5', 7, ['Illustrator', 'InDesign', 'After Effects', 'Lightroom'], 'brand'],
];
$sections = ['manifesto', 'services', 'nabbu', 'eduverse', 'suite', 'work', 'journey', 'method', 'skills', 'ask', 'contact'];
$W = $C['work'];
$NB = $X['nabbu'];
// Nabbü screenshots: drop images in assets/img/nabbu/ (01-dashboard.webp, 02-alumnos.webp…), they appear automatically
$nbShots = [];
foreach (glob(__DIR__ . '/assets/img/nabbu/*.{webp,png,jpg,jpeg}', GLOB_BRACE) ?: [] as $f) {
    $key = strtolower(preg_replace('/^\d+[-_ ]*|\.[a-z]+$/i', '', basename($f)));
    $cap = $NB['cap'][$key] ?? ucfirst(str_replace(['-', '_'], ' ', $key));
    $size = @getimagesize($f) ?: [1600, 1000];
    $nbShots[] = ['src' => $A . '/img/nabbu/' . rawurlencode(basename($f)) . '?v=' . filemtime($f), 'cap' => $cap, 'w' => $size[0], 'h' => $size[1]];
}
$SV = $C['services'];
$EX = $C['experience'];
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e(strip_tags($s('meta.title'))) ?></title>
<meta name="description" content="<?= $e($C['meta']['description']) ?>">
<meta name="author" content="Mathieu Moreau">
<meta name="theme-color" content="#08080C">
<link rel="canonical" href="<?= $e($canonical) ?>">
<?php foreach (LANGS as $l): ?>
<link rel="alternate" hreflang="<?= $l ?>" href="<?= $e($base . '/' . $l . '/') ?>">
<?php endforeach; ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $e(strip_tags($s('meta.title'))) ?>">
<meta property="og:description" content="<?= $e($C['meta']['description']) ?>">
<meta property="og:url" content="<?= $e($canonical) ?>">
<meta property="og:image" content="<?= $e($base) ?>/assets/img/og.png">
<meta name="twitter:card" content="summary_large_image">
<?php if (empty(config()['v2']['index'])): ?><meta name="robots" content="noindex, follow"><?php endif; ?>
<link rel="icon" href="<?= $A ?>/img/favicon.svg" type="image/svg+xml">
<link rel="preload" href="<?= $A ?>/fonts/bricolage-grotesque-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= $A ?>/fonts/instrument-serif-latin-400-italic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $A ?>/css/main.css?v=<?= $v('/assets/css/main.css') ?>">
<script>try{var t=localStorage.getItem('v2theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org', '@type' => 'Person', 'name' => $p['name'], 'url' => $canonical,
    'jobTitle' => 'Full Stack .NET Developer & UI/UX Designer', 'sameAs' => [$p['github'], $p['linkedin']], 'knowsLanguage' => ['fr', 'en', 'es'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body class="<?= $rec ? 'is-recruiter' : '' ?>" data-lang="<?= $lang ?>" data-for="<?= $e($rec['slug'] ?? '') ?>" data-base="<?= $e($B) ?>" data-lab="<?= $A ?>/js/lab3d.js?v=<?= $v('/assets/js/lab3d.js') ?>">
<a class="skip" href="#main">Skip to content</a>

<!-- ============ LOADER: a prism splits the light ============ -->
<div class="loader" aria-hidden="true">
    <div class="prism"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
    <div class="loader-count"><span data-loader-num>0</span></div>
    <div class="loader-name">Mathieu Moreau <em>— design × code × IA</em></div>
</div>
<div class="progress" aria-hidden="true"></div>
<div class="grain" aria-hidden="true"></div>
<div class="cursor" aria-hidden="true"><span class="cursor-dot"></span><span class="cursor-ring"><b class="cursor-label"></b></span></div>

<!-- ============ TOP BAR ============ -->
<header class="nav" data-label="header.nav">
    <a href="#top" class="logo magnetic" aria-label="Mathieu Moreau — design × code">
        <svg class="logo-svg" viewBox="0 0 48 48" aria-hidden="true">
            <defs>
                <linearGradient id="lgSpec" x1="0" y1="0" x2="48" y2="48" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#FF3D6E"/><stop offset=".2" stop-color="#FF8A1E"/><stop offset=".38" stop-color="#FFD21F"/><stop offset=".55" stop-color="#34E089"/><stop offset=".72" stop-color="#1EC8FF"/><stop offset=".86" stop-color="#4C6BFF"/><stop offset="1" stop-color="#B65CFF"/>
                    <animateTransform attributeName="gradientTransform" type="rotate" values="0 24 24;360 24 24" dur="8s" repeatCount="indefinite"/>
                </linearGradient>
            </defs>
            <rect class="lg-bg" x="2" y="2" width="44" height="44" rx="14"/>
            <rect class="lg-ring" x="2" y="2" width="44" height="44" rx="14" stroke="url(#lgSpec)"/>
            <path class="lg-m" d="M13 34 V15 L24 27 L35 15 V34" stroke="url(#lgSpec)"/>
            <circle class="lg-dot" cx="38.5" cy="34" r="2.6"/>
        </svg>
        <span class="logo-word"><b>Mathieu Moreau</b><small>design × code × IA</small></span>
    </a>
    <div class="nav-now" aria-hidden="true"><span class="nav-now-n" data-now-n>00</span><span class="nav-now-t" data-now-t><?= $e($X['sec']['top']) ?></span></div>
    <div class="nav-tools">
        <span class="status-pill"><i></i><span><?= $s('nav.status') ?></span></span>
        <div class="lang-select" data-lang-select>
            <?php $langNames = ['fr' => 'Français', 'en' => 'English', 'es' => 'Español']; ?>
            <button type="button" class="ls-btn" aria-haspopup="true" aria-expanded="false" aria-controls="lang-menu" aria-label="Language: <?= $e($langNames[$lang]) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.4 5.6 12 3z"/></svg>
                <b><?= strtoupper($lang) ?></b><i class="ls-arrow" aria-hidden="true"></i>
            </button>
            <ul class="ls-menu" id="lang-menu" role="menu">
                <?php foreach (['fr', 'en', 'es'] as $l): ?>
                <li role="none"><a role="menuitem" href="<?= $B ?>/<?= $l ?>/<?= $e($recQs) ?>" hreflang="<?= $l ?>" lang="<?= $l ?>" data-track="lang_switch" data-value="<?= $l ?>"<?= $l === $lang ? ' aria-current="true"' : '' ?>><b><?= strtoupper($l) ?></b><span><?= $langNames[$l] ?></span><?= $l === $lang ? '<i aria-hidden="true">✓</i>' : '' ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <button class="icon-btn" type="button" data-code-toggle title="Design / Code" aria-pressed="false"><span class="mode-design">✦</span><span class="mode-code">&lt;/&gt;</span></button>
        <button class="icon-btn" type="button" data-theme-toggle title="Theme" aria-label="Theme">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="5"/><path d="M12 1v3M12 20v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M1 12h3M20 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>
        </button>
        <button class="icon-btn kbd-btn" type="button" data-palette-open title="Ctrl + K">⌘K</button>
        <button class="menu-btn magnetic" type="button" data-menu aria-expanded="false" aria-controls="menu"><span class="menu-btn-t"><span><?= $e($X['menu']) ?></span><span><?= $e($X['close']) ?></span></span><span class="menu-btn-i"><i></i><i></i></span></button>
    </div>
</header>

<!-- ============ FULL-SCREEN 3D MENU ============ -->
<div class="menu" id="menu" data-menu-panel hidden>
    <div class="menu-bg" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
    <div class="menu-grid">
        <nav class="menu-list" aria-label="Main">
            <p class="menu-kicker"><?= $e($X['explore']) ?></p>
            <ol>
                <?php foreach ($sections as $i => $id): ?>
                <li style="--k:<?= $i ?>"><a href="#<?= $id ?>" data-menu-item="<?= $i ?>"><span class="mi-n"><?= sprintf('%02d', $i + 1) ?></span><span class="mi-t" data-text="<?= $e($X['sec'][$id]) ?>"><?= $e($X['sec'][$id]) ?></span><span class="mi-d"><?= $e($X['secd'][$id]) ?></span></a></li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <div class="menu-stage" aria-hidden="true">
            <div class="menu-cyl" data-menu-cyl style="--n:<?= count($sections) ?>">
                <?php foreach ($sections as $i => $id): ?>
                <a href="#<?= $id ?>" class="mc" data-mc="<?= $i ?>" tabindex="-1" style="--k:<?= $i ?>;--c:var(--s<?= ($i % 7) + 1 ?>)">
                    <span class="mc-n"><?= sprintf('%02d', $i + 1) ?></span>
                    <span class="mc-glyph"><?= ['◐', '✦', '🎓', '◆', '▦', '▲', '➝', '⬡', '✺', '❖', '✉'][$i] ?></span>
                    <b><?= $e($X['sec'][$id]) ?></b>
                    <small><?= $e($X['secd'][$id]) ?></small>
                    <span class="mc-go" aria-hidden="true">→</span>
                </a>
                <?php endforeach; ?>
            </div>
            <p class="menu-hint"><?= $e($X['menu_hint']) ?></p>
        </div>
    </div>
    <div class="menu-foot">
        <a <?= $cvAttr ?>>↓ <?= $e($X['open_cv']) ?></a>
        <a href="#" data-mail data-u="mathieu.moreau80" data-d="gmail.com" data-track="email_click">✉ <span>email</span></a>
        <a href="<?= $e($p['linkedin']) ?>" target="_blank" rel="noopener" data-track="linkedin_click">LinkedIn ↗</a>
        <a href="<?= $e($p['github']) ?>" target="_blank" rel="noopener" data-track="github_click">GitHub ↗</a>
        <span class="menu-clock"><?= $s('hero.localtime') ?> <b data-clock>--:--</b></span>
    </div>
</div>

<main id="main">
<!-- ============ HERO (WebGL iridescent blobs) ============ -->
<section class="hero" id="top" data-label="section#hero">
    <canvas class="hero-gl" data-hero-gl aria-hidden="true"></canvas>
    <div class="hero-fallback" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="hero-inner">
        <?php if ($rec): ?>
        <div class="greet" data-in>
            <span class="greet-wave" aria-hidden="true">👋</span>
            <div><strong><?= $e(trim(str_replace(['{name}', '👋'], [$rec['contact_name'] ?: $rec['company'], ''], explode('—', $UI['greet'])[0]))) ?></strong>
            <p><?= $e($rec['message'] ?: $C['recruiter']['text']) ?></p></div>
        </div>
        <?php endif; ?>
        <div class="hero-meta" data-in>
            <span><?= $s('hero.meta1') ?></span>
            <span><?= $s('hero.meta2') ?></span>
            <span class="clock"><?= $s('hero.localtime') ?> <b data-clock>--:--</b></span>
        </div>
        <h1 class="hero-title" aria-label="Mathieu Moreau">
            <span class="hero-line" aria-hidden="true"><span class="hl-in" data-chars>Mathieu</span></span>
            <span class="hero-line indent" aria-hidden="true"><span class="hl-in"><em class="serif amp">&amp;</em><span data-chars>Moreau</span></span></span>
        </h1>
        <div class="hero-bottom" data-in>
            <p class="hero-role"><span><?= $s('hero.iam') ?></span> <span class="typer" data-typer aria-live="polite"></span><span class="caret">|</span></p>
            <p class="hero-intro"><?= $s('hero.intro') ?></p>
            <div class="hero-cta">
                <a href="#work" class="btn btn-rainbow magnetic" data-cursor="Go"><span class="btn-txt" data-text="<?= $e($C['hero']['cta_work']) ?>"><?= $e($C['hero']['cta_work']) ?></span><span class="btn-ico" aria-hidden="true">→</span></a>
                <a <?= $cvAttr ?> class="btn btn-ghost magnetic" data-cursor="PDF"><span class="btn-txt" data-text="<?= $e(strip_tags($s('hero.cta2'))) ?>"><?= $s('hero.cta2') ?></span><span class="btn-ico" aria-hidden="true">↗</span></a>
            </div>
        </div>
    </div>
    <div class="stickers" aria-hidden="true">
        <div class="sticker s1" data-drag style="--r:-8deg"><b>20</b><small><?= $s('sticker.years') ?></small></div>
        <div class="sticker s2" data-drag style="--r:6deg">C# · .NET</div>
        <div class="sticker s3" data-drag style="--r:-4deg">Figma ✦</div>
        <div class="sticker s4" data-drag style="--r:10deg">🤖 IA</div>
        <div class="sticker s5" data-drag style="--r:-12deg">FR · EN · ES</div>
    </div>
    <p class="hero-hint" data-in><?= $e($X['drag_hint']) ?></p>
</section>

<!-- ============ MARQUEE ============ -->
<section class="marquee" aria-label="Skills" data-label="section.marquee">
    <div class="marquee-row" data-velo="1"><div class="marquee-track"><?php for ($i = 0; $i < 3; $i++): foreach ($p['marquee'] as $j => $m): ?><span><?= $e($m) ?></span><i style="--c:var(--s<?= ($j % 7) + 1 ?>)">✦</i><?php endforeach; endfor; ?></div></div>
    <div class="marquee-row alt" data-velo="-1"><div class="marquee-track"><?php for ($i = 0; $i < 3; $i++): ?><span class="serif">Design Thinking</span><i>●</i><span class="serif">Motion design</span><i>●</i><span class="serif">Photographie</span><i>●</i><span class="serif">Identité visuelle</span><i>●</i><span class="serif">IA générative</span><i>●</i><span class="serif">UX research</span><i>●</i><span class="serif">Design systems</span><i>●</i><?php endfor; ?></div></div>
</section>

<!-- ============ 01 APPROACH (V1 manifesto) ============ -->
<section class="section manifesto" id="manifesto" data-label="section#manifesto">
    <div class="wrap">
        <p class="eyebrow"><span>01</span> <?= $s('manifesto.eyebrow') ?></p>
        <p class="manifesto-text" data-words><?= $s('manifesto.text') ?></p>
        <div class="manifesto-cols">
            <?php foreach ($C['about']['text'] as $para): ?><p data-in><?= $e($para) ?></p><?php endforeach; ?>
        </div>
        <div class="stats">
            <?php foreach ($C['about']['stats'] as $i => [$num, $label]): $n = (int)preg_replace('/\D/', '', $num); ?>
            <div class="stat" style="--c:var(--s<?= [1, 3, 5, 7][$i] ?? 4 ?>)" data-flip>
                <b><?= str_starts_with($num, '~') ? '<small>~</small>' : '' ?><span data-count="<?= $n ?>"><?= $n ?></span></b>
                <span><?= $e($label) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="manifesto-foot"><span>🌐 <?= $e($C['about']['langs']) ?></span><span>📍 <?= $e($C['about']['location']) ?></span></p>
    </div>
</section>

<!-- ============ 02 SERVICES ============ -->
<section class="section services" id="services" data-label="section#services">
    <div class="wrap">
        <div class="section-head">
            <p class="eyebrow"><span>02</span> <?= $s('services.eyebrow') ?></p>
            <h2 class="title" data-split><?= $em($SV['title']) ?></h2>
            <p class="sub"><?= $s('services.sub') ?></p>
        </div>
        <div class="bento">
            <article class="card card-xl spot" data-tilt data-card style="--c:var(--s5)">
                <div class="card-tag">01</div>
                <div class="iso" aria-hidden="true">
                    <div class="iso-l iso-db"><b>SQL Server</b><span class="iso-rows"><i></i><i></i><i></i><i></i></span></div>
                    <div class="iso-l iso-api"><b>API · .NET</b><span class="iso-ep"><em>GET</em>/courses</span><span class="iso-ep"><em>POST</em>/enroll</span></div>
                    <div class="iso-l iso-ui"><b>UI</b><span class="iso-kpi"><i></i><i></i><i></i></span><span class="iso-chart"><i></i><i></i><i></i><i></i><i></i><i></i></span></div>
                </div>
                <h3><?= $s('svc.app.t') ?></h3>
                <p><?= $s('svc.app.d') ?></p>
                <div class="code-win" aria-hidden="true">
                    <div class="code-bar"><i></i><i></i><i></i><span>StudentService.cs</span></div>
<pre><code><span class="k">public async</span> Task&lt;<span class="t">Course</span>&gt; <span class="f">EnrollAsync</span>(<span class="t">int</span> id)
{
    <span class="k">var</span> student = <span class="k">await</span> _db.Students
        .<span class="f">FindAsync</span>(id);
    student.<span class="f">AddXp</span>(<span class="n">50</span>); <span class="c">// gamification ✦</span>
    <span class="k">return await</span> _ai.<span class="f">SuggestNext</span>(student);
}</code></pre>
                </div>
            </article>
            <article class="card spot" data-tilt data-card style="--c:var(--s3)">
                <div class="card-tag">02</div>
                <h3><?= $s('svc.ux.t') ?></h3>
                <p><?= $s('svc.ux.d') ?></p>
                <div class="swatches" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
            </article>
            <article class="card spot" data-tilt data-card style="--c:var(--s7)">
                <div class="card-tag">03</div>
                <h3><?= $s('svc.ai.t') ?></h3>
                <p><?= $s('svc.ai.d') ?></p>
                <div class="mini-chat" aria-hidden="true"><span class="bubble me"><?= $s('svc.ai.q') ?></span><span class="bubble ai"><i></i><i></i><i></i></span></div>
            </article>
            <article class="card spot" data-tilt data-card style="--c:var(--s4)">
                <div class="card-tag">04</div>
                <h3><?= $s('svc.wp.t') ?></h3>
                <p><?= $s('svc.wp.d') ?></p>
                <div class="mini-browser" aria-hidden="true"><span></span><span></span><span></span></div>
            </article>
            <article class="card spot" data-tilt data-card style="--c:var(--s1)">
                <div class="card-tag">05</div>
                <h3><?= $s('svc.brand.t') ?></h3>
                <p><?= $s('svc.brand.d') ?></p>
                <div class="logo-morph" aria-hidden="true"></div>
            </article>
            <article class="card spot" data-tilt data-card style="--c:var(--s2)">
                <div class="card-tag">06</div>
                <h3><?= $s('svc.motion.t') ?></h3>
                <p><?= $s('svc.motion.d') ?></p>
                <div class="motion-dots" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
            </article>
        </div>

        <div class="audience" data-audience>
            <div class="aud-head">
                <p class="aud-q"><?= $e($X['audience']) ?></p>
                <div class="aud-switch" role="tablist">
                    <span class="aud-thumb" aria-hidden="true"></span>
                    <button type="button" role="tab" aria-selected="true" data-aud="0"><?= $e($SV['companies']) ?></button>
                    <button type="button" role="tab" aria-selected="false" data-aud="1"><?= $e($SV['individuals']) ?></button>
                </div>
            </div>
            <?php foreach ([$SV['list_companies'], $SV['list_individuals']] as $k => $list): ?>
            <ol class="aud-list<?= $k === 0 ? ' is-on' : '' ?>" data-aud-list="<?= $k ?>">
                <?php foreach ($list as $i => [$t, $d]): ?>
                <li style="--c:var(--s<?= ($i * 2 + $k) % 7 + 1 ?>)"><span class="al-n"><?= sprintf('%02d', $i + 1) ?></span><b><?= $e($t) ?></b><p><?= $e($d) ?></p></li>
                <?php endforeach; ?>
            </ol>
            <?php endforeach; ?>
            <a href="#contact" class="link-arrow"><?= $e($SV['cta']) ?> →</a>
        </div>
    </div>
</section>

<!-- ============ 03 NABBÜ: real product in production, 3D coverflow of screenshots ============ -->
<section class="section nabbu" id="nabbu" data-label="section#nabbu">
    <div class="wrap">
        <div class="nb-grid">
            <div class="nb-copy">
                <p class="eyebrow"><span>03</span> <?= $e($NB['kicker']) ?></p>
                <h2 class="title" data-split><?= $em($NB['title']) ?></h2>
                <p class="sub"><?= $e($NB['sub']) ?></p>
                <ul class="nb-modules" data-stagger><?php foreach ($NB['modules'] as $i => $m): ?><li style="--c:var(--s<?= ($i % 7) + 1 ?>)"><?= $e($m) ?></li><?php endforeach; ?></ul>
            </div>
            <div class="nb-role" data-in>
                <h3><?= $e($NB['role']) ?></h3>
                <ol><?php foreach ($NB['points'] as $i => $pt): ?><li style="--c:var(--s<?= [5, 7, 4, 3, 1][$i] ?? 6 ?>)"><span><?= sprintf('%02d', $i + 1) ?></span><?= $e($pt) ?></li><?php endforeach; ?></ol>
                <div class="chips nb-stack"><span>C#</span><span>ASP.NET WebForms</span><span>SQL Server</span><span>Docker</span><span>Git</span><span>UI/UX</span><span>Claude Code</span></div>
                <a href="https://nabbu.es" target="_blank" rel="noopener" class="btn btn-rainbow magnetic" data-track="nabbu_site" data-cursor="Nabbü"><span class="btn-txt" data-text="<?= $e($NB['cta']) ?>"><?= $e($NB['cta']) ?></span><span class="btn-ico" aria-hidden="true">↗</span></a>
            </div>
        </div>

        <div class="nb-show" data-nb-show>
        <div class="nb-stage" data-nb-stage tabindex="0" aria-roledescription="carousel" aria-label="Nabbü">
            <?php if ($nbShots): foreach ($nbShots as $i => $shot): ?>
            <figure class="nb-shot" data-nb="<?= $i ?>" style="--k:<?= $i ?>">
                <div class="nb-frame"><div class="nb-bar"><i></i><i></i><i></i><span>app.nabbu.es</span></div>
                <img src="<?= $e($shot['src']) ?>" alt="Nabbü — <?= $e($shot['cap']) ?>" width="<?= (int)$shot['w'] ?>" height="<?= (int)$shot['h'] ?>" loading="lazy" decoding="async" draggable="false"></div>
                <figcaption><?= $e($shot['cap']) ?></figcaption>
            </figure>
            <?php endforeach; else: foreach (['dashboard', 'alumnos', 'cursos'] as $i => $k): ?>
            <figure class="nb-shot is-schema" data-nb="<?= $i ?>" style="--k:<?= $i ?>">
                <div class="nb-frame"><div class="nb-bar"><i></i><i></i><i></i><span>app.nabbu.es</span></div>
                    <div class="nb-mock" aria-hidden="true"><aside><b></b><i></i><i></i><i></i><i></i><i></i></aside><div><p><i></i><i></i><i></i><i></i></p><section class="m<?= $i ?>"><em></em><em></em><em></em><em></em><em></em><em></em></section></div></div>
                </div>
                <figcaption><?= $e($NB['cap'][$k]) ?> · <?= $e($NB['schema']) ?></figcaption>
            </figure>
            <?php endforeach; endif; ?>
        </div>
        <div class="ring-ctrl">
            <button type="button" class="round-btn" data-nb-prev aria-label="<?= $e($NB['prev']) ?>">←</button>
            <div class="ring-dots" data-nb-dots><?php foreach (($nbShots ?: [1, 2, 3]) as $i => $x): ?><button type="button" aria-label="<?= $i + 1 ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button><?php endforeach; ?></div>
            <button type="button" class="round-btn" data-nb-next aria-label="<?= $e($NB['next']) ?>">→</button>
        </div>
        <p class="hint center"><?= $e($NB['hint']) ?></p>
        </div>
    </div>
    <dialog class="nb-lightbox" data-nb-lightbox><img alt=""><p></p><form method="dialog"><button class="round-btn" aria-label="<?= $e($X['close']) ?>">✕</button></form></dialog>
</section>

<!-- ============ 04 EDUVERSE ============ -->
<section class="eduverse" id="eduverse" data-label="section#eduverse">
    <div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="edu-inner">
        <div class="edu-copy">
            <p class="eyebrow light"><span>04</span> <?= $s('edu.eyebrow') ?></p>
            <h2 class="edu-title" aria-label="EduVerse AI"><span class="ex3d" data-ex3d aria-hidden="true"><?php for ($i = 14; $i >= 1; $i--): ?><span class="ex-l" style="--i:<?= $i ?>">EduVerse</span><?php endfor; ?><span class="ex-face">EduVerse</span></span> <em class="serif" aria-hidden="true">AI</em></h2>
            <p class="edu-tag"><?= $e($W['eduverse']['tagline']) ?></p>
            <p class="edu-lead"><?= $e($W['eduverse']['text']) ?></p>
            <ul class="edu-points"><?php foreach ($W['eduverse']['points'] as $pt): ?><li><?= $e($pt) ?></li><?php endforeach; ?></ul>
            <div class="tabs" role="tablist">
                <?php foreach ([1, 2, 3] as $i): ?>
                <button role="tab" type="button" class="tab<?= $i === 1 ? ' is-active' : '' ?>" data-tab="<?= $i - 1 ?>" aria-selected="<?= $i === 1 ? 'true' : 'false' ?>" style="--c:var(--s<?= [3, 5, 7][$i - 1] ?>)"><span>0<?= $i ?></span><b><?= $s('edu.t' . $i) ?></b><small><?= $s('edu.t' . $i . 'd') ?></small></button>
                <?php endforeach; ?>
            </div>
            <div class="chips"><?php foreach ($W['eduverse']['stack'] as $st): ?><span><?= $e($st) ?></span><?php endforeach; ?><span>Three.js</span><span>Web Speech API</span></div>
            <a href="https://github.com/MathieuMoe/eduverse-ia" target="_blank" rel="noopener" class="btn btn-light magnetic" data-track="github_eduverse" data-cursor="Code"><span class="btn-txt" data-text="<?= $e(strip_tags($s('edu.cta'))) ?>"><?= $s('edu.cta') ?></span><span class="btn-ico" aria-hidden="true">↗</span></a>
        </div>

        <div class="app-scene" data-app-scene>
          <div class="app-rig" data-app-rig>
            <div class="app-ghost g2" aria-hidden="true"><div class="ghost-mm"><i></i><i></i><i></i><i></i><b></b></div></div>
            <div class="app-ghost g1" aria-hidden="true"><div class="ghost-q"><small>🧪 Lab · Physics</small><b>T = 2π√(L/g)</b><i></i><i></i><i></i></div></div>
            <div class="app" data-label="div.app-mockup">
                <div class="app-bar"><i></i><i></i><i></i><span>eduverse.ai/student</span></div>
                <div class="app-body">
                    <aside class="app-side" aria-hidden="true"><span class="is-on">◆</span><span>▲</span><span>●</span><span>■</span></aside>
                    <div class="app-main">
                        <div class="app-head">
                            <div><small><?= $s('edu.hello') ?></small><b><?= $s('edu.level') ?></b></div>
                            <div class="streak">🔥 <b>12</b></div>
                        </div>
                        <div class="xp"><div class="xp-fill" data-xp-fill style="--xp:62%"></div><span data-xp-text>620 / 1000 XP</span></div>

                        <div class="panel is-active" data-panel="0">
                            <p class="subject"><?= $s('edu.qsubj') ?></p>
                            <p class="quiz-q"><?= $s('edu.q') ?></p>
                            <div class="quiz-opts">
                                <button type="button" data-ok="0"><?= $s('edu.o1') ?></button>
                                <button type="button" data-ok="1"><?= $s('edu.o2') ?></button>
                                <button type="button" data-ok="0"><?= $s('edu.o3') ?></button>
                                <button type="button" data-ok="0"><?= $s('edu.o4') ?></button>
                            </div>
                            <p class="quiz-fb" data-quiz-fb aria-live="polite"></p>
                        </div>

                        <div class="panel" data-panel="1">
                            <p class="subject"><?= $s('edu.labsubj') ?> · <b class="lab-badge"><?= $s('edu.lab3d') ?></b></p>
                            <div class="lab3d" data-lab3d>
                                <canvas aria-label="3D pendulum"></canvas>
                                <p class="lab-hint"><?= $s('edu.drag3d') ?></p>
                                <div class="lab-fallback" aria-hidden="true"><i></i></div>
                            </div>
                            <div class="lab-ctrl">
                                <label><span><?= $s('edu.length') ?> · <b data-len-out>1.20</b> m</span><input type="range" min="0.3" max="2.5" step="0.05" value="1.2" data-len></label>
                                <div class="gravity" role="group" aria-label="<?= $e(strip_tags($s('edu.gravity'))) ?>">
                                    <span><?= $s('edu.gravity') ?></span>
                                    <button type="button" class="is-on" data-g="9.81">🌍 <?= $s('edu.earth') ?></button>
                                    <button type="button" data-g="1.62">🌙 <?= $s('edu.moon') ?></button>
                                    <button type="button" data-g="3.71">🔴 <?= $s('edu.mars') ?></button>
                                    <button type="button" data-g="24.79">🪐 <?= $s('edu.jupiter') ?></button>
                                </div>
                                <p class="lab-out"><span><?= $s('edu.theory') ?> T = 2π√(L/g) ≈ <b data-t-theory>2.20</b> s</span><span><?= $s('edu.measured') ?> ≈ <b data-t-measured>—</b> s</span></p>
                            </div>
                        </div>

                        <div class="panel" data-panel="2">
                            <p class="subject"><?= $s('edu.gensubj') ?></p>
                            <svg class="mindmap" viewBox="0 0 300 150" aria-hidden="true">
                                <g class="mm-lines"><path d="M150 75 L60 30"/><path d="M150 75 L60 120"/><path d="M150 75 L245 30"/><path d="M150 75 L245 120"/></g>
                                <g class="mm-nodes">
                                    <rect x="105" y="58" width="90" height="34" rx="17" class="mm-root"/><text x="150" y="80"><?= $s('edu.mm0') ?></text>
                                    <rect x="20" y="16" width="80" height="28" rx="14"/><text x="60" y="34"><?= $s('edu.mm1') ?></text>
                                    <rect x="20" y="106" width="80" height="28" rx="14"/><text x="60" y="124">CO₂ + H₂O</text>
                                    <rect x="205" y="16" width="80" height="28" rx="14"/><text x="245" y="34"><?= $s('edu.mm2') ?></text>
                                    <rect x="205" y="106" width="80" height="28" rx="14"/><text x="245" y="124">O₂</text>
                                </g>
                            </svg>
                            <button type="button" class="flash" data-flash><span class="flash-in"><span class="flash-f"><?= $s('edu.ff') ?></span><span class="flash-b"><?= $s('edu.fb') ?></span></span></button>
                            <button type="button" class="podcast" data-podcast>▶ <span><?= $s('edu.podcast') ?></span></button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="float-chip fc1" aria-hidden="true">+50 XP ✦</div>
            <div class="float-chip fc2" aria-hidden="true">🧠 BKT</div>
            <div class="float-chip fc3" aria-hidden="true">Claude · GPT · Gemini</div>
          </div>
        </div>
    </div>
</section>

<!-- ============ 03b MATGPT SUITE (holographic 3D cards) ============ -->
<section class="section suite" id="suite" data-label="section#suite">
    <div class="wrap">
        <div class="section-head">
            <p class="eyebrow"><span>04b</span> <?= $e($X['sec']['suite']) ?></p>
            <h2 class="title" data-split><?= $e($W['suite']['name']) ?> <em class="serif">· open source</em></h2>
            <p class="sub"><?= $e($W['suite']['text']) ?></p>
        </div>
        <?php
        // EduVerse AI (the platform that brings everything together) first and highlighted, then the 7 apps
        $apps = $W['suite']['apps'];
        $flagIdx = array_search('eduverse-ia', array_column($apps, 2), true);
        $flag = $flagIdx !== false ? array_splice($apps, (int)$flagIdx, 1)[0] : null;
        ?>
        <div class="holo-grid">
            <?php if ($flag): [$fname, $fdesc, $frepo] = $flag; ?>
            <a class="holo holo-flag" href="<?= $e($p['github'] . '/' . $frepo) ?>" target="_blank" rel="noopener" data-holo data-track="github_repo" data-value="<?= $e($frepo) ?>" data-cursor="Code">
                <span class="holo-shine" aria-hidden="true"></span>
                <span class="holo-glare" aria-hidden="true"></span>
                <span class="holo-n">01 · <?= $e($X['featured']) ?></span>
                <span class="holo-icon" aria-hidden="true">🌌</span>
                <b><span class="rainbow-text"><?= $e($fname) ?></span></b>
                <span class="holo-d"><?= $e($W['eduverse']['tagline']) ?>. <?= $e($fdesc) ?> · <?= count($apps) ?> <?= $e($X['apps']) ?>.</span>
                <span class="flag-stack"><?php foreach ($W['eduverse']['stack'] as $st): ?><i><?= $e($st) ?></i><?php endforeach; ?></span>
                <span class="holo-repo">github/<?= $e($frepo) ?> ↗</span>
            </a>
            <?php endif; ?>
            <?php foreach ($apps as $i => [$name, $desc, $repo]): ?>
            <a class="holo" href="<?= $e($p['github'] . '/' . $repo) ?>" target="_blank" rel="noopener" data-holo data-track="github_repo" data-value="<?= $e($repo) ?>" data-cursor="Code" style="--c:var(--s<?= ($i % 7) + 1 ?>);--k:<?= $i ?>">
                <span class="holo-shine" aria-hidden="true"></span>
                <span class="holo-glare" aria-hidden="true"></span>
                <span class="holo-n"><?= sprintf('%02d', $i + 2) ?></span>
                <span class="holo-icon" aria-hidden="true"><?= ['🎓', '❓', '🎤', '🗓', '📚', '🧑‍🏫', '📊'][$i] ?? '✦' ?></span>
                <b><?= $e($name) ?></b>
                <span class="holo-d"><?= $e($desc) ?></span>
                <span class="holo-repo">github/<?= $e($repo) ?> ↗</span>
            </a>
            <?php endforeach; ?>
            <a class="holo holo-all" href="<?= $e($p['github']) ?>" target="_blank" rel="noopener" data-holo data-track="github_click" data-cursor="GitHub">
                <span class="holo-shine" aria-hidden="true"></span>
                <span class="holo-icon" aria-hidden="true">⌥</span>
                <b><?= $e($W['all_github']) ?></b>
                <span class="holo-repo">github.com/MathieuMoe ↗</span>
            </a>
        </div>
        <p class="hint center"><?= $e($X['suite_hint']) ?></p>
    </div>
</section>

<!-- ============ 04 WORK: V1 stacked cards, now in 3D ============ -->
<section class="section work" id="work" data-label="section#work">
    <div class="wrap">
        <div class="section-head">
            <p class="eyebrow"><span>05</span> <?= $s('work.eyebrow') ?></p>
            <h2 class="title" data-split><?= $s('work.title') ?></h2>
            <p class="sub"><?= $s('work.sub') ?></p>
        </div>
        <div class="stack" data-stack>
            <?php foreach ($works as $i => [$w, $color, $tags, $visual]): ?>
            <article class="stack-card" style="--i:<?= $i ?>;--c:var(--s<?= $color ?>)" data-stack-card>
                <div class="sc-info">
                    <span class="sc-num"><?= sprintf('%02d', $i + 1) ?></span>
                    <p class="sc-cat"><?= $s($w . '.cat') ?></p>
                    <h3><?= $s($w . '.t') ?></h3>
                    <p class="sc-desc"><?= $s($w . '.d') ?></p>
                    <ul class="sc-tags"><?php foreach ($tags as $tag): ?><li><?= $e($tag) ?></li><?php endforeach; ?></ul>
                </div>
                <div class="sc-visual v-<?= $visual ?>" aria-hidden="true">
                    <?php if ($visual === 'dash'): ?><div class="vd-row"><i style="--w:70%"></i><i style="--w:40%"></i></div><div class="vd-grid"><span></span><span></span><span></span></div><div class="vd-chart"><i style="--h:40%"></i><i style="--h:65%"></i><i style="--h:50%"></i><i style="--h:85%"></i><i style="--h:70%"></i><i style="--h:95%"></i></div>
                    <?php elseif ($visual === 'config'): ?><div class="vc-steps"><b class="done">1</b><i></i><b class="done">2</b><i></i><b>3</b></div><div class="vc-form"><i></i><i></i><i class="short"></i><em></em></div>
                    <?php elseif ($visual === 'casino'): ?><div class="slot"><span>7</span><span>★</span><span>7</span></div>
                    <?php elseif ($visual === 'sites'): ?><div class="vs-win"></div><div class="vs-win"></div><div class="vs-win"></div>
                    <?php else: ?><div class="vb-logo"></div><div class="vb-type">Aa</div><?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <div class="concepts">
            <div class="concepts-head">
                <h3><?= $e($W['concepts_title']) ?></h3>
                <p><?= $e($W['concepts_note']) ?></p>
            </div>
            <div class="concept-row">
                <?php foreach ($W['concepts'] as $i => [$key, $name, $type, $desc]): ?>
                <article class="concept card c-<?= $e($key) ?>" data-tilt data-card>
                    <div class="cp-visual" aria-hidden="true">
                        <?php if ($key === 'lumen'): ?><div class="phone"><div class="ph-top"><small>Lumen</small><b>2 480,50 €</b></div><div class="ph-ring"></div><div class="ph-rows"><i></i><i></i><i></i></div></div>
                        <?php elseif ($key === 'nomade'): ?><div class="cup"><i></i></div><div class="beans"><i></i><i></i><i></i></div><span class="price">14 €</span>
                        <?php elseif ($key === 'atlas'): ?><div class="atlas-type">ATLAS</div><div class="atlas-img"></div><div class="atlas-lines"><i></i><i></i></div>
                        <?php else: ?><div class="pulse-kpi"><b>+38%</b><small>MRR</small></div><svg viewBox="0 0 120 50" class="pulse-line"><path d="M0 40 L15 35 L30 38 L45 22 L60 26 L75 12 L90 18 L105 6 L120 10"/></svg><div class="pulse-bars"><i></i><i></i><i></i><i></i></div><?php endif; ?>
                    </div>
                    <span class="cp-badge"><?= $e($W['concept']) ?></span>
                    <b><?= $e($name) ?></b>
                    <small><?= $e($type) ?></small>
                    <p><?= $e($desc) ?></p>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- ============ 05 JOURNEY: 3D camera path (kept) ============ -->
<section class="journey" id="journey" data-label="section#journey">
    <div class="journey-pin" data-journey>
        <div class="journey-head">
            <p class="eyebrow"><span>06</span> <?= $s('journey.eyebrow') ?></p>
            <h2 class="title"><?= $s('journey.title') ?></h2>
            <p class="hint"><?= $s('journey.hint') ?></p>
        </div>
        <div class="tunnel">
            <div class="floor" aria-hidden="true"></div>
            <ol class="world" data-world>
                <?php foreach ($jobs as $i => [$year, $title, $desc, $kind]): ?>
                <li class="jcard<?= $kind === 'now' ? ' now' : '' ?>" style="--i:<?= $i ?>;--c:var(--s<?= ($i % 7) + 1 ?>)" data-jcard>
                    <span class="j-year"><?= $e($year) ?></span>
                    <h3><?= isset($S[$title]) ? $s($title) : $e($title) ?></h3>
                    <p><?= $s($desc) ?></p>
                </li>
                <?php endforeach; ?>
                <li class="jcard next" style="--i:<?= count($jobs) ?>" data-jcard>
                    <span class="j-year"><?= $s('j8y') ?></span>
                    <h3><?= $s('j8t') ?></h3>
                    <p><?= $s('j8') ?></p>
                    <a href="#contact" class="link-arrow"><?= $s('j8c') ?></a>
                </li>
            </ol>
        </div>
        <div class="years" aria-hidden="true" data-years>
            <?php foreach ($jobs as $i => [$year]): ?><span style="--i:<?= $i ?>"><?= $e($year) ?></span><?php endforeach; ?><span style="--i:<?= count($jobs) ?>">→</span>
        </div>
    </div>
    <div class="edu-strip wrap">
        <h3><?= $e($X['education']) ?></h3>
        <ul class="diplomas"><?php foreach ($EX['diplomas'] as $i => $d): ?><li style="--c:var(--s<?= ($i % 7) + 1 ?>)"><?= $e($d) ?></li><?php endforeach; ?></ul>
        <ul class="certs" data-stagger><?php foreach ($EX['certs'] as $c): ?><li><?= $e($c) ?></li><?php endforeach; ?></ul>
    </div>
</section>

<!-- ============ 06 METHOD: 3D cube ============ -->
<section class="method" id="method" data-label="section#method">
    <div class="method-pin" data-method>
        <div class="method-copy">
            <p class="eyebrow"><span>07</span> <?= $s('process.eyebrow') ?></p>
            <h2 class="title"><?= $s('process.title') ?></h2>
            <ol class="method-steps">
                <?php foreach ([1, 2, 3, 4] as $i): ?>
                <li data-mstep="<?= $i - 1 ?>" style="--c:var(--s<?= [1, 3, 5, 7][$i - 1] ?>)"><span>0<?= $i ?></span><?= $s('p' . $i . 't') ?></li>
                <?php endforeach; ?>
            </ol>
            <p class="hint"><?= $e($X['method_hint']) ?></p>
        </div>
        <div class="cube-scene" aria-hidden="false">
            <div class="cube" data-cube>
                <?php foreach ([1, 2, 3, 4] as $i): ?>
                <div class="face f<?= $i ?>" style="--c:var(--s<?= [1, 3, 5, 7][$i - 1] ?>)">
                    <span class="face-n">0<?= $i ?></span>
                    <h3><?= $s('p' . $i . 't') ?></h3>
                    <p><?= $s('p' . $i) ?></p>
                </div>
                <?php endforeach; ?>
                <div class="face side l"></div><div class="face side r"></div>
            </div>
            <div class="cube-shadow"></div>
        </div>
    </div>
</section>

<!-- ============ 07 TOOLBOX: constellation, gauges, equalizer ============ -->
<section class="section skills" id="skills" data-label="section#skills">
    <div class="wrap">
        <div class="skills-grid">
            <div class="skills-copy">
                <p class="eyebrow"><span>08</span> <?= $s('skills.eyebrow') ?></p>
                <h2 class="title" data-split><?= $s('skills.title') ?></h2>
                <p class="sub"><?= $s('skills.sub') ?></p>
                <div class="sk-filters" role="group" aria-label="Filter">
                    <button type="button" class="is-on" data-filter="all"><?= $s('skills.all') ?> <small><?= count($skills) ?></small></button>
                    <?php foreach (['dev', 'design', 'ai'] as $g): ?>
                    <button type="button" data-filter="<?= $g ?>"><i class="dot <?= $g ?>"></i><?= $s('skills.' . $g) ?> <small><?= count($p['skills'][$g]) ?></small></button>
                    <?php endforeach; ?>
                </div>
                <div class="sk-detail" data-sk-detail aria-live="polite">
                    <div class="gauge" data-gauge style="--v:0"><svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52" class="g-track"/><circle cx="60" cy="60" r="52" class="g-val" pathLength="100"/></svg><b data-gauge-n>0</b><small>/10</small></div>
                    <div class="sk-text">
                        <small><?= $e($X['skills_sel']) ?></small>
                        <b data-sk-name>—</b>
                        <span class="sk-group" data-sk-group></span>
                    </div>
                </div>
                <p class="hint"><?= $e($X['skills_click']) ?></p>
            </div>
            <div class="sphere-wrap" data-label="div.constellation-3d">
                <div class="orbits" aria-hidden="true"><i></i><i></i><i></i></div>
                <div class="core" aria-hidden="true"></div>
                <div class="sphere" data-sphere>
                    <?php foreach ($skills as $sk): ?>
                    <button type="button" class="star g-<?= $sk['g'] ?><?= $sk['h'] ? ' is-highlight' : '' ?>" style="--lv:<?= $sk['v'] ?>" data-star data-k="<?= $e($sk['k']) ?>" data-g="<?= $sk['g'] ?>" data-lv="<?= $sk['v'] ?>"><?= $e($sk['l']) ?></button>
                    <?php endforeach; ?>
                </div>
                <p class="hint center"><?= $s('skills.drag') ?></p>
            </div>
        </div>

        <div class="eq-head"><h3><?= $e($X['skills_eq']) ?></h3></div>
        <div class="eq-groups">
            <?php foreach ($p['skills'] as $g => $list): ?>
            <div class="eq g-<?= $g ?>" data-eq="<?= $g ?>">
                <div class="eq-top"><b><?= $s('skills.' . $g) ?></b><span><?= $e($X['skills_avg']) ?> <strong><?= $groupAvg[$g] ?></strong>/10 · <?= count($list) ?> <?= $e($X['skills_count']) ?></span></div>
                <ul>
                    <?php foreach ($list as $k => [$label, $lv]): ?>
                    <li data-eq-k="<?= $e($k) ?>"<?= in_array($k, $highlight, true) ? ' class="is-highlight"' : '' ?>><span><?= $e($label) ?></span><i style="--lv:<?= $lv ?>"><em></em></i><b><?= $lv ?></b></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="skills-bottom">
            <div class="langs-cards">
                <h3><?= $s('tool.langs') ?></h3>
                <?php foreach ([['Français', 100, 'FR'], ['Español', 92, 'ES'], ['English', 85, 'EN']] as $i => [$ln, $pct, $code]): ?>
                <div class="lang-card" style="--p:<?= $pct ?>;--c:var(--s<?= [1, 3, 5][$i] ?>)"><span class="lc-ring"><b><?= $code ?></b></span><div><b><?= $ln ?></b><small><?= $e($X['langs_lv'][$i]) ?></small></div></div>
                <?php endforeach; ?>
            </div>
            <div class="soft-row">
                <h3><?= $s('skills.soft') ?></h3>
                <ul class="soft" data-stagger><?php foreach ($C['skills']['soft'] as $i => $soft): ?><li style="--c:var(--s<?= ($i % 7) + 1 ?>)"><?= $e($soft) ?></li><?php endforeach; ?></ul>
            </div>
        </div>
    </div>
</section>

<!-- ============ 08 ASK ============ -->
<section class="section ask" id="ask" data-label="section#ask">
    <div class="wrap ask-grid">
        <div>
            <p class="eyebrow"><span>09</span> <?= $e($C['ask']['kicker']) ?></p>
            <h2 class="title" data-split><?= $em($C['ask']['title']) ?></h2>
            <p class="sub"><?= $e($C['ask']['text']) ?></p>
            <div class="suggestions"><?php foreach ($C['ask']['suggestions'] as $q): ?><button type="button" data-suggest><?= $e($q) ?></button><?php endforeach; ?></div>
        </div>
        <div class="chat" data-card>
            <div class="chat-head"><span class="chat-avatar"><img src="<?= $A ?>/img/avatar.jpg" alt="" width="40" height="40" loading="lazy"></span><div><b>Mathieu · AI</b><small><i class="pulse"></i> online</small></div></div>
            <div class="chat-log" data-chat-log aria-live="polite"><div class="msg ai"><?= $e($C['ask']['hello']) ?></div></div>
            <form class="chat-form" data-ask>
                <label class="sr" for="ask-input"><?= $e($C['ask']['placeholder']) ?></label>
                <input id="ask-input" name="q" maxlength="400" autocomplete="off" placeholder="<?= $e($C['ask']['placeholder']) ?>" required>
                <button type="submit" aria-label="<?= $e($C['ask']['send']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15M13 6l6 6-6 6"/></svg></button>
            </form>
            <p class="chat-note"><?= $e($C['ask']['disclaimer']) ?></p>
        </div>
    </div>
</section>

<!-- ============ 09 CONTACT ============ -->
<section class="contact" id="contact" data-label="section#contact">
    <div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="contact-inner">
        <div class="contact-head">
            <p class="eyebrow light"><span>10</span> <?= $s('contact.eyebrow') ?></p>
            <h2 class="contact-big" data-split><?= $s('contact.big1') ?><br><em class="serif rainbow-text"><?= $s('contact.big2') ?></em></h2>
            <p class="contact-sub"><?= $s('contact.sub') ?></p>
            <div class="contact-links">
                <a href="#" class="magnetic" data-mail data-u="mathieu.moreau80" data-d="gmail.com" data-track="email_click">✉ <span>email</span></a>
                <a href="<?= $e($p['linkedin']) ?>" target="_blank" rel="noopener" class="magnetic" data-track="linkedin_click">in LinkedIn</a>
                <a href="<?= $e($p['github']) ?>" target="_blank" rel="noopener" class="magnetic" data-track="github_click">⌥ GitHub</a>
                <a <?= $cvAttr ?> class="magnetic">↗ CV (PDF)</a>
            </div>
        </div>
        <form class="form" data-contact novalidate>
            <fieldset class="intent">
                <legend><?= $s('form.intent') ?></legend>
                <?php foreach (['job' => 'form.i1', 'freelance' => 'form.i2', 'private' => 'form.i3', 'other' => 'form.i4'] as $val => $key): ?>
                <label><input type="radio" name="intent" value="<?= $val ?>"<?= $val === 'job' ? ' checked' : '' ?>><span><?= $s($key) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <div class="row2">
                <label class="field"><input name="name" required maxlength="120" placeholder=" " autocomplete="name"><span><?= $s('form.name') ?></span></label>
                <label class="field"><input name="company" maxlength="120" placeholder=" " autocomplete="organization" value="<?= $e($rec['company'] ?? '') ?>"><span><?= $s('form.company') ?></span></label>
            </div>
            <label class="field"><input name="email" type="email" required maxlength="190" placeholder=" " autocomplete="email"><span><?= $s('form.email') ?></span></label>
            <label class="field"><textarea name="message" required rows="4" maxlength="5000" placeholder=" "></textarea><span><?= $s('form.message') ?></span></label>
            <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <input type="hidden" name="ts" value="<?= time() ?>">
            <button class="btn btn-rainbow btn-full magnetic" type="submit"><span class="btn-txt" data-text="<?= $e(strip_tags($s('form.send'))) ?>"><?= $s('form.send') ?></span><span class="btn-ico" aria-hidden="true">→</span></button>
            <p class="form-status" data-form-status aria-live="polite"></p>
            <p class="form-legal"><?= $s('form.legal') ?></p>
        </form>
    </div>
</section>
</main>

<footer class="ft" data-ft data-label="footer">
    <div class="ft-line" aria-hidden="true"></div>
    <div class="ft-grid">
        <div class="ft-id">
            <a href="#top" class="ft-logo" aria-label="Mathieu Moreau">
                <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="2" y="2" width="44" height="44" rx="14" fill="#0E0E12"/><rect x="2" y="2" width="44" height="44" rx="14" fill="none" stroke="url(#lgSpec)" stroke-width="2.5"/><path d="M13 34V15l11 12 11-12v19" fill="none" stroke="url(#lgSpec)" stroke-width="4.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="38.5" cy="34" r="2.6" fill="#FFD21F"/></svg>
                <span><b>Mathieu Moreau</b><small><?= $e($X['explore']) ?></small></span>
            </a>
            <p class="ft-tag"><?= $s('hero.intro') ?></p>
            <span class="status-pill ft-status"><i></i><span><?= $s('nav.status') ?></span></span>
        </div>
        <nav class="ft-col" aria-label="Sections">
            <h3><?= $e($X['menu']) ?></h3>
            <ul><?php foreach (['nabbu', 'eduverse', 'suite', 'work', 'journey', 'skills', 'ask'] as $id): ?><li><a href="#<?= $id ?>"><?= $e($X['sec'][$id]) ?></a></li><?php endforeach; ?></ul>
        </nav>
        <div class="ft-col">
            <h3><?= $s('contact.eyebrow') ?></h3>
            <ul>
                <li><a href="#" class="ft-mail" data-mail data-u="mathieu.moreau80" data-d="gmail.com" data-track="email_click"><span>email</span></a></li>
                <li><a href="<?= $e($p['linkedin']) ?>" target="_blank" rel="noopener" data-track="linkedin_click">LinkedIn ↗</a></li>
                <li><a href="<?= $e($p['github']) ?>" target="_blank" rel="noopener" data-track="github_click">GitHub ↗</a></li>
                <li><a <?= $cvAttr ?>><?= $e($X['open_cv']) ?> ↗</a></li>
            </ul>
        </div>
        <div class="ft-col ft-time">
            <h3><?= $s('hero.localtime') ?></h3>
            <p class="ft-clock"><b data-clock>--:--</b> <small>Europe/Paris</small></p>
            <a href="#top" class="ft-top magnetic" aria-label="<?= $e(strip_tags($s('footer.top'))) ?>">↑</a>
        </div>
    </div>
    <div class="ft-row">
        <span>© <?= date('Y') ?> Mathieu Moreau</span>
        <span><?= $e($X['built']) ?></span>
        <span class="ft-hint"><?= $s('footer.hint') ?></span>
        <a href="/old_v1-02/<?= $e($recQs) ?>" data-track="v1_link">← <?= $s('footer.classic') ?></a>
        <button type="button" class="link" data-privacy><?= $e($C['footer']['privacy']) ?></button>
    </div>
</footer>

<div class="palette" data-palette hidden>
    <div class="palette-box" role="dialog" aria-modal="true" aria-label="Command palette">
        <input type="text" data-palette-input autocomplete="off" placeholder="<?= $e($S['palette.ph'] ?? '') ?>">
        <ul data-palette-list data-lenis-prevent></ul>
        <p class="palette-foot"><kbd>↑</kbd><kbd>↓</kbd> <?= $s('palette.nav') ?> · <kbd>↵</kbd> <?= $s('palette.go') ?> · <kbd>Esc</kbd></p>
    </div>
</div>
<div class="toast" data-toast role="status"></div>
<dialog class="privacy" data-privacy-dialog>
    <h2><?= $e($C['privacy']['title']) ?></h2>
    <p><?= $e($C['privacy']['text']) ?></p>
    <form method="dialog"><button class="btn btn-rainbow"><span class="btn-txt" data-text="OK">OK</span></button></form>
</dialog>

<script type="application/json" id="i18n"><?= json_encode($js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="application/json" id="links"><?= json_encode(['github' => $p['github'], 'linkedin' => $p['linkedin'], 'cv' => $cvUrl, 'classic' => '/old_v1-02/' . $recQs], JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= $A ?>/vendor/gsap.min.js?v=3.13.0" defer></script>
<script src="<?= $A ?>/vendor/ScrollTrigger.min.js?v=3.13.0" defer></script>
<script src="<?= $A ?>/vendor/SplitText.min.js?v=3.13.0" defer></script>
<script src="<?= $A ?>/vendor/lenis.min.js?v=1.3.4" defer></script>
<script src="<?= $A ?>/js/main.js?v=<?= $v('/assets/js/main.js') ?>" type="module"></script>
</body>
</html>
