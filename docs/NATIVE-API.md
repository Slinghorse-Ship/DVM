# Native API v1

DVM stellt seine ursprünglichen CMS-, Konto-, Rollen- und Dateidienste unter
`/api/v1/` bereit. Die API arbeitet mit der nativen Anmeldung oder einem persönlich
angelegten API-Zugang. Vereinszuordnung, aufrufende Person und private Speicherpfade
stammen ausschließlich aus dem Serverkontext. Es gibt keine anonyme allgemeine
Lese- oder Schreib-API und keine frei ausführbaren PHP-, SQL- oder Klassennamen.

## Persönliche Zugänge

Unter **Mein Konto → API-Zugänge** einen Namen, Gültigkeit und einzelne Freigaben
wählen. Keine Freigabe ist vorangekreuzt. Das aktuelle Passwort und der eingerichtete
zweite Faktor müssen erneut bestätigt werden. Authenticator, ursprüngliche
Wiederherstellungscodes und die bestehende E-Mail-Sicherheitsbestätigung verwenden
denselben nativen Anmelde-/Drosselungsvertrag. Ein falscher Sicherheitscode lässt
den fünf Minuten gültigen Vorgang für einen erneuten Versuch geöffnet.

Die Zugangsverwaltung folgt der persönlichen deutschen oder englischen Sprache;
Originalnamen, Kennungen, API-Freigaben und Geheimnisse werden nicht übersetzt.
Bei einem gewöhnlichen frühen Eingabefehler bleiben Anlegekennung, Bezeichnung,
Gültigkeit und Freigaben erhalten. Passwort und Sicherheitscode bleiben leer.
Nicht darstellbare, unvollständige oder inzwischen unberechtigte Originale sowie
Konflikte und unbestätigte Ergebnisse erhalten kein neues Standardformular.
**Aktuelle API-Zugänge öffnen** liest den heutigen Stand ausdrücklich neu. Nach
einer verlorenen Bestätigung kann der bereits angelegte Zugang in der Liste
stehen; eine Wiederholung derselben verbrauchten Anlegekennung erzeugt keinen
zweiten Zugang und liefert kein gespeichertes Klartextgeheimnis nach.

Eine falsche Faktorbestätigung bietet nur einen noch aktuellen, eigenen und
weiterhin faktorpflichtigen Vorgang mit derselben Kennung erneut an. Andere
Widerrufsaktionen bleiben auf Fehlerseiten gesperrt. Die bestehenden getrennten
Passwort-/Faktorbegrenzungen bleiben unverändert; eine aktive Begrenzung meldet
den späteren erneuten Versuch ausdrücklich in der persönlichen Sprache. Mit
JavaScript meldet Kopieren Erfolg oder eine manuelle Ausweichmöglichkeit. Ohne
JavaScript erklärt die einmalige Antwort das Markieren und Kopieren im Textfeld.
Ungültige vollständige Seitenparameter liefern HTTP422 statt Seite1; HEAD bleibt
auch auf Fehlern ohne Antworttext. Die vollständige Seitenauswahl wird vor einer
Anlage, Faktorbestätigung oder einem Widerruf geprüft; ein Seitenfehler legt keinen
Zugang im Hintergrund an. Die Anzeige nutzt Berliner Ortszeit, während
API-Zeitwerte und gespeicherte Originale unverändert bleiben.

Der neue Zugang wird genau in seiner erfolgreichen Antwort angezeigt. Kopieren
oder manuell markieren; die Bedienung funktioniert auch ohne JavaScript. Spätere
Aufrufe zeigen nur Namen, Freigaben, Ablauf und Verwendung. Der Klartext steht
weder in Datenbank, Sitzung, Flash-Nachricht, URL noch einer Idempotenzantwort.
Die Datenbank speichert einen SHA-256 des zufälligen 256-Bit-Geheimnisses.
Persönlich höchstens 20 noch aktive Zugänge, Gültigkeit höchstens 90 Tage;
die Oberfläche bietet 7, 30 oder 90 Tage. Die Liste zeigt sechs Zugänge pro Seite.

