# Lokaler Bilanzbetrieb und Windows-XML-Prüfung

Der aktuelle Stand mit vollständigem Rechenbestand, erfolgreicher Reparatur,
geprüfter Menüberechnung und laufender privater Anwendung steht im
[Betriebsnachweis vom 05.10.2026](BILANZBETRIEB-2026-10-05.md).
Die folgenden älteren Laufstände bleiben als Verlauf erhalten und sind keine
zusätzliche aktuelle Freigabe.

Der Windows-Adapter übergibt große Aufträge jetzt über eine authentifizierte
Verbindung auf `127.0.0.1`. Die Eingabe bleibt im Speicher; eine eigene
Prozessgruppe beendet bei Abbruch auch die Kindprozesse. Der Linux-Pfad bleibt
unverändert. Beide neuen Laufzeitdateien nehmen an der Abgabe-Prüfsumme teil.

`audit/windows-eric-process-20261003T210028Z.json` dokumentiert 14 tatsächliche
Prozessprüfungen einschließlich einer Eingabe über 16 KiB, Unicode, getrenntem
Standard-/Fehlerausgang, Größenlimits und Zeitabbruch mit Prozessbereinigung.
Zusätzlich besteht die tatsächliche native Prüfung aus PHP. Der private lokale
Prüfer weist Versand, Zertifikatszugriff, Druckvorschau und beide Rückmeldeaktionen
zurück. Der frühere blockierte Prozess ist als fehlgeschlagener Start erhalten.

`audit/windows-eric-generation-20261004.json` enthält drei weitere Abläufe:
PHP erzeugt Jahres-, Misch- und Zeitraum-XMLs und prüft jede erzeugte Datei mit
dem nativen ERiC. Alle drei Dateien sind größer als 500 KiB; alle Prüfungen
enden mit Code 0, ohne Datenbankzugriff oder Übermittlung.

Der MySQL-Lauf `f361ad0da8d602ad` verlor seine SSH-Verbindung nach 66 bestandenen
Programmen. Sein Checkpoint bleibt unvollständig und wird nicht als Gesamterfolg
bezeichnet. `audit/ebilanz-mysql-recovered-f361ad0da8d602ad.json` bestätigt getrennt,
dass zweimal weder Testbesitzer noch weitere Testverbindungen vorhanden waren,
die geschützten Produktivdateien unverändert sind und die temporäre Kopie entfernt
ist. Es gab keinen parallelen Datenbanklauf.

Der Nachlauf `3c49ba24888824f0` prüft 114 Programme auf dem aktuellen Quellstand.
Zusammen mit 63 unverändert abgedeckten Programmen umfasst er den vollständigen
vorherigen Umfang. Die Laufzeitänderung wird durch erneute XML-Erzeugung und
Prüfsummenprüfungen abgedeckt. Der Serverprozess läuft unabhängig von seiner
SSH-Beobachtung; Zugangsdaten werden dabei weiterhin nur im Speicher übergeben.
Der erfolgreiche Gesamt- und Oberflächennachweis ist noch ausstehend.

Die lokale Oberfläche wird erst nach erfolgreichem Nachlauf und nativer Prüfung
aller fünf gespeicherten XML-Dateien gestartet. Sie verwendet ausschließlich
die permanent synthetische MySQL-Datenbank und erfundene Testrollen. Die
Produktivkonfiguration des echten Kulturhus bleibt EÜR.

Taxonomiequellen 6.7, 6.8, 6.9 und 6.10 sind vorhanden. Quellenvorhandensein,
Jahreszuordnung, native Adapterabnahme und allgemeine fachliche Bilanzfreigabe
sind getrennte Nachweise. Dieser lokale Lauf erweitert die bestehende begrenzte
Bilanzabdeckung nicht automatisch auf weitere Bewertungen oder das amtliche
2027-Jahrespaket. Maßgeblich bleiben die offenen Punkte der
[Bilanzen-Lückenmatrix](BILANZ-LUECKENMATRIX-2026-10-03.md).


Die amtliche Seite [Steuer Elektronisch](https://www.esteuer.de/) kündigt die
6.10-Testunterstützung für das ERiC-Release im November 2026 und die Unterstützung
für Echtfälle für Mai 2027 an (am 04.10.2026 erneut geprüft). Eine vorgezogene
native 6.10-Abnahme mit dem vorhandenen Prüfer wird deshalb nicht behauptet.
Die DATEV-Seite zum [SKR42 Bilanz](https://www.datev.de/web/de/shop/produkt-details/12901)
weist derzeit den Stand Januar 2026 aus. Die Jahrespflege übernimmt eine spätere
2027-PDF erst nach Prüfung ihres tatsächlichen Jahres und Inhalts; eine
2026-Ausgabe wird nicht als amtliche 2027-Quelle umbenannt.
