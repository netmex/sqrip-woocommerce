=== sqrip.ch ===
Contributors: sqrip
Donate link: https://sqrip.ch/
Tags: woocommerce, payment, sqrip, qrcode, qr, scan, Kontoabgleich, swiss qr invoice, QR-Rechnung, EBICS, QR-facture, bulletins de versement, Einzahlungsschein, QR-Einzahlungsschein, bulletins de versement, Swiss QR Code, code QR, QR-fattura, polizze di versamento
Requires at least: 4.7
Tested up to: 6.5.2
Stable tag: 1.8.3
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

sqrip – A comprehensive, flexible and clever WooCommerce finance tool for the most widely used payment method in Switzerland: the bank transfers. 

== Description ==
At the end of September 2022, the traditional inpayment slips (ISR) for bank transfers in Switzerland have disappeared. The replacement is the "QR bill" (https://www.einfach-zahlen.ch/), which was introduced in July 2020.

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
The reference number is either created randomly or calculated on the basis of the order number. Inital 6 digits can be defined for easier identification. It automatically adapts to the IBAN format used.

e) additional information
On up to five lines or 140 characters additional information can be added to the QR invoice. This includes 
- the due date (The time given to the payer to settle the invoice may be communicated as text on the payment part.).
- the order number (Be aware: sqrip not use the order# of the plugin "WoCommerce Sequential Order Numbers")
- any additional text (e.g. URL of webshop, thank you message)
This field supports WPML.

f) Integration
Define the e-mail to which the qr-invoice will be attached to.
It can also be offered for download on the confirmation page.
If you generally need to adjust pricing or quantity after an order has been placed, you can suppress a QR invoice generation at the checkout and generate it manually later. 

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
- Suppress the creation of the QR invoice at checkout and define the status of the order.
- Add sqrip as payment method for manually created orders.

k) Use sqrip QR-Codes for Refunds
Scan the QR-Code with your Banking App to initiate refunds. Remember: IBAN of client required!

l) Manual payment comparison
Once an order has a defined order status you can confirm that the payment was done by the client and the status of the order can be changed to another status. If there is no suitable status available, you can create one in seconds. You can confirm the payments either on the list of orders or on the order detail page. 

m) Payer Name
For corporate payers, choose to show either the Company name or the Firstname / Name. Or show all names together.

n) File Name
The QR invoice file names can be defined individually. Add date, order number and any other information as shop name to make the QR invoice more personal.

o) Delete unneeded QR invoice automatically
Keeps the size of the media library small. sqrip deletes all QR invoice files if
- certain status of the order are met (e.g. Cancelled);
- x days after the creation have passed.

p) Adjustable to your process 
sqrip is flexible enough to be adopted to your individual process. 
- Define your own order status for payments made with sqrip;
- Define the moment you expect the payment to arrive (prior to shipment or thereafter);
- Define the status after payment has arrived.

q) Shows when something is wrong, turns off automatically
Instead of showing technical, unuseful error messages to your clients, we turn the service off automatically and show you where to look at for resolving the issue.

= Requirements =
1. Besides a current WordPress and WooCommerce installation, an account on sqrip.ch is required.
2. You need a (QR-)IBAN of a Swiss/Liechtenstein bank.
3. Customers must be able to transfer payments using this method.
4. Invoice amounts must be in CHF or EUR.

= Privacy =
- The data transmitted to sqrip for the purpose of creating the QR invoice (e.g. payer, amount) will be deleted within a defined period.
- On https://api.sqrip.ch each production/delivery is recorded in a logbook with date/time, origin (e.g. WooCommerce), API key called and product delivered.

== Frequently Asked Questions ==
= What do I need to start? =  
- A sqrip account (http://api.sqrip.ch/login); 
- An API key that can be created in the account;
- A (QR) IBAN.

= How much will a QR invoice cost me? = 
We charge according to actually used QR invoices. One QR invoice costs 1 credit. Credits can be purchased in packages of 100 pcs. (for CHF 20) to 20'000 pcs. (for CHF 1'000) - each plus VAT. The lowest price for a QR-bill is therefore 5 centimes (CHF 0.05).

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
1. Connection and activation settings
2. Naming
3. Payee information
4. QR invoice detail settings
5. Process definition and house keeping
6. Manual Payment Comparison
7. Refund functionality

