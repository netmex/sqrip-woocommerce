# Übersetzungen zu translate.wordpress.org hochladen (TODO – später)

> **Status: TODO, keine aktuelle Priorität.** Für die Publikation von 1.9 ist das
> **nicht** nötig – die Übersetzungen sind bereits im Plugin gebündelt (`/languages`)
> und greifen out-of-the-box. Dieser Schritt sichert nur zusätzlich ab, dass ein
> künftiges (evtl. unvollständiges) wordpress.org-Language-Pack unsere gebündelten
> Übersetzungen nicht überschreiben kann.

## Warum überhaupt?

WordPress lädt pro Sprache nur **eine** Quelle, in dieser Reihenfolge:
1. wordpress.org-Language-Pack (`wp-content/languages/plugins/`) – falls vorhanden.
2. sonst die im Plugin gebündelten Dateien (`/languages`).

Heute existiert kein Pack → unsere gebündelten Dateien greifen zu 100 %. Sobald aber
jemand auf translate.wordpress.org auch nur ein paar Strings übersetzt, entsteht ein
**Teil-Pack mit Vorrang**, und die restlichen Strings wären wieder unübersetzt. Wenn wir
unsere kompletten Übersetzungen dort importieren, sind beide Pfade deckungsgleich.

## Import-Dateien (bereits import-fertig)

Die `.po`-Dateien in `languages/` sind vollständige Übersetzungen (200/200) mit
korrekten Headern und können direkt bei GlotPress importiert werden:

| translate.wordpress.org – Locale | Datei |
|---|---|
| Deutsch (`de`)                    | `languages/sqrip-swiss-qr-invoice-de_DE.po` |
| Deutsch (Schweiz) (`de_CH`)       | `languages/sqrip-swiss-qr-invoice-de_CH.po` |
| Français (`fr`)                   | `languages/sqrip-swiss-qr-invoice-fr_FR.po` |
| Français Suisse (`fr_CH`)         | `languages/sqrip-swiss-qr-invoice-fr_CH.po` |
| Italiano (`it`)                   | `languages/sqrip-swiss-qr-invoice-it_IT.po` |
| Italiano (Svizzera) (`it_CH`)     | `languages/sqrip-swiss-qr-invoice-it_CH.po` |

## Schritt für Schritt

1. **PTE-Rechte beantragen** (Project Translation Editor) für das Plugin
   `sqrip-swiss-qr-invoice`. Als Plugin-Autor: im Forum von
   https://make.wordpress.org/polyglots/ einen Beitrag mit dem Tag `#editor-requests`
   erstellen, Plugin-Slug nennen und um PTE für de, de_CH, fr, fr_CH, it, it_CH bitten.
   (Alternativ pro Sprache ein bestehender Locale-Manager.)
2. Warten, bis die Rechte vergeben sind (kommt per Ping im selben Thread).
3. Pro Sprache die Projektseite öffnen, z. B.
   `https://translate.wordpress.org/projects/wp-plugins/sqrip-swiss-qr-invoice/dev/de/default/`
   (und analog `/de_ch/`, `/fr/`, `/fr_ch/`, `/it/`, `/it_ch/`; sowohl `dev` als auch
   `stable` importieren).
4. Unten **„Import Translations"** wählen, die passende `.po` aus der Tabelle hochladen,
   als „Current" (bzw. „Approve") importieren.
5. Prüfen, dass die Fortschrittsanzeige auf ~100 % steht.

## Bei künftigen String-Änderungen

Neue/geänderte Strings ⇒ `.pot` neu generieren, Diff übersetzen, `.po`/`.mo` neu
kompilieren (gebündelt) **und** die aktualisierten `.po` erneut bei GlotPress importieren,
damit beide Pfade synchron bleiben.

## Offene Qualitäts-Notiz

FR/IT wurden maschinell (sorgfältig, mit erhaltenen Platzhaltern/HTML/Shortcodes) erstellt
und sollten vor dem GlotPress-Import idealerweise von einer muttersprachlichen Person
gegengelesen werden. Deutsch kann Markus selbst freigeben.
