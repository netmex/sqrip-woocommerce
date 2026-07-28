# sqrip — Prozesskonfigurator

Stand: 27.07.2026 · Vorgeschlagene Zielversion: **2.0** (Vorstufen ab 1.12)

---

## Das Problem

sqrip kann heute jeden dieser Abläufe — aber niemand sieht ihm das an:

| | Ablauf |
|---|---|
| A | Rechnung sofort → Zahlung abwarten → Ware versenden *(Babytuch)* |
| B | Bestellung prüfen und anpassen → Ware versenden → Rechnung nachschicken |
| C | Ware sofort versenden → Rechnung hinterher |
| D | Anzahlung → Ware versenden → Restzahlung |
| E | zwei dieser Abläufe parallel, je nach Kundengruppe |

Dazu kommen jetzt Skonto und Mahngebühr, die jeden dieser Abläufe nochmals verzweigen.

**Der Ablauf ist nirgends benannt.** Er ergibt sich aus dem Zusammenspiel von rund
vierzig Einstellungen, verteilt über fünf Reiter. Der Händler muss rückwärts denken:
„Welche Häkchen ergeben zusammen den Prozess, den ich will?" Das ist der Grund, warum
man viel ausprobieren muss — und warum Fehlkonfigurationen erst auffallen, wenn eine
echte Bestellung im falschen Status hängt.

**Und für Fall E gibt es heute gar keine Lösung.** Alle Einstellungen sind global.
Zwei Kundengruppen mit zwei Abläufen sind mit einer Installation nicht abbildbar.

---

## Was heute den Prozess bestimmt

Von 110 Einstellungsfeldern sind diese prozessrelevant — sie müssten sich also je
Prozess unterscheiden können:

**Wann entsteht die Rechnung**
`suppress_generation`, `status_suppressed`, `qr_order_status`

**Was für eine Rechnung**
`multiple_qr_slips_enabled`, `number_of_invoices`, `invoice_fraction_1..3`,
`skonto_*` (5 Felder), `due_date`, `additional_information`

**Wann geht sie an den Kunden**
`email_attached`, `qr_order_status_send_emails`, `integration_order`

**Wie wird Zahlung festgestellt**
`payment_comparison_enabled`, `camt_reconciliation_enabled`, `status_awaiting`

**Was passiert danach**
`status_completed`, `partial_invoice_1..3_status`, `delete_invoice_status`

**Wenn nicht gezahlt wird**
`reminder_*` (9 Felder)

Rein global bleiben: Konto und Adresse (`token`, `iban`, `address_*`), Darstellung
(`title`, `description`, `icon_height`, `frontend_anrede`), Referenzformat, Dateiname,
Sprache, Produkt. Diese Trennung ist die Grundlage für alles Weitere.

---

## Machbarkeit: ja — mit einer klaren Grenze

**sqrip besitzt den Bestellprozess nicht.** WooCommerce tut das. sqrip erzeugt
Rechnungen und reagiert auf Ereignisse. Ein Konfigurator kann deshalb **nicht** den
Warenversand steuern, keine Lagerprozesse abbilden und WooCommerce keine Reihenfolge
vorschreiben.

Was er kann — und was das Problem tatsächlich löst: **festlegen, wann sqrip was tut,
bezogen auf die Ereignisse, die WooCommerce ohnehin liefert.** Der Händler beschreibt
seinen Ablauf; sqrip klinkt sich an den richtigen Stellen ein. Der Versandschritt
erscheint im Konfigurator als Wegmarke, an der sqrip etwas auslöst — nicht als etwas,
das sqrip ausführt.

Diese Grenze muss in der Oberfläche sichtbar sein, sonst verspricht der Konfigurator
mehr, als er hält.

### Das Vokabular

**Auslöser**, die sqrip beobachten kann:

- Bestellung eingegangen
- Bestellstatus wechselt auf X
- x Tage nach Fälligkeit / nach Rechnungsstellung
- Händler drückt einen Knopf
- Zahlung erkannt (manuell bestätigt oder über den camt-Abgleich)

