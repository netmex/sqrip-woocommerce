=== sqrip – Swiss QR Invoice ===
Contributors: sqrip
Donate link: https://sqrip.ch/
Tags: woocommerce, payment, sqrip, qrcode, qr, scan, swiss qr invoice, QR-Rechnung
Requires at least: 4.7
Tested up to: 5.8
Stable tag: 1.2.1
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

sqrip erweitert die Zahlungsmöglichkeiten von WooCommerce für Schweizer Shops und  Kunden um die neuen QR-Zahlungsteile.

== Description ==
Ende September 2022 werden die traditionellen Einzahlungsscheine (ESR) für Banküberweisungen in der Schweiz verschwinden. Als Ablösung folgt die "QR-Rechnung" (https://www.einfach-zahlen.ch/), welche im Juli 2020 eingeführt wurde. 

Um in Zukunft diese kostengünstige Zahlungsmöglichkeit mit all seinen Vorteilen anzubieten, haben wir sqrip entwickelt. sqrip für WooCommerce besteht aus einer universellen API (http://api.sqrip.ch/) sowie einem WordPress-Plugin, welches sich nahtlos mit WooCommerce verbindet und mit verschiedenen Optionen aufwartet. Das Plugin ist "open source" (https://github.com/netmex/sqrip-woocommerce) und lässt sich so für anderen Shopsysteme anpassen.

= Funktionsweise =
sqrip wird als zusätzliche Zahlungsmethode in WooCommerce aufgeführt und kann dort konfiguriert werden. Zwecks Identifikation und Abrechnung, aber auch aus Sicherheitsaspekten wird das Plugin über einen API Schlüssel/Token mit dem sqrip-Konto verbunden. Die QR-Rechnung wird von der API erstellt, ausgeliefert und im gewünschten Format in der Mediathek abgespeichert. Von dort kann die Datei an verschiedenen Orten integriert (z.B. als Beilage in die Bestätigungs-E-Mail) und jederzeit erneut geöffnet werden. Falls die Rechnung geändert wurde, kann mit einem Klick der QR-Zahlungsteil aktualisiert werden. Die Referenznummer wird prominent beim Auftrag angezeigt, damit ein Abgleich schnell möglich ist.

= Gut zu wissen =
- Die Rechnung aus WooCommerce wird nicht angetastet. Die QR-Rechnung ist ein eigenes PDF-Dokument.
- Als Empfängerkonto kann die normale IBAN oder die neue QR-IBAN verwendet werden. Mit einer QR-IBAN können Zahlungen nur mit Angabe einer QR-Referenz(nummer) ausgeführt werden. Damit lässt sich jede einzelne Einzahlung eindeutig einem Kunden / einer Bestellung zuweisen. Der automatische Abgleich der eingegangenen Zahlungen mit den Bestellungen wird so möglich. Dies ist Grundlage für eine weitere (Teil-)Automatisierung.
- Ein sqrip-Konto kann mit mehreren Shops verbunden werden. Es sind mehrere API Schlüssel möglich. 
- Jeder API Schlüssel sollte nach einer selbst gewählten Dauer ersetzt werden. Es besteht daher die Möglichkeit, ein Ablaufdatum zu definieren. Nach diesem Tag kann ohne Anpassungen des Shop-Eigentümers keine QR-Rechnung mehr angeboten werden. Es muss ein neuer API-Schlüssel erstellt und verlinkt werden. 

= Optionen =
sqrip bietet verschiedene Optionen an:

a) Name und Beschreibung der Zahlungsmöglichkeit
Nenne Sie die Zahlungsmethode 'Banküberweisung' oder 'Einzahlung' oder wie immer Sie wollen.

b) Zahlungsempfänger
Der Zahlungsempfänger wird automatisch vom sqrip-Konto oder den WooCommerce-Einstellungen übernommen. Eine manuelle Anpassung ist möglich.

c) (QR-)IBAN
Die Bankverbindung, auf die der Rechnungsbetrag überwiesen werden soll. Wird diese Kontonummer absichtlich oder unabsichtlich geändert, wird der Eigentümer des sqrip-Kontos über diese Änderung per E-Mail informiert. Er kann die Änderung aktiv bestätigen oder passiv zulassen. 

d) (QR-)Referenznummer
Die Referenznummer wird entweder nach dem Zufallsprinzip erstellt oder auf Basis der Bestell-Nummer berechnet. Sie passt sich automatisch dem verwendeten IBAN-Format an.

e) Fälligkeitsdatum
Die dem Zahlungspflichtigen gewährte Zeit bis zur Begleichung der Rechnung kann als Text auf dem Zahlungsteil mitgeteilt werden.

f) Einbindung
Die erstellte QR-Rechnung wird der Bestätigungs-E-Mail beigelegt. Sie kann auch auf der Bestätigungsseite zum Download angeboten werden.

