<?php

declare(strict_types=1);

namespace Orboni\Exception;

/**
 * Every failure is an OrboniException. A request Orboni refused carries the
 * API's own type (branch on it, or on the subclass, never on the message),
 * the parameter at fault when there is one, and the HTTP status.
 */
class OrboniException extends \Exception
{
    /** The subclass for each error type of the API. */
    private const CLASSES = [
        'invalid_request_error' => InvalidRequestException::class,
        'idempotency_error' => IdempotencyException::class,
        'authentication_error' => AuthenticationException::class,
        'permission_error' => PermissionException::class,
        'receiving_frozen' => ReceivingFrozenException::class,
        'account_past_due' => AccountPastDueException::class,
        'amount_too_small' => AmountTooSmallException::class,
        'rate_limit_error' => RateLimitException::class,
        'rate_unavailable' => RateUnavailableException::class,
        'api_error' => ApiException::class,
    ];

    /** @var array<string, string> */
    private array $headers;

    /** @var array<array-key, mixed>|null */
    private ?array $error;

    /**
     * @param  array<string, string>  $headers  the answer's headers, lower-cased
     * @param  array<array-key, mixed>|null  $error  the error object as the API sent it
     */
    public function __construct(
        string $message,
        private string $type,
        private ?string $param = null,
        private ?int $httpStatus = null,
        array $headers = [],
        ?array $error = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->headers = $headers;
        $this->error = $error;
    }

    /**
     * The exception for an answer that is not a success.
     *
     * @param  array<string, string>  $headers
     * @param  mixed  $body  the answer's body, decoded to arrays; null when it was not JSON
     */
    public static function fromResponse(int $httpStatus, array $headers, mixed $body): self
    {
        $error = is_array($body) && isset($body['error']) && is_array($body['error']) ? $body['error'] : null;
        $type = match (true) {
            is_string($error['type'] ?? null) => $error['type'],
            $httpStatus >= 500 => 'api_error',
            $httpStatus === 401 => 'authentication_error',
            default => 'invalid_request_error',
        };
        $message = is_string($error['message'] ?? null) ? $error['message'] : sprintf('Orboni answered HTTP %d.', $httpStatus);
        $param = is_string($error['param'] ?? null) ? $error['param'] : null;
        $class = self::CLASSES[$type] ?? self::class;

        return new $class($message, $type, $param, $httpStatus, $headers, $error ?? (is_array($body) ? $body : null));
    }

    /** The API's error type, e.g. invalid_request_error; connection_error or signature_verification_error for the library's own. */
    public function getType(): string
    {
        return $this->type;
    }

    /** The parameter at fault, when there is one. */
    public function getParam(): ?string
    {
        return $this->param;
    }

    /** The HTTP status; null when no answer came. */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * The answer's headers, their names lower-cased.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * The error object as the API sent it; null when the answer carried none.
     *
     * @return array<array-key, mixed>|null
     */
    public function getError(): ?array
    {
        return $this->error;
    }
}
