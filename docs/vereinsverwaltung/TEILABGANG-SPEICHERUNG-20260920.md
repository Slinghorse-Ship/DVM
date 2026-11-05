# Teilabgang: gespeicherte Prüfstände und Folgejahresvorschau

Entwicklungsstand 20.09.2026. **Nicht veröffentlicht und keine Buchungsfreigabe.**
Auf den geprüften Rechenkern und die Belegprüfung folgt jetzt die dauerhafte
Speicherung der Vorbereitung. Ein gespeicherter Prüfstand ist ausdrücklich noch
kein Anlagenabgang, keine festgestellte Jahresabschreibung und kein Steuerexport.

## Umgesetzt

- `AssetPartialDisposalRecords::save`: Prüfvorschau unter derselben Vereinssperre
  erneut prüfen und mit Audit-Eintrag in einer gemeinsamen Transaktion speichern.
  Die ausdrückliche Bestätigung, der erwartete Prüfhash und die vorherige
  Revisionsnummer sind erforderlich.
- Wiederholungen desselben Vorgangs liefern denselben Datensatz. Eine geänderte
  Eingabe oder ein anderer Benutzer darf die Vorgangskennung nicht wiederverwenden.
  Veraltete Revisionsnummern und doppelte identische Prüfstände werden abgewiesen.
- Neue Prüfstände ergänzen den Verlauf. Frühere Prüfstände werden weder geändert
  noch gelöscht. Ihre Hash-Kette bindet Verein, Gegenstand, Jahr, Bearbeiter,
  Belegversionen, Berechnung und Vorgangskennung.
- `get` prüft den gesamten gespeicherten Verlauf und die aktuellen Leserechte auf
  die Nachweise. Ein älterer Stand bleibt lesbar, wird aber als nicht mehr jüngster
  Stand ausgewiesen. Lesen ist keine Bestätigung der aktuellen fachlichen Gültigkeit.
- `forecast` verwendet nur den jüngsten Stand und vergleicht dessen Belege,
  Anschaffungs-/Abschreibungsplan und Journalversion erneut mit den Quelldaten.
  Anschließend werden die Buchwerte ab dem Abgangsjahr berechnet, ohne Datensätze
  zu verändern. Getrennte Steuerwerte werden dabei nicht als bestätigt ausgegeben.
- Schemaschritt 97 ergänzt `asset_partial_reviews` mit Fremdschlüsseln,
  Eindeutigkeit für Revision/Vorgangskennung und nativen UPDATE-/DELETE-Sperren.
  Keine automatische DDL-Ausführung im neuen Dienst und keine Veröffentlichung
  dieser Migration. Der neue Dienst ist weiterhin nicht im Bootstrap oder in
  öffentlichen Routen eingebunden.

`AssetPartialDisposalReview` unterstützt dafür zusätzlich eine Prüfung innerhalb
der Transaktion des Aufrufers. Dieser Aufruf übernimmt niemals Commit/Rollback.
Die bisherige eigenständige Vorschau lehnt verschachtelte Vorgänge weiterhin ab.
Alle bisherigen 133 Prüfungen dieser Vorschau bestehen nach der Umstellung.

## Abnahme

Serverbericht: `audit/ebilanz-mysql-host-20260918-1632d2aabdbbb071.json`.
PHP 8.3.33, MySQL 8.4.11, ausschließlich synthetische Testdaten.

Alle fünf Testsuiten bestanden:

| Suite | Prüfungen |
| --- | ---: |
| Speicherung und Folgejahresvorschau | 81 |
| Bisherige Belegprüfung | 133 |
| Schema-Wiederherstellung | 5 |
| Gemischte Abgänge und getrennte Steuerwerte | 105 |
| Rechnungen und Anlagenverkauf | 140 |

Unter anderem geprüft: normaler Kassenwart, Leserecht, fehlendes Inventarrecht,
fremder Verein, entzogener Zugang, Wiederholung auf einer zweiten Verbindung,
veraltete Revision, künstlicher Audit-Fehler mit vollständigem Rollback,
beschädigte Vorgängerversion, unzulässig umgedeutete Freigabe, zurückgezogener
Beleg und geänderte Journalversion. Der Wiederholungstest mit zweiter Verbindung
ist kein Nachweis eines gleichzeitigen Last-/Stresstests.

Die verschlüsselte Sicherung/Wiederherstellung wurde auch mit gespeicherten
Prüfständen getestet: alle Daten, Tabellendefinitionen und Trigger identisch;
die wiederhergestellte Vorbereitung liefert identische Folgejahreswerte.

Die vor dem Lauf vorhandene sekundäre Datenbank wurde danach vollständig
wiederhergestellt (150 Tabellen / 1.393 Datensätze). Temporäre Testdateien sind
entfernt. Produktion wurde nicht angebunden oder verändert; keine Bankaufträge,
Mails oder ELSTER-Sendungen. Die Versandfälle der vorhandenen Steuerregression
benutzen ausschließlich Offline-Transportfixtures.

Lokal erneut bestanden: PHP-Syntaxprüfungen, 6.941 Rechenkern-Prüfungen und sechs
Python-Testmethoden mit 600 unabhängigen Rechenfällen.

## Nächste Integration und Grenzen

Noch fehlen die **wirksame** Ereignisablage und die gemeinsame Übernahme in
`asset_book_years`, `BalanceSources`, Journal, Anlagenverzeichnis und getrennte
Steuerwerte. Vor deren Freigabe müssen insbesondere die Abgangsbuchung und alle
Folgejahre mit den gespeicherten Nachweisen und der Exportvalidierung verbunden
werden. Die öffentliche Bedienoberfläche fehlt ebenfalls noch.

Die hier gespeicherten Vorbereitungen verändern deshalb bewusst keine
Anlagen-/Jahresstände und sperren eine andere, reguläre Jahresbuchung nicht.
Eine zwischenzeitliche Jahresbuchung macht die Vorbereitung für eine erneute
Vorschau ungültig. Sie wird niemals automatisch rückwirkend angewendet.
`postable=false` und `exportable=false` bleiben erhalten. Weiterhin nur ein erster
linearer Teilabgang ohne Erlös als berechenbarer Fall; keine Vollständigkeits- oder
amtliche E-Bilanz-Freigabe.
