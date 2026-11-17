<?php

declare(strict_types=1);

namespace Orboni\Service;

use Orboni\OrboniClient;

/** The part every resource shares: its client and a checked path. */
abstract class AbstractService
{
    /** What trim() takes off, the same in every PHP version. */
    private const SPACE = " \t\n\r\0\x0B\x0C";

    public function __construct(protected readonly OrboniClient $client) {}

    /** `/refunds/re_…`: the ID checked and escaped, so an empty one never reaches the list. */
    protected function path(string $base, string $id, string $action = ''): string
    {
        if (trim($id, self::SPACE) === '') {
            throw new \InvalidArgumentException(sprintf('An ID is needed for %s/{id}%s.', $base, $action));
        }

        return $base.'/'.rawurlencode($id).$action;
    }
}
