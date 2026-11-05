# Kalender, Nachweise und Protokolle: vereinfachte Bedienung

Stand: 9. September 2026. Lokale Umsetzung und synthetische Abnahme; Veröffentlichung erfolgt mit dem gemeinsamen UI-Paket.

## Kalender und Fristen

Der Kalendereditor führt zuerst durch Terminart, Titel, Zeit, Ort und Tagesordnung. Die Teilnehmerauswahl folgt als zweiter Bereich. Durchführungsstand, Kategorie, Tätigkeitsbericht und Facebook-Quelle liegen unter den weiteren Angaben. Der gespeicherte Durchführungsstand bleibt in der geschlossenen Zusammenfassung sichtbar. Zuklappen entfernt keine Feldwerte. Ein Ende vor oder gleich dem Beginn wird direkt am Eingabefeld markiert; die unabhängige Serverprüfung bleibt bestehen.

Mitglieder-/Vorstandsversammlung, Standardtitel, geschützte Zielgruppen, Suche, Gesamtauswahl und getrennte Einladungsprüfung wurden erhalten. Im Test wurden ausschließlich lokale Vorschauen erstellt; kein E-Mail-/Push-Versand wurde beauftragt.

Nachweise haben zwei sichtbare Bereiche: „Nachweis und Datei“ sowie „Frist und Zuständigkeit“. Die optionale Personen-/Veranstaltungszuordnung, Notizen und Archivierung liegen darunter. Die vorhandene Datei, Person und Veranstaltung sind in kombinierten Suchauswahlen erreichbar. Die Übersicht filtert direkt nach Titel, Kategorie, Zuständigkeit oder Handlungsbedarf. Mobil werden die Tabellenzeilen zu lesbaren Karten. „Nachweis erfassen“ ist als sichtbare Hauptaktion erreichbar.

## Protokolle und Vorlagen

Der vorhandene Richtexteditor wurde ausgebaut, ohne eine zusätzliche Editor-Bibliothek oder eine neue Datenbankstruktur einzuführen. Die Vorlage bietet Mitgliederversammlung, Vorstandsversammlung und Besprechung mit passenden bearbeitbaren Tagesordnungspunkten. Ort, Sitzungsleitung, Protokollführung, Uhrzeiten und Anwesende werden als tatsächliche Eingaben übernommen. Jeder Tagesordnungspunkt kann Text, Beschluss, Stimmenzahlen und Ergebnis aufnehmen. Punkte sind einzeln aufklappbar, hinzufügbar und entfernbar. Unbekannte Zahlen bleiben leer; ein Beschlussergebnis wird nicht aus unvollständigen Angaben erfunden.

Die Mitgliederversammlungs-Vorlage enthält entsprechend §7 Nr.5 der vom Nutzer hinterlegten Kulturhus-Satzung leere Unterzeichnungszeilen für Vorsitz oder Stellvertretung und Schriftführung. Es wird keine Unterschrift aus der Ehrenamtsbestätigung übernommen.

Die Vorlagenfelder erzeugen Text im bestehenden `body_html`. Sie sind keine zweite Stammdatenablage: Beim Wiederaufnehmen wird der gespeicherte Text bearbeitet. Ein weiterer Vorlagenabschnitt ergänzt vorhandenen Text. Nach der Übernahme werden die temporären Felder geleert, damit sie nicht beim nächsten Speichern erneut eingefügt werden. Noch nicht übernommene eigene Vorlageneingaben werden beim Speichern aufgenommen. Ein freier Text oder vorhandenes PDF funktioniert weiterhin ohne Vorlage.

„Entwurf speichern und weiterschreiben“ speichert den echten Zwischenstand und öffnet den Editor mit dessen neuer Version. „Speichern und Vorschau öffnen“ speichert vor der lesbaren Entwurfsansicht. Beide Aktionen bleiben intern; Freigabe und ihre Rechte wurden nicht verändert. Paralleländerungen werden weiter abgewiesen. Die nicht gespeicherte Eingabe und die alte Versionsnummer bleiben nach dem Fehler erhalten; die Oberfläche behauptet dann keinen erfolgreichen Speicherstand.

