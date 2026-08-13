# Machbarkeitsstudie — Mehrere Prozesse in sqrip, gesteuert über Auslöser

Stand: 06.08.2026 · Autor: Analyse für babytuch/sqrip · Bezug: baut direkt auf
`docs/PLAN-Prozesskonfigurator.md` (27.07.2026) auf

> **Fragestellung (Markus):** Der Shop babytuch hat seit der Auslandanbindung
> verschiedene Abläufe bei neuen Bestellungen. Bald kommen Vertriebspartner dazu, die
> Ware bestellen und erst danach bezahlen. Es wird unübersichtlich. Kann sqrip mehrere
> Prozesse definieren, die je nach **Auslöser** (Checkbox beim Checkout, verfügbare UID,
> bereits abgeschlossene Bestellungen) ablaufen — und wie?

---

## 1. Ausgangslage

### Das ist nicht neu — es ist der bereits dokumentierte „Fall E"

Der Prozesskonfigurator-Plan vom 27.07.2026 beschreibt fünf Abläufe (A–E), die sqrip
technisch alle kann, die aber heute niemand benennen kann. Deine Situation ist wörtlich
**Fall E**: zwei oder mehr Abläufe nebeneinander, je nach Kundengruppe. Der Plan hält
dazu fest: *„Alle Einstellungen sind global. Zwei Kundengruppen mit zwei Abläufen sind
mit einer Installation nicht abbildbar."* Genau das spürst du jetzt.

Neu ist nur der **Vertriebspartner**: „Ware zuerst, Rechnung hinterher" ist Ablauf **C**
aus demselben Plan.

### Was sqrip heute wirklich tut (geprüft am Code, nicht vermutet)

sqrip trifft heute **drei** Verzweigungen, alle gebündelt in `process_payment()`:

| Weiche | Ort | Kriterium |
|---|---|---|
| Rechnung erzeugen ja/nein | `class-wc-sqrip-payment-gateway.php:2107` | globale Option `suppress_generation` |
| Schweizer QR vs. GiroCode | `class-wc-sqrip-payment-gateway.php:2117` | globale Option `payment_scheme` |
| Land erlaubt ja/nein | `class-wc-sqrip-payment-gateway.php:2129` | `billing_country` gegen erlaubte Länder |

Zwei Dinge sind daran entscheidend:

- **Es gibt bereits echte Auslöser** — aber nur zwei: das **Rechnungsland** und die
  **Währung/IBAN-Art** (EUR + SEPA-IBAN → GiroCode, sonst Schweizer QR). Die Maschinerie
  „schau auf ein Merkmal der Bestellung, wähle danach den Weg" existiert also im Kleinen
  schon.
- **Aber jede dieser Weichen liest eine *globale* Einstellung.** `payment_scheme` ist
  eine einzige Shop-weite Wahl, keine Regel, die pro Bestellung neu ausgewertet wird.
  Das ist der Deckel, der Fall E heute verhindert.

### Was für deine drei Wunsch-Auslöser fehlt

| Dein Auslöser | Verfügbar in sqrip heute? |
|---|---|
| Checkbox beim Checkout | **Nein.** sqrip fügt keine eigenen Checkout-Felder hinzu (einziger Eingriff: `address_2` zur Pflicht machen). |
| Verfügbare UID / MwSt-Nummer | **Nein.** Kein UID-/VAT-Kundenfeld existiert. Der einzige „VAT"-Treffer ist eine Einstellung zur Besteuerung der Mahngebühr. |
| Bereits abgeschlossene Bestellungen | **Nein** in sqrip — aber WooCommerce liefert das von Haus aus (`get_order_count`, `is_paying_customer`). Nur bei **eingeloggten** Kunden; Gäste haben keine Historie. |

### Der Startvorteil, den du vielleicht nicht auf dem Schirm hast

Die read-only-Vorstufe **P0** aus dem Prozesskonfigurator-Plan ist **schon gebaut**
(Branch `feature/1.12-prozesskonfigurator-p0`, Klasse `Sqrip_Process_Overview`, 29 Tests
grün, noch nicht gemerged). P0 leitet aus den heutigen Einstellungen in Klartext ab, was
ein Shop-Betreiber gerade eingestellt hat. Der Plan formuliert die Logik dahinter so:
*„Wer den Prozess aus den Einstellungen lesen kann, kann ihn später auch schreiben."*
Die Lesehälfte deiner Frage ist damit im Prinzip erledigt.

---

## 2. Ziel

