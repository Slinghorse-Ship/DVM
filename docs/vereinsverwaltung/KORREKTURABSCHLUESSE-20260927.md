# Korrekturabschlüsse – Entwicklung und Prüfung, 27.09.2026

Kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR; die Produktivinstallation
wurde nicht verändert. Die neue Funktion läuft ausschließlich im vorhandenen
Bilanz-Arbeitsbereich hinter dessen Freigabeschalter.

## Implementierter Ablauf

- Ein festgeschriebenes Journal wird nicht wieder geöffnet oder überschrieben.
  Begründete, ausgeglichene Korrekturbuchungen bilden einen separaten Abschlussstand.
  Original und alle früheren Korrekturen bleiben abrufbar.
- Finanz-Schreibrecht bereitet vor; ein berechtigter Vorstand bestätigt exakt den
  geprüften Entwurf. Veraltete Formulare, doppelte Bestätigung, andere Vereine,
  Leserechte und EÜR dürfen keine Korrektur buchen.
- Zulässigkeit und Nachweis einer Berichtigung/Änderung werden ausdrücklich
  dokumentiert. Die Software entscheidet nicht automatisch über ein steuerliches
  Wahlrecht oder die Änderbarkeit einer Steuerfestsetzung.
- Offene Entwürfe sperren die weitere Verwendung als geprüfter Abschluss.
  Verwerfen erhält den letzten bestätigten Stand und den verworfenen Entwurf.
- Ein Vorstandswechsel kann durch erneute Prüfung des unveränderten Abschlusses
  nachvollzogen werden, ohne zusätzliche Buchungen zu erfinden.
- Bereits geschlossene Folgejahre erhalten einen eigenen geprüften Korrekturstand.
  Noch offene Folgejahre übernehmen geänderte Anfangsbestände nur nach einer
  ausdrücklichen Vorstandsprüfung. Ihre bisherigen Anfangsbestände bleiben in einer
  unveränderlichen Änderungshistorie erhalten. Der Vorjahresaufwand wird nicht ein
  zweites Mal zum Aufwand des Folgejahres.
- Vorhandene Bereichs-, Verrechnungs- und Steuernachweise werden weiterhin gegen
  ihren damaligen Abschluss geprüft. Sie bleiben lesbar, gelten aber nicht als
  aktuelle Freigabe des berichtigten Abschlusses. Der Folgejahresabgleich benötigt
  neue, exakt gebundene Prüfungen.
- Der Jahresabschluss-Export enthält korrigierte Zahlen, ursprünglichen Abschluss
  und Korrekturverlauf. Bestehende Steuerentwürfe können nicht unbemerkt mit neuen
  Zahlen weiterverwendet werden.

Neue Tabellen im MySQL-Schemaschritt **101**: `balance_closing_corrections` und
`balance_opening_corrections`. Beide besitzen native UPDATE-/DELETE-Sperren.
Die ursprünglichen Tabellen `balance_years` und `balance_entries` bleiben bei
Korrekturen bereits geschlossener Jahre unverändert.

## Nachweise

Abschließender isolierter Webserverlauf:
`audit/ebilanz-mysql-host-20260918-dde8e1f208127d12.json`.
Das historische Dateipräfix ist kein Laufdatum; der Lauf erfolgte am 27.09.2026.
Getestetes Quellpaket:
`a8504cc08ffb5e0833ef80a49ab93a4b10b11699de1c18ca26b4d5f821bba79b`.

| Testgruppe | Prüfungen | Ergebnis |
| --- | ---: | --- |
| Korrekturrechnung einschließlich Bereichsumbuchung | 26 | bestanden |
| Gespeicherter Ablauf, drei Jahre, Rollen, Controller, Export | 59 | bestanden |
| Bestehende steuerliche Nebenrechnung | 58 | bestanden |
| Bestehender SGEB-Ablauf mit simuliertem Transport | 48 | bestanden |
| Bestehender mehrjähriger Verrechnungsnachweis | 38 | bestanden |

229 Assertions sind **nicht** 229 verschiedene steuerliche Sonderfälle.
MySQL-Ziel war ausschließlich die dauerhaft freigegebene synthetische Datenbank
`k15753_migrate`. Im abschließenden Lauf wurde keine zusätzliche Sicherung oder
Wiederherstellung durchgeführt. Produktive Datenbank nicht verbunden, geschützte
Laufzeit-/Website-Dateien unverändert, privates Testverzeichnis wieder entfernt.
Keine tatsächliche Mail, Bankanweisung oder Steuerübermittlung.