**Zugang widerrufen** beendet die Verwendung sofort. Ablauf, native Kontosperre,
Passwort-/MFA-/Kontoversionswechsel und geänderte Identitätsbindung werden bei
jedem Aufruf erneut geprüft. Rechte stammen zusätzlich aus den aktuellen
Vereinsrollen. Ein Zugang kann die beim Anlegen ausgewählten Freigaben oder die
heutigen Rechte seiner Person nie überschreiten. Ein normales Browser-Abmelden
widerruft die unabhängigen persönlichen API-Zugänge nicht; hierfür den Zugang
explizit widerrufen. Fremde Konten können weder Zugangsliste noch Widerruf öffnen.

Geht die einmalige Antwort beim Anlegen verloren, die aktuelle Liste prüfen,
den unbekannten Zugang widerrufen und einen neuen anlegen. Der Server kann ein
verlorenes Geheimnis nicht erneut anzeigen. Sicherheitscodes und Rate-Limits
werden außerhalb nachfolgender Geschäftsänderungen verbraucht; ein fachlicher
Rollback stellt sie nicht wieder her.

## HTTP-Vertrag

- Browser: native Manager-Sitzung; jede Methode außer GET/HEAD benötigt den
  aktuellen `X-CSRF-Token`, auch die lesende JSON-Abfrage per POST.
- Integration: `Authorization: Bearer <persönlicher Zugang>`. Ein vorhandener
  ungültiger Authorization-Header wird abgewiesen, auch mit gültigem Sitzungscookie.
  Geheimnisse werden nicht aus Queryparametern oder JSON gelesen.
- Die erzeugte Apache-Konfiguration leitet vorhandene Authorization-Header
  vor terminierenden Rewrite-Regeln weiter. Interne
  `REDIRECT_HTTP_AUTHORIZATION`-Werte werden nur ohne expliziten Header verwendet;
  auch ein ausdrücklich leerer Header verhindert den Rückfall auf eine Sitzung.
  Paketupgrades erhalten bestehende kundeneigene Regeln bytegenau und ergänzen
  die bedingte Weiterleitung einmalig.
- Produktion: HTTPS. Ein mitgesendeter Origin muss exakt zum konfigurierten
  Serverursprung passen. Keine fremde CORS-Freigabe.
- JSON-Schreiben: `Content-Type: application/json`, höchstens 1 MiB,
  Wurzelobjekt und objektförmiges `data`; gültiges UTF-8. Der Server akzeptiert
  ausschließlich bekannte Schlüssel. Optimistische `version` ist eine
  JSON-Ganzzahl, kein String. Neue Datensätze beginnen mit `version: 0`.
- Schreiben zusätzlich mit `Idempotency-Key`: 16–80 Buchstaben, Ziffern,
  Unterstriche oder Bindestriche. Dieselbe beabsichtigte Änderung mit demselben
  Schlüssel, Pfad, Methode und Inhalt wiederholen. Für eine neue Änderung einen
  neuen Schlüssel verwenden. Änderungen an der JSON-Objektschlüsselreihenfolge
  erzeugen keine andere Anfrage; Listenreihenfolgen bleiben bedeutsam.
- GET/HEAD verändern keine Geschäftsdaten; Sitzungs-/Zugangsverwendung kann
  aktualisiert werden. HEAD prüft dieselben Rechte und liefert keinen Body.
- GET-Listen: `page` ab 1, `limit` 1–50, Standard 25; maximal eine Million
  Datensätze überspringen. Gezählt und gefiltert wird vor LIMIT. Die Antwort
  enthält `page`, `limit`, `total`, `pages`, `has_more` und `request_id`.

Erfolg: `{"api_version":"1","data":...,"meta":{"request_id":"..."}}`.
Erstellung liefert HTTP 201, übriger Erfolg 200. Eine wiederholte bereits
bestätigte Änderung liefert ihre ursprüngliche sichere Antwort und
`Idempotency-Replayed: true`; keine zweite Änderung. Dieselbe Kennung mit anderem
Inhalt liefert 409. Geschäftsänderung und gespeichertes Ergebnis werden in einer
MySQL-Transaktion bestätigt. Innere native Dienste verwenden Savepoints;
eine aufrufende äußere Transaktion behält Commit- und Rollback-Verantwortung.

