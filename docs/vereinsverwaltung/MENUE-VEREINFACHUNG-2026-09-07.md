# Vereinfachte Navigation und Rechte

Neun Hauptgruppen mit 58 Katalogeinträgen wurden zu sieben Hauptgruppen mit gebündelten Einstiegen zusammengefasst. Je nach Konto sind weniger sichtbar. Bestehende Daten und URLs bleiben erhalten.

| Gemeinsamer Einstieg | Enthaltene Funktionen |
|---|---|
| Mitglieder & Kontakte → Mitglieder | Aktuelle, alle, ehemalige Mitglieder, gespeicherte Listen, geschütztes Archiv |
| Mitglieder & Kontakte → Kontakte | Separate Kontaktliste, Favoriten, Kontaktlisten |
| Mitglieder & Kontakte → Geburtstage & Jubiläen | Beide Anzeigen, weiterhin ohne automatische Mails |
| Finanzen → Beiträge | Beitragsabrechnung, Beitragsmails/Erinnerungen, Lastschriftstatus |
| Finanzen → Belege & Spenden | Belege, Geldspenden, Sachspenden, Bescheinigungen |
| Finanzen → Auswertungen & Steuer | Auswertungen, Export/DATEV, ELSTER-Unterlagen, Steuerkonten, Buchungsschlüssel, Steuerdaten/Freistellung |
| Organisation → Dokumente & Nachweise | Dateiablage mit Unterseite Fristen & Nachweise; Dateien, Zuordnungen, Archiv und Überwachung |
| Organisation → Inventar & Ausleihe | Gegenstände, Material, Lagerorte, Ausleihen, Vermögen; jeweils eigene Rechte |
| Website → Seiten & Programm | Übersicht, Seiten, Aktuelles, Fotogalerie, Veranstaltungen und Archiv |
| Website → Newsletter & Öffentlichkeit | Newsletter, Empfänger, Presse, Social Media; getrennte Redaktionsrechte |
| Einstellungen → Verein & Zugänge | Vereinsdaten, Rollen, Postfächer, Zusatzfelder, Briefanreden |
| Einstellungen → Beiträge & Nummern | Beitragssätze, Nummern, Leistungen, Bankverbindung |
| Einstellungen → Vorlagen & Briefpapier | Textbausteine und Briefpapier |

Bank & Kasse, Rechnungseingang, Mitgliedsanträge und GEMA bleiben direkt erreichbar. Fristen & Nachweise liegt zusammen mit der Dateiablage im Bereich Dokumente & Nachweise. Kalender, Protokolle, Chat, Rundmails/Briefe und Sicher helfen liegen im Vereinsportal. Dort teilen sich Mein Profil und Meine Belege einen Einstieg. Die Mitgliederversammlung wird über die ausdrückliche Vorstandsschaltfläche im Kalender angekündigt. Mein Account bleibt unten neben dem Handbuch.

## Bedienung

Die Suche führt weiterhin direkt zu einzelnen Funktionen wie ELSTER oder Briefpapier. Ihre Ziele werden serverseitig nach den aktuellen Rechten gefiltert. Das Löschen des Suchwortes stellt die normale Navigation wieder her. Alte Direktlinks markieren die passende übergeordnete Gruppe. Die Kopfzeile links benötigt weniger Platz; Unterseiten umbrechen auf kleinen Bildschirmen.

## Berechtigungen und Prüfung

Es wurden keine produktiven Rollen oder Daten geändert. `WorkspaceAccess` prüft Ansichten und Menüziele; Dienste prüfen Schreibaktionen weiterhin selbst. Die Portal-Standardroute entspricht nun dem bereits vorhandenen Weiterleitungsziel „Mein Profil“. Postfächer sind auch auf Routenebene ausschließlich für Superuser zugänglich. Eine technische Superuserrolle ersetzt kein aktuelles Kassenwartamt.


Lokale Browsermatrix: Mitglied, Redaktion, Finanzzugang und Superuser bei 1280/390 Pixeln; Suche, Menüöffnung und direkte Finanzsperre mit HTTP 403. Zusätzlich 16 Personen-/Inventaransichten bei 1440/390 und neun Finanzansichten. Zwei Menüabbildungen wurden anhand der synthetischen Umgebung neu aufgenommen und auf personenbezogene Inhalte geprüft. Keine Testmails, Buchungen oder Bankaktionen.

Diese Prüfung umfasst die Navigation und repräsentative Berechtigungsgrenzen; sie behauptet keine vollständige Abnahme jedes Formularzustands oder externer Registrierungen.

## Veröffentlichung und Livekontrolle

`65822fd` wurde am 7. September 2026 veröffentlicht: 31 Zieldateien gegen Git zurückgelesen. `7ec22dd` korrigiert die Positionen der nummerierten Hinweise in zwei Handbuchbildern; beide Bilddateien wurden ebenfalls veröffentlicht und byteweise geprüft. Vorhandene Dateien wurden vor dem Austausch gegen den letzten veröffentlichten Gitstand geprüft und privat gesichert. Keine Datenbankmigration oder Rollenänderung.

Im angemeldeten Live-Browser geprüft: sieben Hauptgruppen, neue Finanz-Einstiege, Körperschaftsteuer-Direktlink, ELSTER-Suche und Rückkehr zur normalen Navigation nach Löschen der Suche. Die Steuerkonten-Einstellungsseite zeigt die aktive Finanzgruppe und passende Unterseiten. Abschließender lesender Datenbankbefund: Schema 48, Integrität ok, null Fremdschlüsselfehler, vier aktive geprüfte Vorstandsämter unverändert, weiterhin keine Rechnungsfreigabe.

Handbuch mit 20 Kapiteln, 197 Aufgaben, 33 privaten Bildern und neun Videos neu gebaut und im lokalen Browser geprüft. Die beiden neuen Menüausschnitte wurden zusätzlich nach der Nummernkorrektur visuell kontrolliert. Ergebnisartefakte liegen privat unter `.local/audit-navigation-final-20260907/`, `.local/navigation-deployment-result.json` und `.local/navigation-figures-deployment-result.json`.

## Nachprüfung der Anleitungen und Videos

Die anschließende Durchsicht aller 20 Kapitel fand weitere alte Menüwege in neun Kapiteln, darunter Mitgliedsanträge, persönliche Belege, Inventar und Postfächer. Diese Wege und die betroffenen Schritttexte wurden berichtigt. Kapitel 7 erklärt nun den aktuellen Rechnungseingang auf einer Seite, geprüfte XML-Vorschläge, optionale Zweckzuordnung und direktes Hochladen; die alte Aussage eines stets leeren Betragsfelds wurde entfernt.

Alle neun Videos wurden jetzt mit den neuen Menüwegen und passenden Einstiegstexten neu erzeugt; das betrifft auch Vorschaubilder, Untertitel und Zeitmarken. Acht relevante bestehende Handbuchausschnitte wurden erneut visuell geprüft, vier neu erzeugte Videoszenen zusätzlich kontrolliert. Es wurden ausschließlich vorhandene geprüfte Beispielbilder verwendet. Alle neun Dateien wurden vollständig mit Bild und nicht stummer Tonspur dekodiert; zusammen rund 14 Minuten 43 Sekunden.

Der lokale Browserlauf bestand erneut mit 20 Kapiteln, 197 Aufgaben, 33 Bildern und neun abspielbaren Videos, Suchfunktion, Sprungmarken, Untertiteln, Bereichsdownloads, Desktop-/Mobilansicht und Mitgliederrolle. Zusätzlich bestanden 59 Hilfe-Routenzuordnungen und 13 Medienbereichsprüfungen. Diese Nachprüfung ändert Dokumentation und Medien, keine Berechtigungen oder Datenbankinhalte.

Veröffentlicht: `39be9df` mit 27 byteweise bestätigten Handbuch-/Mediendateien, danach `1916354` mit der korrigierten HTML-Fassung. Letztere präzisiert auch Superuser-Rechte für Postfächer, den Hilfe-Suchknopf und den zweiten Rechnungseingangshinweis in Kapitel 19. Der Browserlauf bestand nach dieser Textkorrektur erneut. Live wurden neue Menüwege, Videoübersicht und Finanzexportvideo samt Vorschaubild/Zeitmarken sowie die letzten Textkorrekturen sichtbar kontrolliert. Deploymentnachweise: `.local/handbook-followup-deployment-result.json` und `.local/handbook-final-text-deployment-result.json`. Keine Datenbankmigration, kein Testversand.
