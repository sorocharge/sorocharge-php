//! Simulation-effect checking: the native function behind
//! `Sorocharge::verifyTransferEffects`.
//!
//! `verifyEntry` proves the payer authorized a charge. It cannot prove that
//! submitting the transaction moves only that charge: the same invocation
//! can emit extra mints, burns, clawbacks, or a second transfer, and a
//! malicious or buggy contract in the call tree is free to do so. Core's
//! `verify_transfer_effects` closes that gap by reading a simulation's
//! diagnostic events and rejecting any balance movement that is not exactly
//! the expected transfer.
//!
//! This matters most to the party that pays the fees and submits: an x402 or
//! MPP facilitator simulates first, and a PHP facilitator previously had no
//! way to run the check without reimplementing event parsing in PHP — the
//! one thing this repo exists to avoid.

use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;

use crate::charge_params::ChargeParams;
use crate::errors::{guard, invalid_argument, to_php, BindResult};

/// Native implementation of `Sorocharge::verifyTransferEffects`.
///
/// `$events` are the base64 `DiagnosticEvent` XDR strings a simulation
/// returned (`diagnosticEventsXdr` from Soroban RPC's `simulateTransaction`
/// or `getTransaction`). The asset, payer, recipient, and amount to require
/// are read from `$expected`; its `validUntilLedger` is not used, because an
/// event stream carries no expiry.
///
/// An empty `$events` list is a failure, not a pass: no events means the
/// expected transfer is absent.
#[php_function]
#[php(name = "Sorocharge\\Native\\verify_transfer_effects")]
pub fn verify_transfer_effects(events: &ZendHashTable, expected: &ChargeParams) -> BindResult<()> {
    guard(|| {
        let events = events
            .values()
            .map(|zval| {
                zval.str().map(str::to_owned).ok_or_else(|| {
                    invalid_argument("events must contain only base64 DiagnosticEvent XDR strings")
                })
            })
            .collect::<BindResult<Vec<String>>>()?;

        sorocharge_signer::verify_transfer_effects(
            &events,
            &expected.inner.asset_contract,
            &expected.inner.payer,
            &expected.inner.recipient,
            expected.inner.amount,
        )
        .map_err(to_php)
    })
}

pub fn register(module: ModuleBuilder) -> ModuleBuilder {
    module.function(wrap_function!(verify_transfer_effects))
}
