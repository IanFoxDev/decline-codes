<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * A normalized decline reason, such as insufficient_funds or stolen_card. The ids follow
 * Stripe's decline codes where Stripe has one.
 */
final readonly class Reason
{
    public function __construct(
        public string $id,
        public DeclineClass $class,
        public string $description,
    ) {}
}
