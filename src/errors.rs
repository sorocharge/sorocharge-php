//! The PHP exception hierarchy, and the two ways a Rust failure becomes one:
//! [`to_php`] maps every `SorochargeError` variant to its own exception class,
//! and [`guard`] turns a caught panic into [`InternalErrorException`] so no
//! unwind ever reaches the Zend engine.

use std::any::Any;
use std::panic::{catch_unwind, AssertUnwindSafe};

use ext_php_rs::builders::ModuleBuilder;
use ext_php_rs::exception::{PhpException, PhpResult};
use ext_php_rs::prelude::*;
use ext_php_rs::zend::{ce, ClassEntry};
use sorocharge_signer::SorochargeError;

/// Base class of every exception that reports a `sorocharge-core` failure.
/// Catch this to handle any of them; catch a subclass to handle one.
#[php_class]
#[php(name = "Sorocharge\\SorochargeException")]
#[php(extends(ce = ce::exception, stub = "\\Exception"))]
#[derive(Default)]
pub struct SorochargeException;

macro_rules! sorocharge_exceptions {
    ($($(#[$doc:meta])* $rust:ident => $php:literal;)+) => {
        $(
            $(#[$doc])*
            #[php_class]
            #[php(name = $php)]
            #[php(extends(SorochargeException))]
            #[derive(Default)]
            pub struct $rust;
        )+

        /// Registers the base class and every `SorochargeException` subclass.
        pub fn register(module: ModuleBuilder) -> ModuleBuilder {
            module
                .class::<SorochargeException>()
                $(.class::<$rust>())+
        }
    };
}

sorocharge_exceptions! {
    /// A strkey (`G...`, `C...`, `M...`) could not be decoded into an address.
    InvalidAddressException => "Sorocharge\\InvalidAddressException";
    /// The entry's credential shape is not one this library signs or verifies.
    UnsupportedCredentialTypeException => "Sorocharge\\UnsupportedCredentialTypeException";
    /// A delegated credential was requested with no delegate signers.
    EmptyDelegateSignersException => "Sorocharge\\EmptyDelegateSignersException";
    /// A delegated credential listed the same signer more than once.
    DuplicateDelegateSignerException => "Sorocharge\\DuplicateDelegateSignerException";
    /// The entry's expiry ledger is at or before the current ledger.
    ExpiredEntryException => "Sorocharge\\ExpiredEntryException";
    /// The entry does not authorize a single SEP-41 `transfer(from, to, amount)`.
    UnexpectedInvocationShapeException => "Sorocharge\\UnexpectedInvocationShapeException";
    /// The entry's asset contract differs from the expected charge.
    AssetMismatchException => "Sorocharge\\AssetMismatchException";
    /// The entry's authorizing address differs from the expected payer.
    PayerMismatchException => "Sorocharge\\PayerMismatchException";
    /// The entry's amount differs from the expected charge.
    AmountMismatchException => "Sorocharge\\AmountMismatchException";
    /// The entry's recipient differs from the expected charge.
    RecipientMismatchException => "Sorocharge\\RecipientMismatchException";
    /// No signature on the entry verifies against its reconstructed preimage.
    InvalidSignatureException => "Sorocharge\\InvalidSignatureException";
    /// The signer's address matches no credential node in the entry.
    NoMatchingCredentialNodeException => "Sorocharge\\NoMatchingCredentialNodeException";
    /// The signing callback failed or returned something other than 64 bytes.
    SigningFailedException => "Sorocharge\\SigningFailedException";
    /// Building or (de)serializing XDR failed.
    XdrEncodingFailedException => "Sorocharge\\XdrEncodingFailedException";
    /// A Rust panic was caught inside the extension. This is always a bug in
    /// sorocharge, never a property of the input; please report it.
    InternalErrorException => "Sorocharge\\InternalErrorException";
}

/// Maps a core error to its exception class. The match is exhaustive with no
/// wildcard arm, so a new `SorochargeError` variant fails to compile here
/// instead of silently collapsing into a generic exception.
pub fn to_php(err: SorochargeError) -> PhpException {
    let message = err.to_string();
    match err {
        SorochargeError::InvalidAddress { .. } => {
            PhpException::from_class::<InvalidAddressException>(message)
        }
        SorochargeError::UnsupportedCredentialType => {
            PhpException::from_class::<UnsupportedCredentialTypeException>(message)
        }
        SorochargeError::EmptyDelegateSigners => {
            PhpException::from_class::<EmptyDelegateSignersException>(message)
        }
        SorochargeError::DuplicateDelegateSigner => {
            PhpException::from_class::<DuplicateDelegateSignerException>(message)
        }
        SorochargeError::ExpiredEntry { .. } => {
            PhpException::from_class::<ExpiredEntryException>(message)
        }
        SorochargeError::UnexpectedInvocationShape => {
            PhpException::from_class::<UnexpectedInvocationShapeException>(message)
        }
        SorochargeError::AssetMismatch => {
            PhpException::from_class::<AssetMismatchException>(message)
        }
        SorochargeError::PayerMismatch => {
            PhpException::from_class::<PayerMismatchException>(message)
        }
        SorochargeError::AmountMismatch => {
            PhpException::from_class::<AmountMismatchException>(message)
        }
        SorochargeError::RecipientMismatch => {
            PhpException::from_class::<RecipientMismatchException>(message)
        }
        SorochargeError::InvalidSignature => {
            PhpException::from_class::<InvalidSignatureException>(message)
        }
        SorochargeError::NoMatchingCredentialNode => {
            PhpException::from_class::<NoMatchingCredentialNodeException>(message)
        }
        SorochargeError::SigningFailed { .. } => {
            PhpException::from_class::<SigningFailedException>(message)
        }
        SorochargeError::XdrEncodingFailed { .. } => {
            PhpException::from_class::<XdrEncodingFailedException>(message)
        }
    }
}

/// Builds SPL's `\InvalidArgumentException` for input this layer rejects
/// before reaching `sorocharge-core`: a malformed amount, an out-of-range
/// ledger, an unknown credential kind, or delegates where none are allowed.
/// That is a caller's programming error, not a payment-check failure, so it
/// stays outside the `SorochargeException` hierarchy.
///
/// The class is resolved per call because the executor's class table, which
/// the lookup reads, is not populated during module startup. SPL cannot be
/// disabled in PHP 8, so the lookup failing means a broken engine; that is
/// reported as an internal error rather than swapped for another class.
pub fn invalid_argument(message: impl Into<String>) -> PhpException {
    let message = message.into();
    match ClassEntry::try_find("InvalidArgumentException") {
        Some(class) => PhpException::new(message, 0, class),
        None => PhpException::from_class::<InternalErrorException>(format!(
            "internal error: \\InvalidArgumentException is not registered (while rejecting: {message})"
        )),
    }
}

/// Builds [`InternalErrorException`] for a failure that is a bug in this
/// extension or the engine, never a property of the caller's input.
pub fn internal_error(detail: &str) -> PhpException {
    PhpException::from_class::<InternalErrorException>(format!("internal error: {detail}"))
}

/// Runs a binding body, converting a panic into [`InternalErrorException`].
///
/// ext-php-rs 0.16 already stops panics at the handler boundary, but turns
/// them into a bare `\Error` that `catch (SorochargeException)` misses. Every
/// exported function wraps its body in this so the panic surfaces inside the
/// documented hierarchy; the ext-php-rs handler remains a second line.
pub fn guard<T>(body: impl FnOnce() -> PhpResult<T>) -> PhpResult<T> {
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
