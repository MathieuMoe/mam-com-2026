<?php
/** POST contact form → stored in DB + e-mail notification. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);
same_origin_only();

$in = json_in();

// Anti-spam: honeypot field + humans need more than 3 s to fill the form
if (!empty($in['website']) || (int)($in['elapsed'] ?? 0) < 3000) json_out(['ok' => true]);

$name    = clean($in['name'] ?? '', 120);
$email   = clean($in['email'] ?? '', 190);
$company = clean($in['company'] ?? '', 120);
$message = clean($in['message'] ?? '', 5000);
$intent  = in_array($in['intent'] ?? '', ['job', 'freelance', 'private', 'other'], true) ? $in['intent'] : 'other';
$lang    = lang_code($in['lang'] ?? null);
$camp    = slug($in['campaign'] ?? '') ?: null;

if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'invalid'], 422);
}

try {
    rate_limit('contact', 5, 3600);
    db()->prepare('INSERT INTO messages (name, email, company, intent, message, lang, campaign, visitor_hash) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$name, $email, $company ?: null, $intent, $message, $lang, $camp, visitor_hash()]);

    db()->prepare('INSERT INTO events (visitor_hash, session_id, name, value, campaign, lang) VALUES (?,?,?,?,?,?)')
        ->execute([visitor_hash(), clean($in['session'] ?? '', 64) ?: 'none', 'contact_sent', $intent, $camp, $lang]);

    // E-mail notification (OVH: the From address must belong to your domain)
    global $CONFIG;
    $labels  = ['job' => '💼 Poste', 'freelance' => '🧩 Freelance', 'private' => '🏡 Particulier', 'other' => '✉️ Autre'];
    $subject = '=?UTF-8?B?' . base64_encode("[Portfolio] {$labels[$intent]} — {$name}" . ($company ? " ({$company})" : '')) . '?=';
    $body    = "Nom : {$name}\nEmail : {$email}\nEntreprise : {$company}\nType : {$labels[$intent]}\nLangue : {$lang}\n"
             . ($camp ? "Lien personnalisé : {$camp}\n" : '') . "\n---\n{$message}\n";
    $headers = implode("\r\n", [
        "From: Portfolio <{$CONFIG['mail_from']}>",
        'Reply-To: ' . str_replace(["\r", "\n"], '', $email),
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);
    @mail($CONFIG['mail_to'], $subject, $body, $headers);

    json_out(['ok' => true]);
} catch (Throwable $e) {
    error_log('[contact] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'server'], 500);
}
