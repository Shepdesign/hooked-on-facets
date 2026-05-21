# Contributing to hooked on facets

> *Filtering, finally fun.*

HOF is open source. Contributions are welcome — from code to docs to ideas.

## the short version

1. **Read the [full Contributing guide](https://github.com/Shepdesign/hooked-on-facets/wiki/Contributing)** in the wiki. It has the detail.
2. **Check the [Roadmap](https://github.com/Shepdesign/hooked-on-facets/wiki/Roadmap)** before starting anything non-trivial.
3. **Open an issue first** to align before you spend hours.
4. **Follow the [Code of Conduct](./CODE_OF_CONDUCT.md).** Don't be a jerk.

## ways to contribute

| Effort | Impact |
|---|---|
| ⭐ Star the repo | Bigger than you think |
| 🐛 File an issue with a clean repro | Genuinely helpful |
| 📝 Improve the wiki | Permanent value |
| 🛠️ Fix a `good first issue` | You're in |
| 🚀 Build a facet type or integration | You're a maintainer |

## quick setup

```bash
git clone https://github.com/Shepdesign/hooked-on-facets.git
cd hooked-on-facets
composer install
npm install
docker compose up -d
npm run dev
```

Full dev environment details: [Architecture → Dev Environment](https://github.com/Shepdesign/hooked-on-facets/wiki/Architecture#dev-environment).

## the PR checklist

Before opening a PR:

- [ ] Tests added or updated (where it makes sense)
- [ ] `composer test` passes
- [ ] `npm run test` passes
- [ ] `npm run lint` clean
- [ ] Commit messages and PR title follow [Conventional Commits](https://www.conventionalcommits.org/)
- [ ] Docs updated if behavior changed
- [ ] PR description explains *why*, not just *what*

## reporting security issues

**Do not file public issues for security vulnerabilities.** See [SECURITY.md](./SECURITY.md) for the disclosure process.

## license

License **TBD** — leaning open. By contributing, you agree your contributions will be re-licensed under the final license when 1.0 lands.

---

📖 **Full contributing guide:** https://github.com/Shepdesign/hooked-on-facets/wiki/Contributing
