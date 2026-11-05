# Sendungen, Briefpapier, Vorschauen und Spendenformulare

Stand: 8. September 2026. Fortsetzung nach der Veröffentlichung der Chat-, Textbaustein- und Inventarkorrekturen in `1699337`.

## Empfänger oben auswählen

Sendungen beginnen mit der Empfängerauswahl. Aktive und passive Mitglieder lassen sich als Gruppe übernehmen; Kontakte ohne Mitgliedschaft bilden eine eigene Gruppe. Ehemalige bleiben einzeln auswählbar. Eine kombinierte Suche ergänzt einzelne Personen. Die sichtbare Auswahl lässt sich vor dem Speichern korrigieren. Gruppen ersetzen die bisherige Auswahl erst nach ausdrücklichem Klick; die bestehende Vorschau und Versandbestätigung bleiben erhalten.

Die vorhandene Zusammenfassung gemeinsamer E-Mail-Adressen sowie Versand-, Wiederholungs- und Berechtigungsprüfungen bleiben bestehen. Tests speichern ausschließlich synthetische Entwürfe und lösen keinen Sammelversand aus.

## Eine Vereinsanschrift und Bankverbindung

Neue Briefe und Dokumentvorschauen können ihre Absenderanschrift bei jeder Erstellung aus den Vereinsdaten beziehen. Eine abweichende Anschrift bleibt optional. Bei bestehenden bewusst ausgefüllten Briefpapierfeldern wird der bisherige Absender zunächst erhalten; ein Wechsel auf Vereinsdaten löscht diese eigenen Angaben nicht. Bereits gespeicherte Dokumente behalten ihren damaligen Inhalt.

Die Bankverbindung für Briefe und Rechnungen ist aus Vereinsdaten und Briefpapier direkt erreichbar. Kontoinhaber und IBAN sind zentrale Platzhalter der Fußzeile. Dieser Dokumentenabsender aktiviert keinen Onlinebankingzugang und erzeugt kein Finanzkonto. Finanzrechte begrenzen den Zugang zur Bankeinstellung.

## Unterschiedliche Beitrags- und Rechnungsvorschauen

Beiträge erhalten den Titel „Beitragsrechnung“, eine passende Musterposition und eine eigene Beitragserinnerung. Allgemeine Rechnungen verwenden eine Vereinsleistung als Beispiel. Lastschriftvorankündigungen zeigen auch den Nachrichtentext mit Muster-Mandat und Gläubigerangaben. Änderungen am noch ungespeicherten Text erscheinen in HTML und PDF, ohne eine echte Rechnung anzulegen. Alle Beträge in diesen Einstellungen sind ausdrücklich Vorschauwerte.

## Getrennter fiktiver Versandtest

Auf ausdrücklichen Auftrag wurde eine einzelne Testmail mit zwei mehrfach als FIKTIV gekennzeichneten PDFs über eine erfundene Geldspende von 2.000 EUR vorbereitet, visuell geprüft und vom SMTP-Server angenommen. Sie ging ausschließlich an die vom Auftraggeber bestimmte einzelne Adresse. Es gab keine echte Spende, Zahlung, amtliche Bescheinigungsnummer oder Serienversandfreigabe. Private Testdateien, Empfänger- und Transportnachweise bleiben außerhalb des veröffentlichten Codes unter `.local/fiktive-spende-20260908/`.

Der bestehende Ablauf für endgültige Zuwendungsbescheinigungen verlangt weiterhin den Ausdruck zur eigenhändigen Unterschrift. Die beim Vorstand gespeicherte Bildunterschrift wird dort derzeit nicht eingefügt; Entwürfe sind ebenfalls nicht unterschrieben.

## Vorhandene Zuwendungen verknüpfen

Beim direkten Öffnen der amtlichen Formulare fehlte eine sichtbare Auswahl vorhandener Quellen; deren Kennungen wurden nur über Links aus anderen Seiten übernommen. Eine Suche am Anfang des Formulars bietet jetzt die zulässigen Geldspenden mit tatsächlich zugeordneten Zahlungseingängen beziehungsweise vorhandene Sachspenden mit ihren verknüpften Gegenständen an. Erst „Übernehmen“ öffnet die Quelle und befüllt das Formular. Tippen aktualisiert nur die Trefferliste.

Der Betrag einer Teilzahlung wird aus der Zahlungszuordnung übernommen. Stornierte und rückgängig gemachte Zahlungen bleiben ausgeschlossen. Bereits bescheinigte Quellen werden angezeigt und führen zur bestehenden Bescheinigung; die bestehende Prüfung gegen doppelte Ausstellung bleibt zusätzlich auf dem Server wirksam. Beiträge erscheinen nur bei aktivierter Abziehbarkeit und zugeordnetem Beitragszahler; Kulturhus behält seine bisherige Einstellung. Gekaufte Inventargegenstände werden durch eine Suche nicht zu Sachspenden. Ohne Quelle bleibt ein ausdrücklich freier Entwurf möglich.

## Chat nach dem Senden offen halten

Eine nachträglich gemeldete Regression betraf den Aufruf der Neuanlage über `#chat-create`: Der Anker blieb nach dem Schließen stehen und konnte bei Formularweiterleitungen übernommen werden. Beim nächsten Laden öffnete die Clientlogik deshalb erneut die Neuanlage, obwohl die Unterhaltung richtig ausgewählt war. Der Korrekturtest muss diesen vollständigen Weg über den Neuanlage-Link, Abbrechen, Senden und erneutes Laden mit zwei synthetischen Konten abdecken. Ein einfacher direkter Seitenaufruf ohne diesen Anker bildet den Fehler nicht nach.

