# sqrip-woocommerce 1.11 — Umsetzungsplan

Ausgangslage: 1.10 ist seit 25.07.2026 auf wordpress.org live. Der Linear-Workspace
(Team NET2) ist seit 10.07.2026 read-only — Issue-Nummern sind Referenzen, keine
Arbeitsliste. Server-, EBICS- und Magento-Themen gehören Artems Team und sind hier
ausgeklammert.

**Leitplanke:** Alles in 1.11 muss ohne EBICS/bLink funktionieren. Der camt-Upload ist
genau deshalb Plugin-seitig gedacht: er ersetzt den automatischen Abgleich durch einen
manuellen, ohne auf Version 2.0 zu warten.

---

## Arbeitspakete

### A — Bugfixes (P1)

| ID | Issue | Beschreibung | Status |
|---|---|---|---|
| A1 | NET2-2322 (b) | Nach Preis-/MwSt-Änderung zeigte die neu erzeugte QR-Rechnung das alte Total. Hook läuft jetzt auf Priorität 99 und liest die Bestellung erst nach WooCommerces eigener Speicherung. | **erledigt** (uncommitted) |
| A2 | NET2-2331 | Bei aktiver „Rechnung nicht am Checkout erzeugen"-Option erscheint der Pay-Button und wirft beim Klick einen Fehler. Soll sichtbar, aber nicht klickbar sein. | offen |
| A3 | NET2-2310 | Dritte Zeile „Zahlungseingang bestätigen:" / „Confirm receipt of payment:" über dem Bestätigungs-Icon in der Bestellübersicht. | offen |
| A4 | NET2-1488 | Überflüssigen Erklärtext beim automatischen Abgleich entfernen. | offen |

### B — Funktionen aus Linears „V 1.10" (P2)

| ID | Issue | Beschreibung |
|---|---|---|
| B1 | NET2-2329 | **CH-Gatekeeper.** Einstellung, für welche Lieferländer eine QR-Rechnung erzeugt wird (Default: nur CH/LI). Greift vor der Generierung am Checkout *und* bei manueller Auslösung. Verhindert CHF-Rechnungen an Kunden im Ausland. |
| B2 | NET2-2326 | **Skonto.** Zweite QR-Rechnung mit Rabatt. %-Satz in den Einstellungen, Rundung auf 0.05 (`round(total*20)/20`), Dateiname-Suffix `_Skonto`. Nutzt die mit 1.10 ausgelieferte Multi-Slip-Infrastruktur. |
| B3 | NET2-2327 | **Mahngebühr, Stufe 1.** x Tage nach Fälligkeit eine neue QR-Rechnung mit Gebühr; Gebühr als WooCommerce-Fee (MwSt-pflichtig), Dateiname-Suffix `_reminder`, E-Mail an den Kunden. Der automatische Abgleich der neuen Ref# ist Stufe 2 → 2.0 bzw. über C. |

### C — camt-Upload und Abgleich im Plugin (neu, grösstes Paket)

**Ziel:** Der Händler lädt die camt-Datei aus seinem E-Banking direkt in WooCommerce
hoch. Das Plugin gleicht die Gutschriften gegen die offenen sqrip-Bestellungen ab und
markiert bezahlte Bestellungen. Kein EBICS, kein Server-Roundtrip, keine Zahlerdaten
auf fremden Systemen.

**C1 — Parser**
- Unterstützt camt.053 (Kontoauszug) und camt.054 (Detailavis), ISO-20022, XML.
- Extrahiert je Gutschrift: Referenz (QR-Ref 27-stellig / Creditor Reference RF / `EndToEndId`),
  Betrag, Währung, Valuta- und Buchungsdatum, Auftraggeber.
- Normalisiert Referenzen (Leerzeichen entfernen, Grossschreibung) vor dem Vergleich.
- Mehrere Dateien nacheinander; ZIP ist optional und nicht Teil des Mindestumfangs.

**C2 — Abgleich**
- Kandidaten: Bestellungen mit Zahlart `sqrip` und dem konfigurierten „wartet auf
  Zahlung"-Status (`status_awaiting`). Bewusst **kein** `meta_query` — Kandidaten über
  `wc_get_orders()` nach Status holen und die Referenzen in PHP vergleichen. Das ist
  unter HPOS wie unter klassischer Speicherung identisch zuverlässig.
- Referenzquellen je Bestellung: `sqrip_reference_id` sowie `sqrip_reference_id_{n}`
  bei Mehrfachrechnungen (`sqrip_multiple_invoice_count`).
- Erwartete Beträge: `sqrip_partial_invoice_amount_{n}` (existiert bereits), sonst das
  Bestelltotal.
- Ergebniskategorien:
  1. **Bezahlt** — Referenz und Betrag stimmen
  2. **Betragsabweichung** — Referenz stimmt, Betrag nicht
  3. **Mehrfachzahlung** — dieselbe Referenz mehrfach in der Datei oder bereits bezahlt
  4. **Nicht zuordenbar** — Referenz unbekannt
  5. **Offen geblieben** — Bestellung ohne passende Zahlung
