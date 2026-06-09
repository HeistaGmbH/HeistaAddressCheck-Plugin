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
     authenticated with a shared secret.
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
- `apiKey` – your Heista API key.
- `callbackSecret` – shared secret used to authenticate the inbound webhook.
- `pluginCallbackBaseUrl` – public HTTPS base URL of the shop, required for the
  webhook fast path.

**Status mapping**

- `statusOnVerified`, `statusOnCorrected`, `statusOnReviewSuggested`,
  `statusOnUndeliverable`, `statusOnPostnumberInvalid`, `statusOnError` – order
  status IDs to set per outcome. Leave empty to keep the current status. See the
  outcome table below for what each means and the recommended next step.
- `commentAuthorUserId` – PlentyONE user ID under which the result comment is
  created. Leave empty to skip the comment.

| Outcome | Address applied? | Meaning | Next step |
|---|---|---|---|
| `verified` | yes (unchanged) | Google-confirmed, nothing changed | none, ship |
| `corrected` | yes | Fields changed + Google-confirmed | optional spot-check (original kept in comment) |
| `review_suggested` | yes | Cleaned, street not confirmed | glance before shipping |
| `undeliverable` | no | Address not found | contact customer / fix manually |
| `postnumber_invalid` | no | Packstation/Postfiliale post number invalid | request a valid post number |
| `error` | no | Check failed (no result / timeout) | retry — not the customer's fault |

**Shipping mapping**

- `dhlProfileIds`, `dpdProfileIds` – comma-separated shipping-profile IDs per
  carrier, used to pick a carrier-specific correction.

## License

Proprietary. See [LICENSE.md](LICENSE.md).

For inquiries: support@heista.de