Zusätzlich: 1.031 PHP-Dateien ohne Syntaxfehler; anschließend geänderte Dateien
erneut einzeln geprüft. 116 Laufzeit-Fingerprintprüfungen bestanden, 57 gebundene
Dateien und 14 Python-Abhängigkeiten vollständig gefunden.

Der erste Integrationstest fand eine alte Abschlusskennung beim erneuten
Vorbereiten der steuerlichen Nebenrechnung. Diese Bindung wird jetzt auf den
neu geprüften Abschluss gesetzt; die alten Nachweise bleiben unverändert.

## Oberfläche

Eigene Korrekturseite unter dem Jahresabschluss. Ergebnis vorher/nachher und nur
veränderte Konten stehen vorne; Buchungsdetails sind aufklappbar. Schrift und
Schaltflächen verwenden die bereits vorhandene gut lesbare Finanzgestaltung.
Gespeicherte Entwürfe werden wieder ausgefüllt, auch nach Eingabefehlern.

Synthetische Darstellung im Browser und die tatsächliche Rolle von Vorstand,
Finanzbearbeiter und Leser geprüft. Die schmale Rahmenansicht zeigte keinen
horizontalen Inhaltsüberlauf; Absatzschrift 16px, Schaltflächen über 48px hoch.
Die direkte mobile Screenshot-Funktion des eingebetteten Browsers war nicht
zuverlässig verfügbar. Kein echter iPhone-/Android-Test und kein beobachteter
Test mit einem unerfahrenen Menschen. Keine vorgezogene Manager-Gesamtprüfung.

## Fortsetzung: Abgabeart und ursprüngliche Übermittlung

Die zuvor pauschale Sperre korrigierter Abschlüsse wurde durch eine getrennte,
prüfpflichtige Abgabeentscheidung ersetzt. Das ist noch keine Produktivfreigabe.

- Unterstützt werden erstmalig (`E`), berichtigt (`B`), geändert (`G`), berichtigt
  und geändert (`BG`) sowie sonstige Korrektur (`sK`). Die Finalität bleibt davon
  getrennt: `reportStatus.E` bedeutet endgültig, nicht erstmalige Übermittlung.
- Berichtigung/Änderung bindet die letzte eindeutig bestätigte eigene Abgabe:
  Original-XML, Abschlusskennung, Vorstandsfreigabe, Übermittlungsversuch und
  Transferticket. Ein anderer Verein, Zeitraum, Geltungsbereich oder ein unklarer
  Versand kann diesen Bezug nicht ersetzen. Die Auswahl zeigt nur den aktuellen
  bestätigten Abgabestand, keine bereits ersetzten Originale.
- Eine neue Freigabe wird benötigt. Speichern löscht eine alte Validierung und
  Freigabe des Entwurfs, nicht die frühere Erklärung. Vor dem Senden wird unter
  der bestehenden Vereinssperre nochmals geprüft; konkurrierende Korrekturen
  können sich nicht unbemerkt überholen.
- Ein korrigierter, noch nie übermittelter Abschluss kann ausdrücklich als
  erstmalig bestätigt werden. Umbenennen des Betriebs oder Ändern der
  Taxonomieversion erzeugt keine neue Erstabgabeberechtigung.
- `sK` darf weder Bilanz, GuV noch Anlagenspiegel verändern. Verglichen werden
  die ursprüngliche Rechnung und die erzeugten XBRL-Werte einschließlich der
  fachlichen Kontexte; wechselnde technische Kontext-IDs sind unerheblich.
- Außerhalb des Programms abgegebene Erklärungen werden nicht automatisch
  erkannt. Die ausdrückliche Prüfung ist erforderlich. Ohne überprüfbaren
  ursprünglichen eigenen Übermittlungsnachweis ist diese Änderungsfunktion
  nicht verfügbar. Eine externe Erklärung wird nicht als eigene quittiert.

Der fachliche Geltungsbereich ist derzeit an das vorhandene Erzeugungsprofil
gebunden. Ein Wechsel zwischen vollständiger, kombinierter und separater
Darstellung ist damit noch kein unterstützter Änderungsfall.

### Zusätzliche Prüfungen

- Reine XML-Nachweisprüfung: 14 Assertions.
- Generator: 59 Tests; zusätzlich vorhandene Brücken-, Kombinations-,
  Nebenrechnungs-, Gesamtvereins- und Teilbereichstests (8/32/18/29/29).
