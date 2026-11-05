# Anlagenabgang: Entwicklungs- und Prüfstand 16. September 2026

Diese Ergänzung ist **nicht produktiv veröffentlicht**. Sie hebt keine E-Bilanz-Abgabesperre auf. Kulturhus bleibt in seiner bisherigen Gewinnermittlungsart; produktive Datenbanken, Website-Konfiguration, Worker und Zertifikate wurden nicht geändert.

## Implementierter Fall

Vollständige, dokumentierte Entsorgung/Verschrottung beweglicher Ausstattung ohne Erlös oder Entschädigung. Unterstützte Anlagekonten: 06300, 06500 und 06700. Anschaffungsplan, ursprüngliche Rechnung und bereits bestätigte Vorjahreswerte bleiben unverändert.

- Abgang wird beim Bestätigen des abgeschlossenen Jahres erfasst. Datum, Grund, Finanzdokument einschließlich Dateifassung und SHA-256 sowie eine ausdrückliche Erklärung sind erforderlich.
- Monatliche lineare/degressive AfA wird bis einschließlich Abgangsmonat berechnet. GWG bleiben eine Sofortabschreibung, kein Sammelposten. Auszusondernder Restwert wird gesondert als Anlagenabgang auf 68950 behandelt, nicht als weitere gewöhnliche AfA.
- Die Oberfläche zeigt AfA und auszubuchenden Restwert vor dem Bestätigen; kein Geld wird überwiesen. Die körperliche Inventarbewegung ist separat vorzunehmen und wird nicht behauptet oder heimlich erzeugt.
- Anlagenspiegel enthält Brutto-Anschaffungskostenabgang, Abgang kumulierter Abschreibungen und Restbuchwertabgang. Der folgende Jahresstand bleibt null; die historischen Nachweise bleiben erhalten.
- Ein bereits abgeschriebener Gegenstand erhält einen beleggebundenen Nullwert-Abgang ohne erfundene Geldbuchung. Dieser Vorgang erhöht trotzdem Journalversion und Auditstand. Normale leere Rechnungen/Buchungen bleiben unzulässig.
- Vereins- und Finanzrechte, eindeutige Quelle, aktuelle Dateifassung, unveränderter Anschaffungsplan sowie Jahresabschluss-/Versionssperren werden serverseitig geprüft. Gleichzeitige Jahresbestätigung und Ausmusterung können nicht beide erfolgreich gespeichert werden.
- Der Python-Generator rechnet AfA und Bruttobewegungen unabhängig nach, stimmt Bilanz/GuV ab und prüft amtliche Anlagenspiegel-Gleichungen. Unbekannte Bewegungsarten bleiben gesperrt. Keine verdeckte Sammelzuordnung.

Fachlicher Bezug: [EStG § 7](https://www.gesetze-im-internet.de/estg/__7.html), [EStG § 6](https://www.gesetze-im-internet.de/estg/__6.html) und [BMF EStH 2025, R 7.4 – Ende der AfA](https://esth.bundesfinanzministerium.de/esth/2025/A-Einkommensteuergesetz/II-Einkommen-2-24b/3-Gewinn-4-7i/Paragraf-7/r-7-4.html). Die verwendete Monatsberechnung ist im Prüftext sichtbar; keine Ableitung einer universellen steuerlichen Freigabe aus einem technisch gültigen Datensatz. SKR42 68950 ist in beiden gebundenen Jahreskatalogen als Restbuchwert bei Buchverlust enthalten; Zuordnung nur bei abgestimmten Ausmusterungsnachweisen.

## Nachweise

- 92 kombinierte MySQL-Quellen-/Abgangsprüfungen mit künstlichen Daten, einschließlich Festschreibung des Nullwert-Abgangs, CSV-/JSON-Arbeitsunterlagen und Vortrag ins Folgejahr.
- 10 neue Python-Prüfmethoden einschließlich absichtlich falscher Nachweise, Abgangsdaten, Werte und Bewegungsarten; 57 bestehende Generator-Prüfmethoden weiterhin bestanden.
- 22 Browserprüfungen bei 390 und 1280 Pixeln, ohne Netzwerk und ohne Formularversand; Bildschirmdarstellung visuell geprüft.
- Fünf konkurrierende Schreibszenarien: Plan/Jahr, Plan/Plan, Jahr/Jahr, Jahr/Abgang, Abgang/Abgang. Jeweils genau ein erfolgreicher Vorgang.
- Bestehende 708 Finanz-/Steuerprüfungen in 15 Testgruppen sowie zusätzliche GWG-, degressive, Kontenplan-, Steuerwertvortrags- und Freigabetests bestanden.
- Sechs lokale native ERiC-Prüfungen erfolgreich; absichtlich veränderte Bilanzsumme mit 610001002 abgelehnt. `audit/ebilanz-disposal-native-20260916.json`.
- Dieselben sechs Fallarten über einen abgeschotteten temporären HTTP-Testzugang mit PHP 8.3.33, SAPI `cgi-fcgi`, tatsächlichem PHP→Python→Linux-ERiC-Aufruf: jeweils Code 0. **Kein Datenbankzugriff, kein Zertifikat, kein Versandversuch.** `audit/ebilanz-disposal-webserver-20260916.json`.
- Ein erster Hilfsskript-Versuch scheiterte beim Schreiben des temporären Endpunkts, vor dessen Ausführung. Private Testdateien und die leere Endpunktdatei wurden entfernt. Der erfolgreiche Lauf bestätigt unveränderte Hashes der geprüften Produktionsdateien; beide Laufprotokolle bleiben erhalten.

Die Webserverprobe prüft Erzeugung und amtliche Validierung, **nicht** einen produktiven MySQL-Workflow oder einen Testversand. Spätere lokale Schutzprüfungen für beschädigte Jahresstände, Nullwert-Kennungen und doppelte Abgänge sind durch lokale Tests abgedeckt; das Webserverprotokoll ist durch seinen eigenen Quellpaket-Hash begrenzt.

## Weiterhin offen, keine Freigabe durch Umbenennen

Verkäufe, Schenkungen/Entnahmen, Versicherungsersatz, Teilabgänge, Anlagenumbuchungen, andere Anlagenklassen, Sammelposten, §-7g-/weitere Sonderabschreibungen, getrennte Handels-/Steuer-Anlagenwerte und vollständige steuerliche Sonderfallabdeckung bleiben eigenständige offene Implementierungen. Ebenso vollständige SKR42-Taxonomiezuordnung und organisationsweite/mehrbetriebliche Bilanzierung.

Aktuelle Erweiterung ist kein Beleg für eine allgemein vollständige Vereins-E-Bilanz. Die bestehende Beschränkung auf den getesteten Einzel-Geschäftsbetrieb und die Jahrgänge 2025/2026 bleibt bestehen. Produktive Veröffentlichung erst nach Abschluss des zugesagten Umfangs, Rücksicherungsmöglichkeit, vollständiger Webserver-Abnahme und explizitem Abgabeprozess. Keine echte Steuererklärung wurde abgegeben.