## Prüfung und Veröffentlichung

- 21 betroffene PHP-Testsuiten und 32 Syntaxprüfungen am zusammengeführten Stand erfolgreich. Ergebnisdateien: `.local/manager-mailings-letterhead-checks/final-results.json` und `syntax-results.json`.
- Empfänger: 8 gezielte Backend- und 12 Browserprüfungen einschließlich wirklichem Speichern/Öffnen eines synthetischen Entwurfs, Bedienung ohne JavaScript und 390px.
- Briefabsender: 21 Backend- und 21 Browserprüfungen einschließlich drei echten HTTP-PDFs, Formularversionen, eigener Anschrift und Rückwechsel auf Vereinsdaten. Vorhandene Einstellungs-, Signatur- und Briefdokumentprüfungen erfolgreich.
- Textbausteine: 58 Browser-/HTTP-PDF-Prüfungen über acht Gruppen-/Vorlagenkombinationen und 8 zusätzliche Variablenprüfungen. Vorschauen speichern keine echten Einstellungen.
- Zuwendungsverknüpfung: 24 zusätzliche Backend- und 25 Browserprüfungen, darunter Teilzahlungen, alte Direktlinks, umlautunabhängige Suche, mehr als 100 ungeeignete Beiträge vor passenden Treffern, Finanzleserechte ohne Inventarrecht und ein tatsächlich gespeicherter synthetischer PDF-Entwurf. Der Datenbankabgleich bestätigt dabei unveränderte Finanz- und Inventarwerte.
- Chat: 9 gezielte Navigationsprüfungen mit zwei synthetischen Konten, dazu 15 bestehende Browser- und 9 Serviceprüfungen. Vor dem Fix scheiterten sieben Prüfpunkte des neuen Ablaufs. Der neue Test prüft den Zustand direkt nach dem Redirect und verdeckt ihn nicht durch einen weiteren Seitenaufruf.
- Handbuch aus den gepflegten Kapiteln neu gebaut: 20 Kapitel, 253 Aufgaben, 36 bebilderte Anleitungen; Medien- und Routenprüfung erfolgreich.

Es wird keine vollständige Prüfung aller Bank-, ELSTER- oder früheren CMS-Integrationen behauptet.

### Veröffentlichung und Liveprüfung

`393322f4aaff77ce1c0e3cb4eeeb290697fa09d6` ist veröffentlicht. 30 private beziehungsweise öffentliche Laufzeitdateien stimmen nach dem Upload bytegenau mit dem Git-Commit überein. PHP-Syntax wurde vor dem Austausch auf dem Server geprüft; Rücksicherungen liegen privat unter `/tmp/kulturhus-verein/rollback-manager-mailings-letterhead-393322f4aaff`. Der Codeupload führte keine Datenbankmigration aus.

Live geprüft: Empfängerfeld vor Betreff, drei Empfängerarten und zunächst leere Auswahl; Beitragsrechnung und Lastschrifttext; Geld- und Sachspendensuche einschließlich asynchronem Suchaufruf. Aktuell bietet der Live-Bestand keine geeigneten Spendenquellen an; die tatsächliche Zahlungsübernahme und Entwurfsablage wurden deshalb ausschließlich mit synthetischen Belegen geprüft. In einer vorhandenen Live-Unterhaltung funktionieren Neuanlage öffnen, Abbrechen und erneuter Aufruf ohne verbliebenen Anker; die Unterhaltung und das Nachrichtenfeld bleiben offen. Es wurde keine Live-Chatnachricht versandt.

Die zuvor leeren zentralen Kontoinhaber-/IBAN-Felder wurden anhand des auf der eigenen Vereinsseite veröffentlichten Spendenkontos ergänzt und nach dem Speichern erneut gelesen. Briefpapier wurde ausdrücklich auf `sender_source=club` gestellt; der Formularvergleich bestätigt, dass alle anderen Briefpapierfelder erhalten blieben. Die vollständige Vereinsanschrift und die Vereins-IBAN erscheinen in der Vorschau.

### Nachtrag: Textbausteinlisten und vollständiger Bescheid

Die zunächst weiterhin allgemeinen Namen in den Textbausteinlisten werden durch gemeinsame gruppenspezifische Labels ersetzt. Beitragsrechnung, Beitragserinnerung und Beitrags-Lastschrift erscheinen in Auswahl und Kartenüberschriften; allgemeine Rechnungen und Spenden behalten ihre eigenen Namen. 75 Browserprüfungen decken alle drei Gruppen und acht tatsächliche PDF-Vorschauen ab. Gespeicherte Vorlagenschlüssel und sämtliche vorhandenen Textwerte bleiben erhalten.

Auf ausdrücklichen Auftrag wurde die nachgereichte gedruckte Seite 2 mit der vorhandenen Originalseite 1 zusammengefügt. Die vollständige PDF wurde visuell geprüft und über `Documents::newVersion` als Revision 2 desselben vorhandenen Steuerbescheids gespeichert. Nach dem erneuten Herunterladen sind zwei Seiten und der erwartete Dateihash bestätigt. Dokumentmetadaten, sämtliche Verknüpfungen, die Steuereinstellungen sowie die Bytes der alten Revision 1 sind unverändert. Private Nachweise liegen unter `.local/freistellung-vollstaendig/`. Beide Bescheidseiten bestätigen die bestehende Einordnung: Spendenbestätigungen zulässig, Mitgliedsbeitragsbestätigungen ausdrücklich ausgeschlossen.