**Aktionen**, die sqrip ausführen kann:

- QR-Rechnung erzeugen (normal · mit Skonto · als Raten · als Mahnung)
- Rechnung per E-Mail senden
- Bestellstatus setzen
- Rechnung entwerten
- Gebühr auf die Bestellung buchen

Ein Prozess ist eine geordnete Liste von **Auslöser → Aktion**.

### Die fünf Abläufe in diesem Vokabular

Der Beweis, dass das Modell trägt:

**A — Rechnung zuerst**
1. Bestellung eingegangen → Rechnung erzeugen → per E-Mail → Status „wartet auf Zahlung"
2. Zahlung erkannt → Status „abgeschlossen"

**B — Prüfen, anpassen, dann Rechnung**
1. Bestellung eingegangen → *keine* Rechnung → Status „in Prüfung"
2. Händler drückt „QR-Rechnung erzeugen" → Rechnung → per E-Mail → „wartet auf Zahlung"
3. Zahlung erkannt → „abgeschlossen"

**C — Ware zuerst**
1. Bestellung eingegangen → Rechnung erzeugen → Status „in Bearbeitung"
2. Status wechselt auf „versandt" → Rechnung per E-Mail
3. 10 Tage nach Fälligkeit → Mahnung mit Gebühr
4. Zahlung erkannt → „abgeschlossen"

**D — Anzahlung**
1. Bestellung eingegangen → 2 Teilrechnungen (50/50) → erste per E-Mail
2. Rate 1 erkannt → Status „Anzahlung erhalten"
3. Status wechselt auf „versandt" → zweite Rate per E-Mail
4. Rate 2 erkannt → „abgeschlossen"

**E** — A und B nebeneinander, Auswahl über die Kundengruppe.

Dabei fällt eine Lücke auf: Schritt 2 in C und Schritt 3 in D — *„Status wechselt auf
X → Rechnung senden"* — gibt es heute nicht als einstellbare Regel. Der E-Mail-Versand
hängt an WooCommerce-Vorlagen, nicht an frei wählbaren Auslösern. Das ist die einzige
echte Funktionslücke, die der Konfigurator schliessen muss; alles andere ist Umbau der
Bedienung.

---

## Architekturentscheide

**1. Der Konfigurator schreibt die bestehenden Einstellungen — er ersetzt sie nicht.**

Er ist eine geführte Oberfläche über dieselben Optionen. Damit bleibt jeder bestehende
Codepfad gültig, und es kann keine zwei Wahrheiten geben, die sich widersprechen. Ein
zweites Einstellungssystem neben dem alten wäre die sicherste Art, schwer auffindbare
Fehler zu bauen.

**2. Der Prozess wird bei der Bestellung festgehalten, nicht bei jeder Abfrage neu
ermittelt.**

Bei Bestelleingang wird entschieden, welcher Prozess gilt, und als `sqrip_process` auf
der Bestellung gespeichert. Alles Weitere liest diesen Stempel. Ändert der Händler
später seine Regeln, laufen angefangene Bestellungen unverändert zu Ende. Ohne diesen
Stempel würde eine Regeländerung mitten in laufende Vorgänge greifen — bei
Ratenzahlungen mit Monaten Laufzeit ist das keine theoretische Sorge.

**3. Ein Auflöser statt vierzig geänderter Aufrufe.**

Heute liest der Code überall `sqrip_get_plugin_option('status_awaiting')`. Neu:
`sqrip_process_option('status_awaiting', $order)` — liest den Prozessstempel der
Bestellung, fällt auf die globale Einstellung zurück, wenn kein Prozess gesetzt ist.
Damit verhält sich jede Installation ohne Konfigurator exakt wie heute.

**4. Der Konfigurator darf nichts wegnehmen.**

