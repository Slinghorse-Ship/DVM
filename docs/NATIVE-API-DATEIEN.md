# Allgemeine Dateien und unveränderliche Fassungen in API v1

Der bestehende Vertrag für Bilder und Website-PDFs unter `/api/v1/files` bleibt
erhalten. Allgemeine Dokumente, Archive, Audio/Video und Bildfolgen verwenden
zusätzlich `/api/v1/files/assets` und `/api/v1/files/revisions`. Anmeldung,
persönliche Tokenfreigabe, aktuelle native Rechte, feste serverseitige
Vereinsbindung und JSON-Fehler folgen [API v1](NATIVE-API.md). Es gibt keinen
anonymen allgemeinen Schreibzugriff. `files.read` und `files.write` sind
persönliche Freigaben zusätzlich zu den ursprünglichen Vereinsrechten.

## Identitäten und kurze Listen

Ein `asset` ist eine veränderliche Dateifolge. Sein `id` und seine erwartete
`version` steuern Änderungen. `revision_id` bezeichnet seine aktuell gewählte
unveränderliche Fassung; `revision` zählt Fassungen und ersetzt die erwartete
Kontrollversion nicht. `asset_kind` ist `file` oder `image`.

Ein `revision`-Datensatz trägt dagegen die unveränderliche Kennung in `id` und
die Dateifolge in `asset_id`. Er hat keine veränderliche `version`. Eine
Dateiverknüpfung einer CMS-Seite erhält ausschließlich diese unveränderliche
Kennung. Eine logische Dateifolge ist keine gültige Seiten-Dateiverknüpfung.

Alle Ausgabeobjekte haben eine feste Auswahl freigegebener Eigenschaften:
Kennungen, Typen, Fassungen, Zustand, Dateiname, MIME, Bytezahl, SHA-256 sowie
begrenzte Nutzungs-/Beschreibungsangaben. Serverpfade, interne Blobkennungen,
Vereins-/Erstellerkennungen, private Nachweise und Anmeldesecrets fehlen.

| Anfrage | Ergebnis |
| --- | --- |
| `GET /files/assets` | Zehn Dateifolgen; Filter `q`, `page`, `kind=all/file/image`, `mine=0/1`, `tag`, `removed=0/1` vor Zählung/Seitenauswahl. |
| `GET /files/assets/{id}` | Aktuelle Dateifolge; `include_removed=1` erlaubt entfernte Folgen nur mit ursprünglichem Verwaltungsrecht. |
| `GET /files/assets/{id}/versions?page=1` | Zehn unveränderliche Fassungen, neueste zuerst. |
| `GET /files/revisions/{id}` | Genau eine unveränderliche Fassung. |
| `GET /files/revisions/{id}/content` | Aktuell berechtigter Download der exakten Originalbytes. |
| `GET /files/assets/{id}/removal` | Geschützter ursprünglicher Entfernungs-/Aufbewahrungszustand. |

Alle Pfade beginnen mit `/api/v1`. `HEAD` prüft dieselben Rechte und Originale,
liefert jedoch keinen Antwortkörper. Antworten enthalten die normale
`api_version`, `data` und `meta`. Die feste Zehnergrenze steht als `meta.limit`
zusammen mit `page`, `total`, `pages` und `has_more` im Listenvertrag.

## Einzel-/Mehrfachupload und Änderungen

Schreibaufträge benötigen einen unveränderten `Idempotency-Key`. JSON-Versionen
sind ganze Zahlen; das native Uploadformular transportiert sie als dezimale
Zeichenfolge. JSON-Pfade, Client-Vereinskennungen oder ein gewünschter Serverroot
sind keine erlaubten Eingaben.

| Anfrage | Eingabe |
| --- | --- |
| `POST /files/assets` | `multipart/form-data`, `files[]` oder `file`; optional `metadata` als JSON-Objekt für gemeinsame Anfangsangaben. |
| `POST /files/assets/{id}/replace` | Multipart mit genau einer Datei und ursprünglicher `version`. |
| `POST /files/assets/link-image` | JSON `{ "source_id": "native-bildkennung" }`; bindet ein vorhandenes eigenes natives Bild ohne Pixelkopie. |
| `POST /files/assets/{id}/link-image` | JSON mit `version` und `source_id`; hängt ein anderes vorhandenes eigenes Bild als neue Fassung an. |
| `POST /files/assets/{id}/metadata` | JSON mit `version` und `data` als Teiländerung; Bilder benötigen zusätzlich die aktuelle `media_version`. |
| `POST /files/assets/{id}/restore` | JSON mit `version` und ursprünglicher `revision_id`; erzeugt einen neuen Kopf aus dieser exakten Fassung. |
| `POST /files/assets/{id}/detach` | JSON mit `version`; löst eine Bildfolge unter ursprünglichem Verwaltungsrecht, ohne Originalpixel zu löschen. |
| `POST /files/assets/{id}/reattach` | JSON mit aktuellem `version`; prüft alle ursprünglichen Bildfassungen vor Wiederaufnahme. |

Ein Auftrag akzeptiert bis zu zehn gemischte Dateien. Die nativen Grenzen bleiben
erhalten: allgemeine Dateien bis 25 MiB je Datei/50 MiB zusammen, Kameraoriginale
bis 256 MiB je Datei/512 MiB zusammen und zusätzlich die ursprüngliche Grenze der
vorbereiteten Bildbytes. HEIC und unterstützte RAW-Kameraoriginale durchlaufen
denselben lokalen Konverter und Pixelprüfweg wie die Dateiverwaltung. Ein
Webspace kann kleinere PHP-Uploadgrenzen konfigurieren.

