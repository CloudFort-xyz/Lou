<?php

declare(strict_types=1);

namespace Lou;

use Lou\Captcha\Captcha;
use Lou\Captcha\RecaptchaCaptcha;
use Lou\Captcha\TurnstileCaptcha;
use Lou\Http\CurlHttpClient;
use Lou\Http\HttpClient;
use Lou\Http\Webhook;
use Lou\Mail\LogMailer;
use Lou\Mail\Mailer;
use Lou\Mail\PhpMailerMailer;
use Lou\Newsletter\MailchimpProvider;
use Lou\Newsletter\MailerLiteProvider;
use Lou\Newsletter\Provider;
use Lou\Newsletter\WebhookProvider;
use Lou\Support\Arr;

/**
 * Builds the configured mailer, newsletter providers, captchas and webhooks.
 * Non-secret settings come from site/config.php, secrets from .env.php.
 */
final class Integrations
{
    private ?Mailer $mailer = null;

    public function __construct(
        public readonly App $app,
        public readonly Env $env,
        public readonly HttpClient $http = new CurlHttpClient(),
    ) {
    }

    private function config(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->app->config, $path, $default);
    }

    public function mailer(): Mailer
    {
        return $this->mailer ??= match ($this->config('mail.transport', 'smtp')) {
            'log'   => new LogMailer($this->app->varDir . '/mail.log'),
            'smtp'  => new PhpMailerMailer(
                (array) $this->env->require('smtp'),
                (string) ($this->config('mail.from') ?? throw new LouException("Missing 'mail.from' in site/config.php")),
                (string) $this->config('mail.from_name', $this->app->config['name']),
            ),
            default => throw new LouException("Unknown mail.transport '{$this->config('mail.transport')}'"),
        };
    }

    /** For tests and future transports (SES, SendGrid…). */
    public function useMailer(Mailer $mailer): void
    {
        $this->mailer = $mailer;
    }

    /** @param array<string, mixed> $opts the action's options, overriding config newsletter.<provider> */
    public function newsletter(string $provider, array $opts = []): Provider
    {
        $o = $opts + (array) $this->config("newsletter.$provider", []);
        return match ($provider) {
            'mailchimp'  => new MailchimpProvider(
                $this->http,
                (string) $this->env->require('mailchimp_key'),
                (string) ($o['list'] ?? throw new LouException('Mailchimp needs a list id')),
                (bool) ($o['double_opt_in'] ?? true),
                (array) ($o['tags'] ?? []),
            ),
            'mailerlite' => new MailerLiteProvider(
                $this->http,
                (string) $this->env->require('mailerlite_key'),
                isset($o['group']) ? (string) $o['group'] : null,
            ),
            'webhook'    => new WebhookProvider($this->webhook((string) ($o['webhook'] ?? throw new LouException("Newsletter webhook needs 'webhook' (a name in .env.php webhooks)")))),
            default      => throw new LouException("Unknown newsletter provider '$provider'"),
        };
    }

    public function captcha(string $name): Captcha
    {
        return match ($name) {
            'turnstile' => new TurnstileCaptcha($this->http, (string) $this->env->require('turnstile_secret')),
            'recaptcha' => new RecaptchaCaptcha($this->http, (string) $this->env->require('recaptcha_secret'),
                (float) $this->config('captcha.recaptcha.min_score', 0.5)),
            default     => throw new LouException("Unknown captcha '$name'"),
        };
    }

    /** A webhook by name: the URL lives in .env.php under webhooks.<name>. */
    public function webhook(string $name): Webhook
    {
        $url = $this->env->get("webhooks.$name") ?? throw new LouException("Missing webhook URL 'webhooks.$name' in .env.php");
        return new Webhook($this->http, (string) $url, $this->env->get('webhook_secret'));
    }
}
