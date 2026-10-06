//! `Sorocharge\ChargeParams`: the PHP face of `sorocharge_signer::ChargeParams`.

use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use sorocharge_signer::{Address, SorochargeError};
use stellar_xdr::ScAddress;

use crate::errors::{guard, invalid_argument, to_php};

/// One SEP-41 transfer to build, sign, or verify an authorization entry for.
///
/// Immutable once constructed: every field is validated in the constructor
/// and exposed through read-only properties.
#[php_class]
#[php(name = "Sorocharge\\ChargeParams")]
#[php(flags = ClassFlags::Final)]
pub struct ChargeParams {
    pub(crate) inner: sorocharge_signer::ChargeParams,
}

#[php_impl]
impl ChargeParams {
    /// @param string $assetContract SEP-41 token contract address (`C...`).
    /// @param string $amount Transfer amount in the asset's base units, as a
    ///     canonical non-negative decimal integer string (e.g. `"10000000"`).
    ///     A string, never an `int`, because base-unit amounts can exceed
    ///     PHP's 64-bit integer range.
    /// @param string $payer Address authorizing the transfer (`G...` or `C...`).
    /// @param string $recipient Address receiving the transfer.
    /// @param int $validUntilLedger Last ledger sequence the authorization is
    ///     valid for (0 to 4294967295).
    /// @throws \InvalidArgumentException for a malformed amount or ledger, or
    ///     an asset that is not a contract address.
    /// @throws InvalidAddressException for an address that is not a valid strkey.
    pub fn __construct(
        asset_contract: &str,
        amount: &str,
        payer: &str,
        recipient: &str,
        valid_until_ledger: i64,
    ) -> PhpResult<Self> {
        guard(|| {
            let asset_contract = parse_address(asset_contract)?;
            if !matches!(asset_contract, ScAddress::Contract(_)) {
                return Err(invalid_argument(
                    "assetContract must be a contract address (C...)",
                ));
            }
            Ok(Self {
                inner: sorocharge_signer::ChargeParams {
                    asset_contract,
                    amount: parse_amount(amount)?,
                    payer: parse_address(payer)?,
                    recipient: parse_address(recipient)?,
                    valid_until_ledger: parse_ledger("validUntilLedger", valid_until_ledger)?,
                },
            })
        })
    }

    /// The SEP-41 token contract address (`C...`).
    #[php(getter)]
    pub fn get_asset_contract(&self) -> String {
        self.inner.asset_contract.to_string()
    }

    /// The amount in base units, as a decimal string.
    #[php(getter)]
    pub fn get_amount(&self) -> String {
        self.inner.amount.to_string()
    }

    /// The address authorizing the transfer.
    #[php(getter)]
    pub fn get_payer(&self) -> String {
        self.inner.payer.to_string()
    }

    /// The address receiving the transfer.
    #[php(getter)]
    pub fn get_recipient(&self) -> String {
        self.inner.recipient.to_string()
    }

    /// The last ledger sequence the authorization is valid for.
    #[php(getter)]
    pub fn get_valid_until_ledger(&self) -> i64 {
        i64::from(self.inner.valid_until_ledger)
    }
}

/// Decodes a strkey with the same `stellar-xdr` parser core's types come from,
/// reporting failure as core's own `InvalidAddress` variant.
pub(crate) fn parse_address(strkey: &str) -> PhpResult<Address> {
    strkey.parse::<ScAddress>().map_err(|_| {
        to_php(SorochargeError::InvalidAddress {
            strkey: strkey.to_string(),
        })
    })
}

/// Accepts only the canonical decimal form of a non-negative `i128`: ASCII
/// digits, no sign, no whitespace, no leading zeros. Rejecting rather than
/// normalizing (`" 10"`, `"+10"`, `"010"`, `"1e3"`, `"10.0"`) keeps a typo in
/// an amount from being silently reinterpreted as a different amount.
fn parse_amount(amount: &str) -> PhpResult<i128> {
    let canonical = !amount.is_empty()
        && amount.bytes().all(|b| b.is_ascii_digit())
        && (amount == "0" || !amount.starts_with('0'));
    if !canonical {
        return Err(invalid_argument(format!(
            "amount must be a non-negative integer string without sign, \
             whitespace, or leading zeros; got {amount:?}"
        )));
    }
    amount
        .parse::<i128>()
        .map_err(|_| invalid_argument(format!("amount {amount} exceeds the i128 range")))
}

/// Narrows a PHP `int` to a ledger sequence number, rejecting anything
/// outside `u32` instead of wrapping.
pub(crate) fn parse_ledger(name: &str, ledger: i64) -> PhpResult<u32> {
    u32::try_from(ledger).map_err(|_| {
        invalid_argument(format!(
            "{name} must be between 0 and {}, got {ledger}",
            u32::MAX
        ))
    })
}
