# Heista Address Check

PlentyONE plugin that validates and corrects order delivery addresses through
the Heista platform. Tagged orders are submitted to Heista when they are
created; the corrected address is written back to the order, either through a
webhook callback (fast path) or a periodic cron poll (fallback).

## How it works

1. An event procedure submits the order's delivery address to Heista on order
   creation. Attach `Validate address via Heista SaaS` to the orders you want
   checked (for example via an order-status or tag condition).
2. Heista processes the address and reports the result back two ways:
   - **Webhook (fast path):** a callback to `POST /address-check/callback`,
     authenticated with a per-job token the plugin derives from the API key
     (the merchant configures no separate secret).
   - **Cron (fallback):** a job that runs every five minutes and polls for any
     results the webhook did not deliver.
3. When a correction comes back, the plugin updates the delivery address, sets
   the configured order status for the outcome, and stores the original address
   as an internal order comment so it can be reviewed or reverted.

## Requirements

- PlentyONE
- A Heista account with an API key

## Configuration

Settings live in the plugin configuration of the plugin set.

**Connection**

- `environment` – production or development.
- `apiKey` – your Heista API key. Also used to derive the per-job token that
  authenticates the inbound webhook — there is no separate callback secret.

The webhook callback URL is **auto-derived** from the PlentyONE system URL
(`https://p{plentyId}.my.plentysystems.com/rest/heista/address-check/callback`)
and needs no configuration. If the platform can't push (e.g. the system URL is
unreachable), the cron-poll fallback still applies corrections.

**Status mapping**

- `statusOnVerified`, `statusOnCorrected`, `statusOnReviewSuggested`,
  `statusOnUndeliverable`, `statusOnPostnumberInvalid`, `statusOnEmailRequired`,
  `statusOnError` – order status IDs to set per outcome. Leave empty to keep the
  current status. See the outcome table below for what each means and the
  recommended next step.
- `commentAuthorUserId` – PlentyONE user ID under which the result comment is
  created. Leave empty to skip the comment.

| Outcome | Address applied? | Meaning | Next step |
|---|---|---|---|
| `verified` | yes (unchanged) | Confirmed, nothing changed | none, ship |
| `corrected` | yes | Fields changed + confirmed | optional spot-check (original kept in comment) |
| `review_suggested` | yes | Cleaned, but not fully confirmed | glance before shipping |
| `undeliverable` | no | Address not found — or not confirmable. Includes `reason: address_conflict`: the postal code / city could not be confirmed and the same street was found in a *different* postal area, so the address was deliberately **not** changed and the alternative is posted as an **Adress-Vorschlag** in the order comment | contact customer / fix manually |
| `postnumber_invalid` | no | Packstation/Postfiliale post number invalid | request a valid post number |
| `email_required` | no | Carrier needs an email and none is present (e.g. DPD) | request an email from the customer |
| `error` | no | Check failed (no result / timeout) | retry — not the customer's fault |
| `error` (phone) | yes | The address passed, but DHL would refuse the label for the phone number (see below) | fix the phone number in the delivery address |

Email source for the DPD check: the delivery address email (`AddressOption::TYPE_EMAIL`), falling back to the order's billing address email.

**Phone number (DHL only, since 1.7.0)**

DHL refuses a shipping label when the recipient phone contains letters or
symbols such as `*` or `#`, or runs past 20 characters. For orders whose
shipping profile is listed in `dhlProfileIds`, the plugin sends the phone along
and Heista checks it against that rule. Orders on any other or unmapped profile
are not checked.

- Source: the delivery address phone (`AddressOption::TYPE_TELEPHONE`), else the
  billing address phone, else the receiving contact's phone.
- A number DHL accepts as written is left alone.
- Whether Heista repairs a number DHL would refuse depends on the booked check
  model. With DHL validation only, the number is flagged and never changed, so
  it is fixed by hand. The models that include Heista's own correction repair it
  where that is safe.
- A repaired number is rewritten as digits only: separators and stray `*`
  dropped, a leading `+` written as `00`, `(0)` after the country code removed.
  It is written to the **delivery address**, whichever record it came from, and
  the order comment shows the old and the new value.
- A number that is not repaired (the model does not repair, or fixing it would
  mean guessing: an extension, `Tel.`, two numbers in one field) is left
  unchanged. The order goes to `statusOnError`
  when the address result would otherwise have let it ship (`verified`,
  `corrected`, `review_suggested`). Other outcomes keep their own status, and
  the comment names the phone problem either way.
- The phone has no effect on the address result or on what the check costs.

**Order handling**

- `skipSalesOrdersWithDeliveryOrders` – off by default. Turn it on when the
  address check runs on the delivery order (Lieferauftrag). A sales order that
  already has delivery orders is then not checked, and its status is not set, so
  it stays driven by "lowest status of all delivery orders" and the address is
  checked once instead of twice. Delivery orders themselves are never affected.

  Leave it off if the sales order is the one that needs the corrected address
  and the status. The two halves work at different moments: the check is skipped
  only when the delivery orders already exist at submit time, and when one
  appears while the check is running, the address and the comment are still
  written and only the status write is dropped.

**Shipping mapping**

- `dhlProfileIds`, `dpdProfileIds` – comma-separated shipping-profile IDs per
  carrier, used to pick a carrier-specific correction.

## License

Proprietary. See [LICENSE.md](LICENSE.md).

For inquiries: support@heista.de