Fehler: `{"api_version":"1","error":{"code":"...","message":"...","request_id":"..."}}`.
Keine Stacktraces, SQL-Texte oder Schlüssel. 401 Anmeldung, 403 aktuelle Freigabe/
CSRF/Ursprung, 404 eigener Datensatz nicht verfügbar, 405 Methode außerhalb des
Vertrags, 409 Versions-/Anfragekonflikt, 413 Größenlimit, 415 Inhaltstyp und
422 ungültige Daten. Nicht bestätigte interne Ergebnisse liefern 503 mit
`operation_unconfirmed`: denselben ursprünglichen Idempotenzschlüssel verwenden,
keine neue Kennung für eine unsichere Wiederholung erzeugen.

## Ressourcen und Freigaben

| Ressource | Tatsächliche Operationen | Freigaben |
|---|---|---|
| `/pages`, `/pages/{id}` | Liste/Detail GET, POST anlegen, PUT Teiländerung, DELETE in den reversiblen Papierkorb | `pages.read`, `pages.write`; DELETE `pages.publish` |
| `/pages/{id}/publish`, `/unpublish`, `/trash` | POST mit Originalversion | `pages.publish` |
| `/pages/{id}/restore`, `/archive`, `/unarchive` | POST mit Originalversion | `pages.write` |
| `/pages/{id}/versions` | GET mit begrenzter, gezählter ursprünglicher Historie | `pages.read` |
| `/pages/query` | POST mit typisierten `selectors`, `order`, `page`, `limit`; keine Änderung | `pages.read` |
| `/fields`, `/fields/{key}` | GET, POST anlegen, PUT bestehende Definition ändern | `schema.read`, `schema.write` |
| `/templates`, `/templates/{key}` | GET einschließlich eingebauter Vorlagen, POST/PUT versionierte Konfiguration | `schema.read`, `schema.write` |
| `/users`, `/users/{id}` | begrenzte Kontometadaten GET, PUT nativen Aktiv-/Sperrstatus mit `version`, `active` | `users.read`, `users.write` |
| `/users/{id}/role` | PUT `version` der unabhängigen Rollenzuordnung und `role_id`; leerer String setzt ausdrücklich auf Basisrechte zurück | `users.write` und `roles.write` |
| `/roles`, `/roles/{id}` | GET, POST anlegen, PUT Teiländerung der ursprünglichen Vereinsrolle | `roles.read`, `roles.write` |
| `/permissions` | GET tatsächlich wirksame eigene Freigaben/Anmeldeart | `permissions.read` |
| `/files` | GET Bilder oder `kind=document` für Website-PDFs, POST Multipart-Mehrfachupload | `files.read`, `files.write` |
| `/files/{id}`, `/files/{id}/content` | GET Metadaten oder geschützter privater Originalstream; PDFs `?kind=document` | `files.read` |
| `/files/{id}/metadata`, `/files/{id}/archive` | PUT versionierte Bildmetadaten oder Archivzustand | `files.write` |
| `/pages/{id}/translations/{locale}` | GET, PUT Übersetzung mit `version`, `source_revision`, `data` | `pages.read`/`pages.write` plus tatsächliches separates Sprachrecht |
| `/pages/{id}/translations/{locale}/publish`, `/unpublish`, `/restore` | POST mit Übersetzungsversion; Restore zusätzlich `revision` | `pages.publish` bzw. `pages.write` und entsprechendes Sprachrecht |

`POST /pages` und Definitionen verwenden `{"version":0,"data":{...}}`.
PUT bearbeitet bekannte Eigenschaften teilweise und erhält ausgelassene
Originalwerte. Bei Seiten gelten echte Vorlagen-, Vererbungs-, Feld-,
Repeateritem-, Vier-Augen- und Veröffentlichungsrechte des Fachdienstes.
Passwortfeldwerte und -digests werden vollständig aus jeder Seiten-/Historien-
DTO entfernt. Nicht übermittelte Passwortfelder bleiben in der Originalfassung
erhalten. Versteckte Websitefelder und private Repeateritems bleiben für
berechtigte interne Redaktionsleser sichtbar; das ist keine öffentliche
Websiteprojektion. Item-ACLs erscheinen nur mit aktuellem Verwaltungsrecht.
Auch ein wiedergegebenes altes Idempotenzresultat wird mit den heutigen
Seiten-/ACL-Rechten erneut geprüft und gefiltert. Feldwerte benötigen sowohl
das heute geltende als auch das in ihrer ursprünglichen Fassung gespeicherte
Leserecht. Eine spätere Einschränkung greift sofort für Detail, Historie und
Replay; eine spätere Lockerung veröffentlicht keine zuvor geschützte Fassung.

