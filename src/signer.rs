//! Building and signing: `Sorocharge\UnsignedEntry`, `Sorocharge\SignedEntry`,
//! and the native functions behind `Sorocharge::buildChargeEntry` and
//! `Sorocharge::signEntry`.

use std::panic::AssertUnwindSafe;
use std::sync::Mutex;

use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendCallable, ZendHashTable, Zval};
use ext_php_rs::zend::{bailout, try_catch, CatchError};
use sorocharge_signer::{Address, CredentialKind, Signer, SorochargeError};
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

/// Records the preimage core asks it to sign and returns a placeholder
/// signature. Pass one of `sign_entry`: it exists only to learn the exact
/// bytes core will sign, so the PHP closure is never called from inside
/// core's stack frames.
struct CapturePreimage {
    address: Address,
    preimage: Mutex<Option<Vec<u8>>>,
}

impl Signer for CapturePreimage {
    fn sign_preimage(&self, preimage: &[u8]) -> Result<[u8; 64], SorochargeError> {
        let mut slot = self
            .preimage
            .lock()
            .map_err(|_| SorochargeError::SigningFailed {
                reason: "preimage capture lock poisoned".to_string(),
            })?;
        *slot = Some(preimage.to_vec());
        Ok([0; 64])
    }

    fn address(&self) -> Address {
        self.address.clone()
    }
}

/// Returns a signature the PHP closure already produced. Pass two of
/// `sign_entry`: the signing payload is a pure function of the entry and the
/// network passphrase, so it is byte-identical to the one pass one captured.
struct FixedSignature {
    address: Address,
    preimage: Vec<u8>,
    signature: [u8; 64],
}

impl Signer for FixedSignature {
    fn sign_preimage(&self, preimage: &[u8]) -> Result<[u8; 64], SorochargeError> {
        // Fails closed if core ever derives a different payload between the
        // two passes, rather than attaching a signature over other bytes.
        if preimage != self.preimage.as_slice() {
            return Err(SorochargeError::SigningFailed {
                reason: "signing preimage changed between passes".to_string(),
            });
        }
        Ok(self.signature)
    }

    fn address(&self) -> Address {
        self.address.clone()
    }
}

/// Native implementation of `Sorocharge::signEntry`.
///
/// `$signPreimage` receives the raw 32-byte preimage as a binary string and
/// must return the raw 64-byte ed25519 signature. If it throws, that
/// exception reaches the caller unchanged. If it causes a fatal error, the
/// bailout is resumed once this function's own values are dropped, so the
/// script still stops exactly as it would without the extension.
#[php_function]
#[php(name = "Sorocharge\\Native\\sign_entry")]
pub fn sign_entry(
    entry: &UnsignedEntry,
    sign_preimage: &Zval,
    public_address: &str,
    network_passphrase: &str,
) -> PhpResult<SignedEntry> {
    let outcome = guard(|| {
        if network_passphrase.is_empty() {
            return Err(invalid_argument("networkPassphrase must not be empty"));
        }
        let callable = ZendCallable::new(sign_preimage)
            .map_err(|_| invalid_argument("signPreimage must be callable"))?;
        let address = parse_address(public_address)?;

        let capture = CapturePreimage {
            address: address.clone(),
            preimage: Mutex::new(None),
        };
        sorocharge_signer::sign_entry(entry.inner.clone(), &capture, network_passphrase)
            .map_err(to_php)?;
        let preimage = capture
            .preimage
            .into_inner()
            .ok()
            .flatten()
            .ok_or_else(|| {
                to_php(SorochargeError::SigningFailed {
                    reason: "core did not request a signature".to_string(),
                })
            })?;

        let signature = match call_signer(&callable, &preimage) {
            CallOutcome::Signature(signature) => signature,
            CallOutcome::Failed(err) => return Err(err),
            CallOutcome::Bailout => return Ok(None),
        };

        let fixed = FixedSignature {
            address,
            preimage,
            signature,
        };
        sorocharge_signer::sign_entry(entry.inner.clone(), &fixed, network_passphrase)
            .map(|inner| Some(SignedEntry { inner }))
            .map_err(to_php)
    });
    match outcome {
        Ok(Some(signed)) => Ok(signed),
        Err(err) => Err(err),
        // Every Rust value this call created has been dropped by now; only
        // frames without destructors remain between here and the engine's
        // catch point.
        // SAFETY: resuming a bailout the engine started inside the closure.
        Ok(None) => unsafe { bailout() },
    }
}

enum CallOutcome {
    Signature([u8; 64]),
    Failed(PhpException),
    Bailout,
}

/// Calls the PHP signing closure inside its own `try_catch`, so a bailout
/// lands here instead of jumping over Rust frames that own heap values.
fn call_signer(callable: &ZendCallable, preimage: &[u8]) -> CallOutcome {
    let mut arg = Zval::new();
    arg.set_binary(preimage.to_vec());
    let result = try_catch(AssertUnwindSafe(|| {
        callable
            .try_call(vec![&arg])
            .map(|zval| zval.is_string().then(|| zval.binary::<u8>()).flatten())
    }));
    let signing_failed =
        |reason: String| CallOutcome::Failed(to_php(SorochargeError::SigningFailed { reason }));
    match result {
        Err(CatchError::Bailout) => CallOutcome::Bailout,
        Err(err) => CallOutcome::Failed(crate::errors::internal_error(&err.to_string())),
        // An exception thrown by the closure stays pending in the engine;
        // throwing ours is then a no-op, so the caller sees the original.
        Ok(Err(err)) => signing_failed(format!("signPreimage callback failed: {err}")),
        Ok(Ok(None)) => signing_failed("signPreimage must return a string".to_string()),
        Ok(Ok(Some(bytes))) => match <[u8; 64]>::try_from(bytes.as_slice()) {
            Ok(signature) => CallOutcome::Signature(signature),
            Err(_) => signing_failed(format!(
                "signPreimage must return a 64-byte ed25519 signature, got {} bytes",
                bytes.len()
            )),
        },
    }
}

pub fn register(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<UnsignedEntry>()
        .class::<SignedEntry>()
        .function(wrap_function!(build_charge_entry))
        .function(wrap_function!(sign_entry))
}
