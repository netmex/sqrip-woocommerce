=== sqrip – Swiss QR Invoice ===
Contributors: sqrip
Donate link: https://www.sqrip.ch/
Tags: woocommerce, payment, sqrip, qrcode, qr, scan, swiss qr invoice
Requires at least: 4.7
Tested up to: 5.7.2
Stable tag: 1.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

sqrip erweitert die Zahlungsmöglichkeiten von WooCommerce für Schweizer Shops und Schweizer Kunden um die neuen QR-Zahlungsteile.

== Description ==
Ende September 2021 werden die traditionellen Einzahlungsscheine (ESR) für Banküberweisungen in der Schweiz verschwinden. Als Ablösung folgt die"QR-Rechnung" (https://www.paymentstandards.ch/), welche im Juli 2020 eingeführt wurde. 

Um auch in Zukunft diese kostengünstige Zahlungsmöglichkeit anzubieten, haben wir sqrip entwickelt. sqrip für WooCommerce besteht aus einer universellen API (http://api.sqrip.ch/) sowie einem WordPress-Plugin, welches sich nahtlos mit WooCommerce verbindet und mit verschiedenen Optionen aufwartet. Das Plugin ist "open source" (https://github.com/) und lässt sich so für anderen Shopsysteme anpassen.

= Funktionsweise =
sqrip wird als zusätzliche Zahlungsmethode in WooCommerce aufgeführt und kann dort konfiguriert werden. Zwecks Identifikation und Abrechnung wird das Plugin über einen API Schlüssel/Token mit dem sqrip-Konto verbunden. Die verschiedenen Produkte (siehe unten) werden von der API erstellt, ausgeliefert und im gewünschten Format in der Mediathek abgespeichert. Von dort kann das Produkt an verschiedenen Orten integriert (z.B. als Beilage in die Bestätigungs-E-Mail) und jederzeit erneut geöffnet werden. Falls die Rechnung geändert wurde, kann mit einem Klick der QR-Zahlungsteil aktualisiert werden.

= Gut zu wissen =
- Die Rechnung aus WooCommerce wird nicht angetastet. Der QR-Zahlungsteil ist ein eigenes Dokument.
- Die Referenznummer wird nach dem Zufallsprinzip erstellt.
- Ein sqrip-Konto kann mit mehreren Shops verbunden werden.

= Optionen =
sqrip bietet verschiedene Optionen an:

a) Produkte
- Seite A4 (leer) mit Zahlungsteil unten
- nur den Zahlungsteil (früher "Einzahlungsschein)
- nur den QR-Code

b) Formate
- PDF
- PNG
- SVG

c) Integration in E-Mail
- im Text
- als Beilage
- sowohl im Text wie auch als Beilage

d) Fälligkeitsdatum (= Tage nach Rechnungsstellung)

e) IBAN der Bankverbindung, auf die der Betrag überwiesen wird

= Anforderungen =
1. Neben einer aktuellen WordPress und Wocommerce Installation wird ein Konto auf sqrip.ch benötigt. 
2. Sie benötigen eine (QR-)IBAN einer Schweizer/Liechtensteiner Bank.
3. Die Kunden müssen Zahlungen mit dieser Methode überweisen können.
4. Rechnungsbeträge müssen in CHF oder EUR lauten.

= Datenschutz =
Die an sqrip zwecks Erstellung der QR-Rechnungsprodukt übermittelten Daten werden nach der Auslieferung der Produkte umgehen wieder gelöscht. 

== Frequently Asked Questions ==
= What information do I need? = 
You need to create sqrip account and then from sqrip dashboard get API Key.

= Where to create a Sqrip account?
http://api.sqrip.ch/login

= Where is Sqrip API documentation? = 
https://documenter.getpostman.com/view/2535172/TW6xnoDp

== Screenshots ==
1. The Settings
2. Admin Order
3. QRCode
4. Sqrip API Key

== Changelog ==
= 1.0 =
* First commit

== Upgrade Notice ==
= 1.0 =
