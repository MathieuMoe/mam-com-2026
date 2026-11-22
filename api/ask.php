<?php
declare(strict_types=1);
// "Ask me" assistant: real AI (if a key is set in app/config.php) or the local brain; stored in v2_questions.
// Response: answer (text), actions (buttons: open the CV, copy the address, e-mail…), suggestions (follow-up questions).
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/assistant.php';
require_same_origin();
$in = read_json(12000);
$q = clean($in['q'] ?? '', 400);
$lang = lang_code($in['lang'] ?? null);
if ($q === '') {
    json_response(['ok' => false, 'error' => 'empty'], 422);
}
if (rate_limited('ask', (int)(config()['v2']['ai']['max_per_hour'] ?? 30), 3600)) {
    json_response(['ok' => false, 'error' => 'rate_limited'], 429);
}
$history = [];
foreach (array_slice(is_array($in['history'] ?? null) ? $in['history'] : [], -8) as $h) {
    if (is_array($h)) {
        $history[] = ['role' => ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'text' => clean((string)($h['text'] ?? ''), 800)];
    }
}
$local = assistant_local($q, $lang);
$answer = assistant_ai($q, $lang, $history);
$source = 'ai';
if ($answer === null || trim($answer) === '') {
    $answer = $local['answer'];
    $source = 'offline';
}
$answer = trim(mb_substr(str_replace(['**', '## ', '# '], '', $answer), 0, 2000));
$campaign = slug($in['for'] ?? '') ?: null;
$session = clean($in['session'] ?? '', 64) ?: null;
if (($pdo = db()) && !is_bot()) {
    try {
        $pdo->prepare('INSERT INTO v2_questions (visitor_hash, session_id, lang, question, answer, source, campaign) VALUES (?,?,?,?,?,?,?)')
            ->execute([visitor_hash(), $session, $lang, $q, $answer, $source, $campaign]);
    } catch (Throwable $e) {
        error_log('[v2 ask] ' . $e->getMessage());
    }
    log_event('assistant_question', mb_substr($q, 0, 120), $session, $campaign, $lang);
}
json_response(['ok' => true, 'answer' => $answer, 'source' => $source, 'actions' => $local['actions'], 'suggestions' => $local['suggestions']]);
