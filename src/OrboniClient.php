<?php

declare(strict_types=1);

namespace Orboni;

use Orboni\Exception\ApiException;
use Orboni\Exception\ConnectionException;
use Orboni\Exception\OrboniException;
use Orboni\Service\CheckoutService;
use Orboni\Service\CurrencyService;
use Orboni\Service\EventService;
use Orboni\Service\PaymentLinkService;
use Orboni\Service\PaymentMethodService;
use Orboni\Service\RefundService;
use Orboni\Service\WebhookService;

/**
 * Orboni's API. One instance per secret key; it keeps no state between calls.
 *
 *     $orboni = new \Orboni\OrboniClient(getenv('ORBONI_SECRET_KEY'));
 *     $session = $orboni->checkout->sessions->create(['amount' => 2500, 'currency' => 'usd']);
 */
class OrboniClient
{
    public const VERSION = '1.0.0';

    public const DEFAULT_BASE_URL = 'https://orboni.com/v1';

    /** A delay longer than this in Retry-After is not waited out: the error is thrown. */
    private const MAX_RETRY_AFTER_SECONDS = 20;

    private const CONFIG_KEYS = ['base_url', 'timeout', 'max_retries'];

    private const OPTION_KEYS = ['idempotency_key', 'timeout', 'max_retries'];

    /** What trim() takes off, the same in every PHP version. */
    private const SPACE = " \t\n\r\0\x0B\x0C";

    public readonly CheckoutService $checkout;

    public readonly PaymentLinkService $paymentLinks;

    public readonly RefundService $refunds;

    public readonly EventService $events;

    public readonly PaymentMethodService $paymentMethods;

    public readonly CurrencyService $currencies;

    /** Verifies the webhooks Orboni sends; needs no key. */
    public readonly WebhookService $webhooks;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly float $timeout;

    private readonly int $maxRetries;

    /** One handle for every call, so its connection is kept open between them. */
    private ?\CurlHandle $curl = null;

