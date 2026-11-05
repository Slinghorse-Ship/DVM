# Newsletterperioden

- `Schedule`: reine Berlin-Kalenderregeln, Vorbereitung, Monats-/Quartalsgrenzen
  und Versandfenster; keine Datenbank und kein Transport.
- `Plans`: aktuelle Berechtigung, versionierter Gesamtplan, einzelne Periodenpause
  und Zugang zum ursprünglichen Editionskontext.
- `Planner`: genau einen unfreigegebenen Entwurf aus veröffentlichten CMS-Fassungen
  vorbereiten; keine Mailaufträge und keine implizite Veröffentlichung.
- `Announcements`: nach bestätigtem Transport die tatsächlich enthaltenen
  ursprünglichen Veranstaltungshinweise festhalten. Entwürfe, Tests, entfernte
  Bausteine und unklare Ergebnisse zählen nicht als Ankündigung.
- `Paused`, `NotDue`, `Expired`: fachliche Gründe vor dem Transport. Pausen und
  noch nicht begonnene Fenster erhalten denselben Auftrag; abgelaufene Fenster
  beenden ihn. Unklare SMTP-Ausgänge werden weiterhin nicht wiederholt.

`Campaigns` bleibt der Newsletter-Einstieg. `Delivery/Outbox` hält Autorität,
Einwilligung, Auftrag und Periodenzustand bis zum Transportende konsistent.
`src/Events/Calendar/NewsletterPeriodSource` zeigt dieselben Quellen ohne
zusätzliche Kalenderdatensätze. Bereits bestehende separate Monats-/Quartalspläne
bleiben lesbar und pausierbar; der Kalenderplan wird ausdrücklich eingerichtet.
