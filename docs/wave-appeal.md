# Drips Stellar Wave: appeal brief

`sorocharge/sorocharge-php` was **rejected** from the Stellar Wave program,
with no reason given. This page records what the program's rules actually say
about appealing, what has changed in this repository since the rejection, and
what the owner still has to do. Nothing here has been submitted.

> **A rejected repository cannot be re-applied.** An appeal is the only route.
> Do not open a new application for this repo.

## How these facts were checked

Fetched **2026-11-03** from the official Drips sites. Program rules change
between Waves, so re-check the first two before acting.

| Source | URL |
|---|---|
| Participating in a Wave (maintainers) | https://docs.drips.network/wave/maintainers/participating-in-a-wave/ |
| Repo application limits | https://docs.drips.network/wave/maintainers/repo-application-limits/ |
| Stellar Wave program page | https://www.drips.network/wave/stellar |
| Stellar Wave approved repos | https://www.drips.network/wave/stellar/repos |
| Wave 7 changelog (introduced appeals) | https://drips.network/blog/posts/wave-7-changelog |

## The appeal process, as documented

- **Channel.** In the app only: **Maintainers → Orgs and Repos**, find the
  rejected repo, click **Appeal**. Appeals sent by email, Discord or any other
  channel are ignored.
- **What the form asks.** What development work and improvements have happened
  **since the rejection**. The section below is written to answer exactly
  that.
- **Timing.** The first appeal is allowed **two weeks after the rejection**.
  Appealing earlier is not possible.
- **Budget.** Each repo can be appealed **at most three times**. A declined
  appeal starts a **one-month cooldown**. A frivolous appeal still consumes
  one of the three, so do not appeal until there is something substantive to
  report.
- **Standard.** Appeals are reconsidered **only after a substantive change** —
  code or project-quality improvements, added activity, or addressing the
  concerns in the review. "Nothing material changed" means the decision will
  not change.

## Timing

- **Wave 11: 2026-11-18 14:00 → 2026-11-25 14:00**, budget $75,000. No Wave is
  active as of 2026-11-03.
- Waves 1–10 are complete. Each Wave runs 7 days, roughly monthly.
- **Check the rejection date.** The appeal cannot be filed until two weeks
  after it. If the rejection was on or before 2026-11-04, the appeal can go in
  before Wave 11 starts; if it was later, Wave 11 is not reachable and Wave 12
  is the target.

Application limits reset at the start of each Wave cycle, and **a rejected
application still counts against the current cycle's limit**. Programs may set
a per-user limit (shared across all your orgs) and a per-org limit; either,
both or neither. If several of your Stellar repos were applied in the same
cycle, that is worth checking — hitting a limit looks like an unexplained
rejection from the outside.

## What has changed since the rejection

This is the material for the appeal form. Every item is in the repository and
verifiable.

### The documented install was broken, and is now fixed

This is the most likely single cause of a reviewer bouncing off the repo. The
README's first install step was `composer require sorocharge/sorocharge`. That
package **is not on Packagist** and returns 404, so anyone following the
quickstart — including a reviewer — hit a dead end immediately.

- The README now gives the VCS-repository recipe, which works today and pins
  by tag, and states plainly that Packagist publication is still pending
  rather than implying it is done.
- `scripts/install.sh` checks every prerequisite before building, so a missing
  dependency is reported as a named package rather than as a bindgen or linker
  error minutes in; then builds, proves the result loads in the PHP on `PATH`,
  and prints the exact `extension=` line and the drop-in ini directory.

### Prebuilt binaries, so using it no longer requires a Rust toolchain

`.github/workflows/release.yml` builds the extension for **PHP 8.1–8.5** across
**linux-x86_64, linux-aarch64, macos-x86_64 and macos-aarch64** (20
combinations) and drafts a release with `SHA256SUMS`. A PHP extension loads
only on the PHP minor version and platform it was built for, so each file name
carries both.

Before upload, each binary must load, expose all four native functions,
contain no test hooks, and pass the suite.

### New capability: `verifyTransferEffects`

