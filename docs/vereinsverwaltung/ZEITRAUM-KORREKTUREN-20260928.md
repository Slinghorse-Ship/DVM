# Korrekturabschlüsse für tatsächliche Abschlusszeiträume

Stand 28.09.2026. Entwicklungsstand, kein allgemeiner Bilanzrelease.

## Umgesetzt

Der Korrekturablauf verwendet die tatsächliche Zeitraum-ID mit Anfang und Ende,
nicht nur das Kalenderjahr. Auch aufeinanderfolgende Abschlüsse mit gleichem
Endjahr bleiben getrennt. Korrekturbuchungen außerhalb des betroffenen Zeitraums
werden abgewiesen.

Die Finanzbearbeitung bereitet einen begründeten Entwurf vor. Ein berechtigter
Vorstand bestätigt den nochmals geprüften Stand; verworfene Entwürfe verändern
keine Abschlusswerte. Ursprüngliche Abschluss- und Journalereignisse bleiben
unverändert. Bestätigungen sind an Version, geprüfte Werte und Vorzeitraum gebunden.
Ein inzwischen korrigierter Vorgänger macht einen alten Entwurf ungültig.

Ein offener Folgezeitraum kann seine Anfangsbestände mit Vorstandsberechtigung
aus dem berichtigten Vorgänger übernehmen, ohne dessen Aufwand nochmals zu
buchen. Ein bereits abgeschlossener Folgezeitraum benötigt eine getrennte
Vortragskorrektur. Der Verlauf wird beim Lesen aus den eingefrorenen Nachweisen
neu berechnet und auf Übereinstimmung geprüft. Die Arbeitsunterlagen enthalten
Korrektur- und Freigabehistorie, behaupten aber keine amtliche Übermittlungsfähigkeit.

Die Oberfläche zeigt die Veränderung der Kontenwerte sowie Begründung und
Nachweise. Speichern, Vorbereiten und Bestätigen bleiben rollenabhängig.
Die bestehende produktive Sperre der noch unfertigen Zeitraum-Bilanz bleibt aktiv.

## Prüfungen

- 51 synthetische MySQL-Prüfungen und 75 mitausgeführte Zeitraumjournal-
  Prüfungen erfolgreich: `audit/ebilanz-mysql-host-20260918-58e8bbb02118bb37.json`.
- Enthalten sind Rollen-/Mandantengrenzen, Centwerte, abweichende Zeiträume,
  unveränderliche Originalereignisse, offene/geschlossene Folgezeiträume,
  veraltete Bestätigungen, doppelte Formularübermittlung und Arbeitsunterlagen.
- Bestehende Korrektur-, Abschreibungs-, Zeitraum-, Steuerdatei- und
  Laufzeitbindungsgruppen bestanden im vorangegangenen breiteren Testlauf
  `audit/ebilanz-mysql-host-20260918-eb9b658861c979ed.json`.
- Keine produktive Datenbankverbindung, Migration oder Veröffentlichung dieser
  Bilanzänderungen; keine Bank-, Mail- oder ELSTER-Übermittlung. Testdatenbank
  dauerhaft synthetisch genutzt, ohne erneute Sicherung/Rücksicherung.

Die Überleitung aus einem abgeschlossenen Kalenderabschluss ist jetzt angebunden:
Originalhash und ursprüngliche Ereignisse bleiben erhalten; dessen Korrekturen
werden genau einmal in den tatsächlichen Folgezeitraum vorgetragen. Veraltete
Entwürfe und entzogene Freigaben werden abgewiesen.

## Weiterhin offen

Anlagenkorrekturen müssen an berichtigte Originalpläne gebunden
werden und dürfen nicht als freie Ersatzbuchung erfolgen. Periodisierte
Bereichs-/Steuerüberleitung und amtlicher Steuerexport sind noch vollständig
anzubinden. Vollständige Konten-/Anlagensonderfälle, Anlagen-Sphärenwechsel,
Gesamtabnahme und allgemeine Veröffentlichung bleiben offen.

**Kulturhus bleibt EÜR.** Dieser Entwicklungsschritt erfüllt nicht die Bedingung
für die nachgelagerte Gesamtprüfung des Vereinsmanagers.
