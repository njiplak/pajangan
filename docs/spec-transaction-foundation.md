# Spec: Transaction Foundation

Status: draft, awaiting sign-off on section 3
Scope owner: Tier 1 (payment, ongkir, wilayah, money model, stock) minus notifications

---

## 1. Scope

**In**
- Product variants (SKU-level price, stock, weight)
- Order money model: shipping, discount slot, PPN, grand total, invoice number
- Stock ledger, stock decremented on payment
- Wilayah/address reference data
- Ongkir rate quote at checkout, behind a provider interface (Biteship first)
- Payment charge + webhook, behind a gateway interface (Xendit first)
- Payment expiry + reconciliation jobs
- Commercial invoice with tax breakdown

**Out (explicitly deferred)**
- WA/email notifications
- COD
- Customer accounts, vouchers, marketplace sync
- e-Faktur — that is generated in DJP Coretax, not by this app. We produce a
  commercial invoice with a DPP/PPN breakdown and an export for finance.
- Creating the actual shipment / pickup / label at Biteship. Launch quotes rates
  and records the chosen courier; `tracking_number` is a column staff fill in
  manually.

---

## 2. Decisions locked

| # | Decision | Answer |
|---|---|---|
| A | Payment gateway | Xendit first, behind `PaymentGatewayContract` |
| B | Ongkir provider | Biteship first, behind `ShippingProviderContract` |
| C | Variants | Needed, in scope now |
| D | Stock master | This application |
| E | Tax | Platform must be PPN-compliant |
| F | COD | Deferred |
| G | Stock reservation | Decrement on payment only, not at checkout |
| H | Payment window | 24h for VA/retail, 30 min for QRIS/e-wallet |

### 2.1 What G buys and what it costs

**Buys:** no reserve/release cycle. An abandoned order never touches stock, so
expiry is a pure status change. The ledger only ever records real movements.

**Costs:** between order creation and payment there is no stock guard at all.
Two buyers can each pay for the last unit within the same 24h VA window. This
is not a bug to be fixed later — it is the direct consequence of G, and it
makes the following mandatory, not optional:

1. At webhook confirmation, re-check stock under a row lock **before** marking paid.
2. On shortfall the order is marked `needs_resolution`, never `paid`+`processing`.
   Money has been received and cannot be silently kept against stock we lack.
3. An ops screen listing `needs_resolution` orders, with a manual refund
   record. Automated refund is not assumed — see section 10.

Checkout still shows live stock and still blocks ordering more than is on hand.
That is advisory only: it catches the common case, it guarantees nothing.

---

## 3. Derived decisions — need your nod

These follow from C and E but were not asked directly. Building starts on the
assumption below unless you say otherwise.

**3.1 Every product has at least one variant.**
Price, stock, and weight move off `products` and onto `product_variants`. A
simple product gets one auto-created default variant. Alternative — nullable
variants with `if ($product->hasVariants())` branches — was rejected: that
branch would recur in cart, checkout, ledger, and every backoffice screen, and
each branch is a place for the two paths to drift.

**3.2 Prices are entered and displayed tax-inclusive.**
Indonesian retail convention. Staff type the shelf price; the invoice derives
DPP and PPN from it. Storing tax-exclusive would mean the price shown on the
product page never matches the price staff entered.

**3.3 PPN is configurable, not hardcoded, and gated by a PKP toggle.**
`tax_enabled`, `tax_rate`, `tax_applies_to_shipping`. If the company is not
PKP, the toggle is off and no PPN line is produced. The rate is a number the
company's tax advisor supplies — this spec does not assert what it is.

**3.4 Money stays integer rupiah** — matches the existing `unsignedBigInteger`
convention on products and orders. No decimals anywhere.

**3.5 Xendit channels at launch:** QRIS, VA, e-wallet, retail outlet. Cards
excluded at launch (higher fee, higher fraud surface, and no 3DS handling in
scope). Confirm.

---

## 4. Data model changes

### 4.1 `product_variants` (new)
`product_id`, `sku` (unique), `option_1_name`/`option_1_value`,
`option_2_name`/`option_2_value`, `price`, `stock`, `weight_gram`, `is_active`,
timestamps. Two option axes is the ceiling; three is rare in ID retail and
triples the editor complexity.

### 4.2 `products` (changed)
Drop `price`, `stock`. Add nothing. It becomes catalog identity only: name,
slug, description, producer fields, is_active, media.
Migration backfills one default variant per existing product carrying the old
price/stock and a generated SKU.

### 4.3 `orders` (changed)
Add: `invoice_number` (unique, sequential, separate from `order_number`),
`payment_status`, `paid_at`, `payment_expires_at`,
`shipping_cost`, `discount_amount`, `dpp_amount`, `tax_amount`, `grand_total`,
`courier_code`, `courier_service`, `courier_etd`, `tracking_number`,
`shipping_area_id`, `shipping_district`, `shipping_subdistrict`,
`buyer_npwp` (nullable).
`status` narrows to fulfillment only. Existing `shipping_city`/`shipping_province`
stay as the name snapshot.

### 4.4 `order_items` (changed)
Add `variant_id` (nullOnDelete), `variant_sku`, `variant_label`,
`weight_gram`, and per-line `dpp_amount` / `tax_amount`.
Per-line tax is summed for the order total — computing tax once on the order
total and once per line produces off-by-one rupiah on the invoice.

### 4.5 `payments` (new)
`order_id`, `provider`, `provider_ref`, `provider_event_id`, `channel`,
`amount`, `status`, `raw_payload` (json), `paid_at`, `expired_at`, timestamps.
Unique index on (`provider`, `provider_event_id`).

### 4.6 `stock_movements` (new)
`product_variant_id`, `type` (sale / return / adjustment / restock),
`quantity_delta`, `reference_type`, `reference_id`, `balance_after`,
`user_id` (nullable — system moves have no actor), `note`, timestamps.
`product_variants.stock` remains the fast read; the ledger is the audit trail.
A reconcile command compares the two and reports drift.

### 4.7 Wilayah
Sourced from the Biteship area API and cached locally rather than maintained as
our own dataset. Orders store the area id **and** the name snapshot, so
historical orders are unaffected when the provider's reference data changes.

---

## 5. Contracts

Both follow the existing `app/Contract` + `ContractProvider` binding pattern,
resolved through a driver registry keyed on config, not bound directly.

### 5.1 `PaymentGatewayContract`
```
createCharge(ChargeRequest): ChargeResult
parseWebhook(Request): WebhookEvent|null   // null = not ours / bad signature
fetchStatus(string providerRef): PaymentStatus
```

### 5.2 `ShippingProviderContract`
```
searchAreas(string query): AreaResult[]
quoteRates(RateRequest): RateOption[]
```

**The interfaces are shaped around our domain, not Xendit's or Biteship's
payloads.** `ChargeRequest`, `WebhookEvent`, and `RateOption` are our own value
objects; each driver translates. An interface that mirrors one vendor's JSON is
not an abstraction — it is that vendor's API with an extra file, and the second
driver forces a rewrite.

Webhook route is `/webhook/payment/{provider}` — signature schemes differ per
provider, so the registry resolves the driver from the URL segment.

---

## 6. Order state machine

**payment_status:** `unpaid` → `pending` → `paid` | `expired` | `failed` | `refunded`

**status (fulfillment):** `pending` → `processing` → `shipped` → `completed`,
plus `cancelled` and `needs_resolution` reachable from several states.

Rules:
- `processing` requires `payment_status = paid`.
- Only `paid` writes a `sale` movement to the ledger.
- `expired` and `failed` never touch stock (consequence of G).
- Transitions are validated in one place. Today `OrderService::updateStatus`
  accepts any status from any state, including `completed` back to `pending`.

---

## 7. Flows

### 7.1 Checkout
1. Cart resolves against variants; advisory stock clamp as today.
2. Customer picks destination area (cascading select, Biteship area ids).
3. Server quotes rates for cart weight + destination; customer picks a service.
4. Server **re-quotes** the chosen service and recomputes every amount. A
   client-submitted ongkir or total is ignored entirely.
