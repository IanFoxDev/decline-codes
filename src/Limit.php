<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * A network's limit on declined attempts: at most $maxDeclines declines within the window
 * for attempts that share the fields in $same (card, acceptor, amount).
 */
final readonly class Limit
{
    /**
     * @param list<string> $same
     * @param list<Source> $sources
     */
    public function __construct(
        public string $id,
        public int $maxDeclines,
        public int $windowSeconds,
        public array $same,
        public ?\DateTimeImmutable $effectiveFrom,
        public array $sources,
        public string $note,
    ) {}
}
