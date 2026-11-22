<?php
/** GET ?c=slug → company name for the personalised hero greeting. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$slug = slug($_GET['c'] ?? '');
if ($slug === '') json_out(['ok' => false], 400);

try {
    $st = db()->prepare('SELECT company, lang FROM campaigns WHERE slug = ?');
    $st->execute([$slug]);
    $row = $st->fetch();
    if (!$row) json_out(['ok' => false], 404);
    json_out(['ok' => true, 'company' => $row['company'], 'lang' => $row['lang']]);
} catch (Throwable $e) {
    error_log('[campaign] ' . $e->getMessage());
    json_out(['ok' => false], 500);
}
