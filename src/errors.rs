//! How a Rust failure becomes a PHP exception.
//!
//! The exception classes themselves are plain PHP, in `php/src/`, so callers
//! can construct, extend, and mock them like any other exception (classes a
//! native extension registers cannot be instantiated from PHP). This module
//! only decides *which* class: [`to_php`] maps every `SorochargeError`
//! variant to its own subclass, and [`guard`] turns a caught panic into
//! `InternalErrorException` so no unwind ever reaches the Zend engine.
//!
//! A binding returns a [`Failure`], which names the class but does not look
//! it up. ext-php-rs converts it into a `PhpException` after the binding has
//! returned, so the lookup (which may run the Composer autoloader) never
//! executes while this crate's own Rust values are still on the stack.

use std::any::Any;
use std::panic::{catch_unwind, AssertUnwindSafe};

use ext_php_rs::exception::PhpException;
use ext_php_rs::zend::{ce, ClassEntry, ExecutorGlobals};
use sorocharge_signer::SorochargeError;

/// The result type every binding returns.
pub type BindResult<T> = Result<T, Failure>;

/// A failure on its way to PHP: the exception class to throw, and its message.
#[derive(Debug)]
pub struct Failure {
    class: &'static str,
    message: String,
}

impl Failure {
    fn new(class: &'static str, message: impl Into<String>) -> Self {
        Self {
            class,
            message: message.into(),
        }
    }
}

impl From<Failure> for PhpException {
    fn from(failure: Failure) -> Self {
        // An exception the PHP signing closure threw is still pending. It
        // propagates unchanged, and throwing over it is a no-op, so skip the
        // lookup rather than run an autoloader with an exception in flight.
        if ExecutorGlobals::pending_exception_class().is_some() {
            return PhpException::from_message(failure.message);
        }
        match ClassEntry::try_find(failure.class) {
            Some(class) if class.instance_of(ce::throwable()) => {
                PhpException::new(failure.message, 0, class)
            }
            _ => PhpException::new(
                format!(
                    "{} (and the exception class {} could not be loaded: install the \
                     sorocharge/sorocharge Composer package and include its autoloader)",
                    failure.message, failure.class
                ),
                0,
                ce::error(),
            ),
        }
    }
}

/// Maps a core error to its exception class. The match is exhaustive with no
/// wildcard arm, so a new `SorochargeError` variant fails to compile here
/// instead of silently collapsing into a generic exception.
pub fn to_php(err: SorochargeError) -> Failure {
    let class = match &err {
        SorochargeError::InvalidAddress { .. } => "Sorocharge\\InvalidAddressException",
        SorochargeError::UnsupportedCredentialType => {
            "Sorocharge\\UnsupportedCredentialTypeException"
        }
        SorochargeError::EmptyDelegateSigners => "Sorocharge\\EmptyDelegateSignersException",
        SorochargeError::DuplicateDelegateSigner => "Sorocharge\\DuplicateDelegateSignerException",
        SorochargeError::ExpiredEntry { .. } => "Sorocharge\\ExpiredEntryException",
        SorochargeError::ExpirationExceedsAllowance { .. } => {
            "Sorocharge\\ExpirationExceedsAllowanceException"
        }
        SorochargeError::UnexpectedInvocationShape => {
            "Sorocharge\\UnexpectedInvocationShapeException"
        }
        SorochargeError::AssetMismatch => "Sorocharge\\AssetMismatchException",
        SorochargeError::PayerMismatch => "Sorocharge\\PayerMismatchException",
        SorochargeError::AmountMismatch => "Sorocharge\\AmountMismatchException",
        SorochargeError::RecipientMismatch => "Sorocharge\\RecipientMismatchException",
        SorochargeError::InvalidSignature => "Sorocharge\\InvalidSignatureException",
        SorochargeError::NoMatchingCredentialNode => {
            "Sorocharge\\NoMatchingCredentialNodeException"
        }
        // Raised only by verify_transfer_effects, which this binding does not
        // expose (it is facilitator-side). Mapped anyway: no variant may fall
        // through to a generic exception.
        SorochargeError::SimulationEventsMalformed { .. } => {
            "Sorocharge\\SimulationEventsMalformedException"
        }
        SorochargeError::UnexpectedBalanceChange { .. } => {
            "Sorocharge\\UnexpectedBalanceChangeException"
        }
        SorochargeError::ExpectedTransferMissing => "Sorocharge\\ExpectedTransferMissingException",
        SorochargeError::SigningFailed { .. } => "Sorocharge\\SigningFailedException",
        SorochargeError::XdrEncodingFailed { .. } => "Sorocharge\\XdrEncodingFailedException",
    };
    Failure::new(class, err.to_string())
}

/// SPL's `\InvalidArgumentException`, for input this layer rejects before
/// reaching `sorocharge-core`: a malformed amount, an out-of-range ledger, an
/// unknown credential kind, or delegates where none are allowed. That is a
/// caller's programming error, not a payment-check failure, so it stays
/// outside the `SorochargeException` hierarchy.
pub fn invalid_argument(message: impl Into<String>) -> Failure {
    Failure::new("InvalidArgumentException", message)
}

/// `InternalErrorException`, for a failure that is a bug in this extension or
/// the engine, never a property of the caller's input.
pub fn internal_error(detail: &str) -> Failure {
    Failure::new(
        "Sorocharge\\InternalErrorException",
        format!("internal error: {detail}"),
    )
}

/// Runs a binding body, converting a panic into `InternalErrorException`.
///
/// ext-php-rs 0.16 already stops panics at the handler boundary, but turns
/// them into a bare `\Error` that `catch (SorochargeException)` misses. Every
/// exported function wraps its body in this so the panic surfaces inside the
/// documented hierarchy; the ext-php-rs handler remains a second line.
pub fn guard<T>(body: impl FnOnce() -> BindResult<T>) -> BindResult<T> {
    catch_unwind(AssertUnwindSafe(body)).unwrap_or_else(|payload| {
        Err(internal_error(&format!(
            "Rust panic: {}",
            panic_message(payload.as_ref())
        )))
    })
}

fn panic_message(payload: &(dyn Any + Send)) -> &str {
    payload
        .downcast_ref::<&str>()
        .copied()
        .or_else(|| payload.downcast_ref::<String>().map(String::as_str))
        .unwrap_or("non-string panic payload")
}
