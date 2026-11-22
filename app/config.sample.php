<?php
// Optional settings of the v2 only. Copy to config.php (same folder) to change them.
// Database, salt, admin password and e-mails are NOT here: the v2 reads them from the main site's api/config.php.
return [
    // Let search engines index /v2/ (false while it is a preview, to avoid duplicate content with the main site)
    'index' => true,

    // "Ask me" assistant. Provider: 'claude', 'openai', 'gemini' or '' (offline answers built from the site content).
    'ai' => [
        'provider' => '',
        'api_key' => '',          // never commit a real key
        'model' => '',            // empty = default: claude-haiku-4-5 / gpt-5-mini / gemini-flash-lite-latest
        'max_per_hour' => 20,     // questions per visitor per hour
    ],
];
