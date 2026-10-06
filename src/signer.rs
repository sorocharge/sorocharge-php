//! Building and signing: `Sorocharge\UnsignedEntry`, `Sorocharge\SignedEntry`,
//! and the native functions behind `Sorocharge::buildChargeEntry` and
//! `Sorocharge::signEntry`.

use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;
use sorocharge_signer::{CredentialKind, SorochargeError};
use stellar_xdr::{Limits, ReadXdr, SorobanAuthorizationEntry, WriteXdr};

use crate::charge_params::{parse_address, ChargeParams};
use crate::errors::{guard, invalid_argument, to_php};

/// A charge authorization entry built by `Sorocharge::buildChargeEntry`, not
/// yet signed. Only obtainable from that call; PHP cannot construct one.
#[php_class]
#[php(name = "Sorocharge\\UnsignedEntry")]
#[php(flags = ClassFlags::Final)]
pub struct UnsignedEntry {
    pub(crate) inner: sorocharge_signer::UnsignedEntry,
}

#[php_impl]
impl UnsignedEntry {
    /// The entry as base64 `SorobanAuthorizationEntry` XDR, with the
    /// signature placeholder still empty.
    ///
    /// @throws XdrEncodingFailedException
    pub fn to_xdr(&self) -> PhpResult<String> {
        guard(|| xdr_base64(self.inner.as_xdr()))
    }
}

pub(crate) fn xdr_base64(entry: &stellar_xdr::SorobanAuthorizationEntry) -> PhpResult<String> {
    entry.to_xdr_base64(Limits::none()).map_err(|e| {
        to_php(SorochargeError::XdrEncodingFailed {
            reason: e.to_string(),
        })
    })
}

/// Native implementation of `Sorocharge::buildChargeEntry`.
///
/// `$delegates` must be non-empty for `'delegated'` (core rejects an empty
/// list with `EmptyDelegateSignersException`) and must be empty for every
/// other kind: an ignored argument is rejected, never silently dropped.
#[php_function]
#[php(name = "Sorocharge\\Native\\build_charge_entry")]
pub fn build_charge_entry(
    params: &ChargeParams,
    credential_kind: &str,
    delegates: &ZendHashTable,
) -> PhpResult<UnsignedEntry> {
    guard(|| {
        let credential = match credential_kind {
            "legacy" | "v2" if !delegates.is_empty() => {
                return Err(invalid_argument(format!(
                    "delegates are only accepted with credential kind 'delegated', \
                     not '{credential_kind}'"
                )));
            }
            "legacy" => CredentialKind::Legacy,
            "v2" => CredentialKind::AddressV2,
            "delegated" => CredentialKind::Delegated {
                signers: delegates
                    .values()
                    .map(|zval| {
                        zval.str().map_or_else(
                            || {
                                Err(invalid_argument(
                                    "delegates must contain only address strings",
                                ))
                            },
                            parse_address,
                        )
                    })
                    .collect::<PhpResult<_>>()?,
            },
            other => {
                return Err(invalid_argument(format!(
                    "credential kind must be 'legacy', 'v2', or 'delegated'; got {other:?}"
                )));
            }
        };
        sorocharge_signer::build_charge_entry(&params.inner, credential)
            .map(|inner| UnsignedEntry { inner })
            .map_err(to_php)
    })
}

/// A charge authorization entry carrying a signature, from
/// `Sorocharge::signEntry` or decoded from the wire with `fromXdr`.
#[php_class]
#[php(name = "Sorocharge\\SignedEntry")]
#[php(flags = ClassFlags::Final)]
pub struct SignedEntry {
    pub(crate) inner: sorocharge_signer::SignedEntry,
}

#[php_impl]
impl SignedEntry {
    /// The entry as base64 `SorobanAuthorizationEntry` XDR, ready to attach
    /// to an `InvokeHostFunction` operation or send to a facilitator.
    ///
    /// @throws XdrEncodingFailedException
    pub fn to_xdr(&self) -> PhpResult<String> {
        guard(|| xdr_base64(self.inner.as_xdr()))
    }

    /// Decodes a signed entry received over the wire (base64
    /// `SorobanAuthorizationEntry` XDR) so it can be passed to
    /// `Sorocharge::verifyEntry`.
    ///
    /// Decoding proves nothing about the entry: it is not checked for a
    /// signature, let alone a valid one. Only `verifyEntry` does that.
    ///
    /// @throws XdrEncodingFailedException if the input is not valid XDR.
    pub fn from_xdr(xdr: &str) -> PhpResult<Self> {
        guard(|| {
            SorobanAuthorizationEntry::from_xdr_base64(xdr, Limits::len(xdr.len()))
                .map(|entry| Self {
                    inner: sorocharge_signer::SignedEntry::from_xdr(entry),
                })
                .map_err(|e| {
                    to_php(SorochargeError::XdrEncodingFailed {
                        reason: format!("not a base64 SorobanAuthorizationEntry: {e}"),
                    })
                })
        })
    }
}

pub fn register(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<UnsignedEntry>()
        .class::<SignedEntry>()
        .function(wrap_function!(build_charge_entry))
}