Feld- und Vorlagen-DTOs enthalten reine Schemainformationen, keine
Passwortvorgaben. `item_definitions` ist abgeleitet und schreibgeschützt;
Repeater werden über die deklarative `fields`-Schlüsselliste konfiguriert.
Leserechte gelten bereits vor Feldzählung und Seitenauswahl sowie für einzelne
Definitionen, verschachtelte Feldbezeichnungen, Vergleichsbedingungen und
Vorlagenkontexte. Unlesbare Quellen geben keine Vergleichsliterale über ein
anderes erlaubtes Feld frei. Passwortdefinitionen beschreiben ihren Typ ohne
Passwortwerte oder Vorgaben.
Rollen liefern JSON-Booleans und die tatsächlich wirksame Website-Matrix;
eine Teiländerung darf aus einer Standardrolle keine frei erfundene Sonderrolle
machen. Letzte aktive Vereinsverwaltung und ursprüngliche Rollenrevisionen
bleiben durch die vorhandenen Fachdienste geschützt. Rollenänderungen und
Rollennamen verwenden in API und vorhandener Oberfläche dieselbe
Vereinsrollensperre und aktuelle gesperrte Originalzeilen. Die API nimmt diese
Sperre vor inneren Rechteprüfungen, damit parallele Rollenmitglieder keinen
gegenseitigen Sperrwechsel erzwingen. Kontopasswörter,
TOTP-Geheimnisse, Recoverycodes und Anmeldetoken gehören zu keiner Konto-DTO.
Kontoneuanlage erfolgt weiterhin über die persönlichen Einladungs-/Mitglieder-
abläufe; diese API bietet keinen anonymen Registrierungsweg und keine direkte
Passwort-/Identitätsinjektion. Löschbare freie Schemaklassen oder Roh-SQL sind
kein API-Vertrag.

## Dateien und Wiederaufnahme

`POST /files` verwendet echtes `multipart/form-data`, genau `file` oder
`files[]`, `kind=image|document` und optional `alt`. Höchstens zehn Dateien pro
Gruppe. Pfade aus JSON werden niemals als Upload geöffnet. Die HTTP-Schicht
prüft PHP-Uploadstatus und `is_uploaded_file`; die nativen Dienste prüfen Inhalt,
Format, Speicher und Quoten. HEIC-/RAW-/große Handyfotos verwenden die gemeinsame
automatische Fotovorbereitung. Rechteangaben bleiben entsprechend der
vorhandenen Vereinseinstellung optional. Die native Bildpipeline kann Bilder
normalisieren; `sha256` und `bytes` beziehen sich auf die tatsächlich
unveränderlich gespeicherte native Originalvariante. PDF-Bytes bleiben exakt.

Eine ungültige Datei verwirft die gesamte neue Gruppe; zugehörige neue Dateien
und Metadaten werden zurückgerollt. Ein bestätigter Upload wird mit identischem
Schlüssel/Fingerprints ohne erneute Speicherung wiedergegeben. Downloads
serialisieren keinen Serverpfad, prüfen heutige Rechte, Größe und SHA-256 und
streamen mit sicherem Dateinamen als Attachment. Die Versionierung allgemeiner
Dateiassets kann über einen separaten Adapter ergänzt werden; der aktuelle
v1-Dateivertrag bezeichnet ausdrücklich CMS-Bilder und Website-PDFs und bindet
keine öffentlichen Inhalte an einen veränderlichen Latest-Zeiger.

## Weitere Ressourcen und Erweiterungen

Die allgemeine Dateiablage verwendet `/api/v1/files/assets` und
`/api/v1/files/revisions`. Die bisherigen Bild- und PDF-Verträge bleiben erhalten.
Auch hier gelten aktuelle Rechte, Versionsbindung und die dokumentierte
Wiederaufnahme. Die genaue Ressourcenregistrierung liegt im
[API-Fachcode](../vereinsverwaltung/native/Api/).

Erweiterungen verwenden geprüfte Adapter und die bestehenden Fachdienste.
Beliebige PHP-Dateien, Methoden oder SQL-Anweisungen werden nicht aus API-Anfragen
aufgerufen. [Architektur](ARCHITECTURE.md) und
[lokale Prüfungen](LOKALE-PRUEFUNGEN-OHNE-GITHUB-CI.md) beschreiben den Entwicklungsweg.
