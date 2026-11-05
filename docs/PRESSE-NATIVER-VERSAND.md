# Nativer Presseversand

Pressemitteilungen lassen sich über das konfigurierte Pressepostfach tatsächlich
versenden. Die Webseite speichert den Auftrag; der private Versandworker verarbeitet
ihn zum gewählten Zeitpunkt. Entwurf, aktuelle Freigabe und tatsächliche
Empfängerzustellungen bleiben getrennte gespeicherte Originale.

Im gemeinsamen Manager unter Einstellungen die Versandzuordnung **Presse** mit
dem gewünschten Postfach einrichten. Die eigenständige Redaktion verwendet den
explizit eingerichteten zentralen Mailzugang. Die Versandplanung prüft die lokale
Einrichtung, ohne eine SMTP-Verbindung zu öffnen. Das Anlegen und Bearbeiten von
Entwürfen bleibt unabhängig vom Postfach möglich.

Mitteilung schreiben, eigene aktive Pressekontakte auswählen, speichern und die
genaue Fassung freigeben. Unter **Freigabe & Planung** den Versandzeitpunkt in
Europe/Berlin wählen. **Zustellverlauf öffnen** zeigt sechs ursprüngliche Empfänger
pro Seite, Zustellstatus, ursprüngliche Mitteilung und Fassung. Details beginnen
eingeklappt. Änderungen an einem Kontakt oder Text ersetzen keine frühere
Zustellhistorie. Der gemeinsame Kalender zeigt den tatsächlichen Versandstand
einmal pro ursprünglicher Planung und öffnet deren Verlauf.

Für einen getrennten Presse-Zeitplan den bereits installierten privaten Worker
verwenden:

```sh
php /ABSOLUTES_INSTALLATIONSZIEL/app/vereinsverwaltung/bin/native-delivery-worker.php \
  --config=/ABSOLUTES_INSTALLATIONSZIEL/private/worker.php --press --send --limit=50
```

Ohne `--send` wird keine Zustellung verarbeitet. Der getrennte Modus verändert
keine Newsletter-, Mitglieder-, Rechnungs-, Bank-, Social- oder anderen
Originalaufträge. Der vorhandene allgemeine Mailworker verarbeitet Presse ebenfalls
mit dem tatsächlich zugeordneten Versandzweck. HTTP kann keinen Worker aktivieren.
Nach einer Gesamtwiederherstellung gilt weiterhin die ausdrückliche Betriebssperre.

**Versandt** bedeutet, dass der SMTP-Versand angenommen wurde; es ist kein
Empfangs- oder Lesebeleg. Nach einem unklaren Ergebnis oder einem unterbrochenen
Versand bleibt **Ergebnis offen**. Es gibt keine automatische Wiederholung.
Ältere unterbrochene dauerhafte Versandschritte werden nur zur Prüfung markiert.
Eine spätere Freigabe desselben bereits versandten Originals löst ebenfalls
keine zweite Mail aus. Eine neue Mitteilung erhält eine eigene Kennung.

Der Worker prüft aktuelle Veröffentlichungsrechte, ursprüngliche Freigabe,
Vereinsbindung, Kontaktversion, Adresse und Aktivität vor dem Transport erneut.
Deaktivierung, veränderte Adresse, Widerruf oder veraltete Freigabe stoppen den
betroffenen Auftrag. Freigegebene Bildquellen werden auf ihren genauen aktuellen
Rechte- und Quellenstand geprüft. Die originalen privaten Bildbytes werden einmal
mit der ganzen Mail verschlüsselt gespeichert und als passende eingebettete
MIME-Bilder verwendet. Einzelaufträge duplizieren diese Bytes nicht.
Presse-Publisher bekommen dadurch keine allgemeinen Website-/Mediatheksrechte.

Kommunikation **13** ergänzt ausschließlich die leere eigene Tabelle
`native_press_messages`. Bestehende Kontakte, Freigaben, Jobs, private Schlüssel
und Inhalte werden nicht importiert oder ersetzt. Das Original wird nach einer
angenommenen Speicherung unverändert wiederverwendet, auch wenn deren Bestätigung
verloren ging. Nach Formularfehlern bleiben menschliches Datum und ursprüngliche
Versionsnummer erhalten; eine neue Version wird nicht still in das alte Formular
eingesetzt.

62 lokale Fachverträge umfassen echte MySQL-Transaktionen, tatsächlichen
angenommenen COMMIT mit verlorener Bestätigung und einen wirklich beendeten
Versandworker. Der private CLI-Pfad erreicht ausschließlich einen übergebenen
lokalen MIME-Prüfempfänger. Tatsächliche Browser prüfen beide Controller bei
320/390/820 Pixeln, doppelter Schrift, Tastatur und mit/ohne JavaScript mit
246 Verträgen und 16 ausdrücklichen Formularanfragen; vier
menschliche Entwürfe werden gespeichert, freigegeben und mit exakt zwei
Empfängern geplant. Es werden keine realen Mails verschickt.

Alle 171 nativen Suiten und acht Kalendergruppen bestehen am vollständigen
Pressestand. Tatsächliche Neuinstallation/Schema-Aufrüstung, HTTP-Installer
und verschlüsselte Wiederherstellung bestehen mit 222/52/142 Verträgen.
Die Aufrüstung ergänzt ausschließlich eine leere eigene Tabelle, erhält alle
früheren gefüllten Originalzeilen und versendet nichts rückwirkend. Die
Wiederherstellung erhält genaue verschlüsselte Mail, Empfängerauftrag und
ursprünglichen Freigabebezug; der behaltene Schlüssel entschlüsselt unabhängig
dieselben MIME-Quelldaten. Auslieferungsinventar und Paketbytes sind geprüft.

Weitere benötigte Pressevorlagen, Anhänge, Kontakt-/Massenaktionsvarianten und
übrige Rollen-/UI-Abläufe bleiben im Register. Diese Grundfunktion ist keine
Abnahme des ganzen K-30-Bereichs oder einer realen SMTP-Verbindung.
