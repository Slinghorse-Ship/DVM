# Öffentliches Programm, Archiv und Veranstaltungsoriginale

`/veranstaltungen/` und `/site/veranstaltungen` verwenden denselben nativen
Katalog. Voraussetzung ist eine ausdrücklich veröffentlichte Programmseite.
`archiv`, `jahr`, `q`, `sort` und `seite` filtern in MySQL; pro Seite werden
höchstens 20 veröffentlichte Originale geladen. Suche berücksichtigt Titel,
Kurztext, Beschreibung, Ort und Anschrift. Prozentzeichen, Unterstriche und
Regexzeichen sind normale Suchzeichen. Programm und Archiv trennen sich am
Beginn des aktuellen Tages in Europe/Berlin. Jahrfilter verwenden dieselbe
Zeitzone; Ereignisse selbst behalten ihre eingegebene IANA-Zeitzone.

Die Detailansicht zeigt Status, Datum, Beginn/Ende, Ort/Anschrift, freien oder
bekannten Eintritt, Anfahrt, öffentliche Bilder, Ticket-/Referenzlink und
Event-JSON-LD mit den aktuellen separaten Vereinsangaben. Absagen und
Verschiebungen sind sichtbar. Vergangene, abgesagte, verschobene oder
ausverkaufte Termine bieten keinen aktiven Ticketkauf an. Der eigene Editor
pflegt diese Angaben; beim Redigieren bleiben unveränderte UTC-Anker einer
Zeitumstellung erhalten. Ein fehlendes Ende wird nicht erfunden.

CMS-Version **3** ergänzt `khcms_events`. Die Tabelle referenziert vorhandene
veröffentlichte Revisionen; sie enthält keine zweite Inhalts- oder Entwurfskopie.
Veröffentlichen, Zurückziehen und Archivieren aktualisieren den Index in derselben
Vereinstransaktion. Die ausdrückliche Schemawartung baut bestehende veröffentlichte
Referenzen in 200er-Schritten wieder auf. Die öffentliche Navigation lädt ebenfalls
nur ihre benötigten Metadaten. Neue Termine werden gezielt im Programm angezeigt;
ein einzelner Menüeintrag erfordert eine ausdrückliche Auswahl.

Der Eventbrite-Abgleich legt weiterhin Entwürfe an und überschreibt keine lokale
Bearbeitung. Eine externe Absage zieht die normale Veröffentlichung zurück.
War der Termin tatsächlich veröffentlicht, bleibt eine Absageseite mit der
vorherigen freigegebenen Revision und deren Bildern erhalten. Private Änderungen
und unveröffentlichte Anbietertexte erscheinen nicht darin. Alte Inhaltslinks
führen direkt zur Absageseite. „Offline nehmen“ oder Archivieren entfernt auch
den Hinweis und seine öffentlichen Bildreferenzen. Eine spätere Wiederaufnahme
beim Anbieter entfernt den alten Hinweis, veröffentlicht den überarbeiteten
Entwurf jedoch nicht selbstständig.

Kalender und Terminexport verwenden denselben Status und dieselben UTC-Zeiten.
Der Managerkalender kennzeichnet das Original als abgesagt; bestehende
Gutscheinregeln sperren abgesagte Termine. Die Auswahl für kommende Newsletter
lässt sie aus. `/feeds/termine/?event=<native-ID>` und
`/feeds/events.ics?event=<native-ID>` exportieren genau das veröffentlichte
Original einschließlich Ende, Status, unveränderter UID und Änderungssequenz.
Bei Absagen bleibt die UID erhalten und die Sequenz steigt. Der versionierte
JSON-Feed ergänzt Ende, Anschrift, Status, Eintritt und Ticketlink; eine Absage
liefert keinen aktiven Ticketlink. Unveröffentlichte/fremde IDs sind nicht abrufbar.

Die Fachlogik liegt in `native/Cms/EventDetails`, `EventIndex` und `Events`;
`PublishedEvents` ist die Kalender-/Newsletterprojektion. `native/Web/Events`
enthält die öffentliche Bedienung, `Events/Eventbrite` den Anbieterabgleich.

CMS-Version **4** ergänzt `EventPublications`. Eine erstmalige freigegebene
künftige Veranstaltung bereitet die ursprünglichen Manager-Pushhinweise für
aktuell berechtigte Mitglieder mit bestehendem Opt-in und Gerät vor. Speichern
eines privaten Entwurfs und Eventbrite-Import erzeugen keinen Hinweis. Redaktion,
Wiederveröffentlichung und spätere Opt-ins wiederholen die erste Ankündigung nicht.
Veröffentlichte Terminänderungen aktualisieren deren Gültigkeit. Rückzug, Absage,
Archivierung, vergangener Termin oder Wechsel des Inhaltstyps stoppen ausstehende
Hinweise. Historische sowie Meta-Archivquellen bleiben bei späteren Korrekturen still.

Die ausdrückliche Schemawartung bildet in 200er-Schritten die Grundlage aus den
unveränderten originalen Veröffentlichungsrevisionen. Sie verschickt nichts und
setzt vorhandene erste Entscheidungen nicht zurück. Die eigene Veröffentlichung,
der Katalog und die Vorbereitung des Hinweises liegen in derselben Transaktion;
eine fehlgeschlagene Queue-Vorbereitung veröffentlicht nicht teilweise. Auch eine
geplante erste Veröffentlichung verwendet diesen Weg. Der Dispatcher prüft das
aktuelle eigene veröffentlichte Original sowie Rechte, Opt-in und Gerät erneut
vor dem Transport. Empfänger, Veranstaltungstext und Titel stehen nicht im Push.
Die Metadatenabfrage lädt keine privaten Entwürfe oder Artikelkörper.

44 native Prüfungen belegen diese Regeln einschließlich tatsächlicher Änderungen
zwischen Claim und Transport, atomarem Rollback und geplanter Veröffentlichung.
Fünf ursprüngliche Benachrichtigungs-, Chat-/Push- und Protokollsuiten bestehen;
die Abnahme verwendet ausschließlich lokale Transportadapter.

Nachweis: 60 Prüfungen in `native-event-catalogue`, 89 Prüfungen der öffentlichen
Routen, bestehende CMS-/Menü-/Feed-/Termin-/Newsletter- und ursprüngliche
Hintergrundjobprüfungen. Der echte Browser prüft Programm, Archiv, Filter,
Paginierung, Detail, Einzel-ICS, Absage, alte Links, mobile Menübedienung und
angemeldete Redaktion bei 1440, 820 und 390 Pixeln. Alle Daten sind synthetisch;
kein externer Anbieter oder Mailtransport wird aufgerufen.

Der vollständige lokale Entwicklungsprüflauf besteht alle 40 nativen Suiten,
Quellen-/Offlineprüfung, Installer samt Wiederherstellung und tatsächlicher
Browserinstallation ohne Hosting-Shellfunktionen sowie die vorhandenen
Manager-/Kalender-Browserwege. Die ursprünglichen Gutscheinprüfungen verwenden
die aktuelle DVM-Marke und die modularen Quellpfade; persönliche QR-/PDF-
Originale und Empfängergrenzen bleiben unverändert geprüft.

Offen bleiben vollständiger Altimport mit alten IDs/UIDs/Bildadressen,
automatische Übernahme freigegebener Anbieterbilder, der separate alte News-RSS
und weitere alte Feed-Metadaten/Cache-/CORS-Verträge. Auch die gesamte CMS- und
vollständige Produktparität ist damit noch nicht abgeschlossen.
