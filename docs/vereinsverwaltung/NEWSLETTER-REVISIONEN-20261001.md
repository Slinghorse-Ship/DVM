# Newsletter: Wiederherstellung und Versionsschutz (01.10.2026)

## Befund und Wiederherstellung

- Der gespeicherte Entwurf Nr. 4 wurde am 30.09.2026 um 19:51:58 durch eine erfolgreich protokollierte Löschanfrage entfernt. Um 19:53:51 legte die Automatik für denselben Zeitraum den neuen Standardentwurf Nr. 5 an. Der Worker überschreibt einen vorhandenen Entwurf nicht; die frühere endgültige Löschung entfernte jedoch auch die Belegung des Automatik-Zeitraums.
- Die Zuordnung der Löschanfrage zu einem angemeldeten Redaktionszugang wurde anhand des gespeicherten Anfrage-Hashes verifiziert. Das belegt nicht, ob eine Person bewusst geklickt oder ein Skript beziehungsweise eine unbeabsichtigte Bedienhandlung die Anfrage ausgelöst hat.
- Die Sicherungen vom 29. und 30.09.2026 enthalten die gespeicherte, bearbeitete Fassung vom 28.09.2026. Aus der Sicherung vom 30.09. wurde genau diese Fassung am 01.10.2026 um 10:07:42 in Entwurf Nr. 5 wiederhergestellt. Die vorherige Standardfassung wurde ebenfalls als Revision erhalten.
- SHA-256 des unverändert wiederhergestellten HTML-Inhalts: `6ff80114fadfb213166068938f9f52c897a9fd77eade1926fec4582177c0c73e`.
- Das verschlüsselte Backup wurde ausschließlich auf dem Webserver selektiv im Speicher gelesen. Kein vollständiger Datenbankimport, kein Austausch von Empfängern, keine lokale Kopie produktiver Datenbankbestände und kein Speichern von Zugangsdaten in diesem Nachweis.
- Zuerst blieben globale Automatik und Einzelentwurf pausiert. Nach Anzeige der wiederhergestellten Vorschau wurden beide auf ausdrücklichen Nutzerwunsch über die Oberfläche wieder aktiviert. Die Oberfläche bestätigte „Automatischer Versand aktiv“. Kein manueller Worker-Aufruf und kein Testmailversand. Die Aktivierung allein ist kein Nachweis einer Zustellung beim Empfänger.

## Veröffentlichter Schutz

- Unveränderliche Fassungen vor/nach Speicherung, Löschung und Wiederherstellung, mit Zeitpunkt und bearbeitendem Zugang.
- Versionsvergleich innerhalb der gesperrten Datenbanktransaktion; alte Browserformulare dürfen eine neuere Fassung nicht überschreiben. Ein eigener Revisionszähler schützt auch bei identischem Inhalt und gleicher Zeitangabe.
- Wiederherstellbare Löschung statt physischer Entfernung. Der Automatik-Zeitraum bleibt belegt, sodass der Worker keinen Ersatzentwurf anlegt.
- Explizite Löschbestätigung im Vereinsmanager. Wiederherstellen ist auf Entwürfe/gelöschte Entwürfe begrenzt und pausiert die Automatik für diesen Entwurf.
- Globaler Newsletter-Stopp in Datenbank, Oberfläche, Planung und Versand berücksichtigt. `NEWSLETTER_AUTOMATION_ENABLED=0` wird nicht mehr als fehlende Einstellung gewertet.
- Rechteprüfung für Bearbeitung, Wiederherstellung und Automatik-Steuerung; frühere HTML-Fassungen werden im Verlauf als maskierter Text dargestellt.

## Abnahme und Umfang