== Changelog ==

= 1.8.3 : April 2024 – Performance =
* Performance improvement.

= 1.8.2 : April 2024 – adjustments in API Call =
* We reduced the amount of data needed to perform an API call.

= 1.8.1 : March 2024 – minor adjustments =
* Date format adjusted to PHP 8.2.

= 1.8 : March 2024 – Major Service Update =
* Working with PHP 8.2, Wordpress 6.4 and Block Checkout;
* Added a new Tab "Services";
* Added the number of remaining credits;
* Added 'Current sqrip status': See what's wrong to fix it quickly.
* Added 'Auto Turn-off' functionality: Should any parameter be wrong (e.g. no credits left, API key inactive, unknown errors), sqrip will turn itself off in order to prevent any errors visible for the shop clients;
* Define an individual status for orders made with the sqrip payment method;
* The status can be changed for multiple orders now, incl. to the status defined by the merchant;
* Select that no QR bill must be attached to any e-mail;
* Minor bug fixing.

= 1.7.5 : November 2023 – Service Update =
* Adding the Service Tab;
* Assign you own status to new orders made with sqrip; 
* Allows to select your own status in the list of orders;
* Easy to understand error messages;
* Minor bug fixing.

= 1.7.4 : June 2023 – Service Update =
* Automatic changes in order status are prevented. 

= 1.7.3 : May 2023 – Service Update =
* Issues with file name adjustments solved;
* Payment Comparison can be turned off, preventing order status mix-up;
* Problems in some instances with the API token verification resolved.

= 1.7.2 : May 2023 – Service Update =
* Link to updated QR invoice corrected;
* Individual file naming corrected;
* Potential error in attribution of QR invoices to e-mails corrected;
* Empty "additional information" field does no longer trigger an error;
* In case of a refund to a shop client: hint to unknown IBAN is shown;
* Minor Bug fixes.

= 1.7 : April 2023 =
* To save space in your media library on your server the QR Invoices (PDF) are automatically deleted, if certain status of the order are met (e.g. Cancelled) or x days after the creation have passed;
* Inital 6 digits for Reference Numbers are now available in combination with regular IBAN. Letters are possible (e.g. "RF39 SQRI PX11 1115 2023 0331 0");
* The reference numbers are now shown in groups;
* Define a suitable order status when waiting for payments or when no QR invoice has been created;
* Show message when the (QR-)IBAN in the plugin is different from the one on api.sqrip.ch;
* Bug fixing.

We made users (even more) happy with these changes:
* Give individual names to your QR invoice files. Add your shop name, the order number and the order date (e.g. QRRechnung_babytuch_20230331_Bestellung_2503.pdf).
* For corporate payers with a contact person both names can be added to the QR invoice. Optional only one of them is shown;
* Manually created orders do not need the e-mail of the client anymore.

= 1.6 : March 2023 =
* Validation of API keys shows API key name for better identification;
* A hint placed right of the button for sending test e-mails tells you if a bought credit is used for it;
* Test e-mails include initial numbers of QR-Reference;
* Design and Text improvements;
* Bug fixing.

We made users (even more) happy with these changes:
* Plugin is no longer loaded on every page;
* Supressing generation of QR-reference at checkout;
* Manually added orders requires less data, shows specific error message when mandatory fields are void;
* Select the order status for unpaid and paid orders. Should there be no suitable status for paid orders (e.g. "paid, processing"), add one quickly;
* Easier manual payment comparison on list of orders page and on order detail page. With just one click the status is updated;
* allowing allow_url_open in the PHP settings to prevent issues with downloading and local storing of PDF.

= 1.5.6 =
* Content of 'Additional Information' is correctly shown (with some dummy data) in the test e-mail.

= 1.5.5 =
* Bug fixes

= 1.5.4 =
* Add Refund token validation;
* Shows QR-invoice on checkout screen;
* Deletes unnecessary/old QR-invoices in media library.

= 1.5.3 =
* Bug fixes

= 1.5.2 =
* New pictures added to wordpress entry;
* deleted the hash # in the default field after report of problems with certain banking Apps;
* ZIP-Codes with initial "CH-" are now possible;
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
