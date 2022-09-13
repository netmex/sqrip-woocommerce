=== sqrip – Swiss QR Invoice ===
Contributors: sqrip
Donate link: https://sqrip.ch/
Tags: woocommerce, payment, sqrip, qrcode, qr, scan, Kontoabgleich, swiss qr invoice, QR-Rechnung, EBICS, QR-facture, bulletins de versement, Einzahlungsschein, QR-Einzahlungsschein, bulletins de versement, Swiss QR Code, code QR, QR-fattura, polizze di versamento
Requires at least: 4.7
Tested up to: 6.0
Stable tag: 1.5.3
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

sqrip – A comprehensive and clever WooCommerce finance tool for the most widely used payment method in Switzerland: the bank transfers. 

== Description ==
At the end of September 2022, the traditional inpayment slips (ISR) for bank transfers in Switzerland will disappear. The replacement will be the "QR bill" (https://www.einfach-zahlen.ch/), which was introduced in July 2020.

In order to offer this cost-effective payment option with all its advantages in the future, we have developed sqrip. sqrip for WooCommerce consists of a universal API (http://api.sqrip.ch/) and a WordPress plugin, which connects seamlessly with WooCommerce and comes up with various options. The plugin is "open source" (https://github.com/netmex/sqrip-woocommerce) and can thus be adapted for other store systems.

= Functionality =
sqrip is listed as an additional payment method in WooCommerce and can be configured there. For identification and billing purposes, but also for security aspects, the plugin is connected to the sqrip account via an API key/token. The QR invoice is created by the API, delivered and saved in the desired format in the media library. From there, the file can be integrated in various places (e.g. as an insert in the confirmation email) and reopened at any time. If the invoice has been changed, the QR payment part can be updated with one click. The reference number is prominently displayed with the order so that reconciliation is quickly possible.

= Good to know =
- The invoice from WooCommerce is not touched. The QR invoice is a separate PDF document.
- The normal IBAN or the new QR-IBAN can be used as the recipient account. With a QR-IBAN, payments can only be executed with the specification of a QR reference (number). This allows each individual deposit to be uniquely assigned to a customer / order. Automatic matching of payments received with orders is thus possible. This is the basis for further (partial) automation.
- One sqrip account can be connected to multiple stores. Multiple API keys are possible.
- Each API key should be replaced after a self-selected duration. Therefore, it is possible to define an expiration date. After this date, QR Invoice can no longer be offered without adjustments from the store owner. A new API key must be created and linked.

= Options =
sqrip offers several options:

a) Name and description of payment method
Name the payment method 'Bank Transfer' or 'Deposit' or whatever you want.

b) b) Payee
The payee is automatically taken from the sqrip account or the WooCommerce settings. A manual adjustment is possible.

c) (QR-)IBAN
The bank account to which the invoice amount should be transferred. If this account number is changed intentionally or unintentionally, the owner of the sqrip account will be informed of this change by e-mail. He can actively confirm the change or passively allow it.

d) (QR) reference number
The reference number is either created randomly or calculated on the basis of the order number. It automatically adapts to the IBAN format used.

e) additional information
On up to three lines additional information can be added to the QR invoice. This includes 
- the due date (The time given to the payer to settle the invoice may be communicated as text on the payment part.).
- the order number (Be aware: sqrip not use the order# of the plugin "WoCommerce Sequential Order Numbers")
- any additional text (e.g. URL of webshop, thank you message)
This fiels supports WPML.

f) Integration
Define the e-mail to which the qr-invoice will be attached to.
It can also be offered for download on the confirmation page.

g) E-mail enclosure
The QR invoice can be enclosed with the e-mail in two ways:
- page A4 (blank) with payment section at the bottom
- only the payment section (formerly "payment slip") in A6 format.

h) Language
- The default language to be used on the QR invoice (de, fr, it, en) can be set per store.
- WPML is supported. For multilanguage sites, it's possible to display the QR invoice in the language selected by the customer.

i) Test e-mail
With one click, you can test the settings and see how the QR invoice is received by your customers.

j) Add sqrip payment method manually
Add sqrip as payment method for manually created orders.

k) Use sqrip QR-Codes for Refunds
Scan the QR-Code with your Banking App to initiate refunds. Remember: IBAN of client required!


