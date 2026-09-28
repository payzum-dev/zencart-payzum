# Payzum for Zen Cart — Accept Crypto & Stablecoin Payments (USDC, USDT)

Accept **cryptocurrency and stablecoin payments** (USDC, USDT and more, multi-chain) in
[Zen Cart](https://www.zen-cart.com) through [Payzum](https://payzum.com) —
**non-custodial**: funds settle directly to your own wallet, Payzum never takes
custody. No chargebacks, no card networks, no PCI surface.

- **Module:** `payzum` · **Version:** 1.0.0 · **License:** MIT
- **Requires:** Zen Cart 2.x, PHP ≥ 8.1

## How it works

1. The buyer picks **Payzum** at checkout and is redirected to a hosted checkout
   page (QR code + deposit address, live status), where they choose the coin and
   chain and send the payment. No wallet or card data touches your server.
2. Crypto confirmation is **asynchronous**, so the order is credited from
   Payzum's signed server-to-server IPN webhook (`payzum_ipn.php`), never from
   the buyer's browser return — a closed tab never loses a paid order.
3. Every webhook is verified with **HMAC-SHA-512 over the raw request bytes**
   (constant-time compare, replay window) before a single field of it is read.
   The event id is persisted in the order status history for deduplication, and
   the **amount and currency are re-checked against the order** before it is
   credited — so an order is never credited twice, and a settled order is never
   downgraded by a late `expired`.

## Features

- **Stablecoin-first**: USDC and USDT across multiple chains (Polygon, Ethereum,
  Arbitrum, Base, Optimism, Tron, Solana and more), plus major cryptocurrencies.
- **Non-custodial** — payments settle to the merchant's own wallet.
- **Hosted checkout** — no card fields, no crypto handling, no PCI scope.
- **Signed IPN webhooks** (HMAC-SHA-512) credit orders server-side.
- **Configurable order statuses** for paid and failed/expired payments.
- **Production / staging selector** built into the module settings.
- **Zero chargebacks** — crypto payments are final.

## Installation

**From the release zip (recommended).** Download
[`payzum-zencart-1.0.0.zip`](https://github.com/payzum-dev/zencart-payzum/releases/latest) and unzip
it at your Zen Cart root — the archive already mirrors the layout below.

**From a clone.** Copy the repository's files into your Zen Cart root (they follow the standard
Zen Cart drop-in layout):

- `includes/modules/payment/payzum.php` — the payment module
- `includes/modules/payment/payzum/` — the vendored Payzum PHP SDK
- `includes/languages/english/modules/payment/payzum.php` — language file
- `payzum_ipn.php` — the IPN endpoint, at the store root

Then go to **Modules → Payment**, select **Payzum** and click **Install**.
The official [`payzum/payzum-php`](https://packagist.org/packages/payzum/payzum-php)
SDK is vendored, so no composer step is needed.

## Configuration

Go to **Modules → Payment → Payzum**:

| Setting | Meaning |
|---|---|
| Enable Payzum | Turns the payment method on |
| API key | From your [Payzum merchant dashboard](https://merchant.payzum.com) (64 hex chars) |
| Webhook secret | Verifies incoming payment webhooks (IPN, HMAC-SHA-512) |
| Settlement currency | `all` (default) lets the buyer pick the coin on the Payzum checkout, limited to your merchant allowlist; a single ticker (e.g. `usdtmatic`) forces one coin |
| Set Order Status | Status for a paid (`finished`) order |
| Set Failed Order Status | Where an expired/failed invoice leaves the order — **Zen Cart ships no "Cancelled" status out of the box**: add one under **Localization → Orders Status** and select it here. Left unset, the IPN looks for a status named Cancelled or Failed; failing that it leaves the order in place with a warning in its history |
| Environment | Production or staging (staging needs its own API key) |
| Sort order | Position among payment methods |

The IPN signature header is fixed by the SDK — nothing to configure, nothing to
get wrong.

## Order status mapping

| Payzum payment status | Effect in Zen Cart |
|---|---|
| `finished` | Amount & currency verified, order → your configured **paid** status (also covers overpayment; deduplicated) |
| `expired`, `failed` | Order → your configured **failed** status — never downgrading an order that is already paid |
| `waiting`, `partially_paid` | Current status kept; the event is recorded in the order history |
| unknown value | Recorded and acknowledged — never credited on a guess |

## FAQ

**Is Payzum custodial?**
No. Funds settle directly to your own wallet — Payzum never holds your money.

**Which stablecoins and networks can my store accept?**
USDC and USDT on the major chains (Polygon, Ethereum, Arbitrum, Base, Optimism,
Tron, Solana, …), plus native assets. The exact list is your merchant
allowlist, configured in the Payzum dashboard and enforced server-side.

**Do buyers need an account or a browser extension?**
No. They scan a QR or copy a deposit address from the hosted checkout and pay
from any wallet.

**What about chargebacks?**
There are none — crypto payments are final, which eliminates chargeback fraud.

**Can a replayed or forged webhook mark an order as paid?**
No. Every delivery must carry a valid HMAC-SHA-512 signature over the raw
request bytes, the amount is re-verified against the order, and repeated event
ids are detected from the order status history.

**What data is shared with Payzum?**
Only the order total, currency, an order reference and your store's callback
URLs — no customer personal data. Endpoints: `https://merchant.payzum.com`
(production), `https://staging.payzum.com` (staging).

## Related Payzum integrations

Payzum ships official plugins for most major e-commerce, donation and billing
platforms — WooCommerce, Magento 2, PrestaShop, Shopware 6, OpenCart, nopCommerce,
Ecwid, BigCommerce, Shopify, Wix, Medusa, Vendure, Saleor, Sylius, Easy Digital
Downloads, GiveWP, Paid Memberships Pro, WHMCS, Blesta, HostBill, ClientExec,
pretix, Frappe/ERPNext, Akaunting and django-payments — plus official SDKs for
PHP, Node.js/TypeScript, Python and Rust. Browse them all at
[github.com/payzum-dev](https://github.com/payzum-dev).

## About Payzum

[Payzum](https://payzum.com) is a non-custodial crypto payment gateway for
merchants: accept USDC, USDT and other digital assets with settlement straight
to your own wallet, optional auto-conversion to stablecoins, and a single REST
API. API docs: [merchant.payzum.com/api/docs](https://merchant.payzum.com/api/docs).

## License

[MIT](LICENSE). Contributed and maintained by Payzum.
