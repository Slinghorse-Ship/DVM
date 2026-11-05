# Gegenstandsverwaltung und Gesamtprüfung vom 8. September 2026

Die Gegenstandsverwaltung wird nach Aufgaben gegliedert. Der aktuelle Bestand bleibt sichtbar; große Aktionen führen zu Menge und Lagerort, Stammdaten und Rechnungen. Weitere Angaben, einzelne Seriennummern und Preise, Fotos, Verlauf sowie Archivierung öffnen sich bei Bedarf. Das Einklappen entfernt keine Werte aus dem Formular und ersetzt keine Berechtigungsprüfung.

## Korrigierte Bedien- und Zustandsfehler

- Bei Ausgabe, Storno, Frist- und Ortsänderung einer Ausleihe bleiben zurückgewiesene Eingaben im passenden Formular erhalten. Eine Fehlermeldung bedeutet weiterhin, dass der Vorgang nicht gespeichert wurde.
- Eine zurückgewiesene Stückkorrektur behält die ursprünglich übermittelte Bestandsversion. Der Fehler darf nicht stillschweigend mit einer neueren Version überschreibbar werden.
- Die Kachel „Offene Ausleihen“ zählt reservierte und ausgegebene Vorgänge und öffnet genau diese Auswahl.
- Der Kategoriefilter erklärt das Filtern; die Neuanlagehilfe erscheint nur bei der Erfassung. Mindestbestand null wird nicht unter jedem Gegenstand wiederholt.
- Der zentrale Formularvalidator entfernt überholte Fehlermarkierungen, wenn ein Feld nicht mehr erforderlich, deaktiviert oder aus einer dynamischen Zeile entfernt ist. Bestehende Hilfetexte bleiben über `aria-describedby` erhalten.
- Suchbare Auswahllisten erklären bei mehr als 100 Treffern, wie man die Suche eingrenzt. Die Auswahl bleibt beim Verlassen eines nicht passenden Suchtexts erhalten.
- Zuvor zu kleine Bestätigungsflächen bei Vorstandsrechten und eigenen Websitefreigaben sowie Bedienelemente der Steuerkontenauswahl erhalten mindestens 44 Pixel Höhe.
- Die Einführung bezeichnet vorhandene Funktionen nicht mehr als „Umsetzung noch offen“.
- Die Website-Schicht lädt ihre Laufzeitabhängigkeit explizit und weist unbekannte Presseoperationen vor der Übergabe an die Schnittstelle zurück. Die bestehenden nativen Berechtigungsprüfungen bleiben bestehen.

## Prüfung des gesamten Managers


Die erneuerten Migrationstests erzeugen die tatsächlichen Vorgängerschemata aus ihren SQL-Dateien. Sie prüfen die Erweiterung bis zum aktuellen Schema und den unveränderten Erhalt alter Originalmails, Bankdaten und Zugriffsbelege. Die steuerliche Prüfung lockert die Sperren alter Rechnungsjahre nicht, sondern unterscheidet neue Eingaben und bereits vorhandene historische Zuordnungen.

| Bereich | Nachweis in diesem Prüflauf |
| --- | --- |
| Start und Navigation | Gefundene freigegebene Menü-/Funktionssuchziele und benannte Unterreiter als echte GETs; Berechtigungs- und Suchzieltests; ausgeschlossene Formular-/Datensatz-/Blätterziele protokolliert |
| Mitglieder, Kontakte, Familie, Eintritt, Archiv | 17 echte Browserabläufe mit POST, erneutem GET und Datenbankvergleich; PHP-Fachtests |
| Rollen, Websitefreigaben, eigene Mitgliedssicht | 14 echte Rollenabläufe; Rechteentzug, Versionskonflikt und Betrieb ohne JavaScript; PHP-Rechtegrenzen |
| Gegenstände, Einheiten, Stückangaben, Rechnungen | 15 echte Browserprüfungen einschließlich Feld-Roundtrips, Konflikten, Bestandsänderung, Stückangaben, Neuanlage und Mobilbedienung; ergänzende PHP-Tests |
| Ausleihe und Lager | 15 echte Browserabläufe: mehrteilige Reservierung, Storno, Ausgabe, Frist und Aufenthaltsort, Teilrückgabe mit beschädigtem Satz, vollständige Rückgabe, Fehlerentwürfe und Filter |
| Rechnungseingang, Positionen, Lieferanten, Zahlungen | PHP-Tests für Formzustand, Zuordnung, strukturierte Rechnungsvorschläge, Originalmails und Berechtigungen; Ansichten als GET |
| Beiträge, Spenden, Bankdaten, SEPA | PHP-Fachtests einschließlich Verschlüsselung, Aktionsbindung und Rechteentzug; keine echten Bankaktionen |
| Auswertungen, Jahresunterlagen und Finanzexport | PHP-Tests für Jahresgrenzen, Teilzahlungen, historische Kontierung, Export und Nacharbeit; Ansichten als GET |
| Dokumente, Uploads, Fristen und Nachweise | PHP-Tests für Dateitypen, Größen, Versionen, Zuordnungen, Rechte und Formularzustand; Ansichten als GET |
| Kalender, Protokolle, Chat, Rundmails, Anträge | PHP-Tests für Abläufe und Zugriffsgrenzen; erlaubte Ansichten als GET; kein echter Versand |
| GEMA, Material, freie Listen, Einstellungen | PHP-Fach-/Formtests und erlaubte Ansichten als GET |
| Website und externe Dienste | Portable Dienst-/Berechtigungstests. Die lokale Browserprüfung weist fehlende native Verbindungen ausdrücklich aus. |

