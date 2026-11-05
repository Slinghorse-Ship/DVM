# Bedienprüfung Vereinsmanager – 7. September 2026

Alle im Hauptmenü erreichbaren Bereiche wurden anhand ihrer tatsächlichen Oberfläche und des Codes geprüft. Ziel: Funktion finden, Eingaben verstehen, Ergebnis prüfen, Fehler korrigieren und zur passenden Übersicht zurückkommen. Diese technische Prüfung ersetzt keine Bedienprobe mit unerfahrenen Vereinsmitgliedern.

## Umfang

98 Kombinationen aus Rolle und Menüansicht: 57 Vereinsverwaltung, 23 Kassenwart, 9 Mitglied und 9 Redaktion. Jeweils HTTP-Antwort, sichtbare Überschrift, JavaScript-Fehler und mobile Seitenbreite bei 390 px geprüft. Alle HTTP-Antworten erfolgreich; keine erkannten JavaScript-Fehler und keine überbreite Gesamtseite. In der lokalen Bankansicht ist die fehlende Bankkonfiguration erwartbar.

Zusätzlich echte lokale Klickpfade und Formularvorschauen mit synthetischen Daten. Kein tatsächlicher Versand, Bankauftrag oder externe Veröffentlichung. Eine lokale Einladungsvorschau verwendete einen temporären Termin, der anschließend entfernt wurde.

## Konkrete Verbesserungen

| Bereich | Ergebnis |
|---|---|
| Navigation | Suche versteht Passwort, Bilder, Familie und mehrere Suchwörter. Mobile Navigation hat Schließen-Knopf, Hintergrund-Schließen, Fokusführung und Escape. Dunkle Beschriftung auf orangefarbenen Hauptschaltflächen erhöht den rechnerischen Kontrast von 2,62:1 auf 5,96:1. |
| Kontakte/Mitglieder | Rückwege unterscheiden Kontakte, aktuelle und ehemalige Mitglieder. Leser bekommen keine nicht ausführbaren Schreibaktionen angeboten. Leere Suchtreffer sind zurücksetzbar. |
| Kalender/Einladungen | Tag/Woche/Monatsraster und Terminliste zeigen denselben Zeitraum. Suche erklärt ihren Umfang. Empfängergruppe zuerst auswählen, Einzelpersonen durchsuchbar markieren, vollständige Vorschau vor Versand. |
| GEMA | Termin öffnet direkt die Detailbearbeitung, Rückweg zur GEMA-Übersicht; Tabelle mobil scrollbar. Website-Termindetails verlinken kommende GEMA-Vorgänge direkt, Archivtermine bekommen diesen Einstieg nicht. |
| Finanzen/Spenden | Leere Filterergebnisse sind von fehlenden Umsätzen unterscheidbar; Reset und Rückwege ergänzt. Bescheinigungsformulare behalten Beleg- und Sachspendenkontext. Leere DATEV-Vorschau nennt den nächsten Schritt. |
| Inventar/Fristen | Gegenstand → Bestand → Ausleihe wird erklärt. Unmögliche Ausleihe bei fehlendem Bestand verhindert; Abbrechen-Links und direkter Einstieg in Nachweisformulare. |
| Sendungen/Chat | Empfänger und Anhänge durchsuchen; nur angezeigte Treffer gesammelt auswählen, verborgene Auswahl zählen. Betreff, Nachricht und Auswahl bleiben nach Fehlern erhalten. Chat-Erstellung erklärt den Einstieg und behält fehlerhafte Entwürfe. |
| Freie Listen | Archiv unterscheidbar und Rückweg zu aktiven Listen; Suchreset, Abbrechen beim Bearbeiten, bekannte Kontonamen statt technischer Kennungen. Freigaben werden nur für Zugänge angeboten, die den Listenbereich auch öffnen können. |
| Website | Such-/Archivfilter zurücksetzen; Detail-Rückweg behält Suche, Seite, Jahr und Sortierung. Speichern-Hinweise unterscheiden lokale und produktive Website. Newsletter macht gespeicherte Versandfassung deutlich. |
| Vereinsportal | Chat und eigene Belege direkt von der Mitgliederstartseite erreichbar. |
| Einstellungen/Steuer | Bestehende Masken und Hinweise geprüft; Fachfreigaben und tatsächlich fehlende Funktionen bleiben ausdrücklich erkennbar. |

## Regressionen geprüft

