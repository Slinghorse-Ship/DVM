# IBAN-Plausibilität

## Rechnungsbankdaten im aktuellen DVM-Manager

Unter **Einstellungen → Beiträge & Nummern → Bankverbindung** werden die
Angaben für neue Briefe und Rechnungen bearbeitet. Finanzlesen zeigt aktuelle
Bankdaten und zugehörige Konten ohne Änderungsfelder. Finanzschreiben erlaubt
unabhängige eigene Entwürfe; allgemeine Einstellungs- oder Mitgliederrechte sind
dafür nicht erforderlich.

Die eigene Kontensuche verwendet Sechserseiten. Suchen, Auswählen und Leeren der
gewünschten Kontenzuordnung erhalten alle übrigen vollständigen Felder auch ohne
JavaScript. Ganze falsche Angaben einschließlich **0** bleiben zur Korrektur
stehen; eine falsche Seite wählt kein Ersatzkonto. Ein Konto muss tatsächlich zum
Verein gehören und ein Bank-/Offlinekonto sein. Eine inzwischen zur Handkasse
geänderte Quelle ist auch hinter einer älteren Datenbankansicht unzulässig.

**Vollständige Bankdaten prüfen** zeigt ganze Rohdaten, den damaligen Stand,
tatsächliche Kontendaten und die nach Bestätigung gespeicherten Werte. Die
native Prüfung erhält die ursprüngliche Schreibweise; die normierte IBAN/BIC
werden gesondert dargestellt. Erst die getrennte Zustimmung speichert atomar.
Eine aktuelle Kontenumbenennung entwertet eine ältere unangenommene Prüfung.
**Aktuellen Bankdatenstand bewusst prüfen** erhält die gewünschten Daten und
übernimmt nur die aktuelle gespeicherte Fassung.

Die erste persönliche Bestätigung erhält ganze Eingaben, damalige Kontendaten,
tatsächliches Ergebnis, Konto, Zeitpunkt und genaue JSON-Bytes mit SHA-256.
Nach verlorenem Ausgang zuerst das ursprüngliche Original lesend öffnen, bei
Bedarf in einem neuen Tab. Angenommene Kennungen speichern kein zweites Mal.
Eigene erste Bestätigungen und wirkliche rohe Fassungen haben getrennte
25er-Seiten und Originaldownloads. Eine alte rohe Fassung beweist keine frühere
vollständige Prüfung. Andere Konten oder Vereine können private Kennungen nicht
verwenden; aktuelle Finanzrechte gelten auch für alte Tabs.

Die Bestätigung verändert weder Kontenaktivität noch frühere Rechnungen,
Positionen, Original-PDFs oder Kontostände. Der getrennte technische
FinTS-Registrierungswert bleibt der technischen Vereinsverwaltung vorbehalten.
Es wird keine Bankverbindung aktiviert und kein Auftrag eingereicht.

Bedienung und Wiederaufnahme stehen auch im
[Handbuch](handbuch/19-steuerunterlagen-und-einstellungen.md).

## Gemeinsamer IBAN-Vertrag und fachliche Grenzen

Mitgliedsbankdaten einschließlich SEPA-Mandatsgrundlage, Lieferantenbankdaten und Vereins-Kontoeinstellungen verwenden denselben serverseitigen Validator `Iban::normalize`. Er entfernt Leerraum einschließlich geschützter Leerzeichen, vereinheitlicht ASCII-Kleinbuchstaben, prüft Länderkennung, länderspezifische Länge und MOD97-Prüfziffer. Für Deutschland und Österreich muss zusätzlich der nationale Kontoteil ausschließlich aus Ziffern bestehen. Leere optionale Angaben bleiben zulässig; verpflichtende Bankdaten werden weiterhin vom jeweiligen Vorgang verlangt. Es gibt keine Datenmigration und keine Veränderung bereits gespeicherter Bankkonten beim Lesen.

Die übrigen IBAN-Formulare verwenden im Browser dieselbe Längentabelle aus der PHP-Klasse. Neu eingegebene ungültige IBANs werden am Feld rot mit einer verständlichen Meldung markiert. Nach Korrektur verschwindet die Meldung; beim Verlassen des Feldes wird eine gültige Eingabe ohne Leerzeichen gespeichert. Das gilt auch für dynamisch geöffnete Lieferantdialoge und dort ausgewählte Rechnungsvorschläge. Die eigentliche Speicherung prüft unabhängig vom Browser erneut.

Die Länder-Längen basieren auf dem [SWIFT-IBAN-Register, Release 101, Dezember 2025](https://www.swift.com/sites/default/files/files/iban-registry-v101.pdf); die Transkription wurde mit der entsprechend dokumentierten Tabelle des Projekts django-localflavor abgeglichen. Die Tabelle wird ausschließlich in `Iban::LENGTHS` gepflegt. Bei einer neuen Registerfassung ist sie kontrolliert zu aktualisieren; es erfolgt kein Laufzeitabruf externer Dienste.

Diese Prüfung bestätigt **nicht**, dass das Konto existiert oder zum genannten Kontoinhaber gehört. Sie löst keine Bankverbindung, Zahlung, Nachricht oder Mandatserteilung aus.

Historischer Nachweis des gemeinsamen Validators: 20 neue PHP-Prüfungen, 17 Browserprüfungen einschließlich dynamischer Felder, 40 bestehende Tresorprüfungen und 13 Lieferantenprüfungen bestanden. Zusätzlich wurde die tatsächliche lokale Kontoeinstellungsseite im Browser geprüft: falsche Prüfziffer rot, gültige Korrektur normalisiert. Dabei wurde kein Formular abgeschickt.
