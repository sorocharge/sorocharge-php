//! `sorocharge`: a native PHP extension exposing `sorocharge-core`'s Soroban
//! charge-entry builder, signer, and verifier.
//!
//! This crate is the ext-php-rs binding layer only. Every byte of XDR and
//! every signature check comes from `sorocharge-signer`; nothing here
//! re-implements signing, preimage construction, or verification.

#![cfg_attr(windows, feature(abi_vectorcall))]

use ext_php_rs::prelude::*;

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}
