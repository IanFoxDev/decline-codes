# Contributing

## Running locally

You need PHP 8.3 or later and Composer.

```bash
composer install
make check           # PHP-CS-Fixer, PHPStan at level max, PHPUnit
```

## Changing the data

The codes and rules are in `data/*.json`; that is the only copy. A change needs a source:

- the document, its edition or the date you read it, and a URL in the record or the file's
  `sources`;
- `verified: primary` only for the network's own rules; a processor's documentation is
  `secondary`;
- a line in `CHANGELOG.md` that says which rule changed, in which edition, and what it was
  before.

`tests/VisaProfileTest.php` keeps its own copy of Visa's Table 7-2 and
`tests/StripeProfileTest.php` of Stripe's code list, so a change to the data alone fails
until the test is updated against the source too. That is on purpose.

## Changing the policy

`tests/RetryPolicyPropertiesTest.php` runs an eager retrier against random declines and
checks every network rule over every history. It also checks that the simulation reached
each rule; a change that stops it from reaching one needs a change to the simulation.

## Where things are

| Path | What |
|---|---|
| `data/` | Reasons, Visa, Mastercard, ISO 8583, Stripe |
| `src/VisaProfile.php`, `src/CodeProfile.php`, `src/StripeProfile.php` | Reading codes |
| `src/RetryPolicy.php`, `src/Schedule.php` | Deciding retries |
| `examples/renewals` | The example; its output is checked by the tests |

## Pull requests

- One logical change per pull request; Conventional Commits.
- `make check` passes. Add a line to `CHANGELOG.md`.
