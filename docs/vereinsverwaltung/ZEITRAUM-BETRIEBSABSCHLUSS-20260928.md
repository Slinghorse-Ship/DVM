# Gesonderter Geschäftsbetrieb: gespeicherter Zeitraumabschluss

Fortsetzung vom 28.09.2026. Kulturhus bleibt EÜR. Kein allgemeiner Bilanzrelease,
keine Produktivfreischaltung und keine echte Steuerübermittlung.

## Geschlossene Verbindung

Der bereits vorhandene datierte Einzelbetriebs-Generator ist jetzt an Entwurf,
Speicherung, Oberfläche, Steuerpläne, genaue Vorzeitraumübernahme, getrennte
Vorstandsfreigabe, Berichtigung und unveränderliches Übermittlungsprotokoll
angebunden. Der Umfang ist ein **tatsächlich separat geführtes Betriebsjournal**,
nicht die automatische Herauslösung eines Teilbetriebs aus der Vereinsbilanz.

- Originalbuchungen und Original-Anlagenverzeichnis werden vollständig gebunden.
  Keine erfundenen Null-Anlagen und kein bloßes Filtern nach Kontonummern.
- Andere Sphären oder Anlagen-Sphärenwechsel sperren dieses Profil. Auch ein
  umsatzloser Folgezeitraum darf gemischte Anfangsbestände nicht in einen neuen
  Einzelbetrieb umdeuten. Die ursprüngliche Zeitraumkette wird geprüft.
- Der Kalender-Vorabschluss behält Originalwerte und Hash. Seine Buch- und
  Steuer-Anlagenpläne werden unabhängig für den datierten Vortrag rekonstruiert.
- Ein vom Buchplan abweichender Steuerplan wird aus der letzten eindeutig
  bestätigten Abgabe übernommen. Die Änderung der Anfangswerte ist kein neuer
  Ertrag; frühere Anschaffung, Abgang oder Abschreibung werden nicht wiederholt.
- Mehrere kurze Zeiträume im selben Endjahr bleiben unterschiedliche
  Abgabeverpflichtungen. Bereits ersetzte Abgaben stehen nicht als Vortrag bereit.
- Ohne erforderlichen Vorabschluss zeigt die Oberfläche zuerst dessen Übernahme
  statt einer erfundenen Zahlenvorschau. Falscher Verein, Betriebsname,
  Steuerprofil, Steuernummer oder Zeitraum wird beim Speichern erneut abgewiesen.
- Geänderte Belege, Originalabschlüsse oder Steuerwerte verlangen eine neue
  Freigabe. Alte XML-Dateien, Unterschrifts-/Freigabenachweise und Protokolle bleiben
  lesbar und unverändert. Nach einer ursprünglichen Korrektur werden alte Anzeigen
  aus ihrem festgehaltenen Stand aufgebaut, nicht aus heutigen Zahlen.
- Ein veraltetes Eingabeformular behält neben seinen Texten auch seine alte
  Versionsnummer; es kann einen neueren Stand nicht durch eine automatische
  Aktualisierung dieser Nummer überschreiben.
- PHP und Python rechnen unabhängig. Der Laufzeit-Fingerprint erfasst auch den
  neuen Adapter. Eine Änderung nach XML-Prüfung entwertet die Versandfreigabe.

## Prüfungen

- `balance-period-business-values.php`: 41 Prüfungen der Kalender-/Zeitraumkette,
  des genauen Vortrags, der Originalbindung und abgelehnter Veränderungen.
- `balance-period-business-workflow.php`: persistierter Erstabschluss,
  Wertkorrektur, kurze Folgeperiode, Rollen, Vereinsgrenzen, Aktualitätsprüfung,
  gerenderte Maske und historischer Stand nach ursprünglicher Korrektur.
- `balance-period-business-calendar-assets.php`: sechs synthetische Rechnungs-/
  Anlagenketten durch Kalenderabschluss und zwei datierte Folgezeiträume;
  lineare/degressive AfA, GWG, Voll-/Teilabgang und Lizenzende einschließlich
  eigenständigem Steuerplan und unveränderten Originaltabellen.
- `ebilanz-period-business-calendar-test.py`: unabhängiger Kalenderübergang,
  kurze Folgeperiode und erneut gehashte Veränderungen an Original, Plänen,
  Steuerwerten und Profil. Vier Testmethoden mit mehreren Negativfällen.
- Die bestehende gemischte Vereinsregression prüft ausdrücklich, dass deren
  umsatzloser Folgezeitraum nicht als Einzelbetrieb ausgegeben werden kann.

