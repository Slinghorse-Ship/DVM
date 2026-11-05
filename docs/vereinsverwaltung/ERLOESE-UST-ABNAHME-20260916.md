# Erlöskonten und Umsatzsteuer: Abnahme 16. September 2026

**Entwicklungsstand, nicht produktiv veröffentlicht.** Keine echte Steuererklärung, keine Bankaktion und keine Änderung produktiver Daten. Kulturhus bleibt EÜR. Die zusätzlichen Zuordnungen erweitern den abgegrenzten E-Bilanz-Testgenerator, nicht dessen fachlichen Geltungsbereich.

## Umfang

Die jahresgebundene Zuordnungsdatei `skr42-6.9.json`, Revision 5, enthält jetzt 114 überprüfte Kontencodes für 2025 und 117 für 2026. Davon sind jeweils zehn bedingte Zuordnungen (Abschreibungen/Anlagenabgang und ausgeglichene Steuer-Sammelkonten). Das ist **keine vollständige SKR42-Kontenabdeckung**.

Hinzugekommen sind:

- 41840: Kleinunternehmer-Umsätze; getrennt von steuerpflichtigen Erlösen.
- 43000 bis 43170 in ausdrücklich aufgezählten Schritten: Nettoumsätze zum ermäßigten Satz; 43340 ausschließlich im bestätigten Katalog 2026.
- 44000 bis 44190 in ausdrücklich aufgezählten Schritten: Nettoumsätze zum allgemeinen Satz.
- 14610/14620/14630: Umsatzsteuerforderungen; 38430/38440/38990: Umsatzsteuerverbindlichkeiten. Gegenläufige Salden werden nicht heimlich umgedeutet.
- 14000/14010/14060 und 38000/38010/38060: Zuordnung erst bei einem exakt ausgeglichenen Schlussstand. Offene Sammelkonten blockieren den Generator auch bei manueller Taxonomie-Auswahl. **Kein automatischer Umsatzsteuerabschluss, keine erfundene Verrechnung.**

Die Quellübernahme trägt für die bestehenden Sammelkonten `src_input_vat`/`src_output_vat` jetzt die allgemeinen Kontencodes 14000/38000 ein. Bekannte Erlös-Automatikkonten werden mit dem tatsächlich gespeicherten Steuerbetrag übernommen und benötigen keinen zusätzlichen BU-Schlüssel. Widersprüche zwischen einem bekannten 7-/19-%-/Kleinunternehmerkonto und der gespeicherten Rechnungsposition sowie doppelte Kontenautomatik/BU-Schlüssel werden abgewiesen. Unbekannte Automatikkonten bleiben außerhalb dieser Freigabe.

Nettoerlös, Umsatzsteuer und Vorsteuer bleiben getrennte Buchungszeilen. Die Zuordnung entscheidet **nicht**, ob eine Leistung rechtlich steuerfrei ist oder welcher Steuersatz auf sie anzuwenden wäre. Sie überträgt bereits geprüfte Buchführungsangaben. Unbestimmte Konten, echte Mitgliedsbeiträge, Spenden, Auslandsgeschäfte, Differenzbesteuerung und weitere Sonderfälle erhalten keine pauschale Erlöszuordnung. DATEV-CSV und E-Bilanz sind unterschiedliche Exportwege; diese Erweiterung hebt keine gesonderten DATEV-Automatikkonten-Sperren auf.

## Prüfung