`tests/manager-routes-browser.cjs` prüfte 220 GET-Aufrufe mit Verwaltungs- und Mitgliedssicht bei 1280 und 390 Pixeln: je 98 Verwaltungs- und zwölf Mitgliedsziele pro Bildschirmbreite. 200 Aufrufe bestanden vollständig lokal. 18 nicht eingerichtete Integrationsansichten und zwei native Websiteansichten ohne bestätigte native Integration werden gesondert ausgewiesen. Ehemalige Mitglieder, gespeicherte Listen sowie benannte Archiv-, Mailstatus-, Dokument- und Finanzbericht-Unteransichten sind eingeschlossen. Der Bericht hält ausgeschlossene Datensatzordner, neue Formulare und Blätterlinks mit Grund fest. Kein unerwarteter Laufzeitfehler, JavaScriptfehler oder Seitenüberlauf blieb in dieser Prüfung übrig. Die währenddessen gelesenen synthetischen Geschäftsdaten blieben unverändert.

Die Browser-Fixtures erzeugen ausschließlich eigene synthetische Vereine und Fantasiedaten. Sie prüfen die Kennung des tatsächlich laufenden Git-Arbeitsbaums. Der Antragsdienst verwendet dafür im lokalen Betrieb den authentifizierten synthetischen Verein; produktiv bleibt der konfigurierte Kulturhus-Verein maßgeblich. Die Menüprüfung lässt nur GETs am lokalen Ursprung zu. Schreibtests verwenden getrennte Fixtures und prüfen anschließend die gespeicherten Datensätze.

## Wiederholung

- `tests/member-profile-browser.cjs` und `tests/role-editor-http-browser.cjs` mit einer jeweils neuen Fixture aus `tests/support/member-profile-fixture.php`.
- `tests/manager-routes-browser.cjs` mit derselben Art frischer Fixture, ohne Mutation.
- `tests/inventory-fields-browser.cjs` mit `tests/support/inventory-fields-fixture.php`.
- `tests/inventory-flows-browser.cjs` mit seiner getrennten synthetischen Ausleih-Fixture.
- `tests/form-validation-dynamic-browser.cjs` und `tests/select-search-state-browser.cjs` für gemeinsame Browserkomponenten.

Private Rohberichte und Screenshots liegen unter `.local/manager-ui-audit-20260908`, `.local/member-profile-fixtures`, `.local/inventory-fields-fixtures` und `.local/inventory-flow-fixtures`. Sie gehören nicht in das öffentliche Webverzeichnis.

## Veröffentlichung und Livekontrolle


Die anschließende Kontrolle erfolgte mit der bestehenden Live-Anmeldung und ausschließlich durch Lesen und Öffnen von Ansichten:

- Die Gegenstandsliste enthält weiterhin 13 Gegenstände; wiederholtes „Mindestbestand 0“ und die unpassende Neuanlagehilfe im Filter entfallen.
- Alle drei Hauptaktionen beim Bühnenpodest öffnen den richtigen Bereich. Die Aktionen sind 72 Pixel hoch; die geprüften Eingabefelder und Auswahlen 48 bis 50 Pixel.
- Angaben und Einheit, Bestandsänderung und vorhandene Rechnungen sind aufrufbar. Es wurden keine Mengen, Preise, Zuordnungen oder Rollen verändert.
- Die sechs Hauptbereiche beginnen eingeklappt; die jeweilige Aktion öffnet ihren Zielbereich. Die geprüften Liveansichten zeigen keine Fehlermeldung und keinen horizontalen Seitenüberlauf.
- Kapitel 15 enthält die neuen Schritte. Alle vier aktualisierten Bildanleitungen wurden durch die reguläre Handbuchnavigation geöffnet und erfolgreich geladen.

Private Veröffentlichungsevidenz: `.local/manager-inventory-ui-deploy-result.json`. Rückfallkopie: `/tmp/kulturhus-verein/rollback-manager-inventory-ui-901a8605b4f6`. Die oben genannten Grenzen der nativen Integrationsprüfung bleiben bestehen.