`verifyEntry` proves the payer authorized a charge. It cannot prove that
submitting the transaction moves only that charge — the same invocation can
emit a second transfer, a mint, a burn or a clawback, and any contract in the
call tree is free to do so. The core has always had `verify_transfer_effects`;
the binding did not expose it, so a PHP facilitator either skipped the check
or reimplemented event parsing in PHP, which is the one thing this repo exists
to avoid.

It is now bound, tested against the diagnostic events of a real Stellar
testnet transaction across nine cases, and it makes three exception classes
reachable that shipped in 0.1.0 with nothing able to throw them.

### The contributor and security docs that were missing

The repository had no `CONTRIBUTING.md`, no `SECURITY.md`, no
`CODE_OF_CONDUCT.md` and no `CHANGELOG.md`. Issue #1 was *"docs: add
CONTRIBUTING.md and SECURITY.md"*, open since 2026-10-07.

For a program whose purpose is routing contributors to issues, a repo with no
contributor guide fails the one thing the program is for. All four now exist
and are specific to this repository rather than boilerplate: `CONTRIBUTING.md`
leads with what does **not** belong here, documents the load-bearing rules of
the binding layer, and gives the procedure for moving the core pin;
`SECURITY.md` routes to private advisories and is explicit about scope and
about which behaviours are deliberate rather than bugs.

Also added: issue forms for bug, feature and docs reports, a pull-request
checklist, and Dependabot for Cargo, Composer and Actions.

### CI now proves more than it did

- **macOS is built and tested.** The README always listed it, and the
  extension links differently there, but nothing ever built it.
- **PHPStan at level max** over the shipped `php/src` surface, analysed against
  the extension's own stubs, at zero errors.
- CI runs every supported PHP version on Linux and the ends of the range on
  macOS.

### Documentation beyond the API table

`docs/threat-model.md` states, per check, the claims a caller may and may not
make — including the two mistakes this API makes easy: treating verification
as settlement, and setting the expiry allowance to the current ledger.
`docs/facilitator.md` is the end-to-end order of operations.
`examples/verify-transfer-effects.php` runs with no network, keys or
environment.

### Current state

| | |
|---|---|
| Tests | 115 passing, against the real compiled extension |
| Static analysis | PHPStan level max, zero errors on `php/src` |
| Lints | `cargo fmt --check` and `clippy -D warnings` clean |
| CI | PHP 8.1–8.5 on Linux, 8.1 and 8.5 on macOS, plus a malware/supply-chain scan |

## What the owner still has to do

Ordered. The first three are the ones a reviewer can see.

1. ~~Make the test suite a required status check on `main`~~ — done
   2026-11-04. `main` now requires the `ci` and `malware-scan` contexts, a
   pull request, and conversation resolution, with admins included and force
   pushes off. `ci` is a gate job that needs `build-test`, so the matrix can
   change without editing protection. The approving-review count is **0** on
   purpose: GitHub does not let you approve your own pull request, so
   requiring one would lock a solo maintainer out of merging. Raise it when
   there is a second maintainer.
2. **Publish the v0.2.1 release.** Tagging `v0.2.1` runs the release workflow,
   which drafts a release with binaries for PHP 8.1–8.5 across four platforms
   and `SHA256SUMS`. The draft is never published automatically: review that
   all **20** expected platform/PHP pairs are present, then publish. An empty
   Releases tab on a tagged repo reads as abandoned. (`v0.2.0` is a tag with
   no release: it pinned the retired `macos-13` runner, so its five Intel
   macOS jobs never got a runner and the draft step never ran. Fixed in
   0.2.1.)
3. **Publish `sorocharge/sorocharge` to Packagist**, then simplify the README
   install back to `composer require`. For a PHP library, absence from
   Packagist is the single biggest adoption barrier.
4. **Close issue #1** — `CONTRIBUTING.md` and `SECURITY.md` now exist.
5. ~~Set the repository homepage~~ — done 2026-11-04, pointing at `docs/`.
   Change it to the Packagist page once the package is published.
6. ~~Enable Discussions~~ — done 2026-11-04, and the contact link is in
   `.github/ISSUE_TEMPLATE/config.yml`. Seed it with one post so the tab is
   not empty.
7. **Confirm the Drips Wave GitHub App is installed** on the `sorocharge` org
   with access to this repo, and that the org is onboarded.
8. **Complete identity verification** in the app if it asks.
9. **Check the rejection date**, then file the appeal once two weeks have
   passed, using the section above.
