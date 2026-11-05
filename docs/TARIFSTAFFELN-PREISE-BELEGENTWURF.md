# Tarifpreise aus Zuweisungen verwenden

Unter **Einstellungen → Beiträge & Spenden / Leistungen** lassen sich Preise
für die eigenen Tarifzuweisungen ermitteln. Die Auswahl zeigt sechs Kontakte und
Tarife je Seite, literale Suche und direkte Seitenwahl. Nach der Auswahl klappt
die Übersicht zu; Datum, Preis und Übernahme sind direkt erreichbar.

Ein Festbetrag verwendet den gespeicherten Tarifbetrag. Altersstaffeln verwenden
den eigenen persönlichen Geburtstag und die vollendeten Lebensjahre am Preis-
Stichtag. Dauerstaffeln verwenden die zu diesem Stichtag bestehende Mitgliedschaft
und ihre vollendeten Jahre. Ein nur auf das Jahr genauer Eintritt erzeugt keine
erfundene exakte Staffelgrenze. Fehlende oder nicht mehr berechtigte Personendaten
werden als korrigierbarer Fehler angezeigt.

Das Abrechnungsdatum bestimmt den monatlichen, vierteljährlichen, halbjährlichen
oder jährlichen Kalenderzeitraum; vorheriger, aktueller und nächster Zeitraum
werden berücksichtigt. Einmalige Leistungen verwenden den Zuweisungsbeginn.
Der optionale Preis-Stichtag bleibt ohne eigene Eingabe automatisch: das
Abrechnungsdatum innerhalb des zugewiesenen Zeitraums, andernfalls dessen nächste
Grenze. Die tatsächlich verwendete Preisgrundlage und der Bruttobetrag sind
sichtbar. Eine Änderung des Abrechnungsdatums friert keine frühere automatische
Staffelgrenze ein.

Zusätzliche wiederkehrende Tarife können ausdrücklich anteilig nach zugewiesenen
Kalendertagen berechnet werden. Anfang und Ende zählen mit, Schaltjahre und echte
Monatslängen werden verwendet; der Betrag wird einmal in Cent gerundet. Bei den
verknüpften Einzel-/Familien-Jahresbeiträgen gilt weiterhin der volle Jahresbetrag
aus der bestehenden Satzungsregel. Einmalige Tarife werden nicht zu Teilperioden
umgedeutet. Die Preisermittlung verwendet den aktuellen ausgewählten Tarif; sie
rekonstruiert keine früheren Preise aus einem aktuellen Katalog.

**Als Belegvorlage verwenden** öffnet einen bearbeitbaren Belegentwurf mit dem
ermittelten Betrag, Kontakt, Tarif und Abrechnungsdatum. Die genaue Preisgrundlage
bindet Tarif-/Zuweisungs-/Kontakt-/Mitgliedschaftsstände und die berechneten Werte.
Änderungen vor der Übernahme verlangen eine neue Ermittlung. Eine rohe URL eines
gestaffelten Katalogeintrags kann dessen unzutreffenden Grundbetrag nicht mehr
als Belegvorlage verwenden. Finanzleser mit Mitgliederleserecht können Preise
ansehen; nur Finanzschreiber können sie in den Belegentwurf übernehmen. Fehlendes
Mitgliederrecht gibt keine persönlichen Zuweisungen oder Altersdaten frei.

Preisabfrage und Vorlagenaufruf sind rein lesend. Erst der bewusste vorhandene
Vorgang **Beleg speichern** legt den bearbeiteten Beleg an. Die Vorlage startet
keine automatische Serienabrechnung, Zahlung, Rechnungsmail oder Änderung früherer
Buchungen. Bereits gespeicherte Anträge, Jahresforderungen, Belege und Einwilligungen
behalten ihre Originale. Eine manuell bearbeitete Vorlage verwendet die eigenen
geprüften Angaben des Finanzschreibers; sie ist kein eingefrorener neuer Vertrag.

35 Fachprüfungen, 57 tatsächliche Browserprüfungen und drei UI-Datenbankprüfungen
bestehen. Beide JavaScript-/nativen Bedienwege nutzen zwölf Handy-/Tablet-/
200-Prozent-Schriftansichten. Genau zwei bewusst gespeicherte synthetische Belege
enthalten den Staffelbetrag und das gewählte Datum; alle Preis-/Vorlagen-GETs
erzeugen keinen Beleg. Frühere vollständige Originalzeilen bleiben außerhalb der
eigenen Anmeldungs-Zeitstempel unverändert. Zehn bestehende MySQL-Finanz-/Tarif-/
Beitrags-/Aufnahmegruppen und sieben betroffene native Suiten sind grün.

Keine neue Tabelle oder Modulversion: CMS 17, Auth 6, Kommunikation 15,
Delivery 11, Chat 3, Identity 4 und Workflows 2 bleiben erhalten. Paketgeneration
2026101316; 186 native Suiten registriert. Kein vollständiger neuer 186-Suiten-Lauf
oder reale Zahl-/Zustellabnahme wird behauptet.

```sh
DVM_CATALOG_PRICING_BROWSER=1 bash tools/ci/local-native.sh --core native-catalog-pricing
```