Die größere Werkzeugleiste erhält eine markierte Textstelle auch bei Tastaturbedienung. Pfeiltasten wechseln zwischen den Werkzeugen. Fett/Kursiv zeigen ihren aktiven Zustand. Einfügen reduziert fremdes HTML bereits vor der sichtbaren Übernahme. Die unabhängige serverseitige Positivliste in `Minutes::sanitize()` sowie die Zustands-/Versions-/Vereinsgrenzen bleiben maßgeblich.

## Technische Grundlage

Die bestehende Verwendung von `execCommand` wurde bewusst begrenzt weitergenutzt, da sie die native Bearbeitungs- und Rückgängig-Historie erhält. MDN kennzeichnet die API als veraltet und empfiehlt konkrete Browserprüfungen; daraus folgt keine allgemeine Zusicherung für jedes Gerät. [MDN: execCommand](https://developer.mozilla.org/en-US/docs/Web/API/Document/execCommand).

Fokus, editierbare Textflächen und das Bereinigen eingefügter Inhalte wurden anhand der Browserdokumentation geprüft. [MDN: contenteditable](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Global_attributes/contenteditable).

Die beschriftete Werkzeugleiste verwendet einen gemeinsamen Tabulator-Einstieg und Pfeiltasten/Home/End zum Wechsel der Werkzeuge. [W3C WAI-ARIA: Toolbar Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/toolbar/).

## Nachweise

- `calendar-meeting-browser.cjs`: 34 Browserprüfungen, echte lokale Speicherung und Versandvorschau; keine Zustellung.
- `calendar-checks-browser.cjs`: 18 Browserprüfungen, tatsächlicher geschützter Textdatei-Upload, Speichern/Neuladen, optionale Zuordnungen, Suchzustände und Feldfehler.
- `minutes-editor-browser.cjs`: 30 Browserprüfungen, Vorlagenerzeugung, echte Entwurfsspeicherung und Wiederaufnahme, Tastaturformatierung, bereinigtes Einfügen, geschützte Vorschau sowie parallele Bearbeitung mit abgelehntem veraltetem Speicherstand. Zusätzlich tatsächlicher Browserdruck des gespeicherten Entwurfs als zweitseitige PDF mit beiden leeren Unterschriftszeilen, Textprüfung und vollständiger visueller Prüfung nach Poppler-Rendering. Keine Veröffentlichung oder Zustellung.
- `document-checks.php`: 28 Service-/Rechte-/Uploadprüfungen auf PHP 8.3 und 8.5.
- `minutes.php`: 79 Service-/Rechte-/Sanitizing-/PDF-/Versionierungsprüfungen auf PHP 8.3 und 8.5.
- `calendar-visible-range.php`: fünf Bereichs-/Suchprüfungen; sieben betroffene Views zusätzlich auf PHP 8.3 syntaxgeprüft.

Browsernachweis: lokaler synthetischer Vereinsmanager auf Port 8091, Edge/Chromium, Desktop und 390 Pixel Breite. Sämtliche Screenshots wurden visuell geprüft. Es wurden keine echten Mitglieder, Adressen, Bankdaten oder Unterschriften verwendet. Die reale iPhone-/Android-Geräteabnahme und die Verständlichkeitsprüfung durch unabhängige Erstnutzer werden damit nicht ersetzt.

Handbuchkapitel 12 und 13 wurden an die tatsächlichen Beschriftungen und Schritte angepasst. Zwei Nachweisbilder wurden aktualisiert, zwei neue Protokollbilder zeigen nummerierte, eng ausgeschnittene Bedienelemente. Die gemeinsame Handbuchdatei ist nach Zusammenführung aller UI-Änderungen neu zu bauen.
