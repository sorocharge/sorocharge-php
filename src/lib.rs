//! `sorocharge`: a native PHP extension exposing `sorocharge-core`'s Soroban
//! charge-entry builder, signer, and verifier.
//!
//! This crate is the ext-php-rs binding layer only. Every byte of XDR and
//! every signature check comes from `sorocharge-signer`; nothing here
//! re-implements signing, preimage construction, or verification.

#![cfg_attr(windows, feature(abi_vectorcall))]

mod charge_params;
mod errors;
mod signer;
#[cfg(feature = "test-hooks")]
mod test_hooks;
mod verifier;

use ext_php_rs::prelude::*;

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    let module = module.class::<charge_params::ChargeParams>();
    let module = verifier::register(signer::register(module));
    #[cfg(feature = "test-hooks")]
    let module = test_hooks::register(module);
    module
}
