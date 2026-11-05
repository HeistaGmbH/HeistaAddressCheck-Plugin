# Release notes for Heista Address Check

## v1.9.0

### New

- Business customer check: the second plugin action ***Validate address via Heista SaaS, with business customer check*** also checks whether the delivery address lists the kind of recipient you chose in the Heista dashboard, for example day-care centres and schools. It confirms the kind of recipient, not the company. The check is charged per checked address on top and has to be switched on in the Heista dashboard, where you also choose which orders it covers: only orders from this action, orders with a company name, or every order.
- Email check: an order placed with an address from a private email provider, such as web.de or gmx.de, goes to a person for review. You choose the providers in the Heista dashboard. No extra charge.
- Two new fields under ***Order status mapping***: ***Status when the business customer check needs a manual review*** and ***Status when the business customer check finds a contradiction***.
- ***Waiting time while Heista cannot be reached*** under ***Order handling***, from 1 to 24 hours.
- On the first failed attempt the order gets an internal comment: not checked yet, and until when the plugin keeps trying.

### Changed

- The waiting time during an outage is now 8 hours by default instead of a fixed 24 hours. After that the order goes to the error status. To keep 24 hours, set it in the new setting.
- When an error page answers instead of Heista, such as "404 page not found" during an outage, the plugin keeps trying instead of moving the order to the error status at once.
- When Heista is unreachable during a run of the background process, the plugin sends nothing more in that run, so an outage no longer holds the run up for minutes.

### Fixed

- When the answer to an order's confirmation was lost, the next attempt created a second order at Heista, which was charged as well. The plugin now confirms the same order again.
- When a check ended with an error at Heista and the result only arrived through the regular poll, the order got no comment and no status. It now gets both, as with a direct callback.
