# Newsletter und Datenschutz

## Double-Opt-in

1. Formular nimmt E-Mail, optionale Anrede/Name, gewünschte Themen, Datenschutzhinweis-Version und Einwilligung entgegen.
2. Server speichert normalisierte E-Mail, zufälliges DOI-Token nur gehasht, Zeitpunkt, IP gekürzt/gehasht, User-Agent gekürzt, Quelle und Textversion.
3. Bestätigungsmail enthält einen zeitlich begrenzten Einmal-Link.
4. Erst der Klick setzt `confirmed_at`; unbestätigte Datensätze werden nach 30 Tagen gelöscht.
5. Jede Mail enthält einen individuellen Abmeldelink. Abmeldung wirkt sofort und wird als minimaler Sperrnachweis gespeichert.

## Trennung Eventbrite/Newsletter

Eine Eventbrite-Registrierung ist ausschließlich Ticket-/Teilnahmeabwicklung und begründet keine Newsletter-Einwilligung. Newsletter-Werbung erfordert einen separaten, freiwilligen DOI-Prozess. Keine vorausgewählten Checkboxen, keine Übernahme von Teilnehmerlisten ohne passende Rechtsgrundlage.

## Einwilligungsnachweis

Zu speichern sind Empfänger-ID, Zeitpunkt von Anmeldung/Bestätigung/Abmeldung, Formularquelle, Einwilligungstext-Version, technische Nachweise und Versandhistorie. Zugriff nur für Newsletter-Verantwortliche und Administratoren; Exporte werden protokolliert.

## Exporte

- `CSV allgemein`: UTF-8, E-Mail, Name, Status, Themen, bestätigtes Datum
- `WISO`: feldgemappte CSV nach vorherigem Testimport
- `Thunderbird`: CSV mit Spalten `Primary Email`, `First Name`, `Last Name`, `Categories`

Löschfristen, Auftragsverarbeitung, Datenschutzerklärung und Verzeichnis der Verarbeitungstätigkeiten müssen vor Produktivbetrieb rechtlich final geprüft werden.