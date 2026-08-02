# P0 — „So sieht Ihr Prozess heute aus" · Implementierungsplan (V1.12)
### Fassung 2 — nach kritischer Codeprüfung

Ziel: read-only-Ansicht, die aus den bestehenden Einstellungen den aktuellen
Bestellablauf in Klartext ableitet. Ändert **nichts**, schreibt **nichts**, stellt
**keinen** der ~40 prozessrelevanten Aufrufe um (das ist P4). Reines Lesen.
Oberste Regel: **niemals einen Ablauf behaupten, den der Code so nicht ausführt.**

Referenz: docs/PLAN-Prozesskonfigurator.md (Arbeitspaket P0).

---

## Architektur

Neue Datei: **`inc/class-sqrip-process-overview.php`**, statische Klasse
`Sqrip_Process_Overview`.

- **Bootstrap:** `require_once` + `::init()` in `sqrip-woocommerce.php`, im
  bestehenden `is_admin()`-Block (~Zeile 51–53, wo camt-admin bootstrappt).
- **Menü:** `register_menu()` an `admin_menu`, Guard
  `current_user_can('manage_woocommerce')`, dann `add_submenu_page('woocommerce',
  …)` — **mit echtem Seiten-Render-Callback (7. Argument)**.
  ⚠️ Korrektur ggü. Fassung 1: camt-admin ist NUR ein teilweises Vorbild. Sein
  Submenü nutzt einen URL-Slug OHNE Seiten-Callback (class-sqrip-camt-admin.php:65–71),
  sein `render()` ist eine AJAX-HTML-Methode. Die Seiten-Verdrahtung für P0 ist
  eigene Arbeit — nicht „exakt gespiegelt".
- **Ausgabe:** `render_page()` mit `ob_start()`, `<table class="widefat striped">`,
  alle Werte durch `esc_html`/`esc_html__`. Kein AJAX, keine Formular-Aktion.
- **Guard:** kein `is_enabled()`-Gate — die Ansicht ist gerade für unkonfigurierte
  Shops da. Nur der Capability-Check.

**Platzierung — Produktentscheidung (Markus):** eigene Submenüseite (isoliert,
P0-Mandat „ohne Risiko") vs. erster Reiter „Ihr Ablauf" auf der Gateway-Settingseite
(kontextnäher, berührt aber `init_form_fields`). Gleiche Engine, nur andere Hülle.

---

## Kernstück — Ableitungs-Engine, in ZWEI Schichten getrennt

⚠️ Korrektur ggü. Fassung 1: Die Engine kann NICHT zugleich „reine, WP-freie
Funktion" sein UND das Gateway zum Default-Lesen instanziieren. Deshalb strikt
zwei Schichten:

- **Schicht A — rein & testbar:** `derive_steps(array $resolved): array`.
  Bekommt ein **vollständig aufgelöstes** Options-Tupel (alle Defaults schon
  eingesetzt) und gibt die geordnete Schrittliste zurück. Kein WordPress, per
  Shim testbar wie tests/camt.
- **Schicht B — WP-gebunden:** löst die Defaults auf (siehe unten) und reicht das
  fertige Tupel an Schicht A. Lebt in der Render-Klasse.

Jeder Schritt: `{ trigger, action, quelle:[keys], art: measured|derived|extern }`.
Bewusst NUR beschreibend, keine Archetyp-Klassifizierung (A–E) im MVP.

### Ableitungslogik — mit den vom Code erzwungenen Feinheiten

1. **Bei Bestelleingang** — Rechnungserzeugung:
   - `suppress_generation === 'yes'` → keine Rechnung.
   - Status ist NICHT flach `status_suppressed`. Realer Pfad
     (sqrip-woocommerce.php:2098 + 1924–1927): zuerst hart `pending`, dann im
     thank-you-Hook: **`if (suppress==='yes' && status_suppressed nicht leer) →
     status_suppressed; sonst → qr_order_status`**. `status_suppressed` trägt oft
     den Platzhalter `wc-sqrip-default-status` (= „nichts gesetzt"). Die Engine
     MUSS diesen Fallback und den Platzhalter modellieren, sonst zeigt sie im
     Normalfall den falschen Status.
   - Rechnungsart: `multiple_qr_slips_enabled === 'yes'` → „{number_of_invoices}
     Teilrechnungen ({invoice_fraction_1..3})"; `skonto_enabled === 'yes'` → „+
     Skonto {skonto_percentage}%".
2. **Rechnungsversand — WANN erreicht die Rechnung den Kunden** (Kern-Anforderung,
   nachgerüstet): drei Kanäle mit je eigenem Zeitpunkt, alle ableitbar:
   - (a) **Sofort** als Download auf der Bestätigungsseite (`integration_order`,
     Default „yes").
   - (b) **Als PDF-Anhang** an jeder in `email_attached` (Multiselect) gewählten
     WC-E-Mail — und jede WC-E-Mail feuert bei IHREM Auslöser, meist einem
     Statuswechsel. Auslöser-Tabelle `EMAIL_TRIGGERS` (customer_on_hold_order →
     on-hold, customer_processing_order → processing, …, customer_invoice →
     manuell, new_order → Admin/neue Bestellung). Unbekannte/Fremd-Mails →
     ehrlicher Fallback „gemäss den Auslösern dieser E-Mail".
   - (c) **Beim Checkout erzwungen** (`qr_order_status_send_emails`, gateway:2336):
     On-Hold-Kundenmail + Neue-Bestellung-Adminmail direkt. Trägt die Rechnung nur
     mit, wenn `customer_on_hold_order` auch als Anhang gewählt ist
     (`force_carries_invoice`).
   Ehrlichkeits-Zusatz: „Jede E-Mail wird nur versendet, wenn sie in WooCommerce
   aktiviert ist." Damit ist die vom Plan-Doc P5 zugeschriebene Lücke für den
   ableitbaren Teil geschlossen; rein WC-vorlagenseitige Zustände bleiben benannt.
3. **Zahlungsfeststellung** — camt und avis sind BEIDE Sub-Features von
   `payment_comparison_enabled` (camt: payment_comparison && camt_reconciliation,
   class-sqrip-camt-admin.php:40–41; avis: payment_comparison && avis_enabled,
   class-sqrip-avis.php:86–87). Als Baum rendern, nicht als drei gleichrangige
   Schalter. „Zahlung erkannt über {manuell | camt-Abgleich | Avis}; wartet im
   Status {status_awaiting}".
4. **Danach** — „Status → {status_completed}"; ggf. `delete_invoice_status`.
   Bei Teilrechnungen die `partial_invoice_1..3_status` einbeziehen.
5. **Wenn nicht gezahlt** — `reminder_enabled === 'yes'` (simples Gate,
   class-sqrip-reminder.php:37) → „{reminder_days_after_due} Tage nach Fälligkeit
   ({due_date}): Mahnung mit Gebühr {…}".

---

## Korrektheits-Kern 2: geparkte Features (NEU aus Codeprüfung)

`Sqrip_Skonto::is_enabled()` (class-sqrip-skonto.php:34) und
`Sqrip_Reminder::is_enabled()` (class-sqrip-reminder.php:35) haben ein
`return false;` VOR dem echten Gate — beide Features sind per `[HOLD 1.11]`
hart abgeschaltet und laufen NIE, egal was `skonto_enabled` / `reminder_enabled`
sagen. → P0 darf NICHT die Rohoption lesen, sondern muss die kanonischen
`is_enabled()`-Methoden konsultieren:
- `Sqrip_Skonto::is_enabled()`, `Sqrip_Reminder::is_enabled()`,
  `Sqrip_Camt_Admin::is_enabled()`, `Sqrip_Avis::is_enabled()`.
Schicht B ruft diese und legt die EFFEKTIVEN Booleans ins normalisierte Tupel;
Schicht A rendert nur daraus. Sonst zeigt P0 einen Skonto-/Mahn-Schritt, den der
Code gar nicht ausführt — die verbotene Falle.

## Korrektheits-Kern 1: Default-Auflösung (Schicht B)

`sqrip_get_plugin_option($key)` (inc/functions.php:8) gibt für **nie gespeicherte**
Schlüssel `null` zurück, NICHT den Feld-Default (Fallback auskommentiert :17–24).
Ohne Auflösung zeigt P0 frischen/teilweise gespeicherten Installationen einen
falschen/leeren Ablauf.

**Lösung (korrigiert):** Defaults über die **bereits registrierte** Gateway-Instanz
lesen — `WC()->payment_gateways()->payment_gateways()['sqrip']->form_fields[$key]
['default']` — NICHT `new WC_Sqrip_Payment_Gateway()`. Grund: der Konstruktor
registriert bei jeder Instanziierung den Save-Hook neu (:96) und fährt die
~900-Zeilen-`init_form_fields` (:102–1001); die Singleton hat das schon getan,
ohne Nebenwirkung.

⚠️ Vor Umsetzung klären: der auskommentierte Default-Fallback in functions.php
stammt aus einem v1.5.5-Revert (git 6682e34) — WARUM er deaktiviert wurde, ist
unbelegt. P0 reaktiviert diesen Code NICHT, sondern löst Defaults in eigener
Schicht auf; trotzdem den Revert-Grund kurz prüfen, bevor wir auf Feld-Defaults
als Wahrheit bauen.

---

## Tests (nicht im wp.org-ZIP, via .distignore)

`tests/process/run-tests.php` nach Muster `tests/camt/run-tests.php`:
- Getestet wird NUR **Schicht A** (`derive_steps($resolved)`): vollständig
  aufgelöstes Tupel rein, erwartete Klartext-Schritte raus. Kein WP.
- Die Default-Auflösung (Schicht B) gehört NICHT in den Shim-Test — Defaults
  kommen als Fixture ins Tupel.
- Fälle: die fünf Archetypen A–E aus dem Plan. **Aber:** Ablauf D (Anzahlung)
  ist nur darstellbar, wenn `invoice_fraction_1..3` + `partial_invoice_1..3_status`
  in der Engine sind. Entweder diese Felder aufnehmen ODER D ehrlich als „in P0
  noch nicht vollständig darstellbar" kennzeichnen und NICHT als grünen Test
  fixturieren. (Offene Scope-Entscheidung, siehe unten.)
- Plus: leeres Settings-Array + Default-Fixture → muss den Default-Ablauf ergeben.

---

## i18n

⚠️ Korrektur ggü. Fassung 1: Textdomain ist **`sqrip-swiss-qr-invoice`**
(durchgängig im Code, 230× allein in der Gateway-Klasse), NICHT `sqrip`. Mit
falscher Domain bliebe jeder P0-String unübersetzt. Deutsch primär, Strings über
`__()`/`esc_html__()` mit `sqrip-swiss-qr-invoice`. de_CH/fr/it später.

---

## Scope-Grenzen (was P0 NICHT tut)

- Schreibt keine Einstellung. Kein AJAX. Kein Formular. Kein Prozessspeicher,
  kein `sqrip_process`-Stempel (P4), keine Vorlagen (P1), kein Editor (P3).
- Stellt KEINEN der ~40 Aufrufe auf `sqrip_process_option()` um (P4).
- Rein additiv: 1 neue Datei + 1 Bootstrap-Zeile (Submenü-Variante).

---

## Entscheidungen (gelockt, 02.08.2026)

1. **Platzierung:** IN den bestehenden „Dienste"-Reiter (`services`), UNTEN nach
   den Funktionsschaltern. → Kein Submenü. Umsetzung: Pseudo-Feld
   `type=process_overview` in `init_form_fields`, Klasse `services-tab`, platziert
   nach dem letzten Funktionsfeld der services-Sektion; `generate_process_overview_html()`
   auf der Gateway-Klasse delegiert an `Sqrip_Process_Overview::render()`. Damit
   liegt die Ableitungs-Engine weiterhin in eigener Datei; nur der Render-Aufhänger
   sitzt in der Gateway-Klasse.
2. **Ablauf D:** ehrlich zurückstellen. P0 deckt A/B/C/E; D wird als „in P0 noch
   nicht vollständig darstellbar" gekennzeichnet, KEIN grüner D-Test.
3. **Branch:** jetzt isolierter Feature-Branch (vom aktuellen Stand), später auf
   den 1.12-Sockel rebasen.

**Vorbedingung Commit:** Markus muss parallele Git-Arbeit pausieren (Repo-Kollision
aus früheren Sessions). KEIN Commit/Branch in einen aktiv rebasten Zustand.