10. **Decide how to describe the AI-assisted development.** The terms prohibit
    low-quality, untested or not-understood LLM-generated contributions. This
    repository was built with an AI coding assistant under your direction. The
    work stands up — 115 tests against a real compiled extension, level-max
    static analysis, deliberate FFI-safety design — but be ready to explain
    and maintain it yourself, and decide whether to say so in the appeal.

## Issues for the Wave

Issues can only be added from an **approved** repo, so this list matters only
after the appeal succeeds. Issues added from GitHub default to **Trivial**;
set the complexity in the app. Points: Trivial 100, Medium 150, High 200.

Existing:

| Issue | Suggested | Note |
|---|---|---|
| [#5](https://github.com/sorocharge/sorocharge-php/issues/5) wrap sorocharge-x402 and sorocharge-mpp | High | Large scope expansion; two new crates bound |
| [#3](https://github.com/sorocharge/sorocharge-php/issues/3) `cargo php stubs` emits broken output | Medium | Needs a cargo-php fix or a generator of our own |
| [#2](https://github.com/sorocharge/sorocharge-php/issues/2) Symfony integration example | Medium | Mirrors the Laravel example |
| [#1](https://github.com/sorocharge/sorocharge-php/issues/1) CONTRIBUTING and SECURITY | — | **Close it; done** |
| [#4](https://github.com/sorocharge/sorocharge-php/issues/4) surface authorization expiry | — | Keep out: blocked on sorocharge-core#8 |

Opened 2026-11-04, sized from Trivial to High so there is work at every
level:

| Issue | Suggested | Why it is real |
|---|---|---|
| [#8](https://github.com/sorocharge/sorocharge-php/issues/8) Windows support | High | `.cargo/config.toml` only handles the macOS linker, nothing builds Windows, and `abi_vectorcall` may force a nightly toolchain |
| [#9](https://github.com/sorocharge/sorocharge-php/issues/9) a reusable action that installs the extension from a release | High | Turns the new prebuilt binaries into one line of someone else's CI; resolving the right asset per PHP minor is the real work |
| [#11](https://github.com/sorocharge/sorocharge-php/issues/11) end-to-end x402 facilitator that really simulates and submits | Medium | `verify-transfer-effects.php` replays recorded events; nothing shows the live loop |
| [#10](https://github.com/sorocharge/sorocharge-php/issues/10) type the fixtures so the suite passes PHPStan at max | Medium | `Fixtures::load()` returns `mixed`, which is why tests sit outside the analysed paths |
| [#12](https://github.com/sorocharge/sorocharge-php/issues/12) publish `docs/` as a GitHub Pages site | Medium | `docs/` is markdown only; the homepage points at a directory for want of a site |
| [#13](https://github.com/sorocharge/sorocharge-php/issues/13) verify `install.sh` on Debian, Fedora and Alpine | Medium | Only proven on macOS and Ubuntu; BusyBox `sort -V` and musl are likely breakages |
| [#14](https://github.com/sorocharge/sorocharge-php/issues/14) add Fedora and Alpine package hints to `install.sh` | Trivial | `good first issue`: the libclang branch names a package those users do not have |

Set complexity honestly; the docs ask for it to match real scope. Do not pad
the list with invented Trivial issues.

## Honest notes

- **The rejection reason is unknown.** Everything above is inference from the
  repository's state against the published criteria. If the review did give a
  reason anywhere — email, in-app notice — read it first and let it override
  this page.
- **The niche is not the problem.** `winsznx/routedock`, described as a
  "unified payment execution layer for Stellar x402, MPP charge, MPP session",
  is already approved in this program. Overlapping with it is not
  disqualifying.
- **838 repositories are approved** in the Stellar Wave. This is a crowded
  program, not a scarce one, which makes an unexplained rejection more likely
  to be about repo quality signals than about the idea.
- **Zero stars and zero forks.** Nothing above changes that, and it is the one
  signal that cannot be manufactured honestly. Publishing to Packagist and
  cutting a real release are the levers that exist.
- **Do not claim the tool is unique.** It is a thin, careful binding of one
  signing engine. That is a real and defensible thing to be; it is not a
  category of one.