5. Order created: `payment_status = unpaid`, `status = pending`. Stock untouched.
6. Charge created via gateway; `payment_expires_at` set per channel (H).
7. Redirect to the payment page.

### 7.2 Webhook
1. Driver verifies signature. Failure → 200 with no side effect, logged.
   (Never 500 — gateways retry storms on 5xx.)
2. Insert payment event; unique index makes replays a no-op.
3. Lock the order row. Ignore events for orders already in a terminal state.
4. Lock the variant rows, verify stock.
   - Sufficient → decrement, write ledger rows, `payment_status = paid`.
   - Short → `status = needs_resolution`, no stock write, order flagged for ops.
5. Commit.

### 7.3 Expiry — every 5 minutes
`payment_status` in (unpaid, pending) and `payment_expires_at < now`
→ `expired` + `cancelled`. No stock action.

### 7.4 Reconciliation — hourly
For orders `pending` and under 7 days old, call `fetchStatus` and apply the same
handler as 7.2. Webhooks get lost; without this, a paid order sits unpaid until
a customer complains.

---

## 8. Correctness rules

- Every amount recomputed server-side at order creation. Nothing about money
  or ongkir is trusted from the request body.
- Webhook handlers are idempotent and ordering-tolerant.
- Gateway server keys live in `.env`/`config`. **Not** the settings table:
  `settings.value` is a plaintext `text` column exposed through the backoffice
  CRUD UI to anyone holding `setting.view`. Only non-secret toggles (sandbox
  flag, enabled channels, PKP flag, tax rate) go in settings.
- Raw webhook payloads are retained for dispute resolution.

---

## 9. Infrastructure prerequisites

1. **Postgres or MySQL.** `DB_CONNECTION=sqlite` today. `lockForUpdate()`
   compiles to nothing on SQLite, so the row locking this spec depends on — and
   the oversell guard the current checkout comment claims — does not exist.
   This blocks section 3 onward.
2. **Queue worker** running as a service. Config is `database`; nothing runs it
   in production today.
3. **Cron for `schedule:run`.** `withSchedule()` in `bootstrap/app.php` is empty.
4. **Public HTTPS webhook URL**, plus a `routes/api/` directory — the route
   loader already picks it up under the `api` group, but the directory does not
   exist, and a webhook placed under `routes/web/` fails CSRF.

---

## 10. To verify before building (not assumed)

- Xendit refund coverage per channel. Expectation is that card/e-wallet refunds
  are API-driven while VA/QRIS/retail are manual disbursements — this decides
  whether the `needs_resolution` screen triggers a refund or records one.
- Biteship area endpoint shape, and whether its ids are stable enough to store
  on historical orders.
- Current PPN rate and base, and whether ongkir is taxable for this company.
  From the company's tax advisor, not from this spec.
- Xendit's required invoice/charge expiry bounds per channel against H.

---

## 11. Build order

| # | Step | Blocked by |
|---|---|---|
| 0 | Postgres/MySQL migration | — |
| 1 | Variants: tables, backfill, cart re-key, backoffice editor | 0 |
| 2 | Money model, payment_status split, tax fields, invoice numbering | 0 |
| 3 | Stock ledger, decrement-on-paid, transition guard | 1, 2 |
| 4 | Wilayah + area cache | — |
| 5 | Shipping contract + Biteship driver + checkout rate step | 1, 4 |
| 6 | Payment contract + Xendit driver + webhook | 2, 3, 5 |
| 7 | Expiry + reconciliation jobs, queue/cron setup | 6 |
| 8 | Invoice PDF, needs_resolution ops screen | 6 |

Payment is late deliberately: the charge amount must already include ongkir and
tax, and the webhook needs a ledger to write into. Building it first means
charging a wrong number, then rewriting it.

Step 1 is the largest single item. It rewrites `CartService` (session keys move
from product id to variant id), the cart routes' `{productId}` segment, the
storefront product page, and the backoffice product form. In-flight customer
carts break on deploy — sessions should be cleared as part of the release.
