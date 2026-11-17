<?php

declare(strict_types=1);

namespace Orboni\Service;

use Orboni\Exception\SignatureVerificationException;
use Orboni\OrboniObject;
use Orboni\Webhook;

/** `$orboni->webhooks`: the same checks as Orboni\Webhook, from the client. Needs no key. */
class WebhookService
{
    /**
     * @param  string|\Stringable  $payload  the raw body
     * @param  string|list<string>|null  $header
     * @param  string|list<string>  $secret
     *
     * @throws SignatureVerificationException
     *
     * @see Webhook::constructEvent()
     */
    public function constructEvent(
        mixed $payload,
        string|array|null $header,
        #[\SensitiveParameter] string|array $secret,
        int $tolerance = Webhook::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): OrboniObject {
        return Webhook::constructEvent($payload, $header, $secret, $tolerance, $now);
    }

    /**
     * @param  string|\Stringable  $payload  the raw body
     * @param  string|list<string>|null  $header
     * @param  string|list<string>  $secret
     *
     * @throws SignatureVerificationException
     *
     * @see Webhook::verifySignature()
     */
    public function verifySignature(
        mixed $payload,
        string|array|null $header,
        #[\SensitiveParameter] string|array $secret,
        int $tolerance = Webhook::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): void {
        Webhook::verifySignature($payload, $header, $secret, $tolerance, $now);
    }
}
