> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Vereinsmanager: einfache Abläufe, Umfragen und Protokolle

Stand: 9. September 2026. Veröffentlicht und live geprüft: Git `d7ba1a0`, Datenbankschema 72. Basis ist Git `348cdf5`, dieselbe aktuelle Arbeitskopie wurde im isolierten Browser geprüft.

## Was sich für die Bedienung ändert

- Rechnungseingang: suchbare Übersicht mit Jahreswahl und Seiten, getrennte Listen für Rechnungsdateien und E-Mails ohne Beleg. Ein manueller Finanzbeleg erhält seine Datei direkt im Formular. Die drei Schritte sind Angaben, Positionen und Original; optionale Steuerangaben bleiben erreichbar.
- Kalender und Nachweise: wichtige Grundangaben zuerst; Quellen, weiterführende Zuordnungen und Notizen unter aufklappbaren Abschnitten. Vorhandene Werte bleiben auch geschlossen gespeichert. Nachweise erhalten Suchfilter und lesbare Mobilkarten.
- Verein, Bank und Steuer: kurze Abschnitte innerhalb desselben Speicherformulars. E-Mail-Einstellungen zeigen zuerst die eigentlichen Postfächer und ihre Versandzwecke, danach das Rechnungsfach und zuletzt die bisherigen Newsletter-Basisangaben. Veraltete Aussagen über einen noch nicht angeschlossenen Rechnungsversand wurden berichtigt.
- Rundmails und Briefe: Übersicht, Bearbeitung und Vorschau sind getrennte Ansichten. Ein gespeicherter Entwurf öffnet seine Vorschau; „Entwurf weiterbearbeiten“ führt zurück zum Formular. Die Versandprüfung bleibt bestehen. Lange Übersichten sind durchsuchbar und in Seiten aufgeteilt.
- Weitere Listen: Suche und Seiten für Lagerorte, freie Listen, Aufnahmeanträge, eigene Belege und Vorgänge ohne Datei. Website-Seiten und Social-Media-Beiträge durchsuchen ausdrücklich die bereits angezeigten Einträge; die Begrenzung des vorhandenen Website-Abgleichs wird dadurch nicht aufgehoben.
- Mitglieder und Kontakte: Excel-Ausgabe der tatsächlich ausgewählten Personen und sichtbaren Spalten zusätzlich zu CSV. Eigene Kontaktdaten können als Änderungsantrag eingereicht werden. Berechtigte Vorstände prüfen vorher/nachher, übernehmen oder lehnen ab. Login-Adresse, Bankdaten, Mitgliedschaft und Rollen werden damit nicht verändert.
- Chat: einfache Umfragen direkt in der Unterhaltung, mit zwei bis zehn Antworten, optionalem Endtermin, einer änderbaren Stimme pro Konto und gemeinsamem Ergebnis. Andere Einzelstimmen werden nicht angezeigt. Das sind organisatorische Umfragen; sie ersetzen keine geheime Wahl oder satzungsgemäße Beschlussfassung.
- Protokolle: Vorlagen für Mitgliederversammlung, Vorstand und Besprechung; Orts-/Sitzungsangaben und Tagesordnungspunkte mit Beschluss, Stimmenzahlen und Ergebnis. Die größere Werkzeugleiste unterstützt Tastatur und Textauswahl. „Entwurf speichern und weiterschreiben“ speichert tatsächlich. Vorhandene Texte werden ergänzt, nicht still ersetzt. Die Mitgliederversammlungs-Vorlage bietet die beiden Unterschriftszeilen aus §7 Nr.5 der hinterlegten Kulturhus-Satzung.

## Code und Speicherverträge

`simple-lists.js/css` arbeitet nur auf ausdrücklich markierten, bereits berechtigt gerenderten Listen. Es lädt keine zusätzlichen Personen oder privaten Datensätze. Suche, Trefferzahl, Zurück/Weiter und leere Ergebnisse verändern keine Fachwerte. `simple-settings.js/css` öffnet die passenden Abschnitte bei Ankern; das bestehende Formularfehler-System öffnet fehlerhafte Pflichtfelder. Zuklappen deaktiviert keine gespeicherten Eingabefelder.

`InvoiceIntakeList` und `InvoiceMailOnly::page()` filtern die Finanzlisten auf dem Server. `ManualFinanceAttachment` prüft Datei, erlaubten Typ und Rechte und verbindet den Originalbeleg innerhalb der bestehenden Finanztransaktion. `Documents::uploadWithinTransaction()` ist ausschließlich für diesen bereits begonnenen gemeinsamen Speichervorgang gedacht. Eine fehlgeschlagene Speicherung bestätigt weder einen Beleg noch eine Zahlung; die Live-Upload-Auswahl bleibt zur Korrektur erhalten.

`ContactSpreadsheet` schreibt Textzellen, um führende Nullen und als Text gemeinte Zeichenfolgen zu erhalten. Es erzeugt keine Formeln oder Makros. Der vorhandene `Registry::export()` bleibt die Autoritätsgrenze für Personen, Spalten, Archiv und Verein.

Migration 071 ergänzt die drei Umfragetabellen; Migration 072 die Änderungsanträge. Sie enthalten ausschließlich neue Tabellen und Indizes. `ChatPolls` prüft die aktuelle Chatteilnahme, Versionsnummern und Wiederholkennungen transaktional. `MemberChangeRequests` prüft das eigene aktive/passive Mitglied und bei Annahme zusätzlich Vorstand/Superuser und Mitglieder-Schreibrecht. Vorher-/Nachher-Angaben und Prüfanmerkung liegen verschlüsselt; der Antrag übergeht weder die aktuelle Profilversion noch einen bereits offenen Antrag. Für den geänderten Kontakt werden Dokumentzuordnung und Änderungsverlauf mitgeführt. Die beiden Controller laufen im bestehenden authentifizierten, CSRF-geprüften POST-Pfad.

Der Protokollassistent erzeugt bereinigten Text im bestehenden `body_html`; beim Wiederaufnehmen wird dieser Text weiterbearbeitet. Er legt keine widersprüchliche zweite Protokollablage an. Die bestehende serverseitige HTML-Positivliste, Versionierung und Freigabeprüfung bleiben maßgeblich. Die native Rückgängig-Funktion wird vorerst über die bestehende Editor-API erhalten; deren Browsergrenzen sind im [Protokoll-Prüfbericht](KALENDER-NACHWEISE-PROTOKOLLE-UI-2026-09-09.md) dokumentiert.

## Nachgewiesene Prüfungen

Die lokale Oberfläche lief ausschließlich mit synthetischen Vereinsdaten auf Port 8091. Externe Requests beziehungsweise Versandaktionen wurden in den Browserprüfungen gesperrt. Kein Testversand an Mitglieder oder echte Vorstände.

- Gesamtrouten: Admin mit 102 Ansichten und Mitglied mit 13 Ansichten, jeweils Desktop und 390 Pixel. 210 erfolgreiche Prüfungen, keine fehlgeschlagene Ansicht; zwei native Websiteansichten und 18 Integrationsansichten sind in dieser isolierten Umgebung ausdrücklich nicht vollständig abgenommen. Der Geschäftsbestand blieb unverändert.
- Allgemeine UI: 57 Browser-/Speicherprüfungen zu vollständig erhaltenen Einstellungsfeldern, Speicherung trotz Zuklappen, Konflikten, Postfachzuständen, Suche/Seiten, getrennten Rundmailansichten und Mobilansichten. Kein angelegter Versandauftrag.
- Finanz-UI: 27 Serviceprüfungen auf PHP 8.3 und 8.5 sowie 24 Browserprüfungen, darunter Dateibeibehaltung nach Fehler, erneutes Speichern und sofort aktualisierte Steuerzusammenfassung. XLSX: 25 Serviceprüfungen je PHP-Version, tatsächlicher XLSX-/CSV-Download und unabhängige Prüfung mit openpyxl.
- Kalender/Nachweise/Protokolle: 82 Browserprüfungen, 79 Protokoll- und 28 Nachweis-Serviceprüfungen auf beiden PHP-Versionen. Der wirkliche Druck des gespeicherten Protokollentwurfs wurde als zweiseitige PDF gerendert und vollständig visuell geprüft, einschließlich beider Unterschriftszeilen.
- Chat und Selbstpflege: 49 beziehungsweise 43 Serviceprüfungen auf beiden PHP-Versionen, 24 Browserprüfungen und ein tatsächlicher paralleler Abstimmungsversuch. Eine unabhängige Gegenprüfung fand eine Chat-Abrufrace. Sie wurde behoben und mit verzögerter HTTP-Antwort nachgestellt: Eine währenddessen gewählte Antwort bleibt erhalten; zufällige Wiederholkennungen lösen keine unnötigen DOM-Ersetzungen aus.
- Integration: final 82 geänderte/neue PHP-/JS-Dateien syntaxgeprüft, 62 PHP-Dateien zusätzlich auf PHP 8.3. Bestehende Prüfungen für Datenbanktransaktionen, Uploads/Dokumente, Rundmails/Empfänger, Postfachkanäle, Einstellungen und Navigation bestanden.
- Zusätzliche Browserregressionen: 20 Empfänger-/Rundmailprüfungen, acht Vorsteuerprüfungen und Rechnungssammelupload mit individuellen Titeln/Daten sowie Rückkehr zur gefilterten Übersicht bestanden. Wiederholtes Bearbeiten erhält denselben Entwurf. Die Datenbankkontrolle bestätigte keine Versandwarteschlange, Finanzbuchung oder Zahlung aus diesen Tests.

