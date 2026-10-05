<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * A decline code read through a network's profile.
 */
final readonly class Decline
{
    public function __construct(
        public string $network,
        public string $code,
        public string $meaning,
        public Reason $reason,
        public DeclineClass $class,
        public Source $source,
        public ?int $category = null,
        public ?string $adviceCode = null,
    ) {}

    /**
     * Whether any retry with the same card can be allowed. When, and how many times, is the
     * retry policy's question.
     */
    public function isRetryable(): bool
    {
        return $this->class !== DeclineClass::Never;
    }
}
