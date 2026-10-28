# Heista Address Check

The plugin sends an order's delivery address to the Heista address check and writes the result back: the corrected address, an internal comment on the order, and the order status you assign to that result.

## Basic setup

1. Install the plugin in your plugin set and deploy the plugin set.
2. Open the plugin under ***Plugins → Plugin set overview*** and enter the API key from the Heista dashboard in the ***SaaS connection*** section.
3. In ***Order status mapping***, enter the status each result should move the order to. An empty field leaves the status as it is. Under ***Order comment author***, enter the user ID under which the result appears as an internal comment on the order.
4. Under ***Automation → Flow Studio***, create a flow that starts when an order is created and runs the plugin action ***SubmitAddressCheck → Validate address via Heista SaaS***. The flow's filters decide which orders are checked.

If you still work with classic event procedures (***Setup → Orders → Event procedures***), you will find the same two procedures there under the same names.

## Business customer check (since version 1.9.0)

For shops that deliver to companies, schools, Kitas, public authorities and other organisations. On top of the address check there are two checks, each switched on separately:

- **Check the recipient at the address:** the lookup uses the company name and the delivery address as it stands on the order after the address check, so the corrected one if it was corrected. What gets confirmed is that a recipient of the kind you chose under "Who you deliver to" is listed at that address, either whole areas or single kinds. The check confirms the kind of recipient, not the company name itself. Without a company name in the delivery address the check is not possible, and the order goes to manual review. Names of people are never used. Price: €0.025 per checked address, on top of your plan.
- **Flag private email addresses:** if the order was placed with an address from a private provider such as web.de, GMX or Gmail, it goes to manual review, even when the recipient is confirmed. That is what orders look like that are meant to be rerouted to another address during delivery. No extra charge.

### The two plugin actions

The plugin brings two actions. You create the flows that run them yourself.

| Action in Flow Studio | What it does |
|---|---|
| ***SubmitAddressCheck → Validate address via Heista SaaS*** | The address check as before. When the Heista dashboard is set to "Orders with a company name" or "Every order", the business customer check runs here too. |
| ***SubmitAddressCheckB2b → Validate address via Heista SaaS, with business customer check*** | The address check plus the business customer check for every order that goes through this action. The Heista dashboard is set to "Only orders from the plugin action with business customer check" for this. |

### Recipe A: you deliver to business customers only

1. In the Heista dashboard, choose "Orders with a company name" or "Every order".
2. One flow on order creation with the action ***SubmitAddressCheck → Validate address via Heista SaaS*** is enough.

### Recipe B: you deliver to private and business customers

1. In the Heista dashboard, choose "Only orders from the plugin action with business customer check".
2. Flow 1 on order creation, filtered to your business orders, such as a payment method like invoice, a customer class or a tag. Action: ***SubmitAddressCheckB2b → Validate address via Heista SaaS, with business customer check***.
3. Flow 2 with the same trigger and the opposite filter: all other payment methods, all other customer classes, or without the tag. Action: ***SubmitAddressCheck → Validate address via Heista SaaS***.

The filters of the two flows must exclude each other, so that every order goes through exactly one of them. If an order matches both anyway, the plugin still submits it only once:

- If the action with the business customer check came first, the second submit is dropped. The order gets the full check.
- If the plain action came first and has already reached Heista, no business customer check is started. The order gets an internal comment in German: "Die Filter Ihrer beiden Automatisierungen überschneiden sich. Bitte so einstellen, dass jeder Auftrag nur eine davon durchläuft." (the filters of your two automations overlap). Fix the filters then. For that order you can run the action again ten minutes later at the earliest.

Running the same action twice behaves as before.

### Status and comment

The ***Order status mapping*** section has two new fields:

- ***Status when the business customer check needs a manual review***: the recipient was not confirmed or not found, the check was not possible (for example no company name), or the order was placed with a private email address. The email result always uses this status.
- ***Status when the business customer check finds a contradiction***: something other than the chosen kinds of recipient is listed at the address. If the field is empty, the review status is used instead.

Both only apply to orders whose address would otherwise be ready to ship. If the address is undeliverable, the status for that applies. A phone number DHL would refuse takes precedence and sets the error status.

The result is in the internal order comment, in the lines "Geschäftskundenprüfung" and "E-Mail-Prüfung".

### Settings in the Heista dashboard

What gets checked is not set in the plugin but in the Heista dashboard, under ***Services → Address Check → tab "Setup" → step "Model, DHL access and business customer check" → section "Business customer check"***:

- which orders are checked: "Only orders from the plugin action with business customer check", "Orders with a company name" or "Every order",
- "Check the recipient at the address", with your choice under "Who you deliver to",
- "Flag private email addresses": every private provider on the list or only selected ones, say only web.de, plus domains of your own.

The recipient check costs €0.025 per checked address on top of your plan. The email check is free.

Older plugin versions keep working. With “Orders with a company name” or “Every order” set in the dashboard, the checks still run for them and the recipient check is charged, but the result only shows on the order from version 1.9.0. Turn the business customer check on after the update.