- 25 Revisionstests erfolgreich auf separater MySQL-Testdatenbank: Speicherung, Konflikte, fehlende Versionsangabe, Wiederherstellung, Wiederherstellung fremder Fassungen gesperrt, gelöschter Zeitraum, pausierte Automatik, eingefrorene Versandfassung und Rechteprüfungen. Nur synthetische, zufällig benannte Tabellen; bestehende Testdaten unverändert, eigene Testtabellen anschließend entfernt.
- 37 Newsletter-Anrede-/Inhaltstests und 4 Zielgruppen-Formulartests erfolgreich, ohne echten Versand.
- PHP-Syntaxprüfung sämtlicher geänderter PHP-Dateien lokal und der veröffentlichten Dateien auf dem Webserver erfolgreich.
- Live: wiederhergestellter Inhalt, drei Veranstaltungsbilder, vollständige Mail-Vorschau und drei neue Revisionen geprüft; Wiederaktivierung über die Oberfläche bestätigt. Keine echte Empfängerzustellung mit dieser Sichtprüfung behauptet.
- Beim anschließenden lesenden Seitenaufruf meldete die reguläre Automatik „Wird versendet · 40 Empfänger · 20 versendet · 0 fehlgeschlagen“. Der angezeigte Kampagnentext enthält weiterhin die wiederhergestellten Änderungen. Dies bestätigt den begonnenen Versand, nicht den Eingang aller Mails in den Postfächern.
- Sieben Newsletter-Dateien gezielt veröffentlicht; ursprüngliche Codedateien außerhalb des Webroots gesichert. Laufzeitpfad, Vereinsdatenbank und andere Module unverändert. Keine Bilanz- oder Jahreskatalog-Entwürfe mitveröffentlicht.
- Noch vor Einführung des Versionsverlaufs verlorene Zwischenstände sind nur insoweit belegbar, wie Sicherungen sie enthalten. Der neue Verlauf ist keine rückwirkend vollständige Historie.

## Rücknahme

Die vorigen Codedateien liegen im privaten Webspace-Verzeichnis `newsletter-revisions-c04e694dec48`. Zusätzliche Revisionstabellen und die neue Spalte nicht löschen: sie enthalten die Wiederherstellungsnachweise. Vor einer Coderücknahme die Newsletter-Automatik pausieren; die alte Version berücksichtigt die neue datenbankgestützte Pause nicht. Eine Rücknahme erfordert deshalb zusätzlich eine geprüfte serverseitige Versandsperre.

## Vertiefte Ursachenprüfung