Einzelnachweise: [Auditmatrix und Chat/Selbstpflege](UI-AUDIT-2026-09-08.md), [Finanz-UI und Excel](FINANZ-UI-EXCEL-2026-09-09.md), [Kalender, Nachweise und Protokolle](KALENDER-NACHWEISE-PROTOKOLLE-UI-2026-09-09.md).

## Handbuch und Grenzen

Das gemeinsame Handbuch wurde aus 20 Kapiteln neu gebaut: 282 Schrittanleitungen und 43 bebilderte Anleitungen. Aktuelle, nummerierte Ausschnitte zeigen Nachweise, Protokolleditor/-vorlage, Vereins-/Postfacheinstellungen, Umfragen und eigene Datenänderungen/Vorstandsprüfung. Alle neuen Bilder zeigen synthetische Daten. Die neun vorhandenen Videos wurden nicht als neue Videos ausgegeben; ihre vollständige Überarbeitung bleibt separat offen.

Der gebaute Stand wurde anschließend im Browser als Admin und als Mitglied geprüft: alle Bilddateien erreichbar, Suche und Anker intakt, neun vorhandene Videos abspielbar und vorspulbar, Untertitel/Byte-Range-Grenzen geprüft, Desktop/Mobil ohne Seitenüberbreite. Dies bestätigt die technische Nutzbarkeit der bestehenden Videos, nicht ihre vollständige inhaltliche Aktualisierung.

Die technische Browserprüfung ist keine Verständlichkeitsabnahme durch
unabhängige Erstnutzer und keine Geräteprüfung auf allen realen iPhones und
Android-Geräten. Externe Bank-/Steuer-/Anbieterergebnisse und tatsächliche
Vereinsdaten benötigen eigene Nachweise.


## Veröffentlichung und tatsächliche Webprüfung

Git `d7ba1a0886f691907f97d0f631ea75e0690ad249` wurde als unveränderliches Manifest mit 81 Laufzeitquellen auf 95 konkrete Dateiziele veröffentlicht. Alle bisherigen Ziele wurden gegen Git `348cdf5` abgeglichen, privat gesichert und vor Austausch geprüft; alle neuen Dateiinhalte wurden anschließend bytegenau zurückgelesen. Tests und lokale Daten gingen nicht auf den Webspace.

Die Migration 70 → 72 bestand zuerst auf einer privaten Datenbankkopie und danach auf der laufenden Datenbank. Inhalt und Zeilenzahl aller 141 vorherigen Fachtabellen blieben innerhalb der jeweiligen Transaktion unverändert. Hinzu kamen vier leere Tabellen und drei Indizes sowie zwei Migrationseinträge. Integrität und Fremdschlüssel bestanden; die Datenbank wurde nicht ersetzt. Eine zwischenzeitliche Kompatibilitätsdatei verhinderte, dass alte laufende Requests allein durch die neue Schemanummer abgewiesen werden.


Die zuvor angemeldete Live-Browsersitzung blieb erhalten. Lesende Abnahme der veröffentlichten Ansichten: Protokolleditor und geÃ¶ffnete Vorlagen, Vereinsdaten, Fristen & Nachweise, Kalenderformular, MitgliederÃ¤nderungen prÃ¼fen, E-Mail-Einstellungen, Rundmails & Briefe, Rechnungseingang, manuelle Ausgabe mit direktem Belegupload, Chat, Handbuch, Mein Account. Beim Protokoll wurden die Vorlage und Werkzeugleiste geöffnet; es wurde kein echter Entwurf angelegt. Bestehende Mitglieder und Vorstände erhielten keine Testmail, Umfrage oder Push-Mitteilung. Für die Live-UI-Prüfung wurde ausschließlich der besuchsabhängige Workerstart für höchstens230 Sekunden vorübergehend unterdrückt; die Sperre ist wieder freigegeben. Dies war keine Schedulerprüfung.

Zusätzliche Querschnittprüfung: Die allgemeine Sicherung kopiert die komplette Dateidatenbank und den Anwendungsschlüssel; somit sind neue Umfrage-/Änderungstabellen und verschlüsselte Antragstexte eingeschlossen. Archivierung/Kontosperrung erhält Fremdschlüsselziele und entzieht Rechte. Der ältere vollständige Recoverytest enthielt noch keine eigens erzeugten071/072-Datensätze; dafür wird keine neue vollständige Wiederherstellungsabnahme behauptet.
