<?php

// Site settings. No secrets here: those go in ../.env.php (never committed).
return [
    'name'             => 'Példa Kft.',
    'base_url'         => 'https://example.com',
    'languages'        => ['hu', 'en'],
    'default_language' => 'hu',
    'prefix_default'   => false,   // hu at /, en at /en/

    'mail' => [
        'transport' => 'log',      // 'smtp' in production (credentials in .env.php), 'log' writes to var/mail.log
        'from'      => 'web@example.com',
        'from_name' => 'Példa Kft. weboldal',
    ],

    // Public captcha keys (secrets go in .env.php). Enable per form with 'captcha' => 'turnstile' | 'recaptcha'.
    'captcha' => [
        'turnstile' => ['site_key' => ''],
        'recaptcha' => ['site_key' => '', 'min_score' => 0.5],
    ],

    'rate_limit' => ['max' => 5, 'window' => 600],   // per visitor and form

    'forms' => [
        'contact' => [
            'fields'  => [
                'name'    => 'required|max:100',
                'email'   => 'required|email|max:200',
                'phone'   => 'phone',
                'message' => 'required|max:5000',
                'consent' => 'accepted',
            ],
            'captcha' => null,
            'actions' => [
                ['store'],
                ['email', 'to' => ['info@example.com'], 'subject' => '[{site}] Új üzenet: {name}'],
                // ['webhook', 'webhook' => 'zapier_contact'],   // URL in .env.php webhooks.zapier_contact
            ],
            'success' => ['page' => 'thank-you'],
        ],
        'newsletter' => [
            'fields'  => ['email' => 'required|email|max:200', 'consent' => 'accepted'],
            'captcha' => null,
            'actions' => [
                ['store'],
                // ['subscribe', 'provider' => 'mailerlite', 'group' => '123456'],
                // ['subscribe', 'provider' => 'mailchimp', 'list' => 'abc123', 'double_opt_in' => true],
                // ['subscribe', 'provider' => 'webhook', 'webhook' => 'integrately_newsletter'],
            ],
            'success_message' => 'newsletter.success',
        ],
    ],
];
