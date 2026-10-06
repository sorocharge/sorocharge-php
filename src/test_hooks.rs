//! PHP functions that exist only so the integration suite can exercise
//! failure paths no well-formed input reaches. Compiled only with the
//! `test-hooks` feature; a shipped build never contains this module.

use ext_php_rs::prelude::*;
use sorocharge_signer::SorochargeError;

use crate::errors::{guard, invalid_argument, to_php};

/// Panics on purpose, so the suite can prove a Rust panic surfaces as
/// `Sorocharge\InternalErrorException` rather than crashing PHP.
#[php_function]
#[php(name = "Sorocharge\\Internal\\trigger_panic")]
pub fn trigger_panic() -> PhpResult<()> {
    guard(|| panic!("deliberate panic from Sorocharge\\Internal\\trigger_panic"))
}

/// Throws the exception mapped from the named `SorochargeError` variant, so
/// the integration suite can check every mapping, including variants no
/// well-formed PHP input reaches. Only compiled with the `test-hooks` feature.
#[php_function]
#[php(name = "Sorocharge\\Internal\\throw_core_error")]
pub fn throw_core_error(variant: &str) -> PhpResult<()> {
    guard(|| {
        let err = match variant {
            "InvalidAddress" => SorochargeError::InvalidAddress {
                strkey: "GBAD".to_string(),
            },
            "UnsupportedCredentialType" => SorochargeError::UnsupportedCredentialType,
            "EmptyDelegateSigners" => SorochargeError::EmptyDelegateSigners,
            "DuplicateDelegateSigner" => SorochargeError::DuplicateDelegateSigner,
            "ExpiredEntry" => SorochargeError::ExpiredEntry {
                valid_until_ledger: 10,
                current_ledger: 11,
            },
            "UnexpectedInvocationShape" => SorochargeError::UnexpectedInvocationShape,
            "AssetMismatch" => SorochargeError::AssetMismatch,
            "PayerMismatch" => SorochargeError::PayerMismatch,
            "AmountMismatch" => SorochargeError::AmountMismatch,
            "RecipientMismatch" => SorochargeError::RecipientMismatch,
            "InvalidSignature" => SorochargeError::InvalidSignature,
            "NoMatchingCredentialNode" => SorochargeError::NoMatchingCredentialNode,
            "SigningFailed" => SorochargeError::SigningFailed {
                reason: "test".to_string(),
            },
            "XdrEncodingFailed" => SorochargeError::XdrEncodingFailed {
                reason: "test".to_string(),
            },
            other => return Err(invalid_argument(format!("unknown variant: {other}"))),
        };
        Err(to_php(err))
    })
}

pub fn register(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(trigger_panic))
        .function(wrap_function!(throw_core_error))
}
