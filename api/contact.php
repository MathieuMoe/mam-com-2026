<?php
declare(strict_types=1);
// v2 contact form → shared `messages` table (visible in the main admin) + e-mail.
require __DIR__ . '/../app/bootstrap.php';
require_same_origin();
$in = read_json(12000);

// Anti-spam: hidden field filled or form sent in less than 3 seconds → pretend success
if (($in['website'] ?? '') !== '' || time() - (int)($in['ts'] ?? 0) < 3) {
    json_response(['ok' => true]);
}
if (rate_limited('contact', 5, 3600)) {
    json_response(['ok' => false, 'error' => 'rate_limited'], 429);
}
$name = clean($in['name'] ?? '', 120);
$email = clean($in['email'] ?? '', 190);
$company = clean($in['company'] ?? '', 120);
$message = clean($in['message'] ?? '', 5000);
// v2 subjects → intents of the main site
$intent = in_array($in['intent'] ?? '', ['job', 'freelance', 'private', 'other'], true) ? $in['intent'] : (['job' => 'job', 'project' => 'freelance'][$in['kind'] ?? ''] ?? 'other');
$lang = lang_code($in['lang'] ?? null);
$campaign = slug($in['for'] ?? '') ?: null;
$session = clean($in['session'] ?? '', 64) ?: 'none';
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'invalid'], 422);
}
try {
    db()?->prepare('INSERT INTO messages (name, email, company, intent, message, lang, campaign, visitor_hash) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$name, $email, $company ?: null, $intent, "[v2] " . $message, $lang, $campaign, visitor_hash()]);
    log_event('contact_sent', $intent, $session, $campaign, $lang);
    notify(
        "{$intent} — {$name}" . ($company ? " ({$company})" : ''),
        "Name: {$name}\nEmail: {$email}\nCompany: {$company}\nType: {$intent}\nLanguage: {$lang}\n" . ($campaign ? "Recruiter link: {$campaign}\n" : '') . "\n---\n{$message}\n",
        $email
    );
    json_response(['ok' => true]);
} catch (Throwable $e) {
    error_log('[v2 contact] ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'server'], 500);
}
