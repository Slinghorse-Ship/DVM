# Lokales Abhängigkeits- und Lizenzinventar

Stand: 8. Oktober 2026. Grundlage der Lizenz-/Paketangaben: Dateien des Checkouts `/PFAD/ZUM/REPOSITORY`; ergänzend wurden für den eigenen Auth-Kern offizielle PHP-/RFC-Dokumente gelesen, wie im verlinkten Ergebnisbericht belegt. Keine Übermittlung von Vereinsdaten. Dieses Inventar beschreibt belegte lokale Metadaten, keine juristische Freigabe, vollständige SBOM oder Sicherheitszertifizierung. Versionen in Lockdateien sind gesperrte Paketversionen; daraus folgt weder, dass jedes Paket zur Laufzeit installiert ist, noch, dass es aktuell oder sicher ist.

## Neuer Chat und vorhandener Chat

`vereinsverwaltung/native/Chat/{Schema,Workspace,Polls,Conflict,Attachments,Notifications}.php`, `native/Web/ChatView.php` und `native/Web/assets/chat.{js,css}` sowie `chat-worker.js` sind neu geschriebener Anwendungscode. Verwendet werden PHP/PDO-MySQL, mbstring, GD zur Bildprüfung/Neucodierung, Fileinfo zur Typprüfung, PHP-Zufallszahlen/Hash/JSON/Datumsfunktionen sowie die neuen projektinternen `Shared\Access` und `Shared\Db`. Der Browser verwendet DOM-, Fetch-, Web-Crypto- und Notifications-APIs ohne fremdes Chat-/Editorpaket. Der ursprüngliche isolierte Chat-Kern hatte keinen externen HTTP-/Mail-/Pushtransport als Pflichtabhängigkeit. Ein eigener vorbereiteter Service-Worker-Empfänger ist vorhanden, wird in der Demo aber nicht registriert; geschlossener Browser/Web-Push ist ohne weiteren Transport nicht verfügbar. PHP, seine Erweiterungen, der Browser und MySQL bleiben Fremdsoftware und Betriebsabhängigkeiten. Ihre vollständigen jeweiligen Laufzeitdistributionen wurden hier nicht als lizenzrechtlich vollständig geprüft inventarisiert.

