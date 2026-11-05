# Gemeinsame Funktionen statt paralleler Modulbestände

Stand: 9. Oktober 2026. Arbeitszweig: `codex/backup-2026-10-08-cms-workflows`.
Dies ist ein geprüfter Zwischenstand; er erklärt weder den gesamten Vereinsmanager
noch den vollständigen Umbau für fertig.

## Zusammengeführt

| Bereich | Gemeinsame Implementierung | Erhaltene Grenzen |
|---|---|---|
| Seitenaufteilung | `src/Shared/Pagination.php` für Mitglieder/Kontakte, Newsletter-/Pressekontakte, Social-Bibliothek und Dokumente | Jeder Dienst prüft weiterhin seine aktuellen Rechte, Suchfelder und Seitengrößen. SQL-Listen werden weiterhin in SQL begrenzt; Dokumentansichten verwenden ihre bestehenden Quellen. |
| Redaktionsrechte | `src/Auth/EditorialPolicy.php`; die bisherige `WebsiteAreaAccess` delegiert ihre reine Bereichs-/Operationsmatrix dorthin | Native Produktion lädt keine zusätzliche CMS-Laufzeit. Bestehende technische Konto- und Workerprüfungen werden nicht durch die reine Matrix ersetzt. |
| CMS-Veröffentlichungssperre | `native/Cms/ClubPublicationLock.php` für Seiten und Datenmodell | Beide Änderungen sperren denselben Vereinszustand in ihrer bisherigen Transaktion. Veröffentlichungsstände und Generationen werden nicht dupliziert. |
| Finanzrechte | `Permissions::finance()` für Ausgangsrechnungen, reine Rechnungsmails, Spendensammlungen und Aufwandsverzicht | Aktuelles Konto, Verein und Lese-/Schreibrecht werden bei jedem Aufruf erneut geprüft. Fachliche Rechnungs- und Spendenregeln bleiben in ihren Diensten. |
| Bank-Schreibrechte | `Permissions::finance(..., true)` für `BankImport`, `FinTsDialog`, `TransferOrders` | Alle bisherigen Amts-/Konten-/Freigabeprüfungen bleiben zusätzlich erhalten. Drei MySQL-Suiten prüfen den vollständigen ursprünglichen Ablauf und den nachträglichen Zugangsentzug vor Banktransport. |
| Bildstudio | `Web/assets/image-studio/{media,renderer,controls}.js`, gemeinsamer `ImageStudioAssets`-Bestand | Eigene lokale Medien, unveränderliche Bildfassungen und serverseitige Freigaben bleiben erhalten. Beide Manager-Einstiege laden dieselbe Modulreihenfolge. |
| Kalender | `CalendarFeed` kombiniert eigene Quellen unter `src/Events/Calendar` für Termine, veröffentlichte CMS-Veranstaltungen, Nachweisfristen, Ausleih-Abholungen/Rückgaben und Kommunikationsplanung | Filter/Tag/Woche/Monat/Agenda sind Ansichten. Der Feed legt weder zweite Termine noch neue Versandaufträge an. Jede Quelle öffnet das bestehende Original und prüft dessen Rechte. Weitere fachliche Terminquellen bleiben offen. |
| Dokumente einschließlich HSE | `DocumentLibrary` kombiniert die zentrale Ablage mit `ManagerDocumentWorkflows::library()` | Originalanlagen bleiben bei ihrer Beurteilung. Die Bibliothek liefert Metadaten und geschützte Links, keine Kopien von Dateien, Feldinhalten oder Unterschriften. |

Die HSE-Ansicht ist ein Filter derselben Dokumentverwaltung. Sie enthält aktuelle
Beurteilungen und Originalanlagen, Suche und Seitenaufteilung. Der PDF-Abruf
erzeugt die Ausgabe aus der angezeigten Fassung; eine noch nicht erzeugte Datei
wird nicht als gespeichertes Original oder mit erfundener Dateigröße angezeigt.
Der Quelldienst prüft beim Öffnen und Herunterladen erneut die aktuellen Rechte.
Ein Mitglied mit einem persönlichen Unterschriftsauftrag erhält dadurch keinen
Zugriff auf sämtliche HSE-Dokumente. Sein eingefrorenes unterschriebenes Original
bleibt ein eigener Nachweis mit persönlichem Zugriff, auch nach späterer Änderung
der Beurteilung.

## Gefundene Doppelstrukturen, noch offen