Prüfläufe verwenden nur synthetische Daten. PHP-Transportantworten sind simuliert.
Native ERiC-Validierung mit Flag 2 ist weder Testversand noch fachliche Anerkennung
durch das Finanzamt.

### Abschließender gespeicherter MySQL-Ablauf

Geprüftes Quellenpaket:
`860ab7345c88e07ecb9d8edc506c3c5d2b68388d55f4b93ba026eb3bc075ac37`.

- Alle fünf abschließenden Webserver-Testgruppen erfolgreich, ohne PHP-Diagnostik:
  41 reine Betriebs-/Vortragsprüfungen, 35 gespeicherte Betriebsprüfungen,
  24 neue Kalender-/Anlagenprüfungen zuzüglich 22/43 Quellen- und Anlagenprüfungen,
  32 Gesamtvereins- und 61 Nebenrechnungsregressionen sowie 190 Fingerprintprüfungen.
- Alle 29 Python-Regressionsprogramme und die neun gezielt ausgeführten reinen
  PHP-Regressionsprogramme erfolgreich. Die Syntaxprüfung umfasst 555 PHP-Dateien
  in Laufzeit, Ansichten, öffentlichem Einstieg und Migrationen.
- Alle 18 XML-Fälle bestanden zusätzlich die native ERiC-Prüfung mit Code 0:
  13 Kalender-/Anlagen-Rechenfälle und die fünf unveränderten XML-Dateien des
  gespeicherten Webserver-Ablaufs (Erstabgabe, Berichtigung, kurzer Folgezeitraum,
  Kalender-Anlagenübernahme und deren Folgezeitraum). Ausschließlich Flag 2,
  kein Zertifikat, kein Übermittlungsversuch und keine behauptete Finanzamtsannahme.
- Im ersten Webserver-Lauf fehlte der synthetischen Testbuchung eine ausdrückliche
  Forderungszuordnung; die Testdaten wurden ergänzt. Außerdem deckte die bestehende
  gemischte Vereinsregression einen echten Fehler auf: Ein umsatzloser Folgezeitraum
  konnte als einzelner Betrieb erscheinen. Die ursprüngliche Vorabschlusskette wird
  jetzt mitgeprüft. Beide korrigierten Fälle bestehen im abschließenden Paket.
- Die bestehenden Gesamtvereins-Kalenderanlagen und anlagenbezogenen steuerlichen
  Hinzurechnungen bestanden bereits im ersten breiteren Lauf. Der abschließende
  Lauf wiederholt alle vom korrigierten Einzelbetriebsweg betroffenen Gruppen.
- Keine produktive Datenbank verbunden und kein öffentlicher Testendpunkt angelegt.
  Die geprüften produktiven Einstiegdateien sind unverändert. Temporäre Serverdateien
  entfernt; die dauerhaft freigegebene synthetische Datenbank bleibt bestehen, ohne
  zusätzliche Sicherung/Rücksicherung. Kulturhus bleibt EÜR.

Lokale, nicht veröffentlichte Nachweise:
`audit/ebilanz-mysql-host-20260918-9520abd53b287c0c.json` (erfolgreicher Endlauf),
`audit/ebilanz-mysql-host-20260918-91db6f8f02698fee.json` (breiter Erstlauf mit den
beiden anschließend behobenen Fehlern) sowie
`audit/ebilanz-business-native-20260928.json` (18 native XML-Prüfungen).
Die historische Datumskennung im
Dateinamen stammt vom bestehenden Prüfläufer; diese Läufe erfolgten am 28.09.2026.

## Fachliche Abgrenzung und verbleibender Release-Umfang

Die unterschiedlichen Übermittlungsvarianten für steuerbegünstigte Körperschaften
bleiben getrennt. Grundlage der Unterscheidung ist insbesondere Abschnitt 4m der
[amtlichen E-Bilanz-FAQ, Version 2026-01](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf).
Der Adapter entscheidet nicht automatisch über Bilanzierungspflicht oder die
steuerliche Zulässigkeit einer gewählten Abgrenzung.

Die datierte Aufteilung mehrerer steuerpflichtiger Betriebe und deren kombinierte
Ausgabe mit der Gesamtbilanz, übrige Anlagen-/Steuersonderfälle, vollständige
Kontenzuordnung sowie endgültiger Testversand mit Rückmeldungen und Gesamtabnahme
bleiben eigenständige Release-Voraussetzungen. Sie werden weder durch dieses
Profil noch durch Abschalten von Prüfungen ersetzt. Keine Gesamtprüfung des
Vereinsmanagers vor der vollständigen Veröffentlichung auslösen.