    /**
     * $config: `base_url`, the API's address (https://orboni.com/v1 by default);
     * `timeout`, how long one try may take, in seconds (30 by default);
     * `max_retries`, tries after the first for a network failure, a 409, 429 or 5xx (2 by default).
     *
     * @param  string|null  $apiKey  your secret key, sk_test_… or sk_live_…
     * @param  array{base_url?: string, timeout?: int|float, max_retries?: int}  $config
     */
    public function __construct(#[\SensitiveParameter] ?string $apiKey, array $config = [])
    {
        $key = trim((string) $apiKey, self::SPACE);
        if ($key === '') {
            throw new \InvalidArgumentException('Orboni needs your secret key (sk_test_… or sk_live_…), from Developers → API keys in the dashboard.');
        }
        if (str_starts_with($key, 'pk_')) {
            throw new \InvalidArgumentException('That is a publishable key, made for the browser. The API takes your secret key (sk_test_… or sk_live_…).');
        }
        if (preg_match('/[\x00-\x20\x7F]/', $key) === 1) {
            throw new \InvalidArgumentException('An Orboni key has no spaces or line breaks inside: check how it was copied.');
        }
        self::refuseUnknown($config, self::CONFIG_KEYS, 'The client takes');
        $baseUrl = $config['base_url'] ?? self::DEFAULT_BASE_URL;
        if (! is_string($baseUrl) || trim($baseUrl, self::SPACE) === '') {
            throw new \InvalidArgumentException('base_url is the API\'s address, such as https://orboni.com/v1.');
        }

        $this->apiKey = $key;
        $this->baseUrl = rtrim(trim($baseUrl, self::SPACE), '/');
        $this->timeout = array_key_exists('timeout', $config) ? self::checkTimeout($config['timeout']) : 30.0;
        $this->maxRetries = array_key_exists('max_retries', $config) ? self::checkRetries($config['max_retries']) : 2;

        $this->checkout = new CheckoutService($this);
        $this->paymentLinks = new PaymentLinkService($this);
        $this->refunds = new RefundService($this);
        $this->events = new EventService($this);
        $this->paymentMethods = new PaymentMethodService($this);
        $this->currencies = new CurrencyService($this);
        $this->webhooks = new WebhookService;
    }

    /** True for a test key: what it makes never takes real money. */
    public function isTestMode(): bool
    {
        return str_starts_with($this->apiKey, 'sk_test_');
    }

    /**
     * @internal Any call of the API; the resources are its shortcuts.
     *
     * @param  'GET'|'POST'  $method
     * @param  array<string, mixed>|null  $params  a GET's query, or a POST's JSON body (none when null)
     * @param  array{idempotency_key?: string, timeout?: int|float, max_retries?: int}  $opts
     *
     * @throws OrboniException
     */
    public function request(string $method, string $path, ?array $params = null, array $opts = []): OrboniObject
    {
        return OrboniObject::constructFrom($this->send($method, $path, $params, $opts));
    }

    /**
     * @internal A list call: its first page, which walks on to the next ones when $paged.
     *
     * @param  array<string, mixed>  $params
     * @param  array{timeout?: int|float, max_retries?: int}  $opts
     *
     * @throws OrboniException
     */
    public function requestCollection(string $path, array $params, array $opts, bool $paged = true): Collection
    {
        $fetchPage = fn (array $page): OrboniObject => Collection::constructFrom($this->send('GET', $path, $page, $opts));
        $first = Collection::constructFrom($this->send('GET', $path, $params, $opts));

        return $paged ? $first->withPaging($fetchPage, $params) : $first;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'timeout' => $this->timeout,
            'max_retries' => $this->maxRetries,
            'test_mode' => $this->isTestMode(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $params
     * @param  array<string, mixed>  $opts
     */
    private function send(string $method, string $path, ?array $params, array $opts): \stdClass
    {
        if ($method !== 'GET' && $method !== 'POST') {
            throw new \InvalidArgumentException('The API is called with GET or POST.');
        }
        self::refuseUnknown($opts, self::OPTION_KEYS, 'A call takes');
        $timeout = array_key_exists('timeout', $opts) ? self::checkTimeout($opts['timeout']) : $this->timeout;
        $retries = array_key_exists('max_retries', $opts) ? self::checkRetries($opts['max_retries']) : $this->maxRetries;
        $idempotencyKey = $opts['idempotency_key'] ?? null;
        if ($idempotencyKey !== null && (! is_string($idempotencyKey) || trim($idempotencyKey, self::SPACE) === '' || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1)) {
            throw new \InvalidArgumentException('idempotency_key is a string on one line, such as your order ID.');
        }

        $url = $this->baseUrl.$path;
        $body = null;
        if ($method === 'GET') {
            $query = self::encodeQuery($params ?? []);
            $url .= $query === '' ? '' : '?'.$query;
        } elseif ($params !== null) {
            $body = self::encodeJson($params);
        }

        $headers = [
            'Authorization: Bearer '.$this->apiKey,
            'Accept: application/json',
            'User-Agent: orboni-php/'.self::VERSION.' php/'.PHP_VERSION,
            'Expect:',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        } elseif ($method === 'POST') {
            $headers[] = 'Content-Type:';
        }
        if ($method === 'POST') {
            $headers[] = 'Idempotency-Key: '.($idempotencyKey ?? 'orboni-php-'.self::uuid4());
        }

        for ($attempt = 0; ; $attempt++) {
            $answer = $this->transmit($method, $url, $headers, $body, $timeout);
            if ($answer['errno'] !== 0) {
                if ($attempt >= $retries) {
                    throw new ConnectionException(
                        $answer['errno'] === CURLE_OPERATION_TIMEDOUT
                            ? sprintf('Orboni did not answer within %s s.', self::seconds($timeout))
                            : 'Could not reach Orboni: '.$answer['error'],
                        'connection_error',
                    );
                }
                self::sleep(self::backoff($attempt));

                continue;
            }

            if ($answer['status'] >= 200 && $answer['status'] < 300) {
                $decoded = self::decode($answer['body'], false);
                if (! $decoded instanceof \stdClass) {
                    throw new ApiException('Orboni answered with something that is not a JSON object.', 'api_error', null, $answer['status'], $answer['headers']);
                }

                return $decoded;
            }

            $error = OrboniException::fromResponse($answer['status'], $answer['headers'], self::decode($answer['body'], true));
            $wait = $attempt < $retries ? self::retryDelay($answer['status'], $answer['headers'], $attempt) : null;
            if ($wait === null) {
                throw $error;
            }
            self::sleep($wait);
        }
    }

    /**
     * One try.
     *
     * @param  list<string>  $headers
     * @return array{errno: int, error: string, status: int, headers: array<string, string>, body: string}
     */
    private function transmit(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        if ($this->curl === null) {
            $curl = curl_init();
            if ($curl === false) {
                throw new ConnectionException('Could not start cURL.', 'connection_error');
            }
            $this->curl = $curl;
        }
        $curl = $this->curl;
        curl_reset($curl);

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT => max(1, (int) ceil($timeout)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line, self::SPACE);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $name = strtolower(trim($name, self::SPACE));
                    $value = trim($value, self::SPACE);
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name].', '.$value : $value;
                }

                return strlen($line);
            },
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($curl, $options);

        $text = curl_exec($curl);

        return [
            'errno' => curl_errno($curl),
            'error' => curl_error($curl),
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'headers' => $responseHeaders,
            'body' => is_string($text) ? $text : '',
        ];
    }

