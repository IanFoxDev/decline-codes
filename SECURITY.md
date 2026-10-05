# Security

decline-codes reads JSON files shipped with the package and makes no network requests.

If you find a vulnerability, for example input that makes the retry policy allow an attempt
a card network forbids, do not open a public issue. Report it privately through
[GitHub](https://github.com/IanFoxDev/decline-codes/security/advisories/new), or write to
ianfoxdeveloper@gmail.com.

A wrong code or an outdated rule is not a vulnerability: open a regular issue with the
source.

## Supported versions

Fixes go into the latest release only. Until 1.0 that is the latest `0.x` tag.
