# Kontierung, Mitgliedsantrag und amtliche Spendenformulare

Stand: 5. September 2026. Lokale synthetische Entwicklungsumgebung, Schema 9. Ergänzt den Demo-Vergleich; keine vollständige Paritäts- oder Betriebsabnahme.

## Umgesetzt

- Beleg- und Sachspendenpositionen: 1–50 Positionen mit Bezeichnung, Bruttowert, Sphäre, Steuerkonto und Steuersatz; Summe und Steuer werden in ganzzahligen Cent berechnet. Geld-/Sachspenden werden ohne berechnete Steuer erfasst.
- Spätere Kontierungsänderung mit Grund, Versionsprüfung und Vorher-/Nachher-Verlauf. Betrag, Kontotransaktionen und Zahlungszuordnungen ändern sich dabei nicht. Abgeschlossene Kassenzeiträume sowie verworfene/gegengebuchte Belege sind geschützt.
- Durchsuchbarer Kontenplan mit Kategorien, Aktivierung/Deaktivierung, Umbenennung und eigenen fünfstelligen Unterkonten. Historische Positionsbezeichnungen bleiben erhalten. Ein aktiviertes Unterkonto kann keinen reservierten Bereich umgehen.
- SKR42 2026 aus dem öffentlichen DATEV-Jahres-PDF: 1.556 Quellzeilen mit Einzelkonten beziehungsweise Bereichen; 2.727 angezeigte Einzel-/Hauptkonten nach Bereichserweiterung. Bereichsgrenzen, Seiten, Kontenfunktionskürzel und SHA-256 der Quelle bleiben in `vereinsverwaltung/data/skr42-2026.json` erhalten. Bereiche sind mit Hauptkonten in Zehnerschritten vertreten; nicht jede mögliche freie Unterkontonummer wird vorab angelegt.
- Steuer-/Buchungsschlüsselreferenz 2026: sämtliche 292 Tabellenzeilen der Excel-Quelle, 291 unterschiedliche Schlüssel (49 hat zwei historische Varianten), außerdem zehn Funktionen ohne Schlüssel. Quellzeile, Satz, Funktion, UStVA, EU-Merkmal, ZM/OSS und alte Schlüsselnummer bleiben erhalten. Die SKR03/SKR04-Beispielkonten werden nicht als SKR42-Zuordnung verwendet.
- Lokal zuweisbar: 2/102 und 3/101 für einfache inländische Umsatzsteuerfälle, 8/402 und 9/401 für entsprechende Vorsteuerfälle. Belegart, 7/19 %, Kontierung und Automatikkonto-Kollision werden serverseitig geprüft; Schlüsselbedeutung wird an der Position gespeichert. Spenden und Beiträge erhalten keinen solchen Schlüssel. Eine bloße Referenzzeile erzeugt keine Buchung.
- Mitgliedsantrag mit drei Einrichtungsschritten: Feldabfrage, Zahlungsart Rechnung, Hinweistext/Zustimmung. Lokales Formular ohne vorheriges Konto, Eingangsliste, Detailprüfung, Annahme/Ablehnung und Zuordnung zu einem vorhandenen Profil. Annahme erzeugt Kontakt/Mitgliedschaft transaktional; Dublettenverdacht, überlappende Mitgliedschaften und Versionskonflikte verhindern eine unbemerkte Doppelanlage. Hinweisfassung und Zustimmung bleiben beim Antrag gespeichert.
- „Erste Schritte“ entspricht den fünf tatsächlich geöffneten Referenzgruppen: Einrichtung, Allgemeines, Beiträge und Spenden, Finanzen, Organisation. Offene Funktionen bleiben sichtbar; vorhandene Ausgaben, Termine und aktivierter Antrag werden erkannt. Die eigene Einführung kann als gelesen gespeichert werden. Herstellerfilm und abgeschlossene WISO-Schritte werden nicht als lokale Nachweise übernommen.

## Quellen und Grenzen des Kontenplans