- Nur lesende Folgeprüfung auf Produktion: Datenbanktransaktion explizit read-only, keim früheren CMS-Start, keine Worker-Ausführung und kein weiterer Versandauftrag.
- Der historische Anfrage-Hash wurde erneut aus Sicherungsinhalt, Versionsstand, Vorgangskennung und damaligem Bridge-Format rekonstruiert. Genau ein vorhandener Redaktionszugang stimmte überein. Personen- und Verbindungsdaten werden nicht in dieser Quellcodedokumentation veröffentlicht.
- Das zugehörige Domain-Zugriffsprotokoll `access_ssl_log.processed.1` belegt die zusammenhängende Folge: Login-Rückkehr 19:50:50, Newsletter-Liste 19:50:54, Entwurf 4 um 19:51:05, Formular-POST 19:51:17 (Automatik-Einstellung), Formular-POST 19:51:57 (Löschanfrage), danach Umleitung zur Liste 19:51:58. Zeitangaben vom 30.09.2026 mit Zeitzone +0200. Beide Formularaufrufe antworteten mit HTTP 303.
- Alle diese Aufrufe tragen denselben Client und den User-Agent Edge 154 unter Windows. Die IP-Adresse wurde nicht lokal gespeichert. Ein User-Agent beweist kein menschliches Handeln und kann von Software verwendet werden.
- Pfad der Aktion: Vereinsmanager-Newsletterformular → `website-native` / `newsletter/delete` → redaktionelle Bridge → `EditorialIntegration` → damaliges `NewsletterCampaignService::deleteDraft`. Diese frühere Funktion entfernte Entwurf und Automatik-Zeitraum endgültig. Genau die erfolgreiche Löschantwort ist in der Vorgangstabelle gespeichert.
- Im geprüften produktiven Anwendungscode liegt kein zeitgesteuerter Aufruf dieser Löschfunktion. `newsletter_cleanup` bearbeitet abgelaufene Empfängeranmeldungen, nicht Kampagnen. Der automatische Planer legt nur bei fehlendem Zeitraum einen neuen Entwurf an. Die Browser-Bausteinlöschung entfernt HTML-Bausteine im Editor, nicht die Kampagne.
- Die alte Vorgangstabelle enthält weder Mausklick-/Tastaturereignisse noch ein Feld für das auslösende Browserskript. Ob bewusster Klick, unbeabsichtigte Bedienung, Browsererweiterung oder andere Browserautomatisierung, ist daher rückwirkend nicht beweisbar. Keine dieser Möglichkeiten wird als festgestellte Ursache ausgegeben.
- Bereits veröffentlicht: ausdrückliche Bestätigung vor Löschung, wiederherstellbare Fassungen mit Zugang und Zeit, Versionsvergleich sowie dauerhaft belegter Automatik-Zeitraum. Der historische Verlustfall kann so nicht mehr durch bloßes Neuanlegen nach einer regulären Entwurflöschung wiederholt werden.
- Versandabschluss lesend verifiziert: Kampagne 5 `sent`, 40 Empfänger, 40 versendet, 0 fehlgeschlagen; Inhalts-Hash unverändert gegenüber der Wiederherstellung. Dies ist ein System-Versandnachweis, keine Bestätigung des Postfacheingangs.
- Sieben relevante Dateien (Worker, Kampagnenservice, Redaktionsservice, Bridge sowie drei Browser-Skripte) wurden lesend per LF-normalisiertem SHA-256 mit dem untersuchten Quellstand abgeglichen; alle stimmten überein.
- 11 zusätzliche isolierte Ansichtsprüfungen für Zeit/Zugang, escaped HTML, exakte Wiederherstellungsversion, wiederherstellbare Löschung und gesperrte Wiederherstellung nach Versand erfolgreich; außerdem 4 Zielgruppenprüfungen erneut erfolgreich. Der lokale Bridge-Integrationstest wurde auf die jetzt erforderliche Löschbestätigung und erhaltene Revision umgestellt und seine Python-Syntax geprüft; dieser umfassende Test wurde in der Folgeprüfung nicht erneut ausgeführt.

## Automatische Texte: Verein und Veranstaltungsort

Kulturhus bezeichnet den veranstaltenden Verein, nicht einen Veranstaltungsort. Monats- und Saisontexte wurden entsprechend korrigiert; der Herbsttext übernimmt die wiedergefundene Formulierung „bei uns“ / „mit euch“. Ein Veranstaltungsort wird ausschließlich aus `venue_name` und `venue_address` des jeweiligen Termins übernommen, niemals aus dem Vereinsnamen ergänzt. Auch der Bild-Alternativtext der Bestätigungsmail bezeichnet nun das tatsächliche Motiv „Saxophon“.

76 reine Formatter-Prüfungen für alle Monate, Monats-/Quartalsvorlagen, unterschiedliche beziehungsweise fehlende Veranstaltungsorte und unveränderte redaktionelle Texte lokal und auf dem Live-Webserver erfolgreich; zusätzlich 37 bestehende Anrede-/Mailformat-Prüfungen lokal erfolgreich. Nur zwei Quellcodedateien gezielt veröffentlicht, Sicherung in `newsletter-venue-3d5e9128bf` außerhalb des Webroots. Keine Datenbankschreibzugriffe, kein Mailversand und kein Überschreiben bestehender Newsletter.

## Eine automatische Ausgabe vorab aussetzen

