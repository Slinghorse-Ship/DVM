# Öffentlicher Mitgliedsantrag und responsive Websiteübersicht

Der öffentliche Mitgliedsantrag eröffnet seine private Formularsitzung erst,
wenn tatsächlich die veröffentlichte Antragsseite aufgelöst wurde. Dadurch
funktioniert der erste Besuch ohne vorherige Anmeldung. HEAD-Anfragen und
gewöhnliche öffentliche Seiten bleiben sitzungslos. POST-Anfragen benötigen
weiterhin den zur persönlichen Formularsitzung gehörenden CSRF-Nachweis.

Die Websiteübersicht verwendet in beiden Verwaltungszugängen dieselbe
zusätzliche lokale Gestaltung. Karten richten sich nach dem tatsächlich
verfügbaren Inhaltsraum; die Liste der letzten Änderungen wechselt bei wenig
Platz in eine Spalte. Vergrößerte Schrift kann vollständig umbrechen, und
interaktive Verweise behalten mindestens 44 Pixel Höhe. Auf kleinen Inhaltsflächen
werden die fünf Bereiche zu kompakten Titel-/Anzahlzeilen. Lange Titelvorschauen
zeigen höchstens drei Zeilen; vollständiger Originaltext und Linkziel bleiben erhalten. Es wird kein Überlauf
versteckt. Die CSP erlaubt lokale data-Bildquellen für die mitgelieferten
Bootstrap-Symbole; Skript- und Verbindungsregeln werden dafür nicht erweitert.

Die gezielte Abnahme umfasst 64 tatsächliche Browseransichten: beide
Verwaltungszugänge, mit und ohne JavaScript, 320/390/820/1440 Pixel und
16/32-Pixel-Grundschrift, jeweils Übersicht und öffentlicher Mitgliedsantrag.
über 200 Browserbedingungen, 56 vorhandene Workspacebedingungen und acht echte
Formular-HTTP-Bedingungen bestehen. Falsche/fehlende CSRF-Werte werden
abgewiesen; ein gültiges Formular mit fehlenden Pflichtangaben bleibt zur
Korrektur erhalten und erzeugt noch keinen Antrag. 428 vorher vorhandene
Originaltabellen und drei Schlüsseldateien bleiben exakt; externe Aktionen
finden nicht statt. Vorhandene öffentliche Router-, Alias-, Mitglieds- und
Produktionsruntime-Prüfungen werden zusätzlich getrennt ausgeführt.

Prüfwege: `native-website-workspace` mit
`DVM_WEBSITE_WORKSPACE_BROWSER=1`, `native-public-router`,
`native-public-aliases`, `native-membership-family`, `production-runtime`.
Die gemeinsame Gesamtprüfung aller K-Punkte bleibt eine eigene Abnahme.