[DATEV SKR42 2026, Artikel 12901](https://download.datev-shop.de/X7xkGEfqdeYuynPtuXCf5YKaSZyEji6f/12021), [DATEV Steuerschlüssel-Tabelle 2026, Dokument 0907048](https://wissensplattform.apps.datev.de/help/document/0907048), [dort verlinkte Excel-Datei, Stand 29.12.2025](https://wissensplattform.apps.datev.de/api/amr/knowledge-common/v1/entities/st63880282379_de.xlsx).

Der SKR42 verweist auf diese Schlüsseltabelle für die Regelbesteuerung. Kontenrahmen, Schlüssel und Buchungsregeln sind verschiedene Dinge. Automatische Steuerbuchungen, Kleinunternehmer-/Besteuerungsstatus, EU- und §13b-Zusatzangaben, aufzuteilende Vorsteuer, OSS, Berichtigungs-/Kombinationsschlüssel, Abschlusszwecke HB/SB, vollständiges Buchungsjournal sowie DATEV-Export und dessen Importprüfung fehlen noch. Die vollständige Referenz ist keine Zusage, dass sämtliche Fälle lokal buchbar sind.

Das Original-PDF enthält auffällige Angaben: 01430 mit zwei Bezeichnungen, einen reservierten Bereich über 47340 trotz ausdrücklich benanntem Einzelkonto und die absteigende Folge 47370–47339. Die betroffenen Konten werden bis zur Klärung nicht zur neuen Kontierung freigegeben. Alle R-Bereiche sind ebenfalls gesperrt. Seiten 1, 13 und 28 wurden zusätzlich visuell geprüft. Die Extraktion lässt keine unzugeordneten Zeilen in den Kontenspalten zurück; vollständige fachliche Abnahme und jährliche Folgeversionen stehen aus.

Reproduzierbarer Import: `vereinsverwaltung/tools/import-datev-catalog.py <SKR42.pdf> <Schluessel.xlsx>`, mit pdfplumber und openpyxl. Quellen werden als Daten verarbeitet; private WISO-Kontodaten und Zugangsdaten sind nicht enthalten.

## Amtliche Formulare

Die beiden vom Nutzer bereitgestellten Beispiel-PDFs wurden als einseitige, nicht ausfüllbare PDFs gelesen und gerendert. Das erste ist eine Sachzuwendungsbestätigung, das zweite eine Geldzuwendungs-/Mitgliedsbeitragsbestätigung. Im Geldbeispiel sind Aufwandsverzicht und Beitragsbestätigung angekreuzt. Steuerdaten und weitere erforderliche Angaben sind in den Testbeispielen nicht vollständig ausgefüllt; diese Angaben werden nicht als Vereinsstammdaten übernommen.

Amtliche Grundlage: [Hessen: Vordrucke für Ehrenamt/Vereine](https://verwaltungsportal.hessen.de/information/vordrucke-fuer-ehrenamtvereine), [034122 Geldzuwendung](https://verwaltungsportal.hessen.de/sites/default/files/media_documents/0353_Vereine_Muster_Geldzuwendung_2014.pdf), [034123 Sachzuwendung](https://verwaltungsportal.hessen.de/sites/default/files/media_documents/0353_Vereine_Muster_Sachzuwendung_2014.pdf), [BMF: verbindliche Muster und Verwendung](https://ao.bundesfinanzministerium.de/esth/2025/B-Anhaenge/Anhang-37/I/inhalt.html).

Die lokalen Verweise sind unter `/?view=settings&tab=donation-forms` erreichbar. Ausgefüllte PDFs, Vereinssteuerdaten, dokumentierter Aufwandsverzicht, Nachweisdateien, Aussteller/Unterschrift, gespeicherte Ausfertigungen und Sammelbestätigungen bleiben offen. Eine Rechnung oder eine Sachspendenbewertung allein ersetzt keine Bescheinigung.

## Noch nicht im Mitgliedsantrag

Profilfoto, freie Zusatzfelder, PDF-Hinweise/Uploads, mehrere Hinweisblöcke, SEPA-Mandat/Bankdaten, Beitragstarife, Benachrichtigungen und produktive Veröffentlichung mit Schutz des öffentlichen Eingangs. Der lokale Testantrag wurde aktiviert, ein synthetischer Antrag eingereicht und nach Prüfung abgelehnt; kein zusätzliches Mitglied und keine Nachricht wurden dadurch erzeugt. Annahme, Wiederaufnahme und vorhandene Profile sind im Fachtest abgedeckt.

## Verifikation und Wiederherstellung

402 bestandene Fachprüfungen: 72 Fundament, 60 Finanzen, 53 Zahlungszuordnung, 45 Mitgliederübersicht, 56 Positionen, 45 Anträge, 36 Katalog/BU, 35 Bank-Vorschau. Die Bank-Vorschau hat weiterhin keinen echten Adapter. PHP-Lint und JavaScript-Syntaxprüfung bestanden; alle neuen Darstellungsdateien sind im Abschlusslauf enthalten.

Browser: Mehrpositions-Geldspende erstellt, auf 40450 umkontiert und unveränderter Gesamtbetrag/Verlauf zurückgelesen; Sachspendenposition mit 11600 und Wert 0,30 EUR gespeichert. Ausgabe mit BU 401 gespeichert und 119 EUR brutto / 19 EUR Steuer / 100 EUR netto zurückgelesen. Die beiden offenen Geldbelege des Oberflächentests wurden anschließend über den Finanzdienst verworfen; Kontostände bleiben 171,35 EUR und 2.654,50 EUR. Lokalen Antrag eingerichtet, abgesendet, im Eingang geöffnet und mit Prüfvermerk abgelehnt. Neue Ansichten bei tatsächlich gemessenen 360 Pixeln sowie mittleren/breiten Ansichten ohne horizontalen Seitenüberlauf geprüft. Der Browser skaliert Größenangaben; Messwerte werden daher verwendet, nicht bloß angeforderte Breiten. Vollständige Geräte-/Screenreaderabnahme bleibt offen.

Migrationen 7–9 erweitern die vorhandene Datenbank; alte Belege erhalten zunächst unkontierte Positionen. `bin/migrate.php --synthetic` sichert vor Versionswechsel per `VACUUM INTO`. Wiederherstellung: lokalen Server stoppen, aktive Datenbank zusätzlich sichern, ausgewählte private Sicherung zurückkopieren und Integrität/Migration/Fachtests prüfen. Originalbelege und Kontobewegungen werden durch die Migration nicht neu erzeugt.

Banking-Entscheidung des Nutzers: `nemiah/php-fints`, bestätigt am 5. September 2026. Releaseprüfung und Adapterimplementierung folgen gemäß [BANKING-FINTS.md](BANKING-FINTS.md).
