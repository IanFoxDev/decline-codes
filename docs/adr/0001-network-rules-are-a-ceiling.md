# 0001. Network rules are a ceiling, and every rule has a source

Date: 2026-10-05. Status: accepted.

## Context

A payment is declined with a code: `51` from the issuer, `insufficient_funds` from
Stripe, a Merchant Advice Code next to it from Mastercard. Code that handles declines
usually ends up as `if ($code === '05' || str_contains($message, 'insufficient'))`, and the
retries run on one schedule for every decline: tomorrow, in three days, in a week.

That breaks in three ways.

- **Some declines must never be retried.** Visa puts lost, stolen, closed and "pick up"
  codes into a category the issuer "will never approve", and a merchant must never resubmit
  for the same card. Mastercard says the same with Merchant Advice Code 03 and 21. A retry
  there is a fee from the network, not a second chance.
- **The others have a limit.** Visa allows up to 20 reattempts in 30 days for the rest
  (Visa Core Rules, 18 April 2026, Table 7-2). The same rule said 15 until recently, and
  most pages and libraries still say 15. A limit copied into code without its source goes
  stale silently.
- **One code means different things.** `82` is an invalid expiry date in the ISO 8583
  table and a failed CVV check in Visa's. A single global table is wrong for someone.

## Decision

- **The network's rule is a ceiling, not a setting.** The retry policy never allows an
  attempt that the card network forbids: not after a "never" decline, not past the
  network's count in its window, not before a delay the network asked for. Your own
  schedule (when to retry, how many times) works below that ceiling and can only be
  stricter.
- **Codes are read per profile.** `visa`, `mastercard`, `iso8583` and `stripe` each map
  their own codes. A Stripe decline carries the network's raw code and advice code when
  Stripe has them, and the network profile decides the retry.
- **Two answers per decline.** A normalized reason (`insufficient_funds`, `stolen_card`)
  says what to tell the customer. A class says what to do: `never`, `later`, `fix_data`,
  `customer_action`, `technical`.
- **The data is JSON, in the repository, and it is the only copy.** PHP reads it; any
  other language can too. Every record carries its source: the document, its edition, the
  rule ID or URL, and whether it was read from the network's own rules (`primary`) or from
  a processor's documentation (`secondary`).
- **A changed network rule is a release.** When a network changes a limit or moves a code
  between categories, the data changes in a minor version and the CHANGELOG says which
  rule, which edition, and what it was before.
- **The policy is a pure function.** It takes the history of attempts for one card and
  merchant (time, network, code, advice code) and returns a decision: allowed now, not
  before a time, or never, with the rule that decided it. Storing the attempts is the
  caller's job.

## Consequences

- Mastercard's rules are not public in a form we could read (the rules PDF answers 403),
  so its limits come from processors' documentation and are marked `secondary`. Where those
  sources disagree, the stricter value is used and both are named.
- Network fees for excessive retries are not in the public rules and are left out.
- The package cannot know what your processor adds on top: some acquirers apply stricter
  limits. Your schedule is where those go.
- Keeping the data current is the work. A test fails if a record has no source, and the
  edition of each network's rules is part of the public API, so you can check which one you
  run.
