# Security Policy

Thanks for helping keep hooked on facets and the people who use it safe.

## supported versions

| Version | Supported |
|---|---|
| Pre-alpha (all) | ✅ — please report anything |
| Alpha (0.x) | ✅ when it ships |
| Beta (0.5.x) | ✅ when it ships |
| 1.x | ✅ when it ships |

Once 1.0 ships we'll define a formal support window. Until then, all versions are in scope.

## reporting a vulnerability

**Please do not file a public issue.** Public issues are visible to everyone, including bad actors.

Instead, report security issues privately via **[GitHub Security Advisories](https://github.com/Shepdesign/hooked-on-facets/security/advisories/new)**:

1. Go to https://github.com/Shepdesign/hooked-on-facets/security/advisories/new
2. Fill in the details (see "what to include" below)
3. Submit

This goes directly to the maintainers in a private channel. Nobody else can see it until we publish a coordinated disclosure.

If you can't use GitHub Advisories for any reason, contact the maintainer via the email on the [@Shepdesign GitHub profile](https://github.com/Shepdesign).

## what to include

A useful report contains:

- A **clear description** of the vulnerability
- **Steps to reproduce** — the cleaner the repro, the faster the fix
- **Affected version(s)** — HOF version, WordPress version, PHP version, WooCommerce version (if relevant)
- **Impact** — what an attacker could do with this
- **Suggested fix** if you have one (you don't have to)
- Your **disclosure timeline** preference if you have one

## what to expect

| Timeline | What happens |
|---|---|
| Within **3 business days** | Acknowledgement that we received your report |
| Within **7 business days** | Initial assessment of severity and reproducibility |
| Within **30 days** | Fix in progress, or explanation if we disagree it's a vulnerability |
| Coordinated release | Public disclosure with credit to you (if you want it) |

## scope

In scope:

- The HOF plugin itself (PHP, JS, REST endpoints, admin UI)
- HOF's interaction with WordPress core, WooCommerce, and listed dependencies
- HOF's documentation/wiki where it provides exploitable misinformation

Out of scope:

- Vulnerabilities in WordPress core, WooCommerce, or unrelated third-party plugins (report those to their respective maintainers)
- Vulnerabilities that require admin access to exploit (those are operational, not application, vulnerabilities)
- Theoretical attacks without a concrete exploitation path
- Findings from automated scanners without manual verification

## safe harbor

We support responsible security research. If you:

- Make a good-faith effort to avoid privacy violations, data destruction, and service disruption
- Only interact with accounts you own or have explicit permission to test
- Give us reasonable time to address issues before public disclosure
- Don't exploit the vulnerability beyond what's needed to demonstrate it

…we won't take legal action against you. We may publicly thank you (with your consent) for helping make HOF safer.

## credits

Researchers who responsibly disclose accepted vulnerabilities will be credited in the release notes and the project's security hall of fame (once we have one).

Thanks for keeping users safe.
