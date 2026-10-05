# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). Before 1.0, minor versions may break the
API; such changes are marked **BREAKING**.

## [Unreleased]

### Added

- Normalized reasons and five classes (`never`, `later`, `fix_data`, `customer_action`,
  `technical`) in `data/reasons.json`.
- Visa profile from Visa Core Rules, 18 April 2026, Table 7-2: all listed codes with their
  reattempt category (20 reattempts in 30 days for categories 2 to 4), code 83 in category 2
  from 25 July 2026, codes 70 and 1A in category 3 in CEMEA and Europe.
- Mastercard profile with Merchant Advice Codes and limits (10 consecutive declines in 24
  hours, 35 of the same amount in 30 days), from processors' documentation.
- ISO 8583 profile and Stripe profile (all 50 card decline codes, advice codes, the
  network's raw codes when Stripe passes them on).
- `RetryPolicy` with the network rules as a ceiling over your `Schedule`; every `Decision`
  names its rule and source.