Der lokale Chat-Ausbau ist im dokumentierten Umfang geprüft: 69 Basisprüfungen einschließlich vier echter Konkurrenzszenarien, 75 zusätzliche Serviceprüfungen und 11 isolierte Client-DOM-Prüfungen; außerdem sieben Layout-/Mobilprüfungen laut Hauptintegration. Anhänge, Umfragen, Antworten, Teilnehmer, Lesestand, Stummschaltung und Geräte-/Outbox-Modell sind nutzbar. Kein lokaler Queueeintrag wird als externe Zustellung ausgegeben. Siehe [Chat-Ausbau und Grenzen](BEDIENUNG.md#mail-und-newsletter).

Fachliche Referenz waren die vorhandenen projektspezifischen Dateien `vereinsverwaltung/src/ClubChat.php` und `ChatPolls.php`: Unterhaltungen, Teilnehmer, Nachrichten, Lesestand, Bearbeitung, Umfragen. Der Altcode ruft `Permissions`, `DocumentCatalog`, `Documents::inspectFile` und `PushNotifications::enqueue` auf. Der ursprüngliche Berechtigungsdienst prüfte auch seine damaligen Identitätszuordnungen. Der neue Dienst übernimmt diese Pflichtabhängigkeiten nicht. Die bloße Lage von Altcode im Projekt beweist keine vollständige Herkunfts- oder Lizenzprüfung sämtlicher alter Dateien.

Die vorhandenen Fremdlizenzdateien und deren Header bleiben unverändert. Der eigene DVM-Code steht seit dem 5. November 2026 unter der [DVM-Lizenz für gemeinnützige Nutzung 1.0](../LICENSE); [Nutzung und Lizenz](LIZENZ.md) erläutert deren Umfang. Die Beschränkungen gelten nicht für Fremdkomponenten: Deren eigene Rechte, Bedingungen und gegebenenfalls Quellangebote bleiben vollständig erhalten. Das Gesamtpaket erhält dadurch keine einheitliche Lizenz für sämtliche Bestandteile.

## Eigener Authentifizierungskern

`vereinsverwaltung/native/Auth/{Schema,Service,Totp,Conflict}.php` ist neu geschriebener Anwendungscode für Einladungen, Passwörter, TOTP, Wiederherstellungscodes, Sitzungen, Sperren und Versuchsgrenzen. Der eigene TOTP-Code wurde anhand des RFC6238-Verfahrens und dessen Testvektoren implementiert; dessen Referenzquellcode wurde nicht übernommen. Passwort-Hashes verwenden die nativen PHP-Funktionen, die Geheimnisverschlüsselung PHP/libsodium `sodium_crypto_secretbox`; kryptografische Primitive wurden nicht selbst nachgebaut. PDO-MySQL, mbstring und libsodium sind tatsächliche Laufzeitabhängigkeiten. Dieser Bericht weist ihnen ohne Prüfung ihrer konkret ausgelieferten Distribution keine pauschale Lizenz zu.

Die eigene Kontenoberfläche verwendet bestehende projekteigene Kulturhus-Styles. Ihre Wiederverwendung macht vorhandene Fonts, Bilder oder Laufzeitbibliotheken nicht zu neuem oder lizenzfreiem Code. Browserprüfungen verwenden Playwright aus der lokalen Werkzeuglaufzeit ausschließlich zur Entwicklung; der Auth-/Chat-Anwendungscode bindet Playwright nicht ein.

Prüfungen einzelner Abhängigkeiten ersetzen keine vollständige Fach- und
Integrationsabnahme der Anwendung. Kontoeinrichtung und Bedienung stehen unter
[Konto](BEDIENUNG.md); die jeweiligen Fachdienste benötigen eigene Tests.

## Direkt sichtbare Ressourcen und Archive

| Bestandteil | Lokaler Beleg | Festgestellter Stand |
| --- | --- | --- |
| Composer 2.10.3 PHAR | `distribution/packages/php-runtime/composer-2.10.3.phar`, `LICENSES/composer-LICENSE` | Werkzeugarchiv plus Lizenztext vorhanden; unabhängig vom neu geschriebenen Chat. |
| Composer-generierter Autoloader | `vereinsverwaltung/voucher-runtime/vendor/composer/LICENSE` und Autoload-Dateien | Fremd-/generierter Laufzeitcode vorhanden; im neuen Chat nicht eingebunden. |
| Liberation Serif im Dokumentenmodul | `vereinsverwaltung/resources/fonts/liberation-serif/LICENSE` | SIL Open Font License 1.1, Red Hat Copyright 2012. Nicht mit dem anderen Liberation-Fontbestand in PDF.js verwechseln. |
| jsQR-Bestand | `vereinsverwaltung/public/voucher-jsqr-LICENSE.txt` | Apache-2.0-Lizenztext vorhanden; Version aus dieser Datei nicht bestimmt. |
| PDF.js 6.3.289, Build 1c8020a7d | `vereinsverwaltung/public/vendor/pdfjs/build/pdf.min.mjs`, `pdfjs/LICENSE` | Versions-/Buildheader und Apache-2.0-Hinweis im Code. |
| PDF.js CMaps, Standardfonts, WASM | Unter `vereinsverwaltung/public/vendor/pdfjs/{cmaps,standard_fonts,wasm}/LICENSE*` | Eigene Lizenzbestände vorhanden und zu erhalten. PDFium/Foxit-Texte enthalten Bedingungen für Quell-/Binärweitergabe; qcms enthält MIT-Text; OpenJPEG enthält BSD-2-Clause-Hinweis. JBIG2 und PDF.js-Brücken haben getrennte Hinweise. |
| Liberation Sans in PDF.js | `vereinsverwaltung/public/vendor/pdfjs/standard_fonts/LICENSE_LIBERATION` | Text nennt GPL v2 mit Font-Ausnahmen. Keine pauschale OFL-Zuordnung für diesen Bestand. |
| Linux-Hilfsprogramme und Bibliotheken | `distribution/packages/linux-runtime/manifest.json`, `LICENSES/` und Quellarchive | PDF/OCR/HEIC/XML/Java/Python-Distribution mit eigenen und transitiven Lizenzen; unten inventarisiert. Nicht Teil des neuen Chatdienstes. Vollständigkeit der Binär-/Quellentsprechung hier nicht neu geprüft. |

## Weitere Distributionskomponenten

Das vorhandene Linux-Manifest nennt Python 3.11.2, ReportLab 4.4.9, pypdf 6.10.0, Pillow 9.4.0 und charset-normalizer 3.5.1; Tesseract 5.5.3, PDFium 5.13.0 und Pillow 12.3.0; OCR-Modelle mit Commit `e12c65a915945e4c28e237a9b52bc4a8f39a0cec`; pillow-heif 1.7.0, libheif 1.23.3, libde265 1.1.2 und x265 4.2; glibc-Kompatibilitätspakete 2.36/2.41; Temurin 17.0.20.1+1; KoSIT 1.6.3, XRechnung 3.0.2 / Konfiguration 2026-08-31 sowie Mustang 2.26.0.

Der Hybrid-Python-Bestand nennt certifi 2026.7.22, charset-normalizer 3.5.1, factur-x 6.8, idna 3.19, lxml 6.1.3, Pillow 12.3.0, pypdf 6.18.0, python-stdnum 2.2, requests 2.34.2 und urllib3 2.7.0. Separate Lizenztexte liegen unter `distribution/packages/linux-runtime/LICENSES`, darunter `hybrid-python/*/licenses/`. Im HEIC-Manifesteintrag steht `GPL-2.0; component details in LICENSES_bundled.txt`; das wird nicht pauschal auf jede dort enthaltene Bibliothek übertragen. Wo das Manifest keine Lizenz nennt, wird hier keine vermutet.

Diese Bestandsaufnahme hat weder jedes Archiv neu entpackt noch jeden eingebetteten Unterbestand auf vollständige Notices, Quellangebote, Patente, Versionsentsprechung oder Weitergabebedingungen überprüft. Vor einem konkreten Installerpaket sind dessen tatsächlich eingeschlossene Dateien mit Manifest, Checksummen, Lizenztexten und erforderlichen Quellen abzugleichen. Das Entfernen einer Pflichtabhängigkeit aus neuem Anwendungscode entfernt keine Lizenzpflichten verbleibender ausgelieferter Fremdsoftware.

## Composer-Lock-Metadaten

Die folgende Tabelle wurde aus den vier vorhandenen `composer.lock`-Dateien erzeugt. `Root` = `composer.lock`, `Banking` = `banking/composer.lock`, `Push` = `vereinsverwaltung/push-runtime/composer.lock`, `Voucher` = `vereinsverwaltung/voucher-runtime/composer.lock`. Lizenzangaben sind Paketmetadaten; paketinterne Notices können zusätzliche Bestandteile ausweisen. Die native PHP-Distribution bewahrt Paket-Lizenztexte unter `distribution/packages/php-runtime/LICENSES`; Voucher hat eigene Texte direkt im Vendor-Bestand.

| Paket | Version | Lizenz laut Lock | Bestand |
| --- | --- | --- | --- |
| bacon/bacon-qr-code | v3.1.1 | BSD-2-Clause | Voucher |
| brick/math | 0.20.0 | MIT | Push |
| dasprid/enum | 1.0.7 | BSD-2-Clause | Voucher |
| doctrine/lexer | 3.0.1 | MIT | Root |
| egulias/email-validator | 4.0.4 | MIT | Root |
| graham-campbell/result-type | v1.2.0 | MIT | Root |
| guzzlehttp/guzzle | 7.15.5 | MIT | Root, Push |
| guzzlehttp/promises | 2.5.3 | MIT | Root, Push |
| guzzlehttp/psr7 | 2.13.1 | MIT | Root, Push |
| minishlink/web-push | v10.1.0 | MIT | Push |
| nemiah/php-fints | 4.1.0 | MIT | Banking |
| phpoption/phpoption | 1.10.0 | Apache-2.0 | Root |
| psr/clock | 1.0.0 | MIT | Push |
| psr/container | 2.0.2 | MIT | Root |
| psr/event-dispatcher | 1.0.0 | MIT | Root |
| psr/http-client | 1.0.3 | MIT | Root, Push |
| psr/http-factory | 1.1.0 | MIT | Root, Push |
| psr/http-message | 2.0 | MIT | Root, Push |
| psr/log | 3.0.2 | MIT | Root, Banking, Push |
| ralouphie/getallheaders | 3.0.3 | MIT | Root, Push |
| spomky-labs/base64url | v2.0.4 | MIT | Push |
| spomky-labs/pki-framework | 1.6.1 | MIT | Push |
| symfony/deprecation-contracts | v3.7.1 | MIT | Root, Push |
| symfony/event-dispatcher | v7.4.17 | MIT | Root |
| symfony/event-dispatcher-contracts | v3.7.1 | MIT | Root |
| symfony/mailer | v7.4.17 | MIT | Root |
| symfony/mime | v7.4.18 | MIT | Root |
| symfony/polyfill-ctype | v1.37.0 | MIT | Root |
| symfony/polyfill-intl-idn | v1.42.0 | MIT | Root |
| symfony/polyfill-intl-normalizer | v1.42.0 | MIT | Root |
| symfony/polyfill-mbstring | v1.38.2 | MIT | Root |
| symfony/polyfill-php80 | v1.37.0 | MIT | Root, Push |
| symfony/polyfill-php83 | v1.41.0 | MIT | Push |
| symfony/service-contracts | v3.7.3 | MIT | Root |
| vlucas/phpdotenv | v5.7.0 | BSD-3-Clause | Root |
| web-token/jwt-library | 4.2.2 | MIT | Push |