- Native ERiC-Validierung: fünf Abgabearten in jeweils fünf vorhandenen
  Erzeugungsprofilen, **25 erfolgreiche Validierungen mit Code 0**. Nur
  Validierungsflag 2, kein Zertifikat und kein Versand. Ein absichtlich
  verfälschter Summenwert wird weiterhin abgelehnt.
- Belege: `audit/ebilanz-revisions-native-20260927.json` sowie die vier
  entsprechenden `whole-club`, `combined-club`, `subsidiary-club` und `partial`
  Native-Berichte. Die MySQL-Prüfung verwendet ausschließlich die dauerhaft
  freigegebene synthetische Datenbank und simulierten Transport.

Diese Nachweise belegen weder eine amtliche Anerkennung der materiellen
Bilanzwerte noch einen tatsächlichen Versand einer Korrektur an ELSTER.

Letzter gespeicherter Integrationslauf:
`audit/ebilanz-mysql-host-20260918-7fcc6ed09fdbf363.json`, Quellpaket
`d00b4d6e04622e6f119f2bbc0d49669f22d1bc5b6b8137c774d19bb3763a76ac`.
Fünf Testgruppen bestanden: Korrekturabgabe (35 neue + 61 Basisprüfungen),
Korrekturabschluss (59), Nebenrechnung (48), kombinierter Gesamtverein
(164 einschließlich eingebetteter Basis-/Vorläufertests) und steuerliche
Vereinsaufteilung (88 einschließlich Basis). Die Zahlen enthalten wiederholte
Basisprüfungen und sind nicht als Zahl verschiedener Sonderfälle zu summieren.
Keine zusätzliche Sicherung/Wiederherstellung, keine produktive Datenbank,
geschützte Produktionsdateien unverändert, private Laufzeitdateien entfernt.

Die neue Maske wurde mit synthetischen Daten im Browser geöffnet; die
Abgabeauswahl und Bestätigung waren erreichbar. Bei der Breitenprüfung mit
390px-Rahmen betrug die Inhaltsbreite 369px, ohne horizontalen Überlauf.
Ein gefundener CSS-Konflikt wurde danach in `tax-filings.css` behoben und
erneut vermessen: Auswahl 17px/49,69px hoch, Schaltfläche 17px/51,18px hoch.
Die Screenshot-Funktion blieb nicht verfügbar; deshalb keine behauptete
visuelle Desktop-/Handyabnahme und kein echter Nutzertest.

## Bewusst nicht als fertig freigegeben

- Die Bindung einer **berichtigten/geänderten E-Bilanz** und ihre amtliche
  Statusangabe sind implementiert und isoliert geprüft (siehe Fortsetzung).
  Ein tatsächlicher abschließender Testversand dieser neuen Korrekturkette
  sowie die Freigabe für Produktion sind nicht erfolgt.
- Anlagenkorrekturen benötigen einen berichtigten ursprünglichen Anlagen- und
  Belegnachweis. Freie Buchungen auf Anlage-, Abschreibungs- oder Abgangskonten
  ersetzen diesen Nachweis nicht und werden hier gesperrt.
- Die hier geprüfte Umbuchung zwischen Ertrags-/Aufwandssphären ist **kein**
  steuerlicher Eigentums-/Nutzungswechsel eines Wirtschaftsgutes. Sphärenwechsel
  von Anlagen, vollständige Konten-/Steuersonderfälle und abweichende
  Wirtschaftsjahre sind weiterhin offen.
- Gesamtabnahme, allgemeine Veröffentlichung und die nachgelagerte
  vollständige Bedienbarkeitsprüfung sind nicht erfolgt.

## Fachliche Referenzen

Die rechtliche Unterscheidung von Bilanzberichtigung und Bilanzänderung sowie
deren Grenzen stehen in [§ 4 Absatz 2 EStG](https://www.gesetze-im-internet.de/estg/__4.html).
Die Software verlangt deshalb einen belegten Prüfentscheid und setzt ihn nicht
durch eine pauschale automatische Einstufung gleich.
Die [E-Bilanz-FAQ der Finanzverwaltung, Januar 2026](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf)
verlangt bei Korrekturen einer übermittelten E-Bilanz einen entsprechenden
Berichtsstatus. Ein erfolgreich berechneter Korrekturabschluss allein belegt
noch keine korrekte Änderungsübermittlung.
