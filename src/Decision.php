<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * What the retry policy decided, and the rule that decided it. A network rule carries its
 * source; a schedule rule does not.
 */
final readonly class Decision
{
    public function __construct(
        public Verdict $verdict,
        public string $rule,
        public string $explanation,
        public ?\DateTimeImmutable $notBefore = null,
        public ?Source $source = null,
    ) {}

    public function allowsRetryAt(\DateTimeImmutable $at): bool
    {
        return $this->verdict === Verdict::RetryAt && $this->notBefore !== null && $at >= $this->notBefore;
    }
}
