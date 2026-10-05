<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * What to do about a decline. The reason says what to tell the customer; the class says
 * whether and when to try again.
 */
enum DeclineClass: string
{
    /** Never retry with the same card; the customer has to give another payment method. */
    case Never = 'never';
    /** The issuer cannot approve now; retry later, within the network's limits. */
    case Later = 'later';
    /** The card data was wrong or out of date; retry only after it is corrected. */
    case FixData = 'fix_data';
    /** The customer has to do something first: authenticate, enter a PIN, confirm new details. */
    case CustomerAction = 'customer_action';
    /** A failure between systems; retry soon, within the network's limits. */
    case Technical = 'technical';
}