sqrips Verkaufsargument ist, dass jeder Shop seinen eigenen Status definieren kann.
In 1.9.3 und 1.9.4 wurde die Statuslogik zweimal „vereinfacht" und musste zweimal
zurückgenommen werden. Der Konfigurator ist ein Vorschlagswesen, keine Einschränkung:
Wer die Einzeleinstellungen direkt bearbeiten will, muss das weiterhin können.

**5. Vorlagen vor Baukasten.**

Die fünf Abläufe oben sind Archetypen. Die meisten Shops wählen einen davon und ändern
höchstens Kleinigkeiten. Fertige Vorlagen sind deshalb der grösste Teil des Nutzens bei
einem Bruchteil des Aufwands — und sie sind die Rückfallebene, falls der freie
Baukasten sich als zu kompliziert erweist.

---

## Das Bedienmodell

**Schritte in Reihenfolge, aber aus festem Vokabular.** Der Händler sieht eine
Zeitachse und kann Schritte hinzufügen, entfernen und verschieben — jeder Schritt
kommt aber aus der Liste oben und trägt seine Voraussetzungen mit sich. Ein „Rechnung
senden" ohne vorheriges „Rechnung erzeugen" lässt sich gar nicht erst ablegen.

Das ist bewusst nicht der völlig freie Baukasten, den die Aufgabenstellung nahelegt.
**Dagegen spricht:** Ein freier Editor erlaubt unmögliche Abläufe, und jede
Fehlkonfiguration äussert sich erst Wochen später an einer echten Bestellung. Die
Zeitachse mit festem Vokabular fühlt sich gleich an, kann aber keinen Unsinn erzeugen.

**Der Trockenlauf ist der eigentliche Kern.** Genau gegen „man muss viel ausprobieren":

> *Testbestellung, 120.00 CHF, Kunde aus Gruppe „Wiederverkäufer"*
> → Prozess **B — Prüfen, anpassen** greift
> → Bei Bestelleingang: keine Rechnung, Status wird „in Prüfung"
> → Nach Ihrem Klick auf „QR-Rechnung erzeugen": Rechnung über 120.00, E-Mail an den
>   Kunden, Status „wartet auf Zahlung"
> → Nach erkannter Zahlung: Status „abgeschlossen"

Ohne eine einzige echte Bestellung. Dieselbe Ansicht rückwärts an einer bestehenden
Bestellung beantwortet die zweite Hälfte der Ausprobiererei: *„Warum hat diese
Bestellung keine Rechnung bekommen?"*

**Prozessauswahl.** WooCommerce kennt keine Kundengruppen, sondern Benutzerrollen.
Regeln in dieser Reihenfolge, erste Übereinstimmung gewinnt:

1. Benutzerrolle (deckt B2B/B2C und die meisten Gruppen-Plugins ab)
2. Bestellwert über/unter Schwelle
3. Lieferland
4. sonst: Standardprozess

Drei Prozesse plus Standard sind die Obergrenze. Mehr wäre technisch kein Unterschied,
aber die Oberfläche und der Trockenlauf werden dann unübersichtlich.

---

## Arbeitspakete

**P0 — „So sieht Ihr Prozess heute aus"** *(klein, sofort nützlich, ohne Risiko)*
Read-only-Ansicht, die aus den bestehenden Einstellungen den aktuellen Ablauf in
Klartext ableitet. Ändert nichts. Bringt für sich genommen schon einen grossen Teil
des Nutzens und ist gleichzeitig die Vorarbeit für alles Weitere: Wer den Prozess aus
den Einstellungen *lesen* kann, kann ihn später auch schreiben.

**P1 — Vorlagen** *(mittel)*
Die fünf Archetypen als Auswahl. Anwenden setzt die zugehörigen Einstellungen, zeigt
vorher eine Vorschau, was sich ändert, und lässt sich rückgängig machen.

**P2 — Trockenlauf** *(mittel)*
Simulation ohne Bestellung, plus Diagnose an einer echten Bestellung. Prüfbar ohne
Shop, weil reine Auswertung.

