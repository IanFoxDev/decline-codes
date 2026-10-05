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
        public bool $disclose = true,
    ) {}

    /**
     * What to show the customer. Lost, stolen and fraud reasons are shown as a generic
     * decline, as Stripe advises, so the message does not help someone testing stolen cards.
     */
    public function forCustomer(Catalog $catalog): self
    {
        return $this->disclose ? $this : $catalog->reason('generic_decline');
    }
}
