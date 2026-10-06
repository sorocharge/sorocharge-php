//! Verification: the native function behind `Sorocharge::verifyEntry`.

use ext_php_rs::prelude::*;

use crate::charge_params::{parse_ledger, ChargeParams};
use crate::errors::{guard, invalid_argument, to_php, BindResult};
use crate::signer::SignedEntry;

/// Native implementation of `Sorocharge::verifyEntry`: returns normally only
/// if every one of core's checks passes, otherwise throws the exception for
/// the first one that fails.
#[php_function]
#[php(name = "Sorocharge\\Native\\verify_entry")]
pub fn verify_entry(
    entry: &SignedEntry,
    expected: &ChargeParams,
    current_ledger: i64,
    network_passphrase: &str,
) -> BindResult<()> {
    guard(|| {
        if network_passphrase.is_empty() {
            return Err(invalid_argument("networkPassphrase must not be empty"));
        }
        let current_ledger = parse_ledger("currentLedger", current_ledger)?;
        sorocharge_signer::verify_entry(
            &entry.inner,
            &expected.inner,
            current_ledger,
            network_passphrase,
        )
        .map_err(to_php)
    })
}

pub fn register(module: ModuleBuilder) -> ModuleBuilder {
    module.function(wrap_function!(verify_entry))
}
