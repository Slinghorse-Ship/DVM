# Tatsächliche Abschlusszeiträume im E-Bilanz-Generator

Nachtrag: Die damalige Beschränkung auf Abschlüsse ohne Anlagen wurde durch
[die unabhängig geprüfte datierte Anlagenausgabe](ZEITRAUM-ANLAGEN-XBRL-20260928.md)
erweitert. Die folgenden Aussagen dokumentieren den vorherigen Zwischenstand.

Stand 28.09.2026: Entwicklung und native Validierung, **kein allgemeiner
Bilanzrelease und keine Versandfreigabe**. Kulturhus bleibt EÜR.

## Erledigt

Der Generator kann belegte abweichende und kurze Abschlusszeiträume verarbeiten.
Beginn, Ende und Stichtag werden in sämtlichen XBRL-Kontexten, GCD-Angaben und
im ELSTER-Umschlag aus demselben geprüften Zeitraum übernommen. Die Gültigkeit
einer Taxonomieposition richtet sich nach dessen Beginn, nicht einem erfundenen
1. Januar des Endjahres.

`BalancePeriodFilingValues` erzeugt einen ausschließlich internen Datensatz aus
Originaljournal, gesondert bestätigter Steuerbewertung und genauem Vorzeitraum.
Der Python-Generator rechnet Buch- und Steuerbilanz nochmals aus den einzelnen
Originalzeilen und Wertänderungen nach. Ein neu berechneter Hash allein kann
widersprüchliche Zahlen oder einen falschen Vortrag nicht gültig machen.

Ein kurzes Folgejahr kann dasselbe Endjahr wie sein Vorgänger besitzen. Es führt
trotzdem dessen tatsächliche Daten und getrennte Journalidentität. Buchvortrag,
steuerliche Anfangsunterschiede und Nachweiskette werden centgenau geprüft.
Bei einem echten Erstzeitraum ohne Vorgänger werden Vorzeitraumangaben als
fehlend (`xsi:nil`) ausgewiesen; es wird kein Vorjahr erfunden.

Die bestehenden Kalenderjahr-Profile bleiben unverändert. Der native Generator
ist weiterhin testgebunden und hat keinen Versandzugriff. Die neue Schnittstelle
hat weder eine öffentliche Route noch eine gespeicherte Abgabefreigabe.

## Amtliche Grundlage und begrenzter Geltungsbereich

Das [BMF-Schreiben vom 10.06.2025](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Einkommensteuer/2025-06-10-ebilanz-taxonomien-6-9.pdf?__blob=publicationFile&v=7)
nennt die Taxonomie 6.9 für Wirtschaftsjahre mit Beginn nach dem 31.12.2025 und
gestattet bereits 2025 bzw. 2025/2026. Unsere neue datierte Generatorschnittstelle
ist enger begrenzt: Beginn frühestens 01.01.2025 und Ende in 2025 oder 2026,
entsprechend den tatsächlich vorhandenen geprüften Kontenkatalogen.

Die neue datierte Ausgabe ist bisher ausschließlich für ein separates
Geschäftsbetriebsjournal **ohne Anlagekonten oder zurückbehaltene Anlagen**
angebunden. Andere Vereinsbereiche und datierte Anlagen werden ausdrücklich
abgewiesen, auch wenn deren Nettosaldo null ist. Das ist eine technische
Zwischenstufe, nicht die vom Benutzer gewünschte allgemeine Vereinsbilanz.
Bestehende Kalenderjahr-Funktionen mit Anlagen werden dadurch nicht eingeschränkt.

Noch erforderlich für die vollständige datierte Abgabe:

- Unabhängige Generator-Rekonstruktion der datierten Anlagen einschließlich
  steuerlicher Bewertung, Nutzungs-/Sphärenwechsel und Abgänge.
- Datierte Gesamtvereins-/Mehrbetriebs- und Nebenrechnungsprofile.
- Gespeicherte Abgabeidentität je Zeitraum, Korrekturquittungen, Freigabeoberfläche
  und spätere Verarbeitungsrückmeldungen. Die bisherige Kalender-Schutzprüfung in
  `BalanceTaxFiling` bleibt bestehen; ein Zeitraum wird nicht darüber umgangen.
- Vollständige Konten-/Sonderfallabdeckung, Gesamtabnahme und Veröffentlichung.

## Nachweise

- 12 PHP-Prüfungen der neuen Datensatzgrenze, einschließlich unveränderlicher
  Vorgänger, getrenntem Steuerwertvortrag und Verweigerung widersprüchlicher Quellen.
- 10 Python-Testmethoden mit mehreren Fallvarianten: alle Kontextdaten,
  Quellenbindung, manipulierte Beträge, Buchungen außerhalb des Zeitraums,
  fehlender Vorgänger, Lücken, Rumpfjahr, Kalender-Rückwärtskompatibilität,
  nicht unterstützte Anlagen/Profile und fehlende Versandfähigkeit.
- Gesamter Generator-Regressionslauf: 20 Python-Testgruppen erfolgreich,
  einschließlich der neuen Zeitraumtests und bestehender Kalender-, Gesamtvereins-,
  Nebenrechnungs-, gemeinsamer Anlagen-, Verkaufs- und Steuerbewertungsfälle.
  Neue PHP-Dateien ohne Syntaxfehler; `git diff --check` ohne Befund.
- Native ERiC-Validierung, `Bilanz_6_9`, ausschließlich Flags 2:
  01.07.2025–30.06.2026, 01.03.2025–31.12.2025,
  01.01.2026–31.12.2026 und Folgezeitraum 01.07.2026–31.12.2026.
  Alle vier Ergebniscode 0. Bericht:
  `audit/ebilanz-period-native-20260928.json`.
- Bestehende native Kalenderfälle erneut geprüft: 22 gültige Fälle Ergebniscode 0;
  ein absichtlich verfälschter Gesamtwert erwartungsgemäß zurückgewiesen.
  Bericht: `audit/ebilanz-calendar-after-period-20260928.json`.

Keine Datenbankverbindung, kein Zertifikat, kein Transportversuch, keine echte
Steuererklärung in diesen Generatorprüfungen. Native Validierung ist weder ein
Testversand noch ein amtlicher Eingangs-/Verarbeitungsnachweis. Die nachgelagerte
Gesamtprüfung aller Menüpunkte wird dadurch ausdrücklich **nicht** gestartet.
