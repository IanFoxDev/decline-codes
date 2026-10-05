<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

enum Verdict: string
{
    /** Retry, not before Decision::$notBefore. */
    case RetryAt = 'retry_at';
    /** Never retry with this card. */
    case Never = 'never';
    /** Do not retry until the customer acts: new card data, authentication, a PIN. */
    case NeedsCustomer = 'needs_customer';
    /** The network would allow more, but your schedule has no more attempts. */
    case GiveUp = 'give_up';
}
