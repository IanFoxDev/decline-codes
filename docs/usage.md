# Usage

## Reading a decline

```php
$catalog = Catalog::default();          // reads data/*.json once

$catalog->visa()->resolve('51', region: 'Europe', at: $when);
$catalog->mastercard()->resolve('51', '27');            // code, Merchant Advice Code
$catalog->iso8583()->resolve('05');
$catalog->stripe()->resolve(
    'insufficient_funds',               // decline_code
    adviceCode: 'try_again_later',      // outcome.advice_code
    brand: 'visa',                      // payment_method.card.brand
    networkDeclineCode: '51',           // outcome.network_decline_code
    networkAdviceCode: null,            // outcome.network_advice_code
    region: 'Europe',                   // for Visa, see below
    at: $when,
);
```

Every call returns a `Decline`: `profile`, `code`, `meaning`, `reason`, `class`, `source`,
and when they apply `category` (Visa), `advice` (an advice code) and `network` (the same
decline read through the card network's profile).

- Codes are trimmed and upper-cased; `5` and `"05"` are the same code. A code that is not a
  decline code throws `InvalidArgumentException`; an unknown advice code too.
- A Visa code that Table 7-2 does not list is category 4, as the rules say, with the reason
  `generic_decline`. An unknown code in the other profiles is `generic_decline` too.
- `region` is Visa's acquiring region: `AP`, `Canada`, `CEMEA`, `Europe`, `LAC` or `US`.
  Codes 70 (PIN data required) and 1A (additional customer authentication required) are
  category 3 in CEMEA and Europe and category 4 elsewhere.
- `at` matters for codes that change category on a date: Visa's 83 joins category 2 on
  25 July 2026.

## Classes

| Class | Meaning | The policy says |
|---|---|---|
| `never` | the issuer will not approve this card | `never` |
| `later` | the issuer cannot approve now | `retry_at`, by your schedule, under the network's limits |
| `technical` | a failure between systems | `retry_at`, sooner |
| `fix_data` | card data was wrong or out of date | `needs_customer` |
| `customer_action` | authentication, a PIN, new card details | `needs_customer` |

A decline has one class, decided in this order: "never" from any side (the reason, Visa's
category 1, a Mastercard MAC 03 or 21, Stripe's `do_not_try_again`) wins; otherwise the
card network's reading when there is one; otherwise the advice code and the reason.

## The retry policy

```php
$policy = new RetryPolicy($catalog, new Schedule(
    maxAttempts: 6,
    laterDelays: [86400, 3 * 86400, 7 * 86400],        // after the 1st, 2nd, 3rd+ decline
    technicalDelays: [600, 3600, 86400],
));

$decision = $policy->decide($attempts, amount: 1900);
```

- `$attempts` are the `Attempt`s with one card at your merchant, oldest first; the last one
  must be a decline. `Attempt::declined($at, $decline, $acceptor, $amount)` and
  `Attempt::approved(...)`. An approval resets the consecutive-decline count.
- `amount` is the amount of the next attempt, for Mastercard's same-amount limit; the last
  attempt's by default.
- The policy keeps no state. Keep the attempts where you keep payments.
- A Visa reattempt is an attempt that follows a Visa decline. A category 1 decline anywhere
  in the history stops retries for the card, not only when it is the last one.
- Your schedule's delay is counted from the last attempt. When a network rule needs a later
  time, `notBefore` is that time and `rule` names the network rule.

## Decisions

| `rule` | Meaning |
|---|---|
| `schedule.later`, `schedule.technical` | your schedule decided |
| `schedule.max_attempts` | your schedule has no attempts left (`give_up`) |
| `class.never`, `class.fix_data`, `class.customer_action` | the decline's class decided |
| `visa.category_1` | Visa, never for this card |
| `visa.reattempt_limit` | Visa, 20 reattempts in 30 days |
| `mastercard.mac_03`, `mastercard.mac_21` | Mastercard, never |
| `mastercard.mac_24` to `mastercard.mac_30` | Mastercard, wait |
| `mastercard.consecutive_declines` | Mastercard, 10 in 24 hours |
| `mastercard.declines_same_amount` | Mastercard, 35 of the same amount in 30 days |

## The data

The JSON in `data/` is the only copy of the codes and rules; the PHP classes read it. Use it
from other languages directly. Every network file has a `source` with `document`,
`edition`, `url` and `verified` (`primary` for the network's own rules, `secondary`
otherwise), and records can point to other sources by name.
