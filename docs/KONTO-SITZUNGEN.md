# Eigene aktive Anmeldungen

Unter `/account?section=sessions` stehen höchstens sechs eigene aktive
Anmeldungen je Seite. Die aktuelle Anmeldung erscheint zuerst und hat keinen
Knopf für eine Einzelabmeldung. Beginn, letzte Nutzung, tatsächliche Frist und
gewählte Anmeldedauer stammen aus dem persönlichen gespeicherten Original.
Die Zeiten werden in Europe/Berlin angezeigt. Es werden keine Gerätenamen
aus ungespeicherten Angaben abgeleitet.

Die native Seitenwahl steht oben und unten. „Diese Anmeldung beenden“ beendet
genau die gewählte andere eigene Anmeldung. Die aktuelle Anmeldung bleibt
bestehen; zum Abmelden dieses Geräts gibt es den eigenen vorhandenen Knopf.
Das Beenden aller eigenen Sitzungen bleibt separat verfügbar.

Ein beendeter Zugang verliert seine Rechte sofort. Offene Sicherheitsmails,
die ausdrücklich zu dieser Sitzung gehören, werden gesperrt und ihr geheimer
Inhalt entfernt. Andere Anmeldungen und deren eigene Nachweise bleiben erhalten.
Ein wiederholter Aufruf meldet den abgeschlossenen Stand; er schreibt keinen
neuen historischen Zeitstempel. Fremde Konten können auch mit bekanntem
Sitzungsbezeichner weder angezeigt noch beendet werden.

Die Formulare funktionieren mit Touch, Tastatur und ohne JavaScript.
Nach einer Aktion bleibt die Sitzungsgruppe offen; die native Meldung erhält
unmittelbar den Fokus. Ein abgewiesener Formularschutz beendet keine Anmeldung.
Die Liste enthält ausschließlich Metadaten und nicht die Anmeldetokens.

## Nachweise

`native-account-sessions` prüft 180 Fachverträge über 65 eigene Originale und
elf Seiten: genaue Reihenfolge/Identitäten, letzter vollständiger Ausschnitt,
Familienpostfach mit getrennten Konten, fremde und aktuelle Anmeldungen,
Formularschutz, Tokenbindung, Codebindung, Ablauf, Wiederholung und äußeres
Transaktionsrollback. Alle übrigen ursprünglichen Zeilen und Gesamtzeilenzahlen
bleiben bytegleich. Ein Listenbezeichner kann nicht als Anmeldetoken dienen.

`DVM_ACCOUNT_SESSIONS_BROWSER=1` mit `native-account-panels` führt den tatsächlichen
nativen HTTP-Router über zwei neue eigene Familienkonten aus: je dreizehn
vorbereitete und eine echte Browseranmeldung. 51 Browserverträge bei
320/390/820 Pixeln und 200 % Zoom, mit und ohne JavaScript; vollständige letzte
Seite, falscher Formularschutz, genau eine Einzelabmeldung je Konto und
Wiederholung. 194 abschließende HTTP-/Datenbankverträge bestätigen jede andere
eigene Sitzung bytegleich, unveränderte frühere Konten/Rechte/Veröffentlichungen
sowie ursprünglichen Schlüssel und Bildbytes. Keine reale Mail.

Die separate ursprüngliche MFA-Browsergruppe besteht erneut mit 167 Konto-/158
Datenbankverträgen. Dreizehn betroffene native Suiten bestehen, insgesamt 120
registriert; CMS 14 ohne neue DDL. Weitere Kontofunktionen und echte Geräteabnahme
bleiben im Umsetzungsregister offen.
