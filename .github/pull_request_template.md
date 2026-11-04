## What this changes

<!-- What was wrong, and why this fixes it. Link the issue if there is one. -->

## Checklist

- [ ] `cargo fmt --all -- --check` passes
- [ ] `cargo clippy --all-targets --all-features -- -D warnings` passes
- [ ] `composer test` passes against the real compiled extension
- [ ] New or changed behaviour has a test for the happy path **and** a
      thrown-exception path
- [ ] If the extension's surface changed, `php/stubs/sorocharge.stub.php` is
      updated in this PR (`StubsTest` compares them by reflection)
- [ ] If a new `SorochargeError` variant is mapped, it is also in
      `coreErrorVariants()` in `ExceptionHierarchyTest`
- [ ] Any test calling `Sorocharge\Internal\*` carries
      `#[Group('needs-test-hooks')]`
- [ ] `CHANGELOG.md` has an entry under `## [Unreleased]`, if this is
      user-visible
- [ ] No secret, seed phrase or funded key is committed
- [ ] No signing or XDR logic was added in PHP or in this repo's Rust
      (it belongs in `sorocharge-core`)

## Anything reviewers should know

<!-- Trade-offs, things you were unsure about, follow-up work. If this touches
     money handling, say which reading you chose and why. -->
