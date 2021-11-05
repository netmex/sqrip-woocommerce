=== sqrip – Swiss QR Invoice ===
Contributors: sqrip
Donate link: https://sqrip.ch/
Tags: woocommerce, payment, sqrip, qrcode, qr, scan, swiss qr invoice, QR-Rechnung
Requires at least: 4.7
Tested up to: 5.8
Stable tag: 1.1.2
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

sqrip erweitert die Zahlungsmöglichkeiten von WooCommerce für Schweizer Shops und Schweizer Kunden um die neuen QR-Zahlungsteile.

== Description ==
Ende September 2022 werden die traditionellen Einzahlungsscheine (ESR) für Banküberweisungen in der Schweiz verschwinden. Als Ablösung folgt die"QR-Rechnung" (https://www.einfach-zahlen.ch/), welche im Juli 2020 eingeführt wurde. 

Um in Zukunft diese kostengünstige Zahlungsmöglichkeit mit all seinen Vorteilen anzubieten, haben wir sqrip entwickelt. sqrip für WooCommerce besteht aus einer universellen API (http://api.sqrip.ch/) sowie einem WordPress-Plugin, welches sich nahtlos mit WooCommerce verbindet und mit verschiedenen Optionen aufwartet. Das Plugin ist "open source" (https://github.com/netmex/sqrip-woocommerce) und lässt sich so für anderen Shopsysteme anpassen.

= Funktionsweise =
sqrip wird als zusätzliche Zahlungsmethode in WooCommerce aufgeführt und kann dort konfiguriert werden. Zwecks Identifikation und Abrechnung wird das Plugin über einen API Schlüssel/Token mit dem sqrip-Konto verbunden. Die verschiedenen Produkte (siehe unten) werden von der API erstellt, ausgeliefert und im gewünschten Format in der Mediathek abgespeichert. Von dort kann das Produkt an verschiedenen Orten integriert (z.B. als Beilage in die Bestätigungs-E-Mail) und jederzeit erneut geöffnet werden. Falls die Rechnung geändert wurde, kann mit einem Klick der QR-Zahlungsteil aktualisiert werden.

= Gut zu wissen =
- Die Rechnung aus WooCommerce wird nicht angetastet. Der QR-Zahlungsteil ist ein eigenes Dokument.
- Die Referenznummer wird entweder nach dem Zufallsprinzip erstellt oder auf Basis der Bestell-Nummer berechnet.
- Um die Kunden zu zwingen, selbst bei manueller Eingabe der Zahlung im e-Banking die Referenz-Nummer anzugeben, empfehlen wir die Verwendung einer QR-IBAN, welche im e-Banking beim Konto zu finden ist oder bei der Bank erfragt werden kann.
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

e) (QR-)IBAN der Bankverbindung, auf die der Betrag überwiesen wird.

= Anforderungen =
1. Neben einer aktuellen WordPress und WooCommerce Installation wird ein Konto auf sqrip.ch benötigt. 
2. Sie benötigen eine (QR-)IBAN einer Schweizer/Liechtensteiner Bank.
3. Die Kunden müssen Zahlungen mit dieser Methode überweisen können.
4. Rechnungsbeträge müssen in CHF oder EUR lauten.

= Datenschutz =
Die an sqrip zwecks Erstellung der QR-Rechnungsprodukt übermittelten Daten werden nach der Auslieferung der Produkte umgehen wieder gelöscht. 

== Frequently Asked Questions ==
= What information do I need? = 
You need 
- a sqrip account (http://api.sqrip.ch/login); 
- an API Key from that account;
- a (QR-)IBAN

= Where to create a sqrip account?
http://api.sqrip.ch/login

= Where is sqrip API documentation? = 
https://documenter.getpostman.com/view/2535172/TW6xnoDp

== Screenshots ==
1. The Settings
2. Admin Order
3. QR-Code
4. sqrip API Key

== Changelog ==
= 1.1 =
* Validiert API-Schlüssel und Verbindung zu api.sqrip.ch;
* Integriert Sicherheitsprozess bei Änderung der (QR-)IBAN: Informiert Konto-Inhaber über Änderung die entweder aktiv bestätigt oder passiv zugelassen werden muss. Deaktiviert vorsorglich Zahlungsmethode;
* Erlaubt Anpassung Adresse Zahlungsempfänger;
* Prüft (QR-)IBAN und erklärt Unterschied bzw. Vorteile;
* Ermöglicht (QR-)Referenznummern auf Basis einer zufälligen Nummer oder der Bestellnummer;
* Vereinfacht Lieferungsoptionen und ergänzt Fälligkeitsdatum;
* Ermöglicht den Versand einer Test-E-Mail an den Shop-Admin;
* Überarbeitung Informationsdarstellung;
* Kleinere Fehlerbehebungen.

= 1.0.3 =
* Add address options in settings
* Add IBAN options in settings

= 1.0.2 =
* Add check for legacy pdf file meta value

= 1.0.1 =
* Remove deprecated "payable_to" field
* Fix error handling

= 1.0 =
* First commit

== Upgrade Notice ==
= 1.0 =
=======
