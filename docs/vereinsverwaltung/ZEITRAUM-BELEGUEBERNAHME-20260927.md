# Rechnungen und Zahlungen in Abschlusszeiträumen

Stand 27.09.2026. Entwicklungsstand, keine allgemeine Bilanzfreigabe.

Migration 103 bindet eine Rechnung oder einen Bankumsatz genau einem
Abschlusszeitraum zu. Unveränderliche Ereignisse enthalten Originalnachweis,
Kontierung, Eingaben und tatsächliche Buchungszeilen. Änderungen benötigen
eine neue geprüfte Vorschau, einen Berichtigungsgrund und Gegenbuchungen;
Original und Quellenzuordnung werden nicht überschrieben. Übernahmen in
Kalenderjournal und Zeitraumjournal dürfen nicht doppelt erfolgen.

Die Übernahme liest die vorhandenen Rechnungspositionen und Vorsteuerbehandlung.
Bei jahresübergreifenden Zeiträumen bleibt der Belegjahrgang erhalten; abweichende
Kontenbedeutungen und Steuerschlüssel werden nicht ungeprüft übersetzt. Private
Vorleistungen brauchen ihr tatsächliches Zahlungsdatum. Die Erstattung und
Teil-/Restzahlungen gleichen Verbindlichkeiten aus, ohne den Aufwand nochmals
zu buchen. Zahlungen im Folgezeitraum greifen auf die geprüfte Originalrechnung
und den unveränderten Vortrag zurück.

Die Oberfläche bietet Auswahl, Datumsprüfung und bestätigte Übernahme sowie
Berichtigung bereits gebuchter Quellen. Das Mitglied und sein Zahlungsdatum
bleiben auch bei einer noch unvollständigen Vorschau sichtbar. Originalbeträge
und Buchungskonten stammen nicht aus manipulierbaren Formularwerten.

Vor Festschreibung werden fehlende Belege und der Banksaldo am tatsächlichen
Enddatum geprüft. Nachträgliche Quellenänderungen blockieren Export/Folgeabschluss.
Beiträge, Spenden, Sachspenden und Verzicht werden nicht als normale Rechnungen
fehlgebucht. Noch nicht angebundene Anlagen-/Abschreibungsvorgänge bleiben gesperrt.
Die Kandidatenansicht zeigt höchstens 200 Rechnungen und 200 Umsätze; Überschreitung
verhindert eine behauptete Vollständigkeit. Noch keine vollständige Abgrenzungs-
automatik für außerhalb des Zeitraums datierte Rechnungen.

## Prüfstand

- 43 neue synthetische MySQL-/Quellen-/HTML-Prüfungen erfolgreich.
- Bestehende Zeitraumverwaltung, Kalenderquellen, Belegkorrekturen,
  Steuerabgabeworkflow und Laufzeitbindung ebenfalls erfolgreich.
- Geprüft: Mehrjahresdatum, private Zahlung/Erstattung, Teilzahlung, unveränderliche
  Berichtigung, abhängiger Bankbeleg, Folgeperiode, Versionsschutz, Vereinsgrenzen,
  fehlende Berechtigungen und Produktionssperre. Fehler beim Anlegen der Zuordnung
  rollen auch Buchungen, Kontenergänzung und Audit zurück.
- Der alte Korrekturtest hinterließ absichtlich gelöschte Historie in der gemeinsam
  genutzten Testdatenbank. Die Manipulationen laufen jetzt mit garantiertem Rollback.
  Optionale Backup-Rundläufe werden im dauerhaft synthetischen Betrieb ausgelassen.
- Abschließender Lauf: `audit/ebilanz-mysql-host-20260918-78be9f3a79178648.json`;
  Quellpaket `bcc2c27d966bc3543ec04a47ebb01aeae2b423d8ca4a677287f1940bf8959f45`.
  Alle sechs Gruppen bestanden. Kein Zugriff auf die Produktivdatenbank, keine
  echte Mail/Bank-/Steuerübermittlung, geschützte Produktivdateien unverändert.

Das ist keine abschließende Konten-/Anlagensonderfallabdeckung, kein amtlicher
periodisierter XBRL-Export und kein allgemeiner Bedienbarkeitstest. Weiter offen:
Abschreibungs-/Bereichs-/Korrekturintegration der Zeiträume, Sonderfälle,
Gesamtabnahme und Veröffentlichung. Kulturhus bleibt EÜR.