= Requirements =
1. Besides a current WordPress and WooCommerce installation, an account on sqrip.ch is required.
2. You need a (QR-)IBAN of a Swiss/Liechtenstein bank.
3. Customers must be able to transfer payments using this method.
4. Invoice amounts must be in CHF or EUR.

= Privacy =
- The data transmitted to sqrip for the purpose of creating the QR invoice (e.g. payer, amount) will be deleted immediately after delivery of the file.
- On https://api.sqrip.ch each production/delivery is recorded in a logbook with date/time, origin (e.g. WooCommerce), API key called and product delivered.

== Frequently Asked Questions ==
= What do I need to start? =  
- A sqrip account (http://api.sqrip.ch/login); 
- An API key that can be created in the account;
- A (QR) IBAN.

= How much will a QR invoice cost me? = 
We charge according to actually used QR invoices. One QR invoice costs 1 credit. Credits can be purchased in packages of 100 pcs. (for CHF 20) to 20'000 pcs. (for CHF 1'000) - each plus 7.7% VAT. The lowest price for a QR-bill is therefore 5 centimes (CHF 0.05).

= Can I try the solution for free? =
Yes. Registration (http://api.sqrip.ch/login) is free of charge. No credit card details are required. There are 20 credits to try it out. With this you can test all functions (test e-mail!). Afterwards you can buy packages with credits. If you do not like the service, you can simply delete the account again.

= Will the service be developed further? =
Yes. We are already working on comparing the reconciliation of orders/purchases with the payments received on the bank account, thus automatically tracking the status of an order. Our goal remains: To offer a cheap, simple, full-value and reliable payment method. We are happy to receive your ideas for this.

= What are the best reasons to use sqrip with our store? =
- sqrip is set up in 5 minutes - IT knowledge is not necessary.
- sqrip detects when uninvited guests change the payee IBAN in their favor.
- sqrip was developed by store operators - we know the needs of our customers.
- We do not want to stand still ourselves. That's why we continue to develop sqrip. The roadmap stands.
- Only free is even cheaper. A QR bill is available from 5 centimes. For transactions with credit card or Twint, only the fixed amounts are higher.

== Screenshots ==
1. Connection settings and designation
2. Payee
3. Display
4. sqrip API Key

== Changelog ==

= 1.5.2 =
* New pictures added to wordpress entry.
* deleted the hash # in the default field after report of problems with certain banking Apps.
* ZIP-Codes with initial "CH-" are now possible.
* Added company field to payee address.

= 1.5.1 =
* Use default settings if sqrip WC options have not been set before.

= 1.5 =
* Added company field to QRCode;
* More flexibility with additional information on the QR invoice;
* Remove Duplicate due date field;
* Text adjustments;
* Minor bug fixes.

= 1.4 =
* Refunds;
* Allow adding sqrip payment method to manually added orders;
* Define the order status that will add the qr-invoice to the outgoing e-mail;
* PDF files are replaced with new version when QR-invoice is renewed;
* Support for WPML;
* Plugin in available in French and Italian;
* Minor bug fixes.

= 1.3.1 =
* Plugin is now available in German;
* Adjustments in text strings.

= 1.3 =
* Public Beta: Refunds;
* Multiple bug fixes.

= 1.2.1 =
* Pictures updated

= 1.2 =
* Allows to update the QR invoice per order;
* Adaptation to description and the images;
* Minor bug fixes.

= 1.1 =
* Validates API key and connection to api.sqrip.ch;
* Integrates security process when (QR) IBAN is changed: Informs account holder of change which must be either actively confirmed or passively allowed. Disables payment method as a precaution;
* Allowed adjustment address payee;
* Checks (QR) IBAN and explains difference or advantages;
* Allows (QR) reference numbers based on a random number or the order number;
* Simplified delivery options and added due date;
* Enables sending a test email to the store admin;
* Revision of information display;
* Minor bug fixes.

= 1.0.3 =
* Allowed adjustment address payee;
* We now distinguish between IBAN and QR-IBAN.

= 1.0.2 =
* Bugfixing

= 1.0.1 =
* Bugfixing

= 1.0 =
* Here we go!

== Upgrade Notice ==
= 1.0 =
=======