Ein **Prozessspeicher** in sqrip: mehrere benannte Abläufe (z. B. *Standard Schweiz*,
*Ausland*, *Vertriebspartner*), von denen jeder die rund **vierzig prozessrelevanten
Einstellungen** in einem eigenen Profil bündelt — wann die Rechnung entsteht, welche Art
Rechnung, wann sie an den Kunden geht, wie die Zahlung festgestellt wird, was danach
passiert.

Pro Bestellung wählt sqrip **einen** dieser Prozesse. Dein Zusatzwunsch: diese Wahl soll
über **Auslöser** vorbelegt werden. Konkret für babytuch:

- **Standard Schweiz** — QR sofort, Bestellung wartet auf Zahlung, dann Versand (Ablauf A)
- **Ausland** — GiroCode/EPC-QR (läuft heute schon über die Währungsweiche)
- **Vertriebspartner** — Ware zuerst, Rechnung mit Zahlungsziel hinterher (Ablauf C)

---

## 3. Plan

Der tragende Bau ist **kein Neubau**, sondern die Umsetzung der bereits geplanten Pakete
P0–P5. Dein Auslöser-Wunsch ist der Teil, den der alte Plan bewusst kleingehalten hat —
den bewerte ich hier ehrlich neu.

### 3.1 Das Rückgrat (aus dem bestehenden Plan)

Der Plan hat drei Architekturentscheide gefällt, die auch für dein Auslöser-Modell
gelten und es tragen:

1. **Der Konfigurator *schreibt* die bestehenden Einstellungen, er ersetzt sie nicht.**
   Kein zweites Einstellungssystem daneben — sonst gibt es „zwei Wahrheiten", die
   auseinanderlaufen.
2. **Der Prozess wird bei Bestelleingang festgehalten** (`sqrip_process`-Stempel auf der
   Bestellung), nicht bei jeder Abfrage neu ermittelt. Ändert der Händler später seine
   Regeln, laufen angefangene Bestellungen **unverändert** zu Ende. Für Vertriebspartner
   mit Wochen langem Zahlungsziel ist das keine theoretische Sorge, sondern Pflicht.
3. **Ein Auflöser statt vierzig geänderter Aufrufe:** neu `sqrip_process_option($key,
   $order)` — liest den Prozessstempel, fällt auf die globale Einstellung zurück, wenn
   kein Prozess gesetzt ist. Dadurch verhält sich jede Installation **ohne** aktiven
   Konfigurator exakt wie heute.

### 3.2 Der Auslöser — hier weiche ich vom alten Plan ab und bewerte deine drei Trigger

Der alte Plan hat die **automatische** Prozesswahl bewusst verworfen und auf **manuelle**
Wahl gesetzt (Auswahlfeld pro Bestellung, ein vorbelegter Standard). Begründung damals:
*„Sie rät dort, wo heute ein Mensch sicher entscheidet; sie liegt genau in den
Grenzfällen falsch."* Dein Wunsch nach echten Auslösern öffnet diese Entscheidung wieder.
Deshalb prüfe ich jeden deiner drei Auslöser einzeln — sie sind **nicht gleichwertig**:

**a) Checkbox beim Checkout — der ehrliche Auslöser.**
Der günstigste und zuverlässigste, weil niemand raten muss: Der Kunde (oder der Händler)
sagt es explizit. Technisch Standard-WooCommerce (Feld setzen, als Bestell-Notiz
speichern, Weiche liest sie). *Dagegen spricht:* Eine offene Checkbox „Ich bestelle als
Vertriebspartner" kann **jeder** ankreuzen. Sie taugt als Signal nur zusammen mit einer
Berechtigung — z. B. nur sichtbar für eingeloggte Partner-Konten. **Achtung
Block-Checkout:** WooCommerce' neuer Block-Checkout macht eigene Felder deutlich
aufwändiger als der klassische. Welchen babytuch nutzt, muss vorab geklärt werden.

**b) Verfügbare UID — erst ein Feld bauen, und „vorhanden" ≠ „berechtigt".**
Ein UID-Feld existiert heute nicht; es müsste erst eingeführt werden (Checkout +
Kundenprofil, ähnlich dem bestehenden Rückerstattungs-IBAN-Feld). *Dagegen spricht
doppelt:* Erstens kann jeder eine UID **eintippen** — die bloße Anwesenheit ist keine
Prüfung. Echte Validierung (gegen das CH-UID-Register bzw. EU-VIES) ist ein **eigenes,
größeres** Vorhaben. Zweitens: „UID vorhanden" heißt „ist wohl ein Firmenkunde", nicht
„darf auf Rechnung kaufen". Als **Vorbelegung** eines Geschäftskunden-Prozesses sinnvoll,
als **Freibrief** für Kauf-auf-Rechnung nicht.

