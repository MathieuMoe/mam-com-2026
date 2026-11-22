<?php
declare(strict_types=1);
// CV download. POST (JSON): event cv_download (same as the main site) + name/company in v2_cv_requests,
// returns the URL of the PDF. GET ?cv=xx: serves the PDF (direct link → logged too).
require __DIR__ . '/../app/bootstrap.php';

$files = ['es' => 'CV-Mathieu-Moreau-ES.pdf', 'en' => 'CV-Mathieu-Moreau-EN.pdf', 'fr' => 'CV-Mathieu-Moreau-FR.pdf'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_same_origin();
    $in = read_json(2000);
    $cv = isset($files[$in['cv'] ?? '']) ? $in['cv'] : 'en';
    $name = clean($in['name'] ?? '', 120);
    $company = clean($in['company'] ?? '', 120);
    $campaign = slug($in['for'] ?? '') ?: null;
    $lang = lang_code($in['lang'] ?? null);
    if (!rate_limited('cv', 10, 3600)) {
        log_event('cv_download', $cv, clean($in['session'] ?? '', 64), $campaign, $lang);
        if (($name !== '' || $company !== '') && ($pdo = db())) {
            try {
                $pdo->prepare('INSERT INTO v2_cv_requests (visitor_hash, cv_lang, name, company, campaign) VALUES (?,?,?,?,?)')
                    ->execute([visitor_hash(), $cv, $name ?: null, $company ?: null, $campaign]);
            } catch (Throwable $e) {
                error_log('[v2 cv] ' . $e->getMessage());
            }
            notify("CV downloaded ({$cv})" . ($company ? " — {$company}" : ''), "Name: {$name}\nCompany: {$company}\nRecruiter link: {$campaign}\nCV: {$cv}");
        }
    }
    json_response(['ok' => true, 'url' => v2_base() . '/api/cv.php?cv=' . $cv . '&logged=1']);
}

$cv = isset($files[$_GET['cv'] ?? '']) ? $_GET['cv'] : 'en';
if (!isset($_GET['logged']) && !rate_limited('cv', 10, 3600)) {
    log_event('cv_download', $cv);
}
$path = APP_DIR . '/cv/' . $files[$cv];
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $files[$cv] . '"');
header('Content-Length: ' . filesize($path));
header('X-Robots-Tag: noindex');
readfile($path);
