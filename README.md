# decline-codes

[![php](https://github.com/IanFoxDev/decline-codes/actions/workflows/php.yml/badge.svg)](https://github.com/IanFoxDev/decline-codes/actions/workflows/php.yml)

Payment decline codes normalized across card networks and payment providers, and a retry
policy that follows the networks' reattempt rules. Every rule names its source and the
edition of the document it comes from. The data is JSON, so other languages can use it too.

> Status: in development, nothing released yet.

Retrying declined payments usually runs on one schedule for every decline: tomorrow, in
three days, in a week. Two things go wrong with that.

- **Some declines must never be retried.** Visa's category 1 (lost, stolen, closed account,
  "pick up") and Mastercard's Merchant Advice Codes 03 and 21 mean the issuer will not
  approve this card again. Each retry after them can be charged as an excessive retry.
- **The rest have a limit.** Visa allows up to **20 reattempts in 30 days** (Visa Core
  Rules, 18 April 2026, Table 7-2). Until recently the rule said 15, and most pages and
  libraries still do. Mastercard asks to stop after 10 consecutive declines at one merchant
  in 24 hours, and sends codes that say how long to wait.

## Install

```bash
composer require ianfoxdev/decline-codes
```

PHP 8.3 or later, no dependencies.

## Example

```php
use IanFoxDev\DeclineCodes\{Attempt, Catalog, RetryPolicy};

$catalog = Catalog::default();

// A Stripe decline that carries the network's raw code.
$decline = $catalog->stripe()->resolve(
    'insufficient_funds',
    brand: 'visa',
    networkDeclineCode: '51',
);
$decline->reason->id;     // "insufficient_funds"
$decline->class;          // DeclineClass::Later
$decline->category;       // 2, Visa's "Issuer cannot approve at this time"

$decision = RetryPolicy::default()->decide([
    Attempt::declined(new DateTimeImmutable('2026-10-01 06:00'), $decline, 'acme', 1900),
]);
$decision->verdict;       // Verdict::RetryAt
$decision->notBefore;     // 2026-10-02 06:00
$decision->rule;          // "schedule.later"
```

After a Mastercard decline with Merchant Advice Code 03 the same call returns:

```
never  mastercard.mac_03  Mastercard sent Merchant Advice Code 03 (Do not try again);
                          resubmitting is charged as an excessive retry.
```

[examples/renewals](examples/renewals) runs five declined renewals: a schedule, a
Mastercard delay, a Stripe "generic_decline" that is really a stolen card, a wrong CVC, and
an hourly retrier stopped by Visa's limit.

## What you get for a decline

| Field | Example | Meaning |
|---|---|---|
| `reason` | `insufficient_funds` | What happened, normalized. Ids follow Stripe's decline codes. |
| `class` | `later` | What to do: `never`, `later`, `fix_data`, `customer_action`, `technical` |
| `category` | `2` | Visa's reattempt category, when Visa decided |
| `advice` | MAC `27`, wait 96 hours | The network's advice code, when one was sent |
| `network` | Visa `43` | For a Stripe decline, the same decline read through the card network |
| `source` | Visa Core Rules, 2026-04-18 | Where the meaning comes from, and whether it is the network's own rules |

`$reason->forCustomer($catalog)` turns lost, stolen and fraud reasons into
`generic_decline`, as Stripe advises: the message should not help someone testing stolen
cards.

## The retry policy

`decide()` takes the attempts with one card at your merchant, oldest first, and returns a
`Decision`: `retry_at` a time, `never`, `needs_customer` (new card data, authentication, a
PIN) or `give_up` (your schedule is out of attempts). The rule that decided is in `rule`,
its source in `source`.

The network rules are a ceiling. Your schedule runs below them and cannot lift them:

| Rule | Value | Source |
|---|---|---|
| Visa category 1 | never, for the same card | Visa Core Rules, 18 April 2026, ID# 0030640 |
| Visa categories 2 to 4 | up to 20 reattempts in any 30 days | same |
| Mastercard MAC 03, 21 | never | processors' documentation (secondary) |
| Mastercard MAC 24 to 30 | wait 1 hour to 10 days | same |
| Mastercard | stop after 10 consecutive declines at one acceptor in 24 hours | same |
| Mastercard | stop after 35 declines of the same amount at one acceptor in 30 days | same |

The default schedule makes at most 8 attempts, as Stripe recommends. After a "later"
decline it waits 1, 2, 3, 5, 7, 10 and 14 days; after a technical one, 10 minutes, 1 hour,
6 hours, then a day. Pass your own `Schedule` to change it.

A test runs an eager retrier (no limit of its own, retrying the moment the policy allows)
for 400 cards over 90 days against random issuer answers, and checks every network rule
over every card's whole history.

## Where the data comes from

| File | Source | Read from |
|---|---|---|
| `data/visa.json` | Visa Core Rules and Visa Product and Service Rules, 18 April 2026, Section 7.3.6.3, Table 7-2 | the rules themselves |
| `data/mastercard.json` | TabaPay and Adyen docs (Merchant Advice Codes), Paya and Braintree (limits) | secondary: Mastercard's rules PDF is not publicly readable |
| `data/iso8583.json` | ISO 8583:1987 response codes, as listed on Wikipedia | secondary |
| `data/stripe.json` | docs.stripe.com/declines/codes and /declines/card, fetched 5 October 2026 | Stripe's docs |
| `data/reasons.json` | the normalized reasons and classes | this project |

Where secondary sources disagree, the stricter value is used and the record says so. One
example: Braintree describes the Mastercard 35-in-30-days rule in a Canada update; other
processors describe it without a region, so it applies everywhere here. Codes 76 to 89 are
reserved for private use in ISO 8583 and mean different things per network (82 is a CVV
failure at Visa and a policy decline at Mastercard), so the ISO profile does not guess them.

When a network changes a rule, the data changes in a minor release and the CHANGELOG says
which rule, which edition, and what it was before.

More: [docs/usage.md](docs/usage.md).

## Not yet

Network fees for excessive retries (not in the public rules), storing attempts (yours to
keep), more processor profiles (Adyen, Checkout.com, Braintree raw responses), American
Express and Discover, a decline scenario in
[psp-sandbox](https://github.com/IanFoxDev/psp-sandbox) that uses these codes, dunning
emails. Open an issue if you need one of them first.

## License

[MIT](LICENSE)
