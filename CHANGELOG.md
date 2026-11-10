# Changelog

## 0.2.0 – 2026-11-10

- Stable `external_id` for every product and combination (`ps:{id_shop}:{id_product}:{id_product_attribute}`), sent with every product upsert and every order item; SkladX matches by `external_id` first, then by SKU, so a changed reference renames the SKU in SkladX.
- `409 sku_conflict` handling: the owning SkladX product (SKU, name, id) is stored and shown in the product's SkladX section and as a back office notice; not retried and the same payload is not resent until the product changes or a manual resync.
- Immediate product sync: saves are collected per request (de-duplicated) and pushed at its end with a short timeout; network errors, 429 and 5xx fall back to the queue with backoff (1/5/15/60/180/360 minutes). More than 20 products per request (imports) go to the queue.
- New hooks `actionProductAdd`, `actionProductUpdate`, `actionProductSave`, `actionProductAttributeUpdate`, `actionObjectImageAddAfter/UpdateAfter/DeleteAfter` (registered by `upgrade/upgrade-0.2.0.php` too).
- Images: `image_url` (cover, or the combination's image) and up to 10 additional `images`, absolute HTTPS URLs.
- Last sync error per product/combination (with error code) in the product section; new column `skladx_product.sync_error_code`.
- Upgrade queues a full product sync so SkladX learns the `external_id` and images of existing products.
- Unit tests (119).

## 0.1.0 – 2026-11-10

First release, feature parity with skladx-woocommerce 0.3.1.

- Order export on configurable order states: database queue, idempotency key `ps:<host>:order:<id>` (`:r<n>` after a rejected attempt), retries after 1/5/15/60/180/360 minutes, linking of an order SkladX already has, admin notice and order message on validation errors.
- SkladX box on the order page: status, number, tracking with *Track shipment*, pickup point, last sync, address change warning, payload preview, *Send/Resend*, *Refresh from SkladX*, *Cancel in SkladX*.
- Signed webhooks (front controller, HMAC-SHA256, 300 s tolerance, dedupe by event id, processing after the response, works in maintenance mode) and `GET /events` polling fallback.
- Status mapping to order states, private order messages for every status change, tracking number on the order carrier and the *In transit* e-mail; carrier tracking URLs filled in for mapped carriers.
- Carrier mapping by `id_reference`, cash on delivery payment modules.
- Pickup point picker at checkout (attached module carriers): search, near me, opening hours, map, optional Packeta widget; cached, rate-limited proxy; server-side validation of the delivery step; point stored on the cart and the order, shown in the confirmation, order detail and *order_conf* e-mail; COD warning on the payment step.
- Product and combination sync (reference, name, EAN-13, weight, dimensions, price, image, storage class), product section with storage class and per-combination sync state, full catalogue sync, archiving on delete.
- Optional stock sync from SkladX (`stock.changed`, `product.*`, `GET /stock`), stale events ignored, units of orders not yet in SkladX kept deducted.
- Inbound receipts (*Catalog → SkladX receipts*): list with status filter and pagination, editor with bulk paste, EAN scanning, attachments, labels (PDF/ZPL), announce/cancel, received quantities and differences once counting started, status notices from `inbound.*` events and a periodic refresh.
- Queue runner: cron URL with secret token, run-after-response, lazy runs on back office requests, MySQL named lock, cleanup.
- Slovak translation; unit tests (106) for payload mapping, signature verification, status mapping, pickup points, receipts, bulk paste, stock events, backoff, URLs and translations.
