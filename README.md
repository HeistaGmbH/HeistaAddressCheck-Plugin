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
- `statusOnB2bReview`, `statusOnB2bMismatch` (since 1.9.0): order status IDs for
  the business customer check, see *Business customer check* below. An empty
  mismatch status falls back to the review status.
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
| `error` | no | Check failed (no result / timeout), the platform refused the submit (API key, balance, DHL credentials), or it stayed unreachable for 24 hours. The comment says which | retry, or fix what the comment names — not the customer's fault |
| `error` (phone) | yes | The address passed, but DHL would refuse the label for the phone number (see below) | fix the phone number in the delivery address |
| business customer review | yes | The address passed, but the recipient was not confirmed or not found, could not be checked, or the order was placed with a private email address (see below) | check by hand as before, the comment names the reason |
| business customer mismatch | yes | The address passed, but something other than the chosen kinds of recipient is listed there | check by hand, the recipient is likely not who the order claims |

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

**When the platform cannot be reached (since 1.8.0)**

A submit that fails because the platform did not answer, answered with a 5xx or
asked the plugin to wait (429) is queued and retried by the five-minute cron:
after 5 minutes, then at doubling intervals up to an hour, and a `Retry-After`
from the platform is honoured as given. The order keeps its status meanwhile,
because nothing is known about the address yet. After 24 hours the plugin
stops, moves the order to `statusOnError` and posts an internal comment naming
the attempts and the last cause, so an unchecked order never looks like a
checked one. Re-running the event action on a queued order submits it at once
instead of opening a second queue entry.

A refusal that no retry can fix (invalid API key, empty wallet, invalid DHL
credentials, a request the platform rejects) goes to `statusOnError`
immediately, with a comment that says what was refused and where to fix it.
Before 1.8.0 both cases set the status and wrote nothing.

**Business customer check (since 1.9.0)**

The same section, written for merchants, is in the plugin's user guide
(`meta/documents/user_guide_de.md`, `user_guide_en.md`).

For merchants who ship to businesses and public bodies (schools, Kitas,
authorities, practices, companies). Two checks run on top of the address check:

- **Check the recipient at the address** ("Empfänger an der Adresse prüfen"):
  the lookup uses the company name and the delivery address, and confirms that a
  recipient of the kind chosen under "Who you deliver to" is listed there (whole
  areas or single kinds). It confirms the kind of recipient, not the company
  name. Without a company name the check is not possible; the comment says so and
  the order goes to review. Person names are never used. €0.025 per checked
  address on top of the plan.
- **Flag private email addresses** ("Private E-Mail-Adressen markieren"): was the
  order placed with an address of a private email provider (web.de, GMX, Gmail
  and others). Such an order goes to review even when the recipient is confirmed,
  because a real business ordered from a private mailbox is the pattern behind
  rerouted deliveries. Free.

Both are switched on and configured in the Heista dashboard, not in the plugin:
Services → Address Check → tab "Setup" → step "Model, DHL access and business
customer check" → section "Business customer check" (DE: Services → Adressprüfung
→ Tab „Einrichtung“ → Schritt „Modell, DHL-Zugang und Geschäftskundenprüfung“ →
Abschnitt „Geschäftskundenprüfung“). There the merchant picks which orders are
checked ("Only orders from the plugin action with business customer check",
"Orders with a company name", "Every order"), the kinds of recipient, and whether
every mail provider on the list is flagged or only selected ones (for example
only web.de), plus domains of their own.

The plugin brings **two plugin actions** (procedures). The merchant builds the
**flows** in plentyONE themselves, under Automatisierung → Flow Studio, on order
creation:

- **SubmitAddressCheck → Adresse via Heista SaaS prüfen** (`submitAddressCheck`):
  the address check as before. The business customer check runs too when the
  dashboard says "Orders with a company name" or "Every order".
- **SubmitAddressCheckB2b → Adresse via Heista SaaS prüfen, mit
  Geschäftskundenprüfung** (`submitAddressCheckB2b`, en "Validate address via
  Heista SaaS, with business customer check"): the address check plus the
  business customer check for every order through this action, with the
  dashboard set to "Only orders from the plugin action with business customer
  check".

Classic event procedures (Einrichtung → Aufträge → Ereignisaktionen) offer the
same two procedures under the same names.

Recipe A, a shop that ships only to businesses: one flow with the plain action,
dashboard set to "Orders with a company name" or "Every order".

Recipe B, a mixed shop: two flows on the same trigger with mutually exclusive
filters. Flow 1 filters to business orders (payment method, customer class or
tag) and runs the action with the business customer check. Flow 2 filters to
everything else and runs the plain action. The dashboard is set to "Only orders
from the plugin action with business customer check".

The two filters must exclude each other. If an order matches both anyway, the
plugin sends it once, never twice, and the business customer check wins: a plain
submit within ten minutes of a business customer submit for the same order is
dropped, and a queued plain submit is upgraded. If the plain submit already
reached the platform, the business customer check is not started and the order
gets an internal comment: "Die Filter Ihrer beiden Automatisierungen überschneiden
sich. Bitte so einstellen, dass jeder Auftrag nur eine davon durchläuft." Running the same action
twice behaves exactly as before.

Results land in the internal order comment as a "Geschäftskundenprüfung" and an
"E-Mail-Prüfung" line. A result that needs a human moves an order whose address
would otherwise ship to `statusOnB2bReview`, or to `statusOnB2bMismatch` when
something else is listed at the address. The email result uses the review status.
A phone the label would be refused for still goes to `statusOnError` first. The
email checked is the one the plugin already sends: the delivery address email,
else the billing address email.

An older plugin keeps working against the same platform: the checks may run
there too, but nothing about them is shown on the order.

**Shipping mapping**

- `dhlProfileIds`, `dpdProfileIds` – comma-separated shipping-profile IDs per
  carrier, used to pick a carrier-specific correction.

## License

Proprietary. See [LICENSE.md](LICENSE.md).

For inquiries: support@heista.de