`data` akzeptiert Titel, Beschreibung, bis zu zwanzig Schlagwörter sowie die
nativen Alternativtext-/Quellen-/Lizenz-/Nutzungsangaben. Geänderte Nutzungsrechte
benötigen weiterhin das ursprüngliche Verwaltungsrecht. Bei Bildern liefern
`source_metadata` und `media_version` den aktuellen kanonischen Bildstand;
eine zwischenzeitliche Änderung in der bisherigen Bildverwaltung führt beim
veralteten zweiten Versionswert zu 409. Byteersatz und historische
Wiederherstellung lockern keine aktuellen Nutzungsbeschränkungen.

Ein gemischter Upload verwendet eine gemeinsame native SQL-/Dateitransaktion.
Eine bestätigte Rücksetzung entfernt ausschließlich die neu angelegten Blobs
und Varianten. Ein unveränderter Wiederholungsauftrag erhält seine ursprüngliche
Annahme, ohne neue Dateien anzulegen. Ein anderer Dateiname, andere Bytezahl,
anderer SHA-256 oder andere Metadaten unter derselben Anfragekennung ergibt 409.

## Entfernung und bestätigte Wiederaufnahme

Diese Aktionen benötigen zusätzlich ursprüngliches `website.manage`. Sie
verwenden den vorhandenen gesonderten Dateilebenszyklus mit eigener
serialisierbarer Transaktion und ursprünglichem Aufbewahrungsplan.

| Anfrage | JSON |
| --- | --- |
| `POST /files/assets/{id}/remove` | `version`; der Server leitet genau eine ursprüngliche Vorgangskennung aus dem persönlichen Wiederholungsauftrag ab. |
| `POST /files/assets/{id}/restore-removed` | `version` und unveränderte `operation` aus dem Entfernungszustand. |
| `POST /files/assets/{id}/purge` | `version`, unveränderte `operation` und ausdrückliches `confirmed: true`. |

Die API speichert zuerst die gebundene Anfragekennung. Verliert sie nach dem
nativen Commit die Ergebnisbestätigung, bleibt dieser Auftrag wiederaufnehmbar:
Der identische Wiederholungsauftrag nutzt denselben ursprünglichen Vorgang.
Andere Eingaben unter derselben Kennung bleiben gesperrt. Eine abgeschlossene
API-Antwort wird unverändert bestätigt; aktuelle Anmeldung, Tokenfreigabe und
Verwaltungsrechte werden erneut geprüft. Ein unbestätigtes Ergebnis ist 503 und
fordert denselben Wiederholungsauftrag statt eines neuen Löschauftrags.

Physisches Löschen bleibt durch ursprüngliche Seiten-/Versionsreferenzen,
Aufbewahrung, feste Dateipläne, Hashes und geteilte Originale geschützt. Eine
Bildfolge verwendet stattdessen die Bildverwaltung und deren eigene
Aufbewahrungsabläufe; Dateientfernung löscht keine Bildoriginale.

## Download und öffentliche Originalbindung

Private API-Downloads prüfen Anmeldung, persönlichen Scope, aktuellen
Dateizustand und Originalhash erneut direkt am Stream. Allgemeine Dateien
werden mit `application/octet-stream`, Attachment, `nosniff` und Sandbox
ausgeliefert. Entfernte Dateien sind über alte Fassungs-URLs nicht abrufbar.

Öffentliche `/file/{unveränderliche-kennung}`-Downloads bleiben allein an die
vorhandene `FileReferences`-Prüfung gebunden: tatsächliche aktuelle
Website-Veröffentlichung, exakte gespeicherte Fassung, kumulative aktuelle und
damalige Feldrechte, öffentliche Repeater-Items und aktuelle Nutzungsfreigabe.
Eine Änderung des logischen Kopfes veröffentlicht keine neue Datei und ändert
keine bereits veröffentlichte Originalfassung.

Der gezielte Nachweis `native-api-files` verwendet frische synthetische
MySQL-Konten und lokale Originaldateien. Er prüft reale Native-Dienste,
SHA-256-/Dateirücksetzung, verlorene Ergebnisbestätigung nach Originalcommit,
persönliche Freigaben und öffentliche Originalbindung. Es werden keine realen
Anbieter, Postfächer, Zahlungen oder Geschäftsdateien angesprochen.

Die Abschlussprüfung vom 15. Oktober 2026 bestand 53 Fachbedingungen und 116
HTTP-Bedingungen mit 57 tatsächlichen Anfragen an Hauptanwendung und native
Redaktion. `DVM_API_FILES_HTTP=1` aktiviert diese leichte Zwei-App-Probe ohne
Browsermatrix. Zusätzlich bestanden alle 105 vorhandenen API-Fachbedingungen
einschließlich der bisherigen Bild-/PDF-Verträge. Alle ursprünglichen Zeilen
aus jeweils 418 bestehenden Tabellen und beide privaten Schlüssel blieben exakt
erhalten. Dieser lokale HTTP-Nachweis ersetzt keine produktive HTTPS- oder
physische Geräteabnahme.

Die getrennte kuratierte Paketabnahme und die originalgebundenen Zwei-App-Browsernachweise stehen in [Dateien und Bildfassungen](CMS-DATEIEN-UND-BILDFASSUNGEN.md#getrennte-auslieferungsabnahme). Die vorhandene API-Speichergrenze bleibt erhalten.
