# DVM: Projektstruktur und Modulgrenzen

Für die aktuelle Entwicklung mit `vereinsverwaltung/native/` beginnen.
`public/index.php` ist der bestehende gemeinsame Manager-Einstieg;
`native/Integration/` verbindet ihn mit nativer Anmeldung und Redaktionsmodulen.

```text
Kulturhus/
├── AGENTS.md                 Entwicklungsregeln
├── tools/cloud/              Einrichtung und Neustart aus Git
├── tools/tests/              bestehende Fachtests mit synthetischem Linux-MySQL
├── vereinsverwaltung/
│   ├── native/               eigene modulare Anwendung
│   │   ├── Auth/             Konten, Anmeldung, MFA, Sitzungen
│   │   ├── Cms/              Seiten, Datenmodell, Medien, Veröffentlichung, NewsReview, PathHistory
│   │   │                    EventDetails, EventIndex und Events: veröffentlichte Terminoriginale
│   │   │                    EventPublications: eigene erste Ankündigung, ursprüngliche Pushqueue
│   │   │                    ImageOrientation: begrenzte Kamerametadaten, aufrechte Uploadpixel
│   │   │                    NewsChannels: gemeinsame Kanalregel veröffentlichter Beitragsoriginale
│   │   │                    WebsiteSettings: eigene Website-Policy und Änderungshistorie
│   │   │                    WebsiteDesign: fünf auswählbare Vorlagen, Designversionen und eigener Verlauf
│   │   │                    QueryField, Selectors, QueryOrder: gemeinsame typisierte Filter/Sortierung
│   │   │                    PageSummary, EventYears, Navigation::count: SQL-Inhalts-/Originalmetadaten
│   │   │                    ReferenceCatalog: private begrenzte Seiten-/Bildmetadatensuche
│   │   │                    FieldConditions: typisierte Sicht-/Pflichtregeln und Abhängigkeitsprüfung
│   │   │                    PageVersions/VersionChanges: begrenzte Originalhistorie und typisierter Vergleich
│   │   │                    AlbumVersions: atomare private Wiederherstellung ursprünglicher Albumangaben
│   │   │                    HomeLayout: begrenzte versionierte Startseitengestaltung
│   │   │                    CopyInput, PageCopies, TreeCopies/TreeCopySources/TreeCopyAddresses: private Inhaltskopien
│   │   ├── Communication/    Newsletter, Social, Presse: Fachdienste
│   │   │                    EventDrafts: veröffentlichte eigene/importierte und erlaubte Kalenderoriginale
│   │   │                    Attention, PersonalActivity, CheckPlans/CheckSchedule: private Prüfung
│   │   │                    Replies: verschlüsselte Kommentarantworten, ausdrücklicher Versand, Ergebnisprüfung
│   │   │                    MetaComments: vollständige Kommentarstränge und gebundene eigene Antworten
│   │   │   ├── Confirmations/ Auswahl, erneute DOI-Aufträge, Vorbereitung, Mailinhalt
│   │   │   └── Digests/      Periodenpläne, Vorbereitung, Pausen, Terminoriginale
│   │   ├── Delivery/         private Zustelljobs und Mailzugang
│   │   ├── Events/           Eventbrite-Abgleich
│   │   ├── Chat/             Chat und dessen Arbeitsabläufe
│   │   ├── Pos/              Veranstaltungskasse / Zahlungen / Gutscheine
│   │   ├── Shared/           Rechte, Identität, Verschlüsselung, Datenbankhilfen
│   │   ├── Installer/        gemeinsame Initialisierung, CLI-/Browserinstallation und Schema
│   │   ├── Integration/      gemeinsamer Manager, Routing, Anbieteradapter
│   │   │                    ManagerEventSource: ursprüngliche Vereinstermine mit aktuellen Kalenderrechten
│   │   │   ├── StadtLandFunk/ PHP-HTTP, PKCE/Refresh, verschlüsselte Kontoverbindung
│   │   │   └── Jobs/         private Orchestrierung, Originalqueues, DOI-Antragsbindung, Laufhistorie
│   │   └── Web/              Oberfläche, getrennt von den Fachdiensten
│   │       ├── Manager.php   gemeinsame Hülle und Delegation
│   │       ├── BootstrapAssets.php gemeinsame lokale Bootstrap-6-Alpha-1-Assets und Modulkennzeichnung
│   │       ├── Workspace.php gemeinsame Formular-/Rechtehilfen
│   │       ├── Communication/ Redaktion, Kontakte, Bibliothek, EventSources, Review, Planung
│   │       ├── Settings/     Vereinsdaten, ApiPanel, WorkerPanel und eigenes IndexingPanel
│   │       ├── Indexing.php  gemeinsame robots-/Metaregeln für die produktive Website
│   │       ├── ReferencePicker.php / FieldRuleEditor.php: gemeinsame Originalauswahl und Feldregeln
│   │       │                assets/reference-picker.js und field-conditions.js: gemeinsame Bedienung
│   │       ├── Fields/       Controls: zwölf gemeinsame Feldarten; Layout: verschachtelte Formulargruppen
│   │       │                GroupForm: Gruppen-/Zuordnungsformular; eigene field-groups.js und .css
│   │       ├── Website/      Seitenbearbeitung, gemeinsame Beitragskanäle, NewsReviewPanel und alte Links
│   │       │                PageSidebar: vollständiges gezähltes 25er-Verzeichnis der separaten Redaktion
│   │       │                EditorialSearch/PageSearch/EventSearch: gemeinsame SQL-Zählung und 25er-Listen
│   │       │                PageOverview: Metadaten und acht zuletzt bearbeitete Seiten
│   │       │                PageCopyPanel/PageChildrenPanel: gemeinsame Kopierwahl und Unterseitennavigation
│   │       │                PageVersionsPanel/VersionValues: gemeinsame Historie und Originalvergleich
│   │       │                HomeForm/HomePage/HomePreview: Startseitenformular, öffentliche Ausgabe, private Vorschau
│   │       │                PublishedFields: gemeinsame Ausgabe individueller Originalfelder
│   │       ├── Events.php    öffentliches Programm, Archiv und Terminansicht
│   │       └── assets/       lokale JavaScript-/CSS-Ressourcen
│   │           ├── site/    eigene Website-Gestaltung, Vereinsmotiv und Bildmarke
│   │           └── image-studio/ Bildquellen/Pixel, Zeichenlogik, HTML-Bedienung
│   ├── src/                  bestehende Vereinsmodule, jetzt nach Fachbereichen
│   │   ├── Finance/          Accounting, Assets, Banking, Balance, Contributions,
│   │   │                    Donations, Invoices und Tax
│   │   ├── People/Members/   Mitglieder, Familien, Anträge, Konten, Stammdaten
│   │   ├── Inventory/        Inventar, Anschaffung, Zuweisungen, Zubehör
│   │   ├── Documents/        Dokumente, Originale, Schutz, Vorschau und Downloads
│   │   ├── DocumentWorkflow/ Dokumenten-/HSE-Arbeitsabläufe
│   │   ├── Communication/    Redaktion, Mail, Kontakte und Chat
│   │   ├── Events/           Kalender, Veranstaltungen, GEMA und Gutscheine
│   │   │   └── Calendar/     Quellen für Termine, Nachweise, Ausleihen, HSE, Redaktion
│   │   │                    und gemeinsame Newsletterperioden
│   │   ├── Governance/       Tätigkeitsberichte und Protokolle
│   │   ├── Auth/             bestehende Vereins-/Finanz-/Arbeitsbereichsrechte
│   │   ├── Settings/         Einstellungen und Briefpapier
│   │   ├── Catalog/          versionierte Fachkatalogimporte
│   │   ├── Website/          bestehende Website-Arbeitsbereiche
│   │   ├── Web/              gemeinsame Ansichtshilfen und Navigation
│   │   ├── Infrastructure/   Database (gemeinsame Transactions), Runtime
│   │   │                    (native ServerKey-Ableitung), Media
│   │   ├── Integration/      Verbindung der gemeinsamen Fachmodule
│   │   ├── Shared/           kleine gemeinsame Fach-/Formularhilfen
│   │   └── bootstrap.php, autoload.php, SourceFiles.php, module-files.json
│   ├── public/               gemeinsamer HTTP-Einstieg und Manager-Assets
│   ├── views/                bestehende Oberfläche, 296 Ansichten nach Fachbereich
│   │   ├── Shell/           Hülle, Navigation, Dashboard und Moduldelegation
│   │   ├── Finance/         dieselben acht Finanzbereiche wie im Fachcode
│   │   ├── People/Members/, Inventory/, Documents/, Events/, Governance/
│   │   ├── Communication/, Settings/, Auth/, Website/, Catalog/, Help/
│   │   └── templates.json   stabile Ansichtsschlüssel → aktuelle Dateien
│   ├── bin/                  private CLI-Jobs / explizite Demoeinrichtung
│   ├── migrations/           bestehende Vereinsdatenbankschemata
│   ├── tests/                Fach-, native und Browserregressionen
│   ├── resources/            eigene lokale Schriften und Referenzressourcen
│   └── tools/                lokale OCR/PDF-/Video-/Fachwerkzeuge
├── banking/                  Bank-/FinTS-Adapter und eigenes Dependency-Lockfile
├── native-app/ios/           SumUp-/Tap-to-Pay-Gerätebrücke
├── distribution/             Offline-Installer, Bestand, Pakete und Lizenzen
│                             RuntimeInstaller: gemeinsame PDF-/OCR-Laufzeitkonfiguration
├── docs/                     Fach-, Architektur- und Installationsdokumentation
└── config/, tests/          notwendige alte CMS-Modell-/Migrationsverträge
```

