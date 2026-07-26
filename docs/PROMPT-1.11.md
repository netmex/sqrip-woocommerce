# Start-Prompt für den 1.11-Chat

Alles unterhalb der Linie in einen neuen Chat im Verzeichnis `sqrip plugin` kopieren.

---

Wir bauen Version **1.11** des WooCommerce-Plugins sqrip. Der vollständige Plan liegt in
`docs/PLAN-1.11.md` — lies ihn zuerst, er ist die verbindliche Arbeitsgrundlage.

**Ausgangslage**

- 1.10 ist seit 25.07.2026 auf wordpress.org live.
- Der Linear-Workspace (netmexch, Team NET2, Projekt „sqrip/EBICS") ist seit dem
  10.07.2026 read-only. Der Linear-MCP ist verbunden und darf zum Nachlesen einzelner
  Karten genutzt werden (`NET2-xxxx`), aber nichts dort ist mehr Arbeitsliste.
- Server-, EBICS- und Magento-Themen gehören Artem Zyryanov und seinem Team. Nicht
  anfassen, nicht einplanen.

**Leitplanke**

Alles in 1.11 muss **ohne EBICS und ohne bLink** funktionieren. Version 2.0 ist die
Grenze zum automatischen Zahlungsabgleich. Der camt-Upload (Paket C im Plan) ist genau
deshalb Plugin-seitig gedacht: er ersetzt den automatischen Abgleich durch einen
manuellen und wartet nicht auf 2.0.

**Bereits erledigt, nicht nochmal machen**

Paket A1 (NET2-2322 b) ist gefixt: der Hook `woocommerce_process_shop_order_meta` in
`sqrip-woocommerce.php:760` läuft jetzt auf Priorität 99 und liest die Bestellung erst,
nachdem WooCommerce die geänderten Positionen und das neue Total gespeichert hat. Die
Änderung ist noch nicht committet.

**Arbeitsweise**

- Präsentiere Ergebnisse in **A-E-K**: A = Fakten/Belege, E = Erkenntnisse (aus 2+
  Fakten), K = Konsequenz/Empfehlung.
- Entscheide selbst, was du selbst entscheiden kannst. Muss-Fixes sofort ausführen, nicht
  vorher fragen. Nur echte Produkt-Trade-offs bei Markus lassen.
- Prüfe bei jedem Vorschlag, was dagegenspricht, bevor du handelst.
- Markus ist kein Techniker — begründe in Wirkung, nicht in Implementierungsdetails.

**Reihenfolge laut Plan**

0. Die drei geernteten Teile aus `origin/feature/ebics-camt53-live` lesen (Abschnitt
   „Vorhandener Code, der geerntet werden muss" im Plan) — **nicht mergen**
1. E2 (fehlender `sqrip_partial_invoice_amount_{n}` im Regenerationspfad), dann A2–A4
2. B1 — CH-Gatekeeper (NET2-2329)
3. C1–C4 — camt-Upload und Abgleich
4. B2, B3 — Skonto und Mahngebühr (nach C, weil C ihnen die automatische Entwertung liefert)
5. E1 — HPOS-Lücke schliessen
6. D1, D2, D4 — kleine Ergänzungen; D3 erst nach Klärung mit Markus

**Womit du anfängst**

Du arbeitest auf dem Branch `release/1.11` (existiert lokal und auf GitHub, ist
ausgecheckt). Mach Schritt 0, dann Schritt 1 (E2 und A2–A4) und setze diese Fixes um.

**Was du von Markus brauchst, sobald C ansteht**

Anonymisierte echte camt-Dateien aus dem E-Banking: `camt.053` und `camt.054` mit
QR-Referenz, eine mit RF-Referenz, eine mit Sammelbuchung („Zahlungsauftrag e-banking")
und eine mit einer Zahlung ohne passende Bestellung. Frag danach rechtzeitig, bevor du
mit dem Parser beginnst.
