<?php

declare(strict_types=1);

namespace Lou\Bridge;

/**
 * An OAuth error as RFC 6749 names it (invalid_request, invalid_grant…). $redirect is set when the error may be
 * sent back to the client's redirect_uri; without it (unknown client, unregistered redirect_uri) it is shown to
 * the person instead, because sending it anywhere would make Lou an open redirector.
 */
final class OAuthError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
        public readonly ?string $redirect = null,
        public readonly string $state = '',
    ) {
        parent::__construct($description);
    }
}