Ein HTTP-Aufruf läuft über die gemeinsame Hülle/Integration zur zuständigen
Web-Ansicht und deren Fachdienst. `Web/Communication/Workspace` routet den
Redaktionsbereich, `Actions` verarbeitet Formulare, `Compose` zeigt den Editor;
`Communication/Workspace` speichert/versioniert den Inhalt.
`PublicationSnapshot` prüft dieselbe Bild-/Text-/Kanalfreigabe für API-Queue und
manuelle App-Übergabe. `Delivery/Outbox` verarbeitet Zustellaufträge,
`StoryHandoff` dokumentiert den manuellen App-Weg. Die Namespace-Ordner
unterscheiden Oberfläche und Fachlogik.

Formularstart, CSRF, aktuelle Rechte, privater Schlüssel und lokale
Zeitumrechnung liegen in `Web/Workspace`. Einstellungen, Seitenbearbeitung,
Kontaktpflege, Social-Bibliothek, Planung und Story-Übergabe haben eigene
Ansichtsklassen. Der gemeinsame `Web/Manager` enthält ihre Hülle und Delegation.
Neue Fachregeln gehören in den passenden nativen Modulordner.
`Installer/FinTsSetup` prüft und speichert die optionale private Bankregistrierung.
`RuntimeBundle` ergänzt den bestehenden Upload-Builder um die vorhandenen
OCR-Artefakte samt Quellen/Lizenzen; `OcrSetup` prüft das tatsächliche Raster
vor Aktivierung. Voraussetzungen, Wiederaufnahme und OCR-Abwahl bleiben Teil
des bestehenden `PreparedInstall`-/`WebSetup`-Ablaufs.
Das [Bildstudio](../vereinsverwaltung/native/Web/assets/image-studio/README.md)
trennt jetzt auch intern private Bildquellen/Pixelkorrekturen, Zeichenlogik und
Formularbedienung. `ImageStudioAssets` hält Dateizuordnung und Ladereihenfolge
für beide Manager-Einstiege gemeinsam; Export- und Seitenlebenszyklus bleiben
im selben Bearbeitungsablauf.

