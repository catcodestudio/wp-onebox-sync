=== CatCode Order Sync with OneBox for WooCommerce ===
Contributors: catcodestudio
Tags: woocommerce, crm, orders, sync, ukraine
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends every WooCommerce order to OneBox OS as a process: at checkout or on a status change, with de-duplication, retries and an event log.

== Description ==

The plugin connects WooCommerce to OneBox OS through its REST API v2 (`POST /api/v2/order/set/` on your own OneBox domain). Every new shop order is created in OneBox as an order process with the customer, the line items and a comment; catalog products are found or created by SKU.

= Features =

* The order is sent right after checkout (classic checkout and Checkout Blocks) or when it moves into one of the statuses you select
* Double de-duplication: the `wc-{id}` externalid (OneBox upserts on it) plus the OneBox ID stored in the order meta — no duplicate processes
* A database-level lock, so that a checkout hook, a status change and a payment callback arriving at the same second still produce exactly one process
* After an attempt that got no answer the order is looked up by its externalid before anything is sent again — a timeout cannot turn into a second deal in the CRM
* The customer travels in the clientname / clientemail / clientphone / clientaddress fields, the items in a products array bound to the catalog (productinfo: name, SKU, externalid)
* Optional routing: business process ID (workflowid), stage ID (statusid) and source ID (sourceid)
* Payment mark: "Paid online" is added to the process comment for orders actually paid through an online gateway (optional)
* Retries on failure: 3 attempts with 5 min / 30 min / 2 h pauses (WP-Cron)
* Metabox on the order screen: sync status, OneBox ID and a resend button (for an order already in the CRM it updates the same process)
* "Test connection" button on the settings page (token plus the list of business processes)
* Event log of the latest sync attempts (the "Log" tab)
* The API password is encrypted at rest (libsodium with an HMAC fallback); the token is cached and refreshed automatically
* The buyer's phone is normalised to E.164 (+380…)
* HPOS compatible (custom order tables), order data is read and written through the WC_Order CRUD only
* `cc_onebox_order_payload` filter and `cc_onebox_order_sent` action for site-specific integrations

= Requirements =

* WooCommerce 6.0 or newer
* PHP 7.4+
* A OneBox OS account with the "Users and employees" app and a REST API password for the employee

= How it works =

1. The customer places an order in the shop
2. The plugin requests a token (`POST /api/v2/token/get/` with the API login and password) and sends the order to `POST /api/v2/order/set/` on your OneBox domain
3. OneBox answers `{"status":1,"dataArray":[id]}` — the process ID is stored in the `_cc_onebox_order_id` order meta
4. If OneBox is unreachable the attempt is repeated automatically (up to 3 times); before each retry the plugin asks OneBox whether the process already exists
5. The manager sees the status and the ID in the order metabox and in the log

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/catcode-order-sync-with-onebox-for-woocommerce/`
2. Activate it on the Plugins screen
3. WooCommerce → OneBox Sync → enter the OneBox domain, the API login and the API password
4. Press "Test connection" and save the settings

== Frequently Asked Questions ==

= Where do I get the API login and password? =

Your OneBox OS account → the "Users and employees" app → the employee card → REST API password. The login is that employee's login. The plugin exchanges them for a token via `/api/v2/token/get/` on its own; the password is stored encrypted in the database.

= Will a repeated status change create duplicates? =

No. The plugin sends the `wc-{id}` externalid (OneBox updates the process it finds) and additionally stores the OneBox ID in the order meta. Parallel requests are serialised by a database-level lock.

= What does the "Resend" button do? =

If the order is not in OneBox yet, it creates the process. If it is already there, it updates that same process with the current data (items are updated, not duplicated).

= What happens if OneBox does not answer at all? =

The order is flagged, and the scheduled retry first asks OneBox whether a process with this order's externalid already exists. If it does, its ID is adopted and no second process is created.

= Does the plugin remove its data on uninstall? =

Yes. Deleting the plugin drops the log table and the options. The OneBox IDs stored on orders are kept on purpose — they are what prevents a reinstall from sending old orders to the CRM a second time.

== External services ==

This plugin connects to the OneBox OS API v2 on your own OneBox domain (for example `https://mybox.crm-onebox.com/api/v2`), a third-party service. The connection is the whole purpose of the plugin: it creates and updates your WooCommerce orders as processes inside your own OneBox account.

What is sent and when:

* `POST /api/v2/token/get/` — before any other call, to exchange your API login and password for a session token (cached for 50 minutes).
* `POST /api/v2/order/set/` — when a customer places an order, or when the order enters one of the statuses you selected as a trigger. Retried up to 3 times if OneBox is unavailable. The same endpoint is used when you press "Resend" for an order that already exists in OneBox.
* `POST /api/v2/order/get/` — only after an attempt that got no response, to check whether the order already exists before retrying.
* `POST /api/v2/workflow/get/` — when you press "Test connection", and once per hour when no business process ID is configured, to pick the default one.

Every request carries the session token in the `token` header. The order data sent is: the WooCommerce order number and id, the line items (name, SKU, quantity, price), the shipping method and its cost, the delivery city and address, the payment method title and whether the order is paid, the customer note, and the buyer's name, e-mail address and phone number as entered at checkout.

No data is sent to any other service, and nothing about your visitors, site or administrators is sent beyond the order data listed above. Nothing is sent until you enter your OneBox domain and API credentials: without them the plugin makes no external requests.

This service is provided by OneBox OS: [public offer](https://1b.app/en/public-offer-onebox/), [privacy policy](https://1b.app/en/privacy-policy/).

OneBox and OneBox OS are trademarks of their respective owner. This plugin is developed by CatCode and is not affiliated with, endorsed or sponsored by OneBox.

== Screenshots ==

1. Settings: OneBox domain, API credentials, trigger statuses, CRM routing
2. Sync log with the last attempts
3. OneBox metabox on the WooCommerce order with the resend button

== Changelog ==

= 0.2.0 =
* Fixed: the "Resend" button in the order metabox did nothing — the metabox form was nested inside the WooCommerce order form and browsers dropped it. It is a nonced link now, and the result is shown as an admin notice.
* Fixed: two requests hitting the same order at the same moment (checkout hook, status change, payment callback) could create two processes in OneBox. Sending is serialised by a database-level lock and the order is re-read past the HPOS cache before the decision.
* Added: after an attempt that got no answer the order is flagged, and the retry looks the process up by its externalid (`POST /order/get/`) instead of sending a second one.
* Added: uninstalling the plugin now drops the log table, the options and any leftover locks.
* Changed: interface strings are in English with a Ukrainian translation; screen styles are enqueued instead of printed inline.
* Changed: the plugin was renamed to "CatCode Order Sync with OneBox for WooCommerce". Settings, order meta and the log table are unchanged, so nothing needs migrating.

= 0.1.0 =
* First release: orders are sent to OneBox OS (`POST /api/v2/order/set/`), retries, metabox, log, connection test.
