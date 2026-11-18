# Security Policy

## Supported versions

The Mothership has one supported line: the `main` branch. There are no release
branches, version tables or backports. Production is the maintainer's Mac, which
runs a commit from `main`.

A fix is supported once it is merged to `main`. It protects production only
after the maintainer deploys that commit to production, following
[docs/ops/studio-runtime.md](docs/ops/studio-runtime.md).

## Reporting a vulnerability

Report security issues privately to the repository maintainer,
[@ChristianGrubbs](https://github.com/ChristianGrubbs). Do **not** describe the
issue in a GitHub issue, a pull request, a commit message or a shared channel.

Include:

- what the issue is and which part of The Mothership it affects;
- the steps to reproduce it, with a proof of concept if you have one;
- the impact you expect;
- the commit or deployment you tested against;
- a suggested fix, if you have one.

Do not use real assets, accounts or credentials in a report or a proof of
concept. Use made-up files and names, as the [contributing rules](CONTRIBUTING.md#rules)
require.

## What happens next

1. The maintainer confirms the report and reproduces it.
2. The fix goes through the normal workflow in [CONTRIBUTING.md](CONTRIBUTING.md):
   a branch, a pull request and the required checks, all within this private
   repository.
3. The maintainer deploys the fixed `main` commit to production and tells
   the reporter when it is live.

## Inherited code

The Mothership is an owned codebase ([ADR 0001](docs/adr/0001-owned-codebase.md)). Report every issue here, including one in inherited code.
