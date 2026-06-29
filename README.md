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
| `verified` | yes (unchanged) | Google-confirmed, nothing changed | none, ship |
| `corrected` | yes | Fields changed + Google-confirmed | optional spot-check (original kept in comment) |
| `review_suggested` | yes | Cleaned, street not confirmed | glance before shipping |
| `undeliverable` | no | Address not found | contact customer / fix manually |
| `postnumber_invalid` | no | Packstation/Postfiliale post number invalid | request a valid post number |
| `email_required` | no | Carrier needs an email and none is present (e.g. DPD) | request an email from the customer |
| `error` | no | Check failed (no result / timeout) | retry — not the customer's fault |

Email source for the DPD check: the delivery address email (`AddressOption::TYPE_EMAIL`), falling back to the order's billing address email.

**Shipping mapping**

- `dhlProfileIds`, `dpdProfileIds` – comma-separated shipping-profile IDs per
  carrier, used to pick a carrier-specific correction.

## License

Proprietary. See [LICENSE.md](LICENSE.md).

For inquiries: support@heista.de