**P3 — Der Editor** *(gross)*
Zeitachse mit Hinzufügen, Entfernen, Verschieben. Validierung gegen die
Voraussetzungen der Schritte. Schreibt weiterhin die bestehenden Einstellungen.

**P4 — Mehrere Prozesse** *(gross)*
Prozessspeicher, Auswahlregeln, `sqrip_process`-Stempel auf der Bestellung,
`sqrip_process_option()` und die Umstellung der prozessrelevanten Aufrufe darauf.
Der grösste und riskanteste Teil — bewusst zuletzt, weil P0–P3 auch ohne ihn Wert
liefern.

**P5 — Die Lücke schliessen** *(mittel)*
Frei wählbare Auslöser für den Rechnungsversand („Status wechselt auf X → Rechnung
senden"). Ohne das sind die Abläufe C und D nicht vollständig abbildbar.

---

## Was dagegenspricht

**Es ist der grösste Eingriff in der Geschichte des Plugins**, und er trifft genau die
Stelle, an der zweimal etwas zurückgenommen werden musste. Das ist kein Argument
dagegen, aber eines für die Reihenfolge oben: P0 ist reines Lesen, P4 ist das einzige
Paket, das bestehendes Verhalten wirklich verändert.

**Zwei Wahrheiten sind die Hauptgefahr.** Solange der Konfigurator die bestehenden
Einstellungen schreibt, gibt es sie nicht. Sobald jemand anfängt, Prozessdaten
daneben zu speichern, entstehen Zustände, in denen Oberfläche und Verhalten
auseinanderlaufen. Dieser Grundsatz darf nicht aufgeweicht werden.

**Der Nutzen ist ungleich verteilt.** Ein Shop mit einem Ablauf gewinnt vor allem
Übersicht — P0 und P1 genügen ihm. Der volle Konfigurator lohnt sich für Shops mit
mehreren Kundengruppen, und die sind die Minderheit. Wenn P4 zu teuer wird, ist ein
Abbruch nach P3 ein vollständiges, sinnvolles Produkt.

**Bestehende Installationen dürfen sich nicht verändern.** Ohne aktiven Konfigurator
muss jede Installation sich exakt wie heute verhalten. Der Auflöser aus Entscheid 3
sorgt dafür, aber das gehört in jedem Paket geprüft.

---

## Offene Fragen

1. **Kundengruppe = Benutzerrolle?** Wenn ihr Kunden habt, die Gruppen über ein
   B2B-Plugin abbilden, muss die Auswahlregel dort andocken. Welche Plugins sind im
   Einsatz?
2. **Ist P0 allein schon die Antwort?** Möglicherweise löst „zeig mir, was meine
   Einstellungen bedeuten" den Schmerz zu achtzig Prozent. Das liesse sich in 1.12
   ausliefern und danach entscheiden.
3. **Verkauft ihr das?** Wenn der Konfigurator ein bezahltes Merkmal wird, gehört er
   hinter einen Dienst-Schalter wie der camt-Abgleich — das ändert nichts an der
   Architektur, aber an der Reihenfolge von P1.
4. **Was passiert mit laufenden Bestellungen beim Wechsel der Vorlage?** Der
   Prozessstempel beantwortet das für P4. Für P1 braucht es eine bewusste Entscheidung:
   Vorlage anwenden ändert nur neue Bestellungen — oder alle?

---

## Aufwandsbild

P0 und P1 sind zusammen etwa das, was Paket C in 1.11 gekostet hat. P2 ist kleiner.
P3 und P4 sind je für sich grösser als alles, was in 1.11 gemacht wurde — P4 vor allem
wegen der Breite der Umstellung, nicht wegen ihrer Schwierigkeit.

**Empfehlung:** P0 in 1.12, dann anhand echter Rückmeldungen entscheiden, ob P1–P2
folgen oder direkt der volle Konfigurator als 2.0.
