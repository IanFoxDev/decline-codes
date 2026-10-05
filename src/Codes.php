<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * @internal
 */
final class Codes
{
    /**
     * Upper case, trimmed, one digit padded to two: 5 and "05" are the same code.
     */
    public static function normalize(string|int $code): string
    {
        $code = strtoupper(trim((string) $code));
        if (preg_match('/^[0-9A-Z]{1,3}$/', $code) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a decline or advice code.', $code));
        }

        return \strlen($code) === 1 ? '0' . $code : $code;
    }
}
