# ELSTER: PDF-Belege und Anlagen-Rückmeldungen

Stand 15.09.2026. Technische Integration, keine steuerfachliche Freigabe einer echten Erklärung.

## Ablauf

- Bei KSt/EÜR nach amtlicher Prüfung „PDF-Belege auswählen“ öffnen.
- Bereits ausdrücklich ausgewählte Nachweise aus dem Steuerassistenten und sein
  aktuell freigegebener Finanzamt-Tätigkeitsbericht werden versionsgebunden übernommen.
  Ein Bericht für die Mitgliederversammlung ist ausgeschlossen. Geänderte Quellen
  oder fehlende Freigaben blockieren die Übernahme; sie werden nicht ersetzt.
- Weitere aktive Finanz-PDFs auswählen; höchstens 20 Dateien, zusammen 2 MiB.
  Absender, Steuernummer, Jahr und Steuerart stammen aus der Erklärung.
- Belegauftrag prüfen, Vorschauen und exakt eingebettete PDFs öffnen.
- Eine ausdrückliche Abgabebestätigung mit eigenem Zertifikat/Passwort sendet zuerst
  die Erklärung, danach den zugeordneten Belegauftrag. Zwei amtliche Vorgänge,
  getrennte unveränderliche Protokolle. Bereits ausgewählte Assistenten-Nachweise
  dürfen nicht durch Überspringen dieses Schritts stillschweigend entfallen.
- Schlägt der zweite Vorgang fehl, bleibt der erste als übermittelt sichtbar.
  Erklärung niemals erneut senden. Ein unklarer Belegauftrag bleibt gesperrt.

Der Tätigkeitsbericht wird weiterhin ausschließlich vom tatsächlichen ersten
Vorstand freigegeben. Abgabe durch die bereits berechtigte Vereinsrolle mit
eigenem Produktivzertifikat; keine Passwörter in Datenbank, Sitzung oder Cron.

## Spätere Beanstandungen

ELSTER meldet ausschließlich beanstandete Anhänge, nicht nochmals jeden gültigen
Anhang. Deshalb ist ein leerer Abruf keine zusätzliche Eingangsbestätigung.
Die gespeicherte Vereins-E-Mail wird vorgeschlagen; für echte Belegaufträge ist
eine gültige Hinweisadresse erforderlich und Bestandteil des geprüften XML.

„Rückmeldungen abrufen“ verwendet das eigene Zertifikat und fragt das Passwort
erneut ab. Kein unbeaufsichtigter Cron-Abruf ohne Passwort. Der Abruf ist auf
`AnhangRueckmeldung` und das bekannte Transferticket dieses Vereinsauftrags
begrenzt. Andere Postfachdaten werden nicht übernommen.

`PostfachAnfrage_32` → OTTO-Abholung → Prüfung von Ticket, Nutzdatenticket,
Datenart und Dateinamen → MySQL-Commit → `PostfachBestaetigung_32`.
Bei Speicherfehler keine Empfangsbestätigung. Bei Bestätigungsfehler bleibt die
Meldung gespeichert und wird beim nächsten Abruf bestätigt, nicht erneut gesendet.
Ein später leerer Abruf löscht bekannte Beanstandungen nicht. Die amtliche
Handlungsanweisung wird unverändert angezeigt, nicht automatisch ausgeführt.

## Private Laufzeit und Updates

- ERiC 44.3.6.0: **Belegnachreichung_24** mit Anhaenge-simple-3 produktiv;
  Version 25 laut Entwicklerhandbuch S.368 ausschließlich Testbetrieb.
- Für den vollständigen synthetischen Rückmeldetest müssen sowohl Einreichung
  als auch Abholung Testmerker `700000001` tragen (Handbuch S.151).
  `700000004` löscht die Testlieferung am Eingang und erzeugt keine Rückmeldung.
- Neben ERiC benötigt die private Laufzeit `libcheckElsterDatenabholung.so` und
  `libotto.so` samt unveränderten SDK-Abhängigkeiten. Keine Änderung am OCR-Launcher.
- Konfiguration CLI/Web getrennt: `attachment_feedback_enabled:true`,
  `production_versions:["KSt_30_2025","EUER_2025","Belegnachreichung_24"]`;
  `transport_enabled` für diese drei Profile, `test_only:true` für Version 25.
- SDK, Herstellerkonfiguration, Schemata und Katalog bleiben privat und außerhalb
  des Webroots/Git. Versionswechsel in neuem Verzeichnis, nicht über alte Dateien.
- Migration 091 legt nur `tax_filing_evidence`, `tax_attachment_feedback` und
  `tax_attachment_checks` an. Bestehende Geschäftsdaten werden nicht geändert.
- Laut Abhol-SSb32 S.18 beschreibt `Datenpaket/Groesse` die verschlüsselte,
  komprimierte Übertragung, nicht die von OTTO gelieferten Klartextbytes.
  Beide Größen werden unabhängig begrenzt; XML muss vollständig und zuordenbar sein.

## Abnahme

- Live-Webprozess PHP 8.3.33: synthetische Belegnachreichung24 mit gültigem PDF
  erfolgreich (Code0). Separater amtlicher ungültiger PDF-Test erfolgreich gesendet;
  tatsächliche spätere Beanstandung abgeholt, gespeichert und bestätigt (je Code0).
- Native synthetische Vereins-Belegaufträge für KSt/EÜR: Test- und Produktions-
  XML-Prüfung erfolgreich, **kein Produktionsversand**. Jahresangabe ohne das
  bei diesen beiden Steuerarten unzulässige zusätzliche Zeitraum-Feld.
- 94 isolierte MySQL-Abgabe-/Anlagenprüfungen; 15 Python-Rückmeldungstests und
  18 bestehende Bridge-Tests. Zusätzlich Zertifikats-, Einstellungs-,
  Jahrespaket-, Assistenten- und Tätigkeitsbericht-Regressionsprüfungen erfolgreich.
- Synthetische Desktop-/schmale Browseransicht: Auswahl, gemeinsame Bestätigung,
  negative Rückmeldung, geschütztes Passwortfeld und keine zweite Sendefunktion
  im Rückmeldungsbereich geprüft. Produktive Formularübersicht lesend geöffnet.

Live-Release `20260915-tax-attachments-185c628b`, Schema91. Vor Umschaltung
verschlüsselte SQL- und Dateisicherung einschließlich Wiederentschlüsselungsprüfung.
1.831 übrige Quelldateien hashidentisch. Bestandszählung unverändert:
31 Mitgliedschaften, 25 Inventardatensätze, 5 Belege, 17 Dokumente, 0 Kontoumsätze.
Keine echten Steuererklärungen oder Abgabeversuche erzeugt. Temporäre
Testendpunkte und serverseitige Testzertifikate sind nach Abnahme entfernt.
