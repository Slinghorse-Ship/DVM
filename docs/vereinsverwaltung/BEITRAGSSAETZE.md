> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Zentrale Mitgliedsbeiträge

Unter Einstellungen → Beiträge & Spenden stehen die verknüpften Tarife für
Einzel- und Familienmitgliedschaft. Migration 030 übernimmt die bisherigen
48 bzw. 72 Euro jährlich in den Katalog, ohne vorhandene Katalogeinträge,
Mitgliedsanträge oder Forderungen zu überschreiben.

Öffentliche Beitragskarten, Antrag, Aufnahmezuordnung, Mitgliederprofil und
Jahreslauf verwenden diese Tarife. Neue Forderungen speichern Betrag und
Tarifversion. Bestehende Forderungen sowie archivierte Anträge behalten ihren
Stand. Tarifänderungen gelten sofort für neu erzeugte Forderungen, auch bei
nachträglicher Zuordnung eines früheren Beitragsjahrs. Eine zeitlich geplante
Beitragsordnung ist nicht Teil dieser Änderung.

Änderungen erfordern Finanz-Schreibrechte. Die beiden verknüpften Tarife bleiben
aktive, positive Festbeträge pro aktuellem Jahr ohne Umsatzsteuer. Andere
Katalogeinträge bleiben unabhängig. Bei Betragsänderungen wird die Version der
Antragsbedingungen erhöht: bereits geöffnete Formulare und noch unbestätigte
Anträge müssen mit den neuen Bedingungen erneut eingereicht werden.

Prüfung: 27 neue Integrationsprüfungen für Katalog, öffentliche Formulare,
Antrags-PDF, Tarifänderungen, historische Beträge, Familienabrechnung,
Berechtigungen und Migration eines vorhandenen Vereins. Zusätzlich 299
bestehende Prüfungen für Grundlagen, Einstellungen, Anträge, Bestätigung,
Familien und Beitragsläufe erfolgreich. Kein echter Mailversand oder Bankzugriff.

Hinweistexte unterstützen `{{EINZELBEITRAG}}` und `{{FAMILIENBEITRAG}}`.
Der bisherige Standardhinweis wird beim Lesen in diese Platzhalter überführt;
Zustimmung und Antrags-PDF speichern den tatsächlich angezeigten Text.

## Veröffentlichung am 6. September 2026

`3ef5364` und `8b096a8` sind auf Netcup veröffentlicht. Vorherige Live-Dateien
wurden mit Git verglichen, gesichert, vor Aktivierung mit PHP geprüft und nach
Upload bytegenau kontrolliert. Migration 030 wurde auf der bestehenden privaten
Datenbank nach einem Dateidatenbank-Snapshot angewendet; zwei verknüpfte Tarife wurden
angelegt. Sicherung: `/tmp/kulturhus-verein/rollback-contribution-rates/`.
Der ergänzte Hinweistext hat eine eigene Quellcodesicherung unter
`/tmp/kulturhus-verein/rollback-contribution-notice/`.

Live geprüft: öffentliche Beitragskarten, Anmeldeformular und Zustimmung zeigen
die zentralen Beträge; 32 HTTP-Prüfungen für öffentliche Seiten, Gastzugriffe und
private Pfade bestehen. Die angemeldete Einstellungsansicht wurde in dieser
Veröffentlichung nicht erneut im Browser bedient. Es wurden keine Live-Tarife
testweise verändert, keine Nachrichten versendet und keine Bankaufrufe ausgeführt.