Der [gemeinsame Kalender](../vereinsverwaltung/src/Events/Calendar/README.md)
trennt Termin-, Geburtstags-, Nachweis-, Ausleih-, HSE- und Kommunikationsquellen. `CalendarFeed`
prüft und kombiniert sie; `Entry` hält Datum und Eintragsform gemeinsam.
Abholungen und Rückgabefristen öffnen dieselbe ursprüngliche Ausleihe.

Die 108 Bilanzdateien und zwei zugehörigen Downloads liegen in
`src/Finance/Balance/`; weitere 292 bisher flache Dateien sind ihren Fachordnern
zugeordnet. Includes, dynamische Quellzugriffe, Steuer-Fingerprints und lokale
Ressourcenpfade werden gemeinsam angepasst. `module-files.json` ordnet die
bestehenden öffentlichen Klassennamen ihren neuen Dateien zu. So müssen
Aufrufer ihre bisherigen Klassenverträge beim Ordnerumzug nicht ändern.
Das ist ein Übergang für den bestehenden flachen Namespace; neue native Module
verwenden bereits echte Namespace-Ordner und eigene Oberflächen-/Fachschichten.

`src/README.md` beschreibt die bestehenden Modulzugänge. `public/index.php`
bleibt der gemeinsame HTTP-Einstieg. Die [296 bestehenden Ansichten](../vereinsverwaltung/views/README.md)
sind jetzt den Fachordnern zugeordnet; `Web/Templates` löst ihre bisherigen
Schlüssel auf. Die gemeinsame Hülle liegt unter `views/Shell`, die native
Redaktionsoberfläche ist in `native/Web` aufgeteilt.
Weitere große Klassen, gemischte Controller/Fachdienste und wiederholte UI-Hilfen
müssen innerhalb der jetzt klaren Modulgrenzen weiter zerlegt werden. Die
Ordnerordnung allein schließt diese funktionale und innere Überarbeitung nicht ab.

Benötigte
Website-Assets liegen im nativen CMS. Produktive Anmeldung verlangt native
Konfiguration und aktuelle persönliche Konten; der alte Sessionadapter führt
keinen Framework-Bootstrap mehr aus. Ruhende Adapter-/UI-Reste werden weiterhin
bereinigt, keine Altbestände importiert.
Statische Mockups, Vereinsbeispieldaten und überholte Prüf-/Übergabeberichte sind
entfernt. Aktuelle Anforderungen stehen zentral im Funktionsabgleich und Register. `vendor/`, Paketarchive, Lizenzen und Ressourcen
sind Abhängigkeiten bzw. Auslieferungsbestand. `.local/` und `/workspace/.setup/`
sind ignorierte Entwicklungslaufzeiten und kein Anwendungsquellcode.

Die [Architektur](ARCHITECTURE.md) erläutert die Modulgrenzen; die
[Dokumentationsübersicht](README.md) führt zu Bedienung, Installation und Fachregeln.
