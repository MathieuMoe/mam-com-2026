<?php
/**
 * Copy this file to config.php and fill in your OVH values.
 * config.php is blocked from the web by .htaccess — never commit it to Git.
 *
 * OVH Manager > Web Cloud > Hébergements > [your hosting] > Bases de données
 *   host  : something like  xxxxxx.mysql.db
 *   name  : the database name
 *   user  : the database user
 */
return [
    'db' => [
        'host' => 'xxxxxx.mysql.db',
        'name' => 'portfolio',
        'user' => 'portfolio',
        'pass' => 'CHANGE_ME',
    ],

    // Where contact form notifications are sent
    'mail_to'   => 'mathieu.moreau80@gmail.com',
    // Sender must be an address on your OVH domain, otherwise mail() is rejected/spam-filtered
    'mail_from' => 'contact@mam-com.com',

    // Long random string. Generate one: php -r "echo bin2hex(random_bytes(32));"
    'salt' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',

    // Admin password hash. Generate: php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
    // Or open /admin/?hash=your-password ONCE locally (only works while this value is empty).
    'admin_password_hash' => '',

    // Public base URL (used to build recruiter links in the admin)
    'site_url' => 'https://mam-com.com',
];