- 54 PHP-Prüfungen der Kontenzuordnung, Jahresgrenzen, Umsatzsteuer-Sammelkonten und Steuersatzregeln.
- 79 kombinierte MySQL-Quellen-/Umsatzsteuerprüfungen, einschließlich tatsächlicher Rechnungsübernahme, manueller Abschlussbuchung mit Nachweis, Rechte-/Vereinsgrenzen, Versionssperre und unveränderten Originalquellen. Ausschließlich synthetische Daten in kurzlebigen Testdatenbanken.
- Zehn Python-Testmethoden: Nettoumsätze, Zahlbetrag, Erstattung, Umsatzkorrektur, Jahresgrenzen, PHP/Python-Gleichlauf und nicht umgehbare Sperre offener bekannter Sammelkonten. Zusätzlich 57 vorhandene Generator-Testmethoden und zehn Anlagenabgang-Testmethoden bestanden.
- 207 eigene Datensätze durch die native ERiC-Bibliothek validiert: alle unbedingten Nicht-Anlagen-Kontencodes in beiden zulässigen Jahrgängen und vier Umsatzsteuerfälle. Ein absichtlich verfälschter Summenwert wird mit 610001002 zurückgewiesen. Nur Validierung, keine Übermittlung. Protokoll: `audit/ebilanz-registry-r5-native-20260916.json`.
- Zehn Fälle (sechs Anlagenabgänge, vier Umsatzsteuerfälle) über temporären HTTPS-Endpunkt, PHP 8.3.33/FastCGI, Python und Linux-ERiC: alle Code 0. Kein Datenbankzugriff und kein Zertifikat/Versand. Temporäre Dateien entfernt, Hashes der kontrollierten Produktionsdateien unverändert. Hauptwebsite und Manager danach HTTP 200, entfernter Endpunkt HTTP 404. Protokoll: `audit/ebilanz-r5-webserver-20260916.json`.
- 58 Browserprüfungen der E-Bilanz-Oberfläche bei 390 und 1280 Pixeln, einschließlich der neuen Kontenauswahl. Kein Netzwerk/Formularversand; mobile Darstellung zusätzlich visuell geprüft.
- Die bestehende Suite mit 708 Finanz-/Steuerprüfungen bestand erneut. Spätere gezielte Anschlussprüfungen decken insbesondere die zusätzliche Steuersatz-/Automatikkontenprüfung ab.

Die Webserverprobe umfasst **Erzeugung und Validierung**, nicht die komplette Datenbank- und Freigabekette. Die anschließend ergänzte Prüfung der Rechnungs-Steuersätze/Automatikkonten ist lokal mit MySQL geprüft; sie ist nicht Teil des im Webserverprotokoll festgehaltenen Quellpaket-Hashes. ERiC-Code 0 ist keine Bestätigung materieller steuerlicher Richtigkeit und keine spätere Verarbeitungsbestätigung.

## Sicherung und Veröffentlichung

Quellstand wird anhand der überprüften Distributions-Allowlist lokal gesichert. Das Archiv enthält keine produktive Datenbank, Zugangsdaten oder Vereinsunterlagen. Es ersetzt kein verschlüsseltes Live-Backup. Keine Schemaänderung erforderlich.

Die veränderten SKR-Codes in Quellvorschlägen können den Vergleich mit früheren **Entwicklungsjournalen** verändern. Bestehende Quellenbindungen werden nicht überschrieben; offene Jahre benötigen gegebenenfalls den vorhandenen Korrekturablauf. Festgeschriebene Jahre bleiben unverändert. Vor Veröffentlichung ist diese Kompatibilität auf dem tatsächlichen Zielstand erneut zu prüfen; keine pauschale Reparatur alter Journalbelege.

Weiter offen: weitere Anlagenbewegungen (insbesondere Verkauf, Teilabgang und Umbuchung), getrennte handels-/steuerliche Anlagenwerte, Sammelposten und Sonderabschreibungen, weitere steuerliche Überleitungsfälle, vollständige Kontenabdeckung und organisationsweite/mehrbetriebliche Bilanzierung. Die Echtabgabe bleibt gesperrt. Die vorhandene Eingrenzung auf einen gesonderten steuerpflichtigen Geschäftsbetrieb, GKV, Kalenderjahre 2025/2026 bleibt unverändert.

Referenzen: [DATEV SKR42 Bilanz, Artikel 12901](https://www.datev.de/web/de/shop/produkt-details/12901), [amtliche Taxonomie-Veröffentlichung und Versionshinweise](https://www.esteuer.de/). Die beiden lokalen Jahreskataloge und die Taxonomie werden weiterhin gegen ihre gebundenen Quell-Prüfsummen geprüft.