- Bei Mehrfachrechnungen (Skonto, Mahngebühr, Ratenzahlung): Trifft eine Referenz, werden
  die übrigen Referenzen derselben Bestellung entwertet. **Damit schliesst C genau die
  Lücke, die B2 und B3 sonst offen lassen.**

**C3 — Bedienung**
- Eigene Seite unter WooCommerce → sqrip → „Zahlungsabgleich".
- Ablauf: Datei wählen → **Vorschau** (Dry-Run, ändert nichts) → prüfen → „Übernehmen".
- Übernahme setzt den konfigurierten bezahlt-Status und schreibt eine Bestellnotiz mit
  Referenz, Betrag, Valutadatum und Dateiname.
- Nur Kategorie 1 wird automatisch übernommen. Kategorien 2–4 werden gelistet und
  bleiben Handarbeit.

**C4 — Sicherheit (nicht verhandelbar)**
- `current_user_can('manage_woocommerce')` **und** Nonce — Muster wie in
  `inc/sqrip-ajax.php`.
- XML gegen XXE härten: `libxml_set_external_entity_loader(null)`, Parsen mit
  `LIBXML_NONET`, **kein** `LIBXML_NOENT`. Kontoauszüge sind fremde Dateien.
- Upload nicht in die Mediathek. Verarbeitung im temporären Verzeichnis, Datei danach
  löschen.
- Grössenlimit und Endungs-/MIME-Prüfung.
- Keine Zahlerdaten über den Abgleich hinaus speichern (Linie aus NET2-1391).

### D — Kleine Ergänzungen (P3)

| ID | Issue | Beschreibung |
|---|---|---|
| D1 | NET2-2279 | Danke-Nachricht unter der Beschreibung auf der Bestellbestätigung. |
| D2 | NET2-2181 | Mehr Variablen im Feld „Zusatzinformationen" (SIX IG QR-Bill v2.2, S. 62–65). |
| D3 | NET2-2169 | QR-Rechnung sprachabhängig via WPML. **Beschreibung ist nur ein Screenshot — vor Beginn mit Markus klären, was genau gemeint ist.** |
| D4 | NET2-2285 | Bestellliste als Download (Teil 1). Der periodische Versand hängt am Abgleich und kommt später. |

### E — Vorarbeiten, die beim Anfassen auffallen

| ID | Beschreibung |
|---|---|
| E1 | **HPOS-Lücke.** `sqrip-woocommerce.php:476, 576, 1565, 1568, 1574, 1575`, `inc/class-sqrip-media-cleaner.php:87–97` und `inc/class-wc-sqrip-payment-gateway.php:1620` lesen/schreiben Bestell-Meta noch über `get_post_meta`/`update_post_meta`. Unter HPOS greift das ins Leere. Der Changelog von 1.10 verspricht volle HPOS-Kompatibilität — die Stellen gehören auf `sqrip_get_order_meta_value()` bzw. `$order->update_meta_data()` umgestellt. Blockiert C nicht (die Referenzen werden bereits korrekt geschrieben), gehört aber in dieselbe Version. |
| E2 | **Lücke im Regenerationspfad.** `sqrip-woocommerce.php:875` schreibt bei Mehrfachrechnungen `sqrip_reference_id_{n}`, aber **nicht** `sqrip_partial_invoice_amount_{n}` — anders als `inc/functions.php:907` und `inc/class-wc-sqrip-payment-gateway.php:1610`. Nach manueller Neuerzeugung fehlt der Betrag pro Slip, und C2 könnte nicht auf Betrag prüfen. Muss vor C behoben werden. |

---

## Reihenfolge

1. **E2, dann A2–A4** — kleine, klar umrissene Fixes. Bringt das Repo auf einen sauberen Stand.
2. **B1 (CH-Gatekeeper)** — bestes Nutzen/Aufwand-Verhältnis, unabhängig von allem anderen.
3. **C1–C4 (camt)** — das grosse Paket. Zuerst Parser mit echten Beispieldateien, dann Abgleich, dann Oberfläche.
4. **B2, B3 (Skonto, Mahngebühr)** — nach C, weil C ihnen die automatische Entwertung liefert.
5. **E1 (HPOS)** — vor dem Release.
6. **D1, D2, D4** — Auffüllmasse. **D3** erst nach Klärung mit Markus.

## Testmaterial, das gebraucht wird

Echte camt-Dateien aus dem E-Banking, anonymisiert: `camt.053`, `camt.054` mit QR-Ref,
eine mit RF-Referenz, eine mit Sammelbuchung („Zahlungsauftrag e-banking"), und eine mit
einer Zahlung, die zu keiner Bestellung passt. Ohne diese Dateien ist C1 nicht
verlässlich testbar — vgl. NET2-1376, wo genau daran ein Upload gescheitert ist.

## Definition of Done

- `php -l` sauber auf allen geänderten Dateien.
- Getestet mit HPOS **ein** und **aus**.
- Changelog-Eintrag in `README.txt` unter `= 1.11 =`, Version in `sqrip-woocommerce.php`
  und `Stable tag` in `README.txt` gleichgezogen.
- Tag erst setzen, wenn alle README-Änderungen drin sind — bei 1.10 lag der Tag vor den
  letzten beiden README-Commits, die dadurch nicht auf wordpress.org gelandet sind.