    /**
     * Seconds to wait before the next try, or null when this answer is not worth another.
     *
     * @param  array<string, string>  $headers
     */
    private static function retryDelay(int $status, array $headers, int $attempt): ?float
    {
        if ($status !== 409 && $status !== 429 && $status < 500) {
            return null;
        }
        $after = $headers['retry-after'] ?? null;
        if (is_numeric($after) && (float) $after >= 0) {
            return (float) $after > self::MAX_RETRY_AFTER_SECONDS ? null : (float) $after;
        }

        return self::backoff($attempt);
    }

    /** 0.5 s, 1 s, 2 s… up to 8 s, less up to a quarter at random so clients that failed together do not retry together. */
    private static function backoff(int $attempt): float
    {
        $base = min(0.5 * (2 ** $attempt), 8.0);

        return $base * (0.75 + mt_rand() / mt_getrandmax() * 0.25);
    }

    private static function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    /**
     * `['limit' => 10, 'created' => ['gte' => 1]]` → `limit=10&created%5Bgte%5D=1`; nulls are left out.
     *
     * @param  array<array-key, mixed>  $params
     */
    private static function encodeQuery(array $params, string $prefix = ''): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
            if (is_array($value) && ! array_is_list($value)) {
                $nested = self::encodeQuery($value, $name);
                if ($nested !== '') {
                    $parts[] = $nested;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item !== null) {
                        $parts[] = rawurlencode($name.'[]').'='.rawurlencode(self::queryValue($item, $name));
                    }
                }
            } else {
                $parts[] = rawurlencode($name).'='.rawurlencode(self::queryValue($value, $name));
            }
        }

        return implode('&', $parts);
    }

    private static function queryValue(mixed $value, string $name): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value), is_string($value), $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException(sprintf('The parameter %s is a string, a number, a boolean or an array.', $name)),
        };
    }

    /** @param  array<string, mixed>  $params */
    private static function encodeJson(array $params): string
    {
        try {
            return json_encode($params === [] ? new \stdClass : $params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The parameters cannot be sent as JSON: '.$e->getMessage(), 0, $e);
        }
    }

    /** The body decoded, or null when it is not JSON. */
    private static function decode(string $text, bool $associative): mixed
    {
        try {
            return json_decode($text, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /** A version 4 UUID, from random_bytes. */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** 30 → "30", 0.1 → "0.1". */
    private static function seconds(float $seconds): string
    {
        return rtrim(rtrim(sprintf('%.3F', $seconds), '0'), '.');
    }

    private static function checkTimeout(mixed $timeout): float
    {
        if ((! is_int($timeout) && ! is_float($timeout)) || ! is_finite((float) $timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('timeout is a number of seconds above 0, such as 30 or 2.5.');
        }

        return (float) $timeout;
    }

    private static function checkRetries(mixed $retries): int
    {
        if (! is_int($retries) || $retries < 0) {
            throw new \InvalidArgumentException('max_retries is a whole number from 0, such as 2.');
        }

        return $retries;
    }

    /**
     * @param  array<array-key, mixed>  $given
     * @param  list<string>  $known
     */
    private static function refuseUnknown(array $given, array $known, string $takes): void
    {
        $unknown = array_diff(array_map('strval', array_keys($given)), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown option %s. %s %s.', implode(', ', $unknown), $takes, implode(', ', $known)));
        }
    }
}
