# SOLL / Pflichtenheft — sqrip Auskunftsdienst (E-Mail-Zahlungsavisierung)

> Status: Entwurf zur Freigabe durch Markus. Der englische Implementierungs-Prompt
> für Artem (Abschnitt 11) geht **erst nach deiner Durchsicht** raus.
> Order-getrieben, kein Bankzugriff — dieselbe Datenschutz-Linie wie C (CAMT-Upload).

---

## 1. Zweck & Abgrenzung

**Problem:** Ein Shop soll möglichst unmittelbar nach einer Gutschrift erfahren
— Betrag, Absender, Referenz — um eine offene QR-Rechnung als bezahlt zu erkennen.

**Lösung:** Die vom Kunden im E-Banking aktivierte **Konto-Avisierung per E-Mail**
(Text oder PDF-Beleg) ist der günstigste, sofort aktivierbare Informations-Export.
Ein **Auskunftsdienst** liest diese Mails, extrahiert die nötigen Felder, gleicht
sie **gegen die vom Shop vorgelegte Liste offener Bestellungen** ab und gibt bei
einem Treffer die Statusänderung frei.

**Abgrenzung:**
- **C (CAMT-Upload) bleibt** als monatliche Wahrheit / Fallback. Die E-Mail ist der
  *schnelle* Weg, nicht der Ersatz. Beide teilen die Order-getriebene Logik.
- **Kein Bankzugriff** (kein EBICS/bLink). Wir empfangen nur, was die Bank pusht.
- Roh-Mail wird **nach dem Auslesen gelöscht**; behalten wird nur das gelernte
  **Format-Muster** einer Bank, nie die Transaktionsdaten.

---

## 2. Gesamtbild / Datenfluss

**Verarbeitungseinheit ist die Avisierungs-E-Mail** — nicht „ein Kandidat pro Kunde".
Eine Sammelbuchung liefert in *einer* Mail *mehrere* Gutschriften und kann *mehrere*
Bestellungen betreffen. Die Mail wird zuerst vollständig zerlegt, dann jede Gutschrift
einzeln gegen die offenen Bestellungen geprüft.

```
Bank ──(Avisierung E-Mail)──▶ box@avis.sqrip.ch (Catch-all *@avis.sqrip.ch)
                                   │
                        (1) Vorfilter: Bank-Gutschrift? sonst → SPAM-Report, verwerfen
                                   │ ja
                        (2) Extraktor: Regex zuerst, Claude nur bei unbekanntem Format
                                   │  → Kunde (aus Empfänger-Localpart), dann je Gutschrift:
                                   │     Betrag, Währung, Referenz?/Bestellnr?, Absender
                                   │  → Roh-Mail SOFORT gelöscht (nur Format-Muster bleibt)
                                   ▼
              (3) Kandidaten-SET (flüchtig) — 1..n Gutschriften aus dieser Mail
                                   │
                        (4) Matcher (Order-getrieben): jede Gutschrift auf der Offen-Liste?
                                   │     ├─ nein → sofort verwerfen (z.B. Stripe)
                                   │     └─ ja  → Match (Stufe 1/2/3, s. §4)
                                   ▼
                        (5) Push-Hook an das Plugin: „hier ist etwas abzugleichen"
                                   │
                        (6) Freigabe-Regel (§5): auto unter Schwelle / Bestätigung ab Schwelle /
                                   │              Vorschlag ohne Referenz
                                   ▼
                        (7) Plugin setzt den **bereits konfigurierten** Bezahlt-Status
                                   │
                        (8) Credit-Abzug (§7): Consume-Endpunkt — oder Fallback via /api/code
```

---

## 3. Komponenten

### 3.1 Postfach
- Physische Mailbox: **`box@avis.sqrip.ch`** (bereits eingerichtet; Passwort folgt).
- **Catch-all**: alle `*@avis.sqrip.ch` landen in dieser einen Box.
- Pro Kunde ein eigener Localpart (`timber@avis.sqrip.ch`), beim Onboarding vergeben.
  Die Kundenzuordnung wird aus dem **Empfänger-Header** der Mail gelesen
  (`Delivered-To` / `X-Original-To` / `To`) — eine Box, viele Kunden.
