<?php

// Copy to .env.php (next to site/, outside the web root) and fill in. Never commit .env.php.
return [
    // 'app_key' => '…64 random hex chars…',   // optional: otherwise generated into var/app.key

    'smtp' => [
        'host'   => 'mail.example.com',
        'port'   => 587,
        'user'   => 'web@example.com',
        'pass'   => '',
        'secure' => 'tls',                    // 'tls' (STARTTLS, port 587), 'ssl' (port 465) or ''
    ],

    // 'turnstile_secret' => '',
    // 'recaptcha_secret' => '',
    // 'mailchimp_key'    => '…-us21',
    // 'mailerlite_key'   => '',

    // Named webhook URLs, referenced from site/config.php actions: ['webhook', 'webhook' => 'zapier_contact']
    'webhooks' => [
        // 'zapier_contact'         => 'https://hooks.zapier.com/…',
        // 'integrately_newsletter' => 'https://webhooks.integrately.com/…',
    ],
    // 'webhook_secret' => '',                // optional: adds X-Lou-Signature: sha256=<hmac of body>
];
