> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Bankanzeige und gespeicherte Zahlungen – Prüfstand 8. September 2026

## Tatsächlich vorhandene Funktionen

Die Bankanzeige und die Vereinsbuchhaltung sind zurzeit getrennt. `EnableBanking::accountData()` liest Salden und Umsatzseiten aus der gewählten Umgebung. Der Controller bietet Kontofreigabe und Anzeige, keinen Import in `finance_transactions`. FinTS ist als Bibliothek vorbereitet; der produktive Abruf mit Dialog-/TAN-Fortsetzung und eigener Produktregistrierung ist noch nicht angebunden.

Ein späterer Bankabruf ohne einen älteren Umsatz verändert deshalb keine bereits **manuell gespeicherte** Zahlung. Die Anzeige des Bankdienstes selbst ist hingegen kein dauerhaftes Bankarchiv. Es wäre falsch, für die bisher nur angezeigten API-Umsätze bereits einen vollständigen, wiederholbaren Archivimport zuzusagen.

Gespeicherte Kontoumsätze haben eigene beständige IDs. `finance_allocations` verbindet diese IDs mit Rechnungen; Betrag, Buchungsdatum, Konto, Herkunft und Auditereignisse bleiben in der Vereinsdatenbank erhalten. Das Entfernen einer Zuordnung löscht den Kontoumsatz nicht. Korrekturen tatsächlicher Geldflüsse verwenden Gegenbuchungen.

## Manuelle Zahlung und vorläufige Meldung

- **Tatsächliche Zahlung manuell erfassen:** erzeugt einen Kontoumsatz mit Herkunft `manual` und ordnet den eingegebenen Betrag der Rechnung zu. Auch Teilzahlungen und alte Zahlungsjahre sind möglich. Erforderlich sind Finanz-Schreibrecht, ein gültiges Konto, ein positives Datum/Betrag-Paar innerhalb des geführten Kontenzeitraums und eine unveränderte Rechnung. Der Betrag darf den offenen Rest nicht überschreiten. Der Kontoanfangsbestand muss zur nacherfassten Historie passen; schon darin enthaltene Zahlungen dürfen nicht nochmals gebucht werden.
- **Zahlung nur vorläufig melden:** erzeugt weder Umsatz noch Zahlungsnachweis. Der Status bleibt „Als bezahlt gemeldet – Kontozuordnung offen“, bis tatsächliche Umsätze vollständig zugeordnet sind.
- **Vorhandene Kontobuchung zuordnen:** verknüpft einen schon gespeicherten Umsatz; es entsteht kein zweiter Geldfluss. Teil-/Sammelzahlungen werden über mehrere Zuordnungen dargestellt.

Die Rechnung und zusätzliche Zahlungsnachweise können unter ihren Dokumenten abgelegt werden. Die Transaktionsansicht zeigt die Herkunft „Manuell erfasst“ ausdrücklich. Das macht die manuelle Buchung nachvollziehbar, ersetzt aber keinen externen Zahlungsnachweis.

## Noch notwendige Arbeit vor einem echten Bankimport

1. Freigegebene Providerkonten dauerhaft den Vereinskonten zuordnen: Verein, Umgebung, Bank/Provider und Kontoidentität prüfen. Sandbox-Umsätze dürfen keine Finanzbuchung erzeugen.
2. Gebuchte Bankumsätze mitsamt stabiler externer Referenz, Importlauf und Abrufzeit lokal speichern. Fehlende Umsätze einer späteren Antwort dürfen niemals als Löschauftrag gelten. Vorgemerkte und gebuchte Umsätze müssen voneinander unterschieden werden.
3. Eindeutigkeit pro Verein/Konto/Providerreferenz in der Datenbank absichern; wiederholte und überlappende Abrufe müssen dieselben lokalen IDs verwenden. Seitenfehler dürfen keinen teilweise als vollständig gemeldeten Import hinterlassen.
4. Referenzänderungen, echte Bankstornos, fehlende Referenzen und identische reale Doppelzahlungen ausdrücklich behandeln. Betrag und Datum allein sind keine eindeutige Identität.
5. Bereits manuell erfasste Zahlungen vor einer neuen Geldbuchung abgleichen. Eine sichere vorhandene Zahlung muss mit dem Importnachweis verknüpft werden, ohne einen zweiten Umsatz oder eine zweite Rechnungszuordnung zu erzeugen. Mehrdeutige Fälle benötigen eine sichtbare manuelle Entscheidung. Aufgehobene Zuordnungen dürfen nicht automatisch wiederhergestellt werden.
6. Export, Steuerprüfung, Audit und gespeicherte Originalbelege müssen weiterhin auf dieselben lokalen Zahlungs- und Zuordnungs-IDs zeigen. Importhistorie und Korrekturen dürfen diese Nachweise nicht still überschreiben.

Dieser Importweg ist noch nicht implementiert. Die vorhandene `finance_transactions.source` erlaubt derzeit nur `manual`, `legacy` und `reversal`; Providerreferenzen und Importläufe sind noch nicht als vollständiges Datenmodell vorhanden.

## Nachweise der aktuellen Prüfung

- `tests/manual-payment-persistence.php`: 24 tatsächliche Dateidatenbank-Prüfungen mit Datei schließen/neu öffnen; Rechnung von 2019, Teilzahlungen 40 + 60 EUR, Herkunft, gleichbleibende IDs, Audit, manuelle Transaktion von 2022, Zuordnung ohne neuen Umsatz, Überbetrag/Nullbetrag/ungültige Beträge, fremder Verein, fehlende Rechte, zukünftiges Datum und Datum vor Kontoeröffnung sowie Rollback bei Auditfehler.
- `tests/manual-payment-browser.cjs` mit `tests/support/manual-payment-fixture.php`: 12 echte lokale Edge-Prüfungen, synthetischer eigener Verein. Rechnungseingang → Zahlungserfassung → POST → Neuladen, leeres Pflichtfeld, serverseitiger Überbetrag, Teilzahlungen, Schutz gegen veralteten Doppel-POST, Herkunft und Mobilansicht bei 390 Pixeln.
- Bestehende Finanz-, Zuordnungs-, Formularzustands- und Zahlungsmeldungstests werden zusätzlich im Release geprüft.

Die Prüfungen haben keine Bank kontaktiert, keine produktiven Finanzdaten geändert und keine Mail oder Zahlung versandt. Sie bestätigen die gespeicherte manuelle Buchhaltung; sie sind kein Nachweis einer noch nicht vorhandenen Bankimportfunktion.