- Empfehlung Localpart statt `client+timber@…`: das `+`-Tag lehnen manche
  E-Banking-Avisierungs-Formulare ab oder schneiden es weg; ein Catch-all-Localpart
  wird überall akzeptiert.

### 3.2 Vorfilter (kostenlos, regelbasiert)
- Entscheidet ohne Claude: „sieht das nach einer Bank-**Gutschrift** aus?"
  (Absender-Domain-Allowlist optional, Schlüsselwörter „Gutschrift/Zahlungseingang/
  crédit/credit", Betrags-/Referenz-Muster).
- Nicht-Treffer (Stripe, Newsletter, Spam) → **SPAM-/Filter-Report**, dann verworfen.
- Kein Credit-Abzug für gefilterte Mails.

### 3.3 Extraktor (Regex zuerst, Claude als Fallback)
- Bekannte Bankformate liest ein **Muster-Parser** → 0 Kosten, **kein PII-Abfluss**.
- Nur **unbekannte/neue Formate** gehen an Claude. Das ist zugleich die *Anlernstufe*.
- Pro Gutschrift extrahiert: `Betrag`, `Währung`, `Referenz` (QRR/SCOR) **oder**
  `Bestellnummer` aus dem Mitteilungs-/Referenzfeld, `Absendername`, Bank-Transaktions-ID.
- **Nach erfolgreichem Auslesen: Roh-Mail sofort löschen.** Behalten wird nur das
  **Format-Template** der Bank (Struktur, keine Werte) für künftige Regex-Treffer.

### 3.4 Matcher — siehe §4.  ·  3.5 Freigabe — siehe §5.

### 3.6 Credit-Ledger
- Abzug pro Abgleich (Ausgangslage: 1 Credit — genaue Einheit noch zu definieren, §7).
- Endpunkt am sqrip-Server (noch zu bauen) **oder** Fallback: eine Dummy-QR-Rechnung
  über `POST /api/code` erzeugen, was heute schon einen Credit zieht.

### 3.7 SPAM-/Filter-Report & Anlernstufe
- Gefilterte/nicht zugeordnete Mails werden **regelmässig rapportiert**; der Kunde kann
  eine fälschlich gefilterte echte Gutschrift **zurückholen** — das trainiert den
  Vorfilter und liefert der Anlernstufe ein neues Bankformat.

---

## 4. Match-Logik

**Order-getrieben:** Ausgangspunkt sind immer die **offenen Bestellungen des Shops**,
nie der volle Zahlungsstrom. Gutschrift nicht auf der Liste → sofort verwerfen.

**Drei Match-Stufen** (gemessen an den drei realen AKB-Belegen):

| Stufe | Signal | Beispiel | Freigabe |
|---|---|---|---|
| **1 — sicher** | strukturierte QR-Referenz (QRR 27-stellig / SCOR `RF…`), Prüfziffer, unratbar | Inland-QR `2606…59667` | automatisch unter Schwelle |
| **2 — stark** | Bestellnummer als **Freitext** im Mitteilungsfeld + Betrag | EUR-Konto: `Referenz Bestellung 4217`, EUR 95.85 | automatisch unter Schwelle¹ |
| **3 — wahrscheinlich** | nur `Absendername + Betrag`, keine Referenz | CHF-Konto, EUR umgerechnet: kein Ref, „Ruzicka", CHF 123.96 | **immer nur Vorschlag** |

¹ Bestellnummern sind fortlaufend/ratbar → Stufe 2 nur zusammen mit Betrags-Treffer;
  ob Stufe 2 automatisch freigibt oder wie Stufe 3 nur vorschlägt, ist ein
  Einstellungs-Schalter pro Shop.

**Weitere Regeln:**
- **Sammelbuchung**: eine Mail → mehrere Gutschriften → mehrere mögliche Bestellungen.
- **Referenz-only-Sammelbuchung** (Raiffeisen-Beleg 31.07.): die Mail listet nur die
  QR-Referenzen und **eine Gesamtsumme**, **keine Einzelbeträge**. Der Einzelbetrag kommt
  dann aus der zugeordneten Bestellung; die Gesamtsumme dient als **Quersumme**: Summe der
  zugeordneten Bestellbeträge muss der Gesamtsumme entsprechen. Weicht sie ab, wird die
  ganze Buchung zur Kontrolle zurückgehalten (fängt eine Unterzahlung, die ohne
  Einzelbeträge sonst unsichtbar bliebe).
- **Umrechnung**: Fremdwährung auf CHF-Konto wird umgerechnet (EUR 136.30 → CHF 123.96),
  Betrag nicht vorhersagbar → ohne Referenz zwingend Stufe 3. Auf einem Konto in der
  Fremdwährung selbst (EUR-Konto) bleibt der Betrag exakt → Stufe 2 möglich.
- **Valuta ist KEIN Kriterium für die Match-Qualität** — nur Anzeige-Metadatum.
- **Dedup / Idempotenz**: Schlüssel = **Bank-Transaktions-ID** (in den AKB-Belegen z.B.
  `Referenznummer 1557893527` / Fuss `inpay-…-1557893527-…`), ersatzweise Referenz+Betrag.
  Verhindert Doppelverbuchung, wenn dieselbe Gutschrift zweimal eintrifft.
- **Teil-/Überzahlung, Duplikat**: Kategorien wie im C2-Reconciler wiederverwenden.

---

## 5. Sicherheit

### 5.1 Bedrohungsmodell (kalibriert)
Realistischer Angreifer = **der Käufer selbst** (nur er kennt Referenz + Betrag). Fremde
müssten zusätzlich Existenz, Funktionsweise und Adresse des Dienstes kennen sowie wissen,
dass der Absender ungeprüft bleibt. Für zwei-/dreistellige Warenwerte unverhältnismässig.

### 5.2 Primärschutz — Betrags-Schwelle (Kunde definiert X)
- `Betrag < X` **und** Stufe-1/2-Match → **bereits im Plugin konfigurierter Bezahlt-Status**
  wird automatisch gesetzt (kein neuer Status — genau der, den der Shop heute nach Zahlung nutzt).
- `Betrag ≥ X` → **keine automatische Freigabe**. Mail an Shop-Betreiber:
  „Match über CHF … zu Bestellung …, wegen der Höhe nicht ohne deine Bestätigung
  freigegeben. Bitte kurz auf dem Konto prüfen und freigeben." → Klick → frei.
- Ohne Klick: Bestellung bleibt offen, keine Warenauslösung.

### 5.3 Nicht verhandelbar (aber billig): sicherer Freigabe-Link
- Der Bestätigungs-Link ist ein **signierter, einmaliger, ablaufender Token**
  (HMAC/JWT, an Kunde+Order+Betrag gebunden, TTL ~72 h). Sonst wäre der Klick selbst die Lücke.

### 5.4 Optionale Zusatzschicht
- **Absender-/DKIM-Prüfung** als Schalter: nur Mails akzeptieren, die per DKIM
  nachweislich von einer Allowlist-Bankdomain signiert sind — sofern die Bank so
  identifizierbar ist. Standard: aus.

### 5.5 Postfach & Daten
- Roh-Mail **sofort nach dem Auslesen gelöscht**. Kandidaten-Set flüchtig, nach Abgleich verworfen.
- **Plugin ↔ Dienst authentifiziert** über einen pro-Kunde-Token (bei Aktivierung
  ausgegeben, an das sqrip-Konto gebunden). Verhindert Abfragen fremder Offen-Listen.

### 5.6 PII-Minimierung
- Regex-First hält die meisten Mails komplett von Claude fern; wenn Claude nötig ist:
  EU-Region, nur zur Extraktion, danach gelöscht.

---

## 6. Prozess

### 6.1 Onboarding (im Plugin, einfacher Ablauf mit klaren Anweisungen)
1. Plugin zeigt dem Kunden **seine** Avisierungs-Adresse (`timber@avis.sqrip.ch`).
2. Anleitung, im E-Banking die **E-Mail-Avisierung bei Gutschriften** auf diese Adresse
   zu aktivieren.
3. **Verifizierungscode-Sonderfall:** Die Bank schickt zur Bestätigung eine erste Test-Mail
   mit einem Code, der im E-Banking einzugeben ist. Diese Mail landet in
   `box@avis.sqrip.ch` — der Kunde sieht sie sonst nie. Während eines zeitlich begrenzten
   **Verifizierungsfensters** fängt der Dienst diese Mail ab und **zeigt den Code direkt im
   Onboarding-Assistenten** (Fallback: Weiterleitung an die hinterlegte Kundenadresse).
   Nach erfolgter Verifizierung wird das Abfangen/Weiterleiten **abgeschaltet**.
4. Kunde legt **Betrags-Schwelle X** und optionale Absenderprüfung fest.
5. Testzahlung → erster Match → Bestätigung, dass die Kette steht.

### 6.2 Laufzeit / Latenz — Push, kein Cron
- Der **Auskunftsdienst meldet sich per Hook beim Plugin**, sobald etwas abzugleichen ist
  (Dienst → authentifizierte REST-Route im Plugin, beim Onboarding registriert).
  Kein Cron, der ausfallen kann.
- **On-Demand nur auf Knopfdruck** („Jetzt abgleichen"-Button) — **nicht** beim Laden der
  Seite (das würde die Seite nur verlangsamen).

### 6.3 Zustände einer Bestellung
`offen → (Gutschrift zugeordnet) → { automatisch Bezahlt-Status | Bestätigung ausstehend | Vorschlag }`
- Der gesetzte Status ist **immer der im Plugin bereits konfigurierte** Bezahlt-Status.
- „Bestätigung ausstehend" und „Vorschlag" lösen **keine** Warenauslösung aus.

### 6.4 Kein Match / Report
- Gutschrift nicht auf der Offen-Liste → sofort verworfen.
- Gefiltertes/Unzugeordnetes → periodischer Report zur „SPAM-Kontrolle", rückholbar.

---

## 7. Finanzen

- **Verrechnungseinheit:** Ausgangslage **1 Credit pro Abgleich** — ob pro Avisierungs-Mail,
  pro geparster Gutschrift oder pro Match, ist noch zu definieren.
- Vom Vorfilter gratis aussortierte Mails (Stripe, Newsletter, Spam) kosten nichts →
  kein Missbrauch durch Fremd-Spam ins eigene Postfach.
- **Abzug:** sauberer Consume-Endpunkt am sqrip-Server (zu bauen) **oder Fallback**: eine
  Dummy-QR-Rechnung via `POST /api/code` erzeugen — das zieht heute schon einen Credit.
- **Kostenuntergrenze:** Credit-Preis ≥ Claude-Lesekosten; Regex-First drückt die Zahl der
  Claude-Aufrufe auf unbekannte Formate.

---

## 8. Datenschutz (DSG / DSGVO)

- **Aufbewahrung = Löschen-nach-Lesen.** Behalten wird nur das Format-Template, nie
  Transaktions- oder Personendaten.
- Auftragsverarbeitung: Auskunftsdienst als Auftragsverarbeiter des Shops; Claude als
  Unter-Auftragsverarbeiter, EU-Region, keine Trainingsnutzung, sofortige Löschung.
- Datenminimierung: Regex-First; nur die nötigen Felder verlassen je die Mail.

---

## 9. Hosting (Interim bis sqrip 2.0)

- **Empfehlung: Google Cloud** — Cloud Run (zustandslos) + Inbound-Mail an `box@avis.sqrip.ch`
  + kurzer, flüchtiger Store (TTL). Region EU. Nicht cyon (Shared-Hosting trägt keinen
  dauerhaften Mail-Dienst sauber).
- **Brücke:** schlanker API-Call zum bestehenden sqrip-Server für den Credit-Abzug.
- **Migrationspfad:** später als Modul in sqrip 2.0 falten; Schnittstellen (Plugin-Hook,
  Credit-API) von Anfang an so schneiden, dass der Umzug nur die Deployment-Ebene betrifft.

---

## 10. Stand der offenen Punkte

1. **Bestellnummer über die Grenze — GEKLÄRT: ja.** EUR-Konto-Beleg 27.07. trägt
   `Referenz Bestellung 4217`. Überlebt als Freitext (Stufe 2), nicht als QR-Referenz;
   bei Umrechnung auf CHF-Konto kann sie fehlen (Stufe 3).
2. **Catch-all — GEKLÄRT.** `box@avis.sqrip.ch` steht, Passwort folgt.
3. **Server-API — kartiert.** Vorhanden: `POST /api/code` (QR-Rechnung, zieht Credit),
   Mehrfach-QR, IBAN-Ops, `Get User Details`, `Update active additional services`.
   **Fehlt:** Credit-Saldo lesen, Credit **consume**, **Abgleich-Endpunkt**,
   Plugin-Callback-Registrierung. Markus: Abgleich-Logik soll in sqrip 2.0 schon vorliegen
   → von Artem freilegen statt neu bauen. „Update active additional services" ist der Haken
   zum Freischalten des bezahlten Dienstes pro Kunde.

---

## 11. English implementation brief for Artem (DRAFT — review before sending)

> Für Markus: Das ist der Brief an Artem für die Server-Seite. Bitte erst freigeben.

**Subject: sqrip payment-notification service ("Auskunftsdienst") — server-side brief**

We are adding a fast payment-detection path to sqrip that works **without EBICS/bLink and
without any bank-account access**. It reads bank *credit-notification emails* the merchant
enables in their e-banking, and confirms whether an incoming credit matches one of the shop's
**open orders**. It is strictly **order-driven**: the WooCommerce plugin submits its open
orders; the service only checks whether a received credit is on that list. If not, the parsed
data is discarded immediately. Raw emails are deleted right after extraction; only per-bank
*format templates* (structure, no values) are retained. Interim hosting is Google Cloud
(Cloud Run + inbound mail to `box@avis.sqrip.ch`), calling the server only for credit metering.

**Current API (from the Postman collection) already provides:** `POST /api/code`
(generate QR invoice — this already consumes one credit), generate/fetch multiple QR invoices,
IBAN validate/update/details, `GET` user details, and `Update active additional services`.
**What I need in addition:**

1. **Reconcile logic exposed.** You mentioned sqrip 2.0 already contains reconciliation
   logic. Please expose it (or confirm its contract) so the plugin can call:
   `POST /reconcile/open-orders` `{ account_id, orders:[{order_id, reference?, order_number?, amount, currency}] }`
   → `[{ order_id, verdict: paid|confirm_required|suggested, tier:1|2|3,
   credit:{amount,currency,sender?,reference?,bank_txn_id} }]`.
   Match tiers: (1) structured QR reference QRR/SCOR → may auto-release below the merchant's
   amount threshold; (2) free-text order number in the remittance line + amount → strong,
   auto-release configurable; (3) name+amount only (e.g. FX-converted foreign credit) →
   suggestion only. Value date is **not** a match criterion. Idempotency key = bank
   transaction id.

2. **Credit metering.** Either a clean `POST /credits/consume`
   `{ account_id, units, reason:"avis_parse", idempotency_key }` (idempotent), **or** confirm
   we may meter by generating a throwaway invoice via `POST /api/code` (which already
   decrements a credit). Also: can `Get User Details` return the current credit balance?

3. **Paid-service flag.** Use `Update active additional services` to enable/disable the
   Auskunftsdienst per account (this is how we monetize after the intro period). Confirm the
   service key to use.

4. **Per-merchant service token** issued at plugin activation, bound to the sqrip account,
   used to authenticate plugin↔service calls and to map the mailbox localpart
   (`<localpart>@avis.sqrip.ch`) to the account.

5. **Push, not poll.** The plugin exposes an authenticated REST callback; the service pushes
   "you have something to reconcile" when a candidate arrives. No cron dependency. Please
   confirm nothing server-side prevents the service from calling the shop directly.

6. **Threshold confirmation link:** for credits ≥ the merchant threshold, the service emails a
   **signed, single-use, expiring** confirmation link (HMAC/JWT bound to account+order+amount,
   TTL ~72h). Confirm signing-key management if this should live server-side.

7. **Data handling:** EU region, no training use, delete source data after extraction, retain
   only bank format templates. Confirm this matches sqrip's DPA posture.

---

---

## 12. Inventur (Bestandsaufnahme — gemessen im Code, 31.07.2026)

### 12.1 Bereits gebaut und 1:1 wiederverwendbar (das Match-Hirn)
- **`inc/class-sqrip-camt-reconciler.php`** (C2, 20 KB) — die vollständige Abgleich-Kette:
  - `collect_open_orders()` — sammelt offene Bestellungen (order-getrieben).
  - `build_expectations($orders)` — was jede Bestellung erwartet (Referenz, Betrag, Slips,
    inkl. Skonto/Mahnung-Alternativen).
  - `references_of($expectations)` — die Referenzliste, die dem Dienst vorgelegt wird.
  - `match($expectations, $matches)` — Zuordnung mit 6 Kategorien: `PAID`,
    `AMOUNT_MISMATCH`, `DUPLICATE`, `OUT_OF_SEQUENCE`, `OPEN`, `PARTLY_PAID`;
    `AMOUNT_TOLERANCE = 0.005`; `paid_short_of_the_fee()`.
  - **Kein Umbau nötig** — der Auskunftsdienst füttert genau diese `match()`-Funktion.
- **`inc/class-sqrip-camt-parser.php`** (C1, 13 KB) — `collect_matches($file, $references)`
  liefert **nur** Einträge mit bekannter Referenz zurück; Fremdes wird gezählt, nie
  ausgeliefert. **Die Order-getriebene Datenschutz-Logik ist hier fertig.**
  Einschränkung: XML-spezifisch (camt.053/054, DOMXPath). → Für E-Mail brauchen wir einen
  **neuen Extraktor mit demselben Rückgabeformat**, nicht eine neue Match-Logik.

### 12.2 Vorhanden, aber zu ersetzen/ergänzen
- **`inc/class-sqrip-camt-admin.php`** (C3, 24 KB) — Menü, Datei-Upload, Nonce,
  `is_uploaded_file`, `discard()/unlink`. UI-Gerüst; für den Dienst brauchen wir stattdessen
  Onboarding-Assistent + Push-Empfang + „Jetzt abgleichen"-Button.
- **Tests** `tests/camt/`: Parser 27, Reconciler 60, Skonto 20, Mahnung 19 (grün).
- **B1 (Länder)** aktiv; **B2 Skonto / B3 Mahnung geparkt** (`[HOLD 1.11]`).

### 12.3 Server (sqrip Live API, Postman)
- **Vorhanden:** `POST /api/code` (QR-Rechnung, **zieht 1 Credit**), Mehrfach-QR + Ergebnis,
  IBAN validate/update/details, `GET` User-Details, `Update active additional services`.
- **Fehlt:** Credit-Saldo lesen, Credit-**consume**, **Abgleich-Endpunkt**,
  Plugin-Callback-Registrierung. (Abgleich-Logik laut Markus in sqrip 2.0 vorhanden.)

### 12.4 Infrastruktur
- Mailbox **`box@avis.sqrip.ch`** + Catch-all `*@avis.sqrip.ch` eingerichtet (Passwort folgt).
- Google-Cloud-Projekt: **noch nicht** (siehe §14).

---

## 13. Umsetzungsplan (phasiert)

Rollen: **[C]** = ich im Plugin/Dienst · **[A]** = Artem (Server/2.0) · **[M]** = Markus (Infra/Entscheid).

- **Phase 0 — Fundament** [M/C]
  Entscheidungen fixieren (Credit-Einheit, Stufe-2-Autofreigabe, Schwellen-Default),
  Google-Cloud-Projekt + Mail-Zugang bereitstellen, Artem-Brief freigeben.

- **Phase 1 — Dienst (Google Cloud)** [C]
  Mail-Eingang aus `box@avis.sqrip.ch` (IMAP-Poll **oder** Inbound-Parse-Webhook);
  **Vorfilter** (gratis, regelbasiert); **Extraktor** (Regex-First, Claude-Fallback) →
  Kandidaten-SET im **gleichen Format wie `collect_matches`**; Roh-Mail sofort löschen;
  **Push-Hook** an das Plugin.

- **Phase 2 — Plugin-Seite** [C]
  Authentifizierte REST-Route, die `references_of(build_expectations(collect_open_orders()))`
  ausliefert; REST-**Callback** zum Empfang der Kandidaten; `match()` aufrufen; bei Freigabe
  den **bereits konfigurierten Bezahlt-Status** setzen; **„Jetzt abgleichen"-Button**.

- **Phase 3 — Onboarding-Assistent** [C]
  Einfacher Ablauf im Plugin: Adresse anzeigen, E-Banking-Anleitung,
  **Verifizierungscode-Abfang** (Code im Assistenten anzeigen, danach abschalten),
  Schwelle X, optionale Absenderprüfung, Testzahlung.

- **Phase 4 — Abrechnung & Freischaltung** [A/C]
  Credit-Abzug pro Abgleich: sauberer **consume-Endpunkt** (Artem) **oder** Fallback
  Dummy-QR via `POST /api/code` (sofort machbar); Dienst pro Kunde über
  **`Update active additional services`** freischalten.

- **Phase 5 — Anlernen & Kontrolle** [C]
  Format-Templates pro Bank (aus Claude-Läufen), **SPAM-/Filter-Report** mit Rückholen,
  **Sammel-Quersumme** (§4).

- **Phase 6 — Härtung & Pilot** [C/M]
  Signierter Einmal-Link, Pro-Kunde-Token, DKIM-Option, Löschen-nach-Lesen verifizieren;
  Tests; End-to-End-Pilot mit einem echten Konto.

**Kritischer Pfad:** Phase 1+2 hängen nur an Google Cloud + Mail-Zugang, nicht an Artem.
Der Server-Teil (Phase 4) kann parallel laufen und notfalls über den Dummy-QR-Fallback
überbrückt werden. → Ein Pilot ist möglich, **bevor** sqrip 2.0 steht.

---

## 14. Was ich von dir brauche (explizit)

**Zugänge / Infrastruktur**
1. **Google Cloud** — soll ich den Dienst dort bauen (Empfehlung), oder Artem? Wenn ich:
   ein GC-Projekt (EU-Region) + eine Rolle für mich (Cloud Run, Secrets, ggf. Pub/Sub).
   Alternativ legst du das Projekt an und ich bekomme Deploy-Zugriff.
2. **`box@avis.sqrip.ch`** — Passwort/IMAP-Zugangsdaten. Und die Frage: soll der Dienst die
   Box **per IMAP pollen** oder richten wir einen **Inbound-Parse-Webhook** ein? Letzteres
   braucht DNS/MX-Kontrolle über `avis.sqrip.ch`.
3. **DNS von `avis.sqrip.ch`** — Kontrolle (für Inbound-Parse/MX und optional DKIM-Prüfung).
4. **Claude-API-Key** für den Dienst (separat von Co-Work), mit Budget.
5. **sqrip-Server-API** — ein Bearer-Token eines Testkontos, um `POST /api/code` (Credit-Zug)
   und die Freischaltung real zu testen.

**Entscheidungen** (klein, aber blockierend für den Feinbau)
6. Credit-Einheit: pro Mail / pro Gutschrift / pro Match?
7. Stufe 2 (Freitext-Bestellnummer): unter Schwelle automatisch freigeben — ja/nein als Default?
8. Default-Wert der Betrags-Schwelle X.

**Mit Artem zu klären** (dein Kanal)
9. Baut Artem den **consume-Endpunkt** und legt die **Abgleich-Logik aus sqrip 2.0** frei —
   oder starten wir interim mit dem Dummy-QR-Fallback?
10. **Freigabe des Artem-Briefs** (§11), dann formuliere ich ihn versandfertig.

**Für den End-to-End-Pilot**
11. Ein echtes Testkonto mit ein paar offenen QR-Rechnungen und **eine echte Bank-Avisierung**
    an `box@avis.sqrip.ch` (damit ich die realen Empfänger-Header und das Format sehe).

---

Siehe [[sqrip-1-11-stand-und-pivot]] (C/CAMT bleibt als Fallback) und [[sqrip-plugin-release]].