- Konfliktschutz bleibt beim Wiederanzeigen fehlgeschlagener Formulare erhalten. Veraltete Zellen oder Mailtexte erhalten keinen aktuellen Versionsschlüssel. Neun neue In-Memory-Viewchecks prüfen das Verhalten ohne Versand.
- Zwischenzeitlich breiter gewählte Einladungsempfänger bleiben beim Zurückwechseln auf eine alte Kombination nicht aktiv. Isolierter Browsertest prüft die Checkboxdaten.
- Einzelpersonen-Vorschau enthält ausschließlich die ausgewählte Personengruppe; der lokale Versandknopf bleibt gesperrt.
- Kalenderbereich/Umlautsuche: fünf neue Checks. Mitglieder/Archiv: 20; Veranstaltungs/GEMA: 32; Inventar: 83 plus 38 HTTP-Checks.
- Finanzen: 48 Viewchecks; DATEV: 14; Spendenformulare: 75; Bescheinigungen: 29; Spenden-Views: 5.
- Website: 51 Domänenchecks und 15 lesende HTTP-Prüfungen.
- Sendungen und Chat: vorhandene Domänenchecks erfolgreich; freie Listen: 34 Checks. Neue Browserprüfungen für Auswahl, Fehlererhalt und mobile Tastaturführung erfolgreich.
- Geänderte PHP-/JavaScript-Dateien syntaktisch geprüft. Unabhängige Codeprüfung der gemeinsamen Bedienhilfen und Konfliktfälle abgeschlossen; gemeldete Fehler korrigiert und nachgeprüft.

## Ehrliche Grenzen

Eigene Stammdatenänderungen durch einfache Mitglieder sind noch kein selbstständiger Ablauf; die Oberfläche nennt dafür die Mitgliederverwaltung. Zusatzfelder in Einstellungen speichern bisher Definitionen, ohne sie in allen Mitglieder-/Belegmasken zu erfassen. XLSX-Spalten und neue Familien müssen weiterhin bewusst zugeordnet werden. FinTS/produktive Bankfreigabe und ELSTER-Übermittlung bleiben externe Voraussetzungen; Lastschriftausführung ist noch nicht implementiert. GEMA-Anmeldung und Setliste werden außerhalb im GEMA-Portal eingereicht.

Authentifizierte Bedienabläufe wurden lokal getestet. Eine umfassende produktive Abnahme mit realen Konten und unerfahrenen Vereinsmitgliedern wurde hier nicht durchgeführt. Änderungen an der Oberfläche machen diese fachlichen und betrieblichen Grenzen nicht automatisch erledigt.

## Veröffentlichung

Code-Commit `0b72627` wurde am 7. September 2026 ausgehend von `b490b16` veröffentlicht. 38 Dateiziele wurden vorher gegen den erwarteten Live-Altstand geprüft, privat gesichert und nach Veröffentlichung byteweise kontrolliert. Remote PHP-Syntaxprüfungen erfolgreich. Rücknahmedateien: `/tmp/kulturhus-verein/rollback-usability-20260907`.

Nach Veröffentlichung: 32 HTTP-Prüfungen für öffentliche Seiten, geschützte Gastzugriffe und private Dateipfade erfolgreich. Die fünf geänderten JavaScript-/CSS-Dateien werden bytegenau wie lokal geprüft ausgeliefert. Die authentifizierten Bedienprüfungen bleiben lokale Prüfungen.

## Geprüfte Menüpunkte

| Bereich | Ansicht |
|---|---|
| Start | Dashboard |
| Kontakte | Geschütztes Archiv |
| Kontakte | Kontakte |
| Kontakte | Geburtstage |
| Kontakte | Listen |
| Kontakte | Favoriten |
| Mitglieder | Mitgliedsanträge |
| Mitglieder | Aktuelle Mitglieder |
| Mitglieder | Alle Mitglieder |
| Mitglieder | Ehemalige Mitglieder |
| Mitglieder | Jubiläumsliste |
| Mitglieder | Listen |
| Finanzen | Beitragsmails |
| Finanzen | Vereinsvermögen |
| Finanzen | Bank & Kasse |
| Finanzen | Beitragsabrechnung |
| Finanzen | Belege |
| Finanzen | Lastschriften |
| Finanzen | Finanzexport |
| Finanzen | Körperschaftsteuer & ELSTER |
| Finanzen | Auswertungen |
| Spenden | Geldspenden |
| Spenden | Sachspenden |
| Orga | Mitgliederversammlung |
| Orga | Material |
| Orga | GEMA |
| Orga | Inventar & Ausleihe |
| Orga | Lagerorte |
| Orga | Ausleihen |
| Orga | Fristen & Nachweise |
| Orga | Dokumente |
| Orga | Sendungen |
| Orga | Freie Listen |
| Vereinsportal | Kalender |
| Vereinsportal | Protokolle |
| Vereinsportal | Chat |
| Vereinsportal | Sicher helfen |
| Vereinsportal | Meine Belege |
| Vereinsportal | Mein Profil |
| Website | Übersicht |
| Website | Seiten |
| Website | Aktuelles |
| Website | Fotogalerie |
| Website | Veranstaltungen |
| Website | Newsletter |
| Website | Empfänger & Kontakte |
| Website | Social Media |
| Website | Presse |
| Website | Technik |
| Einstellungen | Vereinsdaten |
| Einstellungen | Account |
| Einstellungen | Rollen & Rechte |
| Einstellungen | Nummernkreis |
| Einstellungen | Beiträge & Spenden |
| Einstellungen | Leistungen |
| Einstellungen | Textbausteine |
| Einstellungen | Briefpapier |
