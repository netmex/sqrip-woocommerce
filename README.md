# sqrip for WooCommerce

[![WordPress plugin version](https://img.shields.io/wordpress/plugin/v/sqrip-swiss-qr-invoice)](https://wordpress.org/plugins/sqrip-swiss-qr-invoice/)
[![Tested WordPress version](https://img.shields.io/wordpress/plugin/tested/sqrip-swiss-qr-invoice)](https://wordpress.org/plugins/sqrip-swiss-qr-invoice/)
[![License](https://img.shields.io/badge/license-GPLv2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)

The official [sqrip](https://sqrip.ch/) plugin for WooCommerce. It adds the Swiss
QR bill as a payment method: sqrip creates the QR payment part through the
[sqrip API](https://api.sqrip.ch/), attaches it to your WooCommerce orders and
e-mails, and helps you reconcile incoming bank transfers with those orders.

The Swiss QR bill is also known as QR-Rechnung / QR-Einzahlungsschein (de),
QR-facture / bulletin de versement (fr) and QR-fattura / polizza di versamento (it).

## Features

- **QR bill per order** — generated at checkout or manually later, as a full A4 page
  or the A6 payment part only, stored in the media library.
- **Reference numbers** — random or derived from the order number, QR-IBAN and
  normal IBAN supported, so incoming payments can be matched automatically.
- **Payment reconciliation** — confirm payments manually per order or in bulk;
  automatic matching via EBICS / camt.053.
- **Flexible order status flow** — define which status new sqrip orders get and
  which status a confirmed payment leads to; custom statuses are supported.
  Optionally send the order-confirmation e-mails even for statuses that do not
  themselves trigger WooCommerce e-mails.
- **E-mail integration** — attach the QR bill to any WooCommerce e-mail template,
  or combine it with the invoice of *PDF Invoices & Packing Slips for WooCommerce*
  into a single PDF.
- **Refunds** — create a QR code to scan with your banking app for payouts.
- **Installments (beta)** — split an order into multiple QR bills by percentage.
- **Multilingual** — German, French and Italian translations are bundled
  (including the Swiss de_CH / fr_CH / it_CH variants); WPML supported.
- **Housekeeping** — automatically delete QR bills that are no longer needed,
  by order status or after a number of days.
- **Compatible** with WooCommerce High-Performance Order Storage (HPOS), the
  block-based checkout, and multi-store / multi-site setups.

## Requirements

| | |
|---|---|
| WordPress | 6.0 or higher |
| PHP | 7.4 or higher |
| WooCommerce | current versions, HPOS compatible |
| Account | a [sqrip account](https://api.sqrip.ch/login) and an API key |
| Bank | a (QR-)IBAN of a Swiss or Liechtenstein bank; invoices in CHF or EUR |

## Installation

Install **sqrip.ch** from the
[WordPress plugin directory](https://wordpress.org/plugins/sqrip-swiss-qr-invoice/)
(Plugins → Add New → search for "sqrip"), or upload a release ZIP under
Plugins → Add New → Upload Plugin.

Then open WooCommerce → Settings → Payments → **sqrip** and paste your API key.

## Repository layout

```
sqrip-woocommerce.php   plugin bootstrap, hooks, admin/e-mail integration
inc/                    gateway class, helpers, AJAX handlers, cron jobs, blocks
js/ · css/              admin and front-end assets
languages/              bundled .po/.mo translations + .pot template
assets/                 wordpress.org page assets (icon, screenshots)
```

## Development

- **Translations** are shipped with the plugin in `languages/`. When you add or
  change a translatable string, regenerate the `.pot`, translate the new strings
  and rebuild the `.mo` files. Always wrap strings with the text domain
  `sqrip-swiss-qr-invoice`, and never interpolate a variable into the translation
  call — use `sprintf( __( '… %s …', 'sqrip-swiss-qr-invoice' ), $value )`.
- **Releases** are deployed to wordpress.org by GitHub Actions
  (`.github/workflows/main.yml`, 10up plugin-deploy) when a version tag is pushed.
  Tag names must match the `Stable tag` in `README.txt` exactly — e.g. `1.10`,
  without a `v` prefix. `.distignore` controls what is excluded from the package.
- **Documentation** of the underlying API:
  [sqrip API reference](https://documenter.getpostman.com/view/32414298/2s9YsT58Nx).

## Links

- Website: [sqrip.ch](https://sqrip.ch/)
- Plugin page: [wordpress.org/plugins/sqrip-swiss-qr-invoice](https://wordpress.org/plugins/sqrip-swiss-qr-invoice/)
- Changelog: see [`README.txt`](README.txt)
- Support: [info@sqrip.ch](mailto:info@sqrip.ch)

## License

GPLv2 or later — © [netmex digital gmbh](https://sqrip.ch/)