| Befund | Konkrete Quellen | Erforderliche Zusammenführung |
|---|---|---|
| Mehrere Mailzugangsspeicher und Pflegewege | `native/Delivery/MailAccount.php`, `src/Communication/MailChannelSettings.php`, `src/Finance/Invoices/InvoiceMailbox.php` | Ein gemeinsamer Zugangsspeicher mit Zweckzuordnungen; IMAP-Abruf und SMTP-Versand bleiben unterschiedliche Fähigkeiten. Bestehende verschlüsselte Zugänge benötigen eine geprüfte Migration, Vereinszuordnung und Konfliktbehandlung. Aktuell ist der native Zugang nicht der einzige Mailzugang des gesamten Managers. |
| Vereinsname und Adresse mehrfach pflegbar | `native/Shared/Identity.php`, `src/Settings/Settings.php` (Vereinsdaten und Briefpapier) | Ein maßgeblicher Vereinsdatensatz; Briefpapier, Website, QR und Beleg beziehen dessen Werte. Bereits ausgestellte Belege behalten ihre eingefrorenen historischen Angaben. Keine automatische Überschreibung widersprüchlicher Bestandswerte. |
| Weitere identische Finanzhilfen | Quellnachweise, Belegzugriffe, Normalisierung und CSV-Hilfen verschiedener Bilanz-/Anlagenklassen | Gemeinsam nutzbare Technik extrahieren, nachdem die jeweilige Original-, Buch- und Steuerbasis geprüft ist. Ähnliche Namen bedeuten nicht automatisch dieselbe fachliche Aufgabe. |

PayPal-Bankumsätze und PayPal-Kassenzahlungen teilen den Anbieter, aber ihre
fachlichen Aufgaben unterscheiden sich. Ebenso benötigen SumUp-Leser und natives
Tap to Pay unterschiedliche Geräteadapter. Gemeinsame Auftragszustände und
Rechteprüfungen sind sinnvoll; eine Zusammenlegung der Bestätigungswege darf
keinen Verkauf ohne unabhängigen Zahlungsnachweis abschließen.

## Reproduzierbare Codeprüfung

`php vereinsverwaltung/tools/check-duplicate-code.php` prüft die eigenen PHP-Quellen
unter `vereinsverwaltung/src`, `vereinsverwaltung/native` und den damaligen Integrationsdateien
und `banking`. Drittanbieter-, Laufzeit- und Testdateien werden ausgeschlossen.
Der Bericht enthält Dateipfade, Zeilen und Methodennamen, keine Quelltexte oder
Zugangsdaten. Gleiche Methodenkörper werden ohne Kommentare und Leerraum verglichen.

Im Bericht vom 8. Oktober 2026 nach den damaligen Änderungen: **564 PHP-Dateien, keine identischen ganzen
Dateien ab 200 Bytes, 14 Gruppen identischer benannter Methodenkörper ab 140
normalisierten Zeichen**. Dieser Schwellenvergleich entdeckt Kandidaten; er ist
keine vollständige semantische Prüfung. Unterschiedlich geschriebene Dopplungen,
JavaScript, Oberflächen und Datenmodelle benötigen zusätzlich einen fachlichen
Vergleich. Die Mail- und Vereinsdaten-Dopplungen wurden deshalb separat durch
Prüfung ihrer Speicher- und Aufrufwege festgestellt.

## Nachweise für diesen Zwischenstand

- HSE: 30 Prüfungen mit isoliertem MySQL, echten PDF-Ausgaben, unveränderten
  Originaldateien, aktuellen/entzogenen Rechten, Vereinsgrenzen und persönlichem
  signiertem Archiv; keine externen Aktionen.
- HSE im Browser: 20 Prüfungen bei 1440/820/390 Pixeln, 25/2 Seiten, Suche,
  Originaldownload einschließlich SHA-256, tatsächliche PDF-Erzeugung,
  Leserecht und direkte Zugriffssperre für Mitglieder; keine JavaScriptfehler.
- Gemeinsamer Kalender/Dokumente: 22 Dienst- und 24 Browserprüfungen.
- Ausleih-Kalender: 31 Dienstprüfungen mit realen synthetischen Bestands- und
  Ausleihübergängen; angemeldeter Browser bei 1440/820/390 Pixeln. Die fünf
  Kalender-/Einladungs-/Website-Testsuiten und der gemeinsame Dokument-Browser
  bestehen auch nach der inneren Aufteilung der Kalenderquellen.
- Kontakte/Newsletterzustellung: 24 Prüfungen; Social-Bibliothek: 28.
- CMS-Seiten: 84 Prüfungen; CMS-Datenmodell: 125; native Produktion und
  Redaktionsrechte: 16; bisherige Website-Rollen: 18.
- Mitglieder/Kontakte/Sicherung: 72 Prüfungen einschließlich tatsächlicher
  MySQL-Wiederherstellung. Der Cloud-Testclient verwendet den konfigurierten
  isolierten Port und den installierten MariaDB-Client mit MySQL-8-Authentifizierung.
- Spendenformulare: 75 Prüfungen; Spendensammlungen: 31; reine Rechnungsmails: 18.
- Ausgangsrechnungen: 47 Prüfungen einschließlich tatsächlicher
  KoSIT-/Mustang-/PDF/A-Validierung, Ausstellung, Teilgutschriften, unveränderlichem
  Original, unklarer Zustellung ohne Wiederholung und entzogenen Versandrechten.

Kontrollierte Testtransporte belegen keine produktive Anbieterfreigabe. Die
gesamte Anwendung und das endgültige Installationspaket
benötigen weiterhin ihre vollständigen Abnahmen. Der damalige Laufzeitumbau und die
abschließende Sicherheitsprüfung gehören ans Ende dieser Gesamtarbeit.
