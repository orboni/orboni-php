<?php

declare(strict_types=1);

namespace Orboni;

use Orboni\Exception\SignatureVerificationException;

/**
 * Orboni signs every webhook: `Webhook-Signature: t=<unix seconds>,v1=<hex
 * HMAC-SHA256 of "<t>.<raw body>" with your endpoint's signing secret>`, one
 * `v1` per secret while a rolled secret still signs. Needs no API key.
 *
 *     $event = \Orboni\Webhook::constructEvent(
 *         file_get_contents('php://input'),
 *         $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? null,
 *         $signingSecret,
 *     );
 */
final class Webhook
{
    /** The header Orboni signs with. */
    public const HEADER = 'Webhook-Signature';

    /** How far the signature's time may be from now, in seconds, unless told otherwise. */
    public const DEFAULT_TOLERANCE = 300;

    /** What trim() takes off, the same in every PHP version. */
    private const SPACE = " \t\n\r\0\x0B\x0C";

    /**
     * The event, once its signature is checked: throws SignatureVerificationException
     * otherwise. $payload is the request's body exactly as it arrived (a string,
     * or a PSR-7 body stream), never JSON your framework parsed and wrote again:
     * an array or an object is refused.
     *
     * @param  string|\Stringable  $payload  the raw body
     * @param  string|list<string>|null  $header  the Webhook-Signature header; of a list, the first is read
     * @param  string|list<string>  $secret  the endpoint's signing secret (whsec_…), or several while one is rolled
     * @param  int  $tolerance  seconds the signature's time may be from now, above 0
     * @param  int|null  $now  now, in Unix seconds: for tests only
     *
     * @throws SignatureVerificationException
     */
    public static function constructEvent(
        mixed $payload,
        string|array|null $header,
        #[\SensitiveParameter] string|array $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): OrboniObject {
        $body = self::body($payload);
        self::verifySignature($body, $header, $secret, $tolerance, $now);
        try {
            $event = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('The webhook is signed but its body is not JSON.', 'signature_verification_error', previous: $e);
        }
        if (! $event instanceof \stdClass) {
            throw self::fail('The webhook is signed but its body is not a JSON object.');
        }

        return OrboniObject::constructFrom($event);
    }

    /**
     * Checks the signature only; throws SignatureVerificationException when it does not hold.
     *
     * @param  string|\Stringable  $payload  the raw body
     * @param  string|list<string>|null  $header
     * @param  string|list<string>  $secret
     *
     * @throws SignatureVerificationException
     */
    public static function verifySignature(
        mixed $payload,
        string|array|null $header,
        #[\SensitiveParameter] string|array $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): void {
        $body = self::body($payload);
        $value = is_array($header) ? ($header === [] ? null : $header[array_key_first($header)]) : $header;
        if (! is_string($value) || trim($value, self::SPACE) === '') {
            throw self::fail(sprintf('No %s header: this request did not come from Orboni.', self::HEADER));
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $value) as $pair) {
            $at = strpos($pair, '=');
            $key = trim($at === false ? $pair : substr($pair, 0, $at), self::SPACE);
            $part = $at === false ? '' : trim(substr($pair, $at + 1), self::SPACE);
            if ($key === 't') {
                if ($timestamp === null && preg_match('/^\d+$/', $part) === 1) {
                    $timestamp = (int) $part;
                }
            } elseif ($key === 'v1') {
                $signatures[] = $part;
            }
        }
        if ($timestamp === null || $timestamp <= 0 || $signatures === []) {
            throw self::fail(sprintf('The %s header is not t=…,v1=….', self::HEADER));
        }

        if ($tolerance <= 0) {
            throw new \InvalidArgumentException('The tolerance is a number of seconds above 0.');
        }
        $distance = abs(($now ?? time()) - $timestamp);
        if ($distance > $tolerance) {
            throw self::fail(sprintf('The signature is %d s from now, more than the %d s allowed: an old webhook replayed, or this server\'s clock is wrong.', $distance, $tolerance));
        }

        $secrets = array_values(array_filter(is_array($secret) ? $secret : [$secret], static fn (mixed $s): bool => is_string($s) && $s !== ''));
        if ($secrets === []) {
            throw new \InvalidArgumentException("A webhook needs your endpoint's signing secret (whsec_…) to be verified.");
        }
        foreach ($secrets as $key) {
            $expected = hash_hmac('sha256', $timestamp.'.'.$body, $key);
            foreach ($signatures as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return;
                }
            }
        }

        throw self::fail('No signature matches: check that the signing secret is this endpoint’s, of this mode (test or live), and that the body was not changed.');
    }

    /** The raw body as a string; parsed JSON (an array or an object) is refused with why. */
    private static function body(mixed $payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }
        if ($payload instanceof \Stringable) {
            return (string) $payload;
        }

        throw self::fail('Pass the raw request body (a string, such as file_get_contents(\'php://input\')), not parsed JSON: the signature is over the bytes as sent.');
    }

    private static function fail(string $message): SignatureVerificationException
    {
        return new SignatureVerificationException($message, 'signature_verification_error');
    }
}
