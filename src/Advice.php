<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * Advice the network sends next to a decline, such as Mastercard's Merchant Advice Code.
 */
final readonly class Advice
{
    public const string NEVER = 'never';
    public const string LATER = 'later';
    public const string WAIT = 'wait';
    public const string UPDATE_CREDENTIALS = 'update_credentials';
    public const string FIX_DATA = 'fix_data';
    public const string NONE = 'none';

    public function __construct(
        public string $code,
        public string $meaning,
        public string $effect,
        public ?int $waitHours = null,
    ) {}
}