g) E-Mail Beilage
Die QR-Rechnung kann in zwei Arten der E-Mail beigelegt werden:
- Seite A4 (leer) mit Zahlungsteil unten
- nur den Zahlungsteil (früher "Einzahlungsschein) im Format A6

h) Sprache
Die auf den QR-Rechnung zu verwendende Sprache (de, fr, it, en) lässt sich pro Shop einstellen.

i) Test E-Mail
Mit einem Klick testen Sie die Einstellungen und sehen, wie die QR-Rechnung bei Ihren Kunden ankommt.

= Anforderungen =
1. Neben einer aktuellen WordPress und WooCommerce Installation wird ein Konto auf sqrip.ch benötigt. 
2. Sie benötigen eine (QR-)IBAN einer Schweizer/Liechtensteiner Bank.
3. Die Kunden müssen Zahlungen mit dieser Methode überweisen können.
4. Rechnungsbeträge müssen in CHF oder EUR lauten.

= Datenschutz =
- Die an sqrip zwecks Erstellung der QR-Rechnung übermittelten Daten (z.B. Zahlungspflichtiger, Betrag) werden nach der Auslieferung der Datei umgehend wieder gelöscht. 
- Auf https://api.sqrip.ch wird jede Produktion/Auslieferung in einem Logbuch mit Datum/Uhrzeit, Herkunft (z.B. WooCommerce), aufgerufenem API Schlüssel und ausgeliefertem Produkt erfasst. 

== Frequently Asked Questions ==
= Was brauche ich zum Starten? =  
- Ein sqrip Konto (http://api.sqrip.ch/login); 
- Einen API Schlüssel, der im Konto erstellt werden kann;
- Eine (QR-)IBAN.

= Was kostet mich eine QR-Rechnung? = 
Wir rechnen nach effektiv genutzten QR-Rechnungen ab. Eine QR-Rechnung kostet 1 Credit. Credits können in Paketen zu 100 Stk. (für CHF 20) bis 20'000 Stk. (für CHF 1'000) – jeweils zzgl. 7.7% MWST – gekauft werden. Der günstigste Preis für eine QR-Rechnung ist somit 5 Rappen (CHF 0.05).

= Kann ich die Lösung kostenlos ausprobieren? =
Ja. Die Anmeldung (http://api.sqrip.ch/login) ist kostenlos. Es werden keine Kreditkartendetails benötigt. Zum Ausprobieren gibt es 20 Credits. Damit lassen sich alle Funktionen testen (Test E-Mail!). Im Anschluss sind Pakete mit Credits zu kaufen. Wenn Ihnen die Leistung nicht passt, können Sie das Konto einfach wieder löschen.

= Wird der Dienst weiterentwickelt? =
Ja. Wir arbeiten bereits daran, den Abgleich der Aufträge/Bestellungen mit den eingegangenen Zahlungen auf dem Bankkonto zu vergleichen und so den Status einer Bestellung automatisch nachzuführen. Zudem möchten wir die Rückerstattung über den üblichen Bank-Weg ermöglichen. Unser Ziel bleibt: Eine günstige, einfache, vollwertige und verlässliche Zahlungsmethode anzubieten. Ihre Ideen nehmen wir dazu gerne entgegen.

= Welches sind die besten Gründe, sqrip bei unserem Shop einzusetzen? =
- sqrip ist in 5 Minuten eingerichtet – IT-Kenntnisse sind nicht nötig.
- sqrip erkennt, wenn ungebetene Gäste die Zahlungsempfäger-IBAN zu ihren Gunsten änderen.
- sqrip wurde von Shop-Betreibern entwickelt – wir kennen die Bedürfnisse unserer Kunden.
- Wir wollen selbst nicht stehen bleiben. Deshalb entwickeln wir sqrip weiter. Die Roadmap steht. 
- Nur gratis ist noch billiger. Eine QR-Rechnung gibt es ab 5 Rappen. Bei Transaktionen mit Kreditkarte oder Twint sind nur schon die fixen Beträge höher.

== Screenshots ==
1. Verbindungseinstellungen und Bezeichnung
2. Zahlungsempfänger
3. Anzeige
4. sqrip API Schlüssel

== Changelog ==
= 1.2.1 =
* Bilder aktualisiert

= 1.2 =
* Ermöglicht die QR-Rechnung pro Bestellung zu aktualisieren;
* Anpassung an Beschreibung und der Bilder;
* Kleinere Fehlerbehebungen.

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
* Erlaubt Anpassung Adresse Zahlungsempfänger;
* Wir unterscheiden nun zwischen IBAN und QR-IBAN

= 1.0.2 =
* Bugfixing

= 1.0.1 =
* Bugfixing

= 1.0 =
* Es geht los!

== Upgrade Notice ==
= 1.0 =
=======
