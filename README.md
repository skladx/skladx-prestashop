# SkladX for PrestaShop

PrestaShop module `skladx` that connects a shop with the **SkladX** fulfilment warehouse:

- sends orders to SkladX when they reach chosen order states (database queue, idempotent, retries with backoff),
- pickup point picker at checkout (Packeta and other pickup carriers, points from SkladX), the choice is stored on the order,
- syncs products and combinations (reference = SKU, name, EAN-13, weight, dimensions, price, image, storage class) and optionally takes stock levels from SkladX,
- receives order status and tracking updates via signed webhooks (HMAC-SHA256) and a polling fallback,
- maps SkladX statuses to order states, passes the tracking number to the order carrier and the customer ("In transit" e-mail with the tracking link),
- shows a SkladX box on the order page (status, number, tracking with a *Track shipment* button, pickup point, last sync, Send/Resend, Refresh, Cancel in SkladX),
- **receipts** (*Catalog → SkladX receipts*): announce goods you send to the warehouse — header, items with batch and expiry, bulk paste, EAN scanning, attachments, label (PDF/ZPL), received quantities and differences.

Requirements: PrestaShop 8.0–9.x, PHP 8.1+ with cURL. No runtime Composer dependencies. Translations: English (source) and Slovak.

It is the PrestaShop counterpart of [skladx-woocommerce](https://github.com/skladx/skladx-woocommerce) and talks to the same SkladX API v1.

[Slovenská verzia nižšie](#slovensky)

---

## Installation

1. Copy the module to `modules/skladx` — e.g. `git clone https://github.com/skladx/skladx-prestashop.git modules/skladx`, or upload a ZIP with the folder `skladx/` in *Modules → Module Manager → Upload a module*.
2. Install **SkladX** (`php bin/console prestashop:module install skladx` works too).
3. Open *Modules → Module Manager → SkladX → Configure*.
4. Add the cron job (see *Queue and cron*).

## Configuration

| Setting | Notes |
| --- | --- |
| API base URL | Your SkladX URL including `/api/v1`, default `https://skladx.sk/api/v1`. |
| API key | From the SkladX client portal (*Integrations → API keys*). `skx_test_…` = sandbox, `skx_live_…` = production. Scopes: `orders:read`, `orders:write`, `products:write`, `events:read`, `webhooks:write`, `pickup_points:read`, `stock:read`, `inbound:read`, `inbound:write`. Stored in the configuration table, never printed back into the page and never logged. |
| Test connection | `GET /ping`: client, environment and missing scopes. |
| Webhook | *Register webhook in SkladX* calls `POST /webhooks` and stores the signing secret. Endpoint: `https://your-shop/module/skladx/webhook` (front controller). Manually: paste the `whsec_…` secret into *Webhook signing secret*. |
| Send orders in states | Default: *Payment accepted*, *Remote payment accepted*, *Awaiting cash on delivery validation*, *Processing in progress*, *On backorder (paid)*. An order is sent once. Bank wire orders are sent when you mark them paid. |
| Cash on delivery payment modules | Orders paid with these modules get `cod_cents` = order total. Default `ps_cashondelivery`. |
| SkladX status → order state | Every SkladX status change is added as a private order message; mapped statuses also change the order state. Defaults: `handed_over` → *Shipped*, `delivered` → *Delivered*, `cancelled` → *Canceled*. A state the order already had is not applied again. |
| Tracking e-mail | A new tracking number is written to the order carrier (customer's order history) and the PrestaShop *In transit* e-mail is sent; the link uses the carrier tracking URL (`@` = number), filled in automatically for mapped carriers without one. |
| Carriers | Each carrier (by its `id_reference`, stable across carrier edits) maps to a SkladX carrier: `packeta`, `dpd_sk`, `gls_sk`, `sps`, `posta_sk`, `balikovo`. |
| Pickup point | Ticking it attaches the carrier to this module (`is_module`, prices still from the carrier ranges): the checkout shows the picker under the carrier and requires a choice. |
| Packeta widget API key | Optional: Packeta carriers open the official Packeta widget instead of the built-in picker; the point is still verified with SkladX. |
| Product sync | On product/combination save (and *Sync all products*) → `POST /products` upsert by SKU. Products without a reference are skipped; unchanged payloads are not resent. Deleting a product archives it in SkladX. |
| Storage class | Product page → *Modules* tab → *SkladX*: `ambient`, `chilled`, `frozen`, `hazardous`, `hanging_garment`, `oversize`, `high_value` or a code of your warehouse; sent as `storage_class`. The section also shows the sync state per combination and has *Sync to SkladX now*. |
| Stock sync | Off by default. When on, SkladX is the source of truth: `stock.changed` / `product.*` events set the available quantity to SkladX `available` (on hand − reserved) minus units of shop orders SkladX does not have yet. Turning it on (or *Take over stock from SkladX now*) pulls all levels via `GET /stock`. Older events never overwrite newer ones. |
| Debug log | `var/logs/skladx-YYYY-MM-DD.log`, secrets masked. Errors are always logged, also to *Advanced parameters → Logs*. |

### Order payload

`reference` = PrestaShop order reference (`REFERENCE-id` when one cart was split into several orders), items by SKU (duplicate SKUs merged, `unit_price_cents` incl. tax), delivery address (invoice address as fallback), `carrier`, `pickup_point_id`, `cod_cents`, `total_cents`, `currency`, `customer_email`, `customer_phone` (mobile first), `note` (checkout message) and `metadata: {ps_order_id, ps_order_reference, site}`.

The `Idempotency-Key` is `ps:<host>:order:<id>`; after a rejected attempt (4xx) a revision suffix `:r<n>` is added so the corrected order can be sent again. Network errors, 429 and 5xx are retried after 1, 5, 15, 60, 180 and 360 minutes; validation errors (422) stop, are written to the order messages and the SkladX box and show an admin notice. If SkladX already has the reference and it belongs to this order (lost response), the module links it instead of failing.

Hooks for other modules: `actionSkladxOrderPayload`, `actionSkladxProductPayload`, `actionSkladxInboundPayload` (params: `payload` by reference).

### Pickup point picker

1. Under a pickup point carrier the checkout shows *Choose pickup point*: search by city, postcode or name, *Near me*, list with type, COD/card badges, distance and opening hours, OpenStreetMap preview. *Continue* without a point opens the picker; the delivery step is also validated on the server (`actionValidateStepComplete`).
2. Points come from the module proxy `GET /module/skladx/pickup?action=search&carrier=packeta&q=…` which calls SkladX `GET /pickup-points` with the shop's API key (never sent to the browser). Only pickup carriers are allowed, answers are cached (search 1 h, points 6 h) and cache misses are limited to 30 per minute per visitor.
3. The choice is saved per cart (verified with `GET /pickup-points/{carrier}/{id}`), copied to the order on validation and sent as `pickup_point_id`. It is shown in the order confirmation, the customer's order detail, the *order_conf* e-mail (carrier line) and the SkladX box. When the point does not accept cash on delivery, the payment step shows a warning.

### Receipts (Catalog → SkladX receipts)

Announce stock you are sending to the warehouse. SkladX is the source of truth — only a small status cache is stored in PrestaShop. All calls go through the back office controller (admin token), the API key never reaches the browser.

- **List:** `GET /inbound-receipts` with status badges (Draft, Awaiting receipt, In transit, Arrived at warehouse, Receiving, Received, Cancelled), expected date, carrier and tracking link, announced/received pieces with difference, status filter, cursor pagination. Without the SkladX endpoint (404) the screen says the module is not supported yet.
- **Editor** (tabs *Detail*, *Edit receipt*, *Bulk add*, *Scan EAN*, *Attachments*): name, expected delivery date, carrier (incl. *In person* / *Other*), tracking, reference, blind receipt, note; items by product search (name, reference, EAN; combinations listed one by one), quantity, batch, expiry; bulk paste `code;quantity[;batch[;expiry]]` with per-line errors; USB EAN scanning; attachments (PDF/JPG/PNG/WebP, 10 MB).
- **Flow:** *Save draft* (`POST`/`PUT`), *Send to warehouse* (`announce`), *Cancel receipt*, *Print label* (PDF, ZPL in Detail). Received quantities and differences are shown only once the warehouse starts counting.
- **Status updates:** `inbound.*` webhooks/polling and a 30-minute refresh of open receipts update the cache and show a back office notice once per status change.

### Webhook

`POST /module/skladx/webhook` verifies `X-SkladX-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "t.body")>` over the raw body (300 s tolerance, constant-time compare; invalid → `401`), stores the event keyed by `X-SkladX-Event-Id` (duplicates → `200 {"duplicate":true}`), answers `200` and processes the event right after the response. Handled: `order.*`, `shipment.tracking_updated`, `stock.changed`, `product.created`, `product.updated`, `inbound.*`, `ping`. Stale events (older `status_changed_at`) are ignored. Webhooks work in maintenance mode too.

**Polling fallback:** every 10 minutes `GET /events?after=<last evt_…>`; missed events go through the same inbox and dedupe. The first run only remembers the newest event.

### Queue and cron

PrestaShop has no background job runner, so the module has its own queue table (`skladx_queue`: order export, product sync, full sync batches, stock pull, events) with retries. Jobs run:

- right after the response of the request that created them (PHP-FPM `fastcgi_finish_request`), so customers never wait for SkladX,
- on back office page loads (at most once a minute),
- from the cron URL shown in the settings — recommended every minute:
  `* * * * * curl -fsS "https://your-shop/module/skladx/cron?token=…" >/dev/null`

The cron also runs event polling (10 min), the receipt refresh (30 min) and cleanup (daily). A MySQL named lock prevents overlapping runs. The settings page shows queue counts, failed jobs (*Retry failed jobs*), recent events and *Process queue now* / *Fetch missed events now*.

### Uninstall

Removes the `SKLADX_*` configuration, the module tables (order links, queue, events, cache) and the carrier attachments.

## Troubleshooting

- **Test connection → `invalid_api_key` / `insufficient_scope`:** issue a new key with the scopes above.
- **Orders stay "Queued":** check the cron URL is called (*Last cron run* in the settings) or click *Process queue now*.
- **422 on export:** the SkladX box says which field (unknown SKU — sync products first; invalid pickup point; missing address). Fix it and click *Resend to SkladX*.
- **Webhooks not arriving:** check deliveries in the SkladX portal; `401 invalid_signature` = wrong secret (register again) or clock off by more than 5 minutes. The shop must be reachable over HTTPS. Missed events are still picked up by polling.
- **Order state changes fail with an error from `ps_eventbus`:** PrestaShop SA cloud modules of the Classic edition (`ps_eventbus`, `ps_accounts`, …) throw when the shop is not linked to a PrestaShop account. Link the shop or uninstall these modules.
- **Picker does not appear:** the carrier must be mapped, ticked as *Pickup point* (attached to the module) and the API key must have `pickup_points:read`. The standard multi-step checkout is supported; the PrestaShop 9.2 one-page checkout (`ps_onepagecheckout`, off by default) is not tested.
- **Local development with a SkladX on Laravel Herd (`*.test`):** define `SKLADX_CA_BUNDLE` (path to the Herd CA PEM) in `config/defines_custom.inc.php`. TLS verification is never disabled.

## Development

```bash
composer install            # dev only: PHPUnit, php-cs-fixer (PrestaShop coding standards)
composer test               # unit tests: payload mapping, signature, status mapping, pickup points, receipts, bulk paste, stock events, backoff, translations
composer cs-fix             # PrestaShop php-cs-fixer config
php dev/i18n.php            # build translations/sk.php from dev/sk.php (php dev/i18n.php --check lists missing strings)
```

Source strings are English. PHP strings use `SkladX\PrestaShop\Support\T::__()` (source key `skladx`), templates `{l s='…' mod='skladx'}`; texts of the JavaScript are passed from PHP.

---

<a id="slovensky"></a>

## Slovensky

Modul `skladx` prepojí PrestaShop so skladom **SkladX**: odosiela objednávky, synchronizuje produkty a zásoby, cez podpísané webhooky prijíma stavy objednávok a sledovanie zásielok, ponúka výber výdajného miesta v pokladni a príjemky tovaru do skladu.

Požiadavky: PrestaShop 8.0–9.x, PHP 8.1+ s cURL. Bez runtime závislostí Composeru. Preklady: angličtina a slovenčina.

### Inštalácia

1. Modul nahrajte do `modules/skladx` (`git clone https://github.com/skladx/skladx-prestashop.git modules/skladx` alebo ZIP s priečinkom `skladx/` cez *Moduly → Správca modulov → Nahrať modul*).
2. Nainštalujte **SkladX** a otvorte *Nastaviť*.
3. Pridajte cron (URL je v nastaveniach modulu), napr. každú minútu `curl -fsS "https://vas-obchod/module/skladx/cron?token=…"`.

### Nastavenie

- **Základná URL API** – predvolene `https://skladx.sk/api/v1`.
- **API kľúč** – z klientskeho portálu SkladX (*Integrácie → API kľúče*), oprávnenia `orders:read orders:write products:write events:read webhooks:write pickup_points:read stock:read inbound:read inbound:write`. Kľúč sa nikdy nezobrazí ani nezapíše do logu.
- **Otestovať pripojenie** a **Zaregistrovať webhook v SkladX** (adresa `https://vas-obchod/module/skladx/webhook`, podpisový kľúč sa uloží automaticky).
- **Odosielať objednávky v stavoch** – predvolene zaplatené objednávky a dobierka; objednávka sa odošle raz, pri výpadku SkladX sa odoslanie opakuje (1, 5, 15, 60, 180, 360 minút).
- **Platobné moduly na dobierku** – predvolene `ps_cashondelivery`, suma objednávky sa pošle ako dobierka.
- **Stav SkladX → stav objednávky** – každá zmena je súkromná správa k objednávke; predvolene `handed_over` → *Odoslaná*, `delivered` → *Doručená*, `cancelled` → *Zrušená*.
- **E-mail so sledovaním** – číslo zásielky sa uloží k dopravcovi objednávky a zákazník dostane e-mail *Na ceste* s odkazom.
- **Dopravcovia** – každý dopravca (podľa `id_reference`) sa mapuje na `packeta`, `dpd_sk`, `gls_sk`, `sps`, `posta_sk`, `balikovo`. Stĺpec **Výdajné miesto** pripojí dopravcu k modulu: v pokladni sa pod ním zobrazí výber výdajného miesta (vyhľadávanie, *V mojom okolí*, otváracie hodiny, mapa) a výber je povinný. Miesta sa načítavajú cez proxy modulu (API kľúč sa do prehliadača nedostane), výber overí SkladX a uloží sa k objednávke.
- **Synchronizácia produktov** – pri uložení produktu/kombinácie (kód = SKU, názov, EAN-13, hmotnosť, rozmery, cena, obrázok, skladová trieda). Skladová trieda sa nastavuje na stránke produktu v záložke *Moduly → SkladX*.
- **Synchronizácia zásob** – predvolene vypnutá; po zapnutí je zdrojom pravdy SkladX (dostupné = na sklade − rezervované, mínus kusy objednávok, ktoré ešte nie sú v SkladX).
- **Ladiaci log** – `var/logs/skladx-RRRR-MM-DD.log`, tajné údaje maskované.

### Box SkladX pri objednávke

Stav a číslo objednávky v SkladX, číslo zásielky s tlačidlom *Sledovať zásielku*, výdajné miesto, posledná synchronizácia, chyba odoslania, tlačidlá *Odoslať / Odoslať znova do SkladX*, *Obnoviť zo SkladX*, *Zrušiť v SkladX* a náhľad odosielaných údajov.

### Príjemky (Katalóg → Príjemky SkladX)

Ohlásenie tovaru, ktorý posielate do skladu: zoznam so stavmi a filtrom, editor (Názov, Predpokladaný dátum dodania, dopravca vrátane *Osobne na sklad* / *Iný*, tracking, referencia, bianco príjemka, poznámka), položky s vyhľadávaním produktu podľa názvu, kódu alebo EAN, šaržou a expiráciou, hromadné pridanie (`kód;počet[;šarža[;expirácia]]`), skenovanie EAN USB skenerom, prílohy, *Uložiť koncept*, *Odoslať do skladu*, *Zrušiť príjemku*, *Tlačiť štítok* (PDF/ZPL). Prijaté počty a rozdiely sa zobrazia, až keď sklad začne počítať. Zmeny stavu (webhooky `inbound.*`) sa zobrazia ako upozornenie v administrácii.

### Webhook, fronta a cron

Webhook overuje HMAC podpis (tolerancia 300 s), deduplikuje podľa `X-SkladX-Event-Id`, odpovie hneď `200` a udalosť spracuje po odoslaní odpovede. Každých 10 minút modul načíta `GET /events` (záložné načítanie zmeškaných udalostí). Úlohy (odoslanie objednávky, produkty, zásoby, udalosti) sú v databázovej fronte s opakovaním; spúšťajú sa hneď po požiadavke, pri načítaní administrácie a z cron URL.

### Riešenie problémov

- **422 pri odoslaní** – v boxe SkladX je presná chyba (neznáme SKU → najprv synchronizujte produkty; neplatné výdajné miesto; chýbajúca adresa). Po oprave kliknite *Odoslať znova do SkladX*.
- **Objednávka visí vo fronte** – skontrolujte cron (*Posledné spustenie cronu*) alebo kliknite *Spracovať frontu teraz*.
- **Zmena stavu objednávky padá na chybe modulu `ps_eventbus`** – cloudové moduly PrestaShop SA z edície Classic (`ps_eventbus`, `ps_accounts`, …) vyžadujú prepojenie s účtom PrestaShop; prepojte obchod alebo tieto moduly odinštalujte.
- **Webhooky nechodia** – v portáli SkladX pozrite doručenia; `401` = zlý kľúč (zaregistrujte znova) alebo posunutý čas servera. Zmeškané udalosti dobehne polling.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later.