- Befund: Die bisherige Pause erforderte einen vorhandenen Entwurf. Für die nächste Ausgabe fehlte die Schaltfläche daher bis zum Vorbereitungstermin.
- Unter **Automatischer Newsletter** stehen jetzt Zeitraum, Versandtermin und **Diese Ausgabe aussetzen**, auch ohne Entwurf. Die Pause gilt ausschließlich für diesen Monats-/Quartalszeitraum; spätere Ausgaben bleiben unverändert. **Diese Ausgabe wieder aktivieren** hebt nur diese Pause auf. **Alle automatischen Ausgaben stoppen** bleibt davon getrennt.
- Eine eigene Zeitraumtabelle speichert Pause, Zugang, Zeitpunkt und Revisionszähler, ohne einen künstlichen Entwurf anzulegen. Versionsvergleich und gesperrte Transaktion verhindern veraltete oder fremde Formularänderungen. Vorhandene Texte und Zielgruppen bleiben unverändert.
- Planung, Versandvorbereitung und die atomare Übernahme einer einzelnen ausstehenden Mail berücksichtigen globale und einzelne Pausen. Eine bereits laufende Übertragung kann noch abschließen; schon versendete Mails werden nicht zurückgeholt. Manuelle Newsletter bleiben unabhängig.
- 35 Prüfungen auf separater MySQL-Testdatenbank erfolgreich: fehlender Entwurf, Pause/Fortsetzen, zukünftige Ausgaben, Versionskonflikte, unveränderte Inhalte, gesperrte Versandvorbereitung, wartende und laufende Ausgabe, globale Pause, Rechte und bereits abgeschlossene Ausgabe. Ausschließlich eigene synthetische Tabellen, anschließend entfernt; kein echter Transport.
- 16 isolierte Ansichtsprüfungen einschließlich des tatsächlichen Bridge-Vertrags erfolgreich. Außerdem 25 Revisionstests, 11 Revisionsansichtsprüfungen, 76 Vereins-/Veranstaltungsortprüfungen, 37 Anredeprüfungen, 4 Zielgruppenprüfungen und 27 Bridge-Prüfungen erfolgreich. PHP-Syntax lokal und für veröffentlichte Dateien auf dem Webserver geprüft.
- Vier Laufzeitdateien gezielt veröffentlicht: `NewsletterDrafts.php`, `NewsletterCampaignService.php`, `EditorialIntegration.php` und `website-newsletter-status.php`. Vorige Dateien gesichert unter `/kulturhus.de/private/newsletter-period-a10300153c34`. Laufzeitpfad unverändert; zusätzliche Tabelle angelegt, vorhandene Kampagneninhalte und Versandzustände vor/nach der Schema-Erweiterung verglichen und unverändert.
- Live-Oberfläche lesend geprüft: November 2026, Versand 01.11.2026 um 10:00 Uhr, Vorbereitung 25.10.2026 um 10:00 Uhr und neue Schaltfläche sichtbar. Keine produktive Pause/Wiederaktivierung ausgelöst, kein Testversand und kein Worker manuell gestartet. Ein vollständiger schreibender Live-Oberflächentest wurde bewusst nicht durchgeführt.
- Bei Rücknahme muss die Automatik unabhängig gesperrt werden: frühere Codefassungen kennen die neue Zeitraumtabelle nicht und könnten eine dort ausgesetzte Ausgabe sonst planen. Zeitraumdaten nicht löschen.

## Bestätigungserinnerungen optional anzeigen

Der Bereich **Anmeldung nochmal bestätigen** ist auf Newsletter- und Empfängerseite standardmäßig eingeklappt. **Optionen: Anmeldung nochmal bestätigen** öffnet beziehungsweise schließt ihn mit der vorhandenen, auch ohne JavaScript und per Tastatur bedienbaren Aufklappfunktion. Zahlen und Versandformular bleiben innerhalb dieses Bereichs. Die zusätzliche Versandbestätigung, Rollenprüfung und lokale Versandsperre bleiben unverändert; das Öffnen löst keine Mail aus. Vier ergänzende Ansichtsprüfungen sichern diese Eigenschaften ab.

Alle 50 Erinnerungsprüfungen mit synthetischem Speicher und abgefangenem Mailtransport erfolgreich. Nur `website-newsletter-reminders.php` veröffentlicht, vorherige Fassung privat unter `newsletter-reminders-view-756b47b8f002` gesichert. PHP-Syntax lokal und serverseitig geprüft. Live auf der Newsletter-Seite den geschlossenen Anfangszustand, Aufklappen und erneutes Zuklappen geprüft; keine Versandaktion und keine Änderung an Empfängern oder Datenbank.
