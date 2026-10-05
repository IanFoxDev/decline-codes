<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * Your retry schedule: how long to wait after the n-th decline, by class, and the most
 * attempts in total. It runs below the network rules and can only be stricter than them.
 */
final readonly class Schedule
{
    /**
     * @param list<int> $laterDelays seconds to wait after the 1st, 2nd, ... decline of class later
     * @param list<int> $technicalDelays the same for class technical
     */
    public function __construct(
        public int $maxAttempts,
        public array $laterDelays,
        public array $technicalDelays,
    ) {
        if ($maxAttempts < 1 || $laterDelays === [] || $technicalDelays === []) {
            throw new \InvalidArgumentException('A schedule needs at least one attempt and one delay per class.');
        }
        foreach ([...$laterDelays, ...$technicalDelays] as $delay) {
            if ($delay < 0) {
                throw new \InvalidArgumentException('Delays are seconds and cannot be negative.');
            }
        }
    }

    /**
     * Eight attempts in all, as Stripe recommends. After a "later" decline: 1, 2, 3, 5, 7, 10
     * and 14 days. After a technical failure: 10 minutes, 1 hour, 6 hours, then a day apart.
     */
    public static function default(): self
    {
        $day = 86400;

        return new self(
            8,
            [$day, 2 * $day, 3 * $day, 5 * $day, 7 * $day, 10 * $day, 14 * $day],
            [600, 3600, 6 * 3600, $day, $day, $day, $day],
        );
    }

    /**
     * The delay after the given number of declines so far (1 = after the first decline).
     */
    public function delayAfter(int $declines, DeclineClass $class): int
    {
        $delays = $class === DeclineClass::Technical ? $this->technicalDelays : $this->laterDelays;

        return $delays[min($declines, \count($delays)) - 1];
    }
}