**c) Bereits abgeschlossene Bestellungen — verlässliche Daten, aber der falsche Hebel für
dein Ziel.**
WooCommerce liefert die Zahl fertig, das Auslesen ist billig. Zwei harte Haken: Sie
existiert **nur für eingeloggte** Kunden (Gäste = keine Historie). Und sie versagt genau
im Fall, den du lösen willst — ein **neuer** Vertriebspartner hat **null** abgeschlossene
Bestellungen. Historie eignet sich für „treuer Stammkunde bekommt Vertrauensvorschuss",
nicht für das Onboarding von Partnern.

**Erkenntnis aus a + b + c:** Der „Vertriebspartner" ist keine Eigenschaft, die man aus
einer Bestellung *erraten* sollte — er ist eine **Berechtigung, die du als Händler
vergibst**. Der sauberste Auslöser ist deshalb die **WooCommerce-Kundenrolle/-gruppe**
(„Vertriebspartner"-Rolle → Standardprozess *Vertriebspartner*), ergänzt um die Checkbox
als expliziten Opt-in. UID und Historie taugen als **Bequemlichkeit obendrauf**, nicht
als Tor. Damit landet man fast wieder bei der Empfehlung des alten Plans — manuelle/über
die Rolle gesetzte Wahl plus optionale Vorbelegung —, nur mit einem echten Auslöser für
den Regelfall.

**Bewusst *nicht* bauen** (unverändert aus dem alten Plan): einen allgemeinen
Wenn/Dann-Editor für beliebige Regelketten. Er erlaubt unmögliche Abläufe, und jeder
Fehler zeigt sich erst an einer verkorksten echten Bestellung. Ein **geordneter
Erst-Treffer-Vorwähler** (Rolle → Checkbox → UID vorhanden → Bestellzahl → Land/Währung),
den der Händler pro Bestellung überschreiben kann, deckt deine Fälle ab, ohne diese Tür
zu öffnen.

### 3.3 Die Ausbaustufen (aus dem Plan, auf deinen Wunsch gemappt)

| Paket | Inhalt | Für deinen Wunsch |
|---|---|---|
| **P0** ✅ | Read-only „So sieht Ihr Prozess heute aus" | **gebaut**, nur noch mergen + Sichtprüfung |
| **P1** | Fünf Vorlagen (A–E) anwendbar machen | Grundlage; liefert *Standard* und *Ausland* fast geschenkt |
| **P4** | Prozessspeicher, Auswahlfeld an der Bestellung, `sqrip_process`-Stempel, `sqrip_process_option()`, Umstellung der ~40 Aufrufe | **Kern deines Wunsches** — mehrere Prozesse überhaupt |
| **Auslöser-Schicht** | Erst-Treffer-Vorwähler (Rolle/Checkbox/UID/Historie) als Vorbelegung *auf* P4 | **dein Auslöser-Wunsch**, klein *wenn* P4 steht |
| **P5** | Frei wählbarer Rechnungsversand („Status → Rechnung senden") | **nötig für Vertriebspartner** (Ablauf C: Rechnung erst bei Versand) |

---

## 4. Voraussetzungen

**Technisch**

- **Die doppelte Schema-Weiche zuerst zusammenführen.** Die Verzweigung
  Swiss/GiroCode existiert **zweimal**: in `process_payment()` der Gateway-Klasse *und*
  in der parallelen `process_payment_stt()` (`functions.php:1345`). Solange das doppelt
  ist, wird jede Prozesslogik doppelt gepflegt. Konsolidierung ist Vorbedingung für P4.
- **Verhalten ohne Konfigurator muss bitgenau bleiben.** Der Auflöser
  (`sqrip_process_option`) muss bei fehlendem Prozess exakt die heutige globale Option
  liefern. Das ist die „zwei Wahrheiten"-Hauptgefahr des Plans — in jedem Paket zu
  prüfen.
- **Checkout-Technik klären:** klassischer vs. Block-Checkout bei babytuch entscheidet
  den Aufwand der Checkbox und des UID-Felds erheblich.
- **Historie-Auslöser verlangt Kundenkonten.** Gäste haben keine. Entscheidung nötig:
  Setzt „Kauf auf Rechnung" ein Login voraus?
- **UID-Feld ist Neubau; echte UID-Prüfung ist getrennter Scope.**

**Organisatorisch / Risiko**

- **Kauf auf Rechnung = Zahlungsausfallrisiko.** sqrip *ermöglicht* den Ablauf, macht
  **keine** Bonitätsprüfung. *Wer* auf Rechnung kaufen darf, ist eine
  Geschäftsentscheidung — am besten als ausdrückliche Händler-Freigabe (Rolle) abgebildet,
  nicht als automatisches Raten.
- **Unantastbare Grundsätze des Plans:** Konfigurator schreibt bestehende Einstellungen
  (keine zweite Wahrheit); nimmt nichts weg (die Statuslogik wurde in 1.9.3/1.9.4
  zweimal „vereinfacht" und zweimal zurückgenommen); bestehende Installationen dürfen
  sich ohne Konfigurator nicht verändern.

---

## 5. Aufwand

Der Plan vermeidet bewusst Tagesangaben und eicht stattdessen an bekannter Arbeit; ich
halte das bei und ergänze eine grobe Relativ-Einordnung.

| Stufe | Umfang | Größe (relativ) |
|---|---|---|
| P0 mergen + Sichtprüfung | fertig gebaut | **klein** |
| P1 Vorlagen | *Standard* + *Ausland* als anwendbare Vorlagen | ~ wie „Paket C" aus 1.11 |
| **P4 Prozessspeicher** (Kern) | Speicher, Auswahlfeld, Stempel, Auflöser, ~40 Aufrufe umstellen | **groß** — je für sich größer als alles in 1.11 |
| Auslöser-Vorwähler | Erst-Treffer-Regel auf P4 | **klein**, *sobald* P4 steht |
| P5 Versand-Auslöser | „Status → Rechnung" (Vertriebspartner-Ablauf C) | **mittel** |
| UID-Feld | Neues Feld Checkout + Profil | **klein–mittel** |
| Echte UID-Validierung | Register/VIES-Abgleich | **eigener Scope** — später/optional |

**Kritische Einordnung (was dagegenspricht):**

- **P4 ist der größte Eingriff in der Geschichte des Plugins** — so der Plan selbst — und
  trifft genau die Stelle, die zweimal zurückgenommen werden musste. Die Breite (40
  Aufrufe), nicht die Schwierigkeit, ist das Risiko.
- **Dein Automatik-Wunsch zieht Richtung der Regel-Engine, vor der der Plan warnt.**
  Meine Empfehlung: **erst** Prozessspeicher + manuelle/rollenbasierte Wahl bauen — das
  allein löst den Vertriebspartner sauber —, den Auslöser-Vorwähler als dünne Schicht
  **darauf**, und den freien Wenn/Dann-Editor **weglassen**.
- **Für den vollen Vertriebspartner-Ablauf ist P5 nötig** (Rechnung erst bei Versand).
  Ohne P5 kann sqrip „Ware zuerst, Rechnung hinterher" nur eingeschränkt abbilden.

**Empfehlung:** P0 abschließen (mergen, prüfen) und P1 liefern — das gibt babytuch sofort
*Standard* und *Ausland* als benannte, sichtbare Abläufe. Danach anhand echter
Rückmeldung entscheiden, ob der volle Prozessspeicher (P4 + Auslöser + P5) für den
Vertriebspartner gebaut wird. Bricht man nach P1–P3 ab, ist das immer noch ein
vollständiges, sinnvolles Produkt — nur Fall E (mehrere Prozesse gleichzeitig) bleibt
dann offen.

---

## 5a. Ausblick: „Ich beschreibe den Prozess, die KI stellt das Plugin ein"

Zielbild (Markus, 06.08.): in einigen Monaten den Ablauf einer KI beschreiben, die KI
setzt danach alles im Plugin passend. **Ändert das die Architektur? Nein — es validiert
sie und verschärft zwei Pflichten.**

**Warum es nichts umwirft.** Architekturentscheid 1 des Plans lautet: *der Konfigurator
schreibt die bestehenden Einstellungen, er ersetzt sie nicht.* Er ist eine geführte
Oberfläche über dieselben ~40 Optionen. Eine KI ist damit schlicht ein **dritter
Aufsatz** auf genau diese Schicht — neben den manuellen Einstellungen und dem geführten
Konfigurator. Sie braucht kein neues Datenmodell, sondern dieselben drei Dinge, die die
Pakete ohnehin bauen: den Zustand lesen (P0), gültig schreiben (P1/P3/P4), und
Unfug verhindern (die Validierung des Editors).

**Warum ausgerechnet die konservativste Entscheidung des Plans die KI trägt.** Der Plan
baut bewusst ein **festes Vokabular** (Auslöser → Aktion, feste Schrittliste) statt eines
freien Wenn/Dann-Editors, *„weil ein freier Editor unmögliche Abläufe erlaubt"*. Genau
dieses eingezäunte Vokabular ist die ideale KI-Schnittstelle: Die KI gibt in einen
**begrenzten Satz gültiger Schritte** aus, nicht in rohe Einstellungen — sie *kann* keinen
Unsinn erzeugen, weil ein „Rechnung senden" ohne vorheriges „Rechnung erzeugen" sich
schon im Modell nicht ablegen lässt. Und die reine, WP-freie Schicht `derive_steps()` aus
P0 liefert den Prozess bereits **maschinenlesbar** — die halbe Lese-Seite für eine KI
existiert.

**Zwei Rollen der KI sauber trennen:**

- **Definitions-KI (das meinst du):** „Beschreib dein Geschäft, ich stelle das Plugin
  ein." Passt lückenlos auf die Architektur — Aufsatz auf P4, kein Umbau.
- **Laufzeit-KI (das meinst du *nicht*):** pro Bestellung entscheiden, welcher Prozess
  gilt. Das wäre die automatische Regel-Engine, vor der der Plan warnt. Deine Frage
  betrifft die **Definition** des Prozesses, nicht die Wahl pro Bestellung — die bleibt
  bei Rolle/Auswahlfeld.

**Was dadurch von „nett" zu „Pflicht" wird:**

1. **Der Trockenlauf (P2) wird zum Sicherheitstor, nicht zur Diagnose.** Beim Menschen,
   der klickt, prüft das Auge. Schreibt eine KI, muss eine maschinell prüfbare Simulation
   *vor* dem Scharfschalten in Klartext zeigen, was passieren wird — **KI schlägt vor,
   Mensch bestätigt, dann erst wird geschrieben.** Eine KI, die reale Zahlungs- und
   Rechnungsabläufe scharf stellt, darf nicht ohne Freigabe committen.
2. **Die eine Wahrheit wird kritischer.** Die „zwei Wahrheiten"-Gefahr des Plans wächst,
   weil eine KI schnell und breit ändert. Der Grundsatz „KI schreibt die bestehenden
   Einstellungen, niemals einen Parallelspeicher" muss absolut halten.

**Was es *nicht* erlaubt:** weniger zu bauen. Der tragende Kern P4 (Prozessspeicher,
Stempel, Auflöser) bleibt nötig — die KI schreibt *hinein*, sie ersetzt ihn nicht. Die
Versuchung, „die KI macht's ja eh" als Grund zu nehmen, den geführten Editor und seine
Validierung wegzulassen, ist ein Trugschluss: Die KI braucht dieselbe Validierung und
denselben Trockenlauf — und ohne die menschenlesbare Oberfläche fehlt die Rückfallebene,
wenn die KI danebenliegt.

**Zwei Festlegungen jetzt, damit die Tür offen bleibt** (beide stehen schon im Plan, die
KI macht sie nur tragend): die Prozessdefinition als **strukturierte Daten** führen, die
1:1 auf die Einstellungen abbilden (das Vokabular *ist* das Format); und Trockenlauf +
menschliche Freigabe als **verbindliches Tor** vor jeder scharfen Änderung behandeln.

---

## 6. Fazit

**Machbar — ja, und zu großen Teilen schon geplant und begonnen.** Deine Frage ist der
dokumentierte „Fall E" plus den Vertriebspartner-Ablauf C; die Lese-Vorstufe (P0) ist
gebaut. Der Kern (mehrere Prozesse pro Bestellung) ist Paket P4 und der größte, aber
klar umrissene Brocken.

**Der wichtigste Befund zu deinen Auslösern:** Sie sind ungleich. Die **Checkbox** ist
der ehrliche, günstige Trigger; die **UID** braucht erst ein Feld und sagt „Firma", nicht
„darf auf Rechnung"; die **Bestellhistorie** versagt ausgerechnet beim neuen
Vertriebspartner. Der verlässlichste Auslöser für „Vertriebspartner" ist deshalb keine
erratene Eigenschaft, sondern eine **Kundenrolle, die du vergibst** — mit Checkbox und
UID als Komfort obendrauf. Damit bekommst du deine automatische Vorbelegung, ohne die
Fehleranfälligkeit eines freien Regelwerks.
