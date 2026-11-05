# Originalgebundene persönliche StadtLand.Funk-Veröffentlichung

Dieser Stand verbindet den nativen Veröffentlichungsdienst mit einem eigenen
CLI-Modus und der mobilen Planung. K-29 und die Gesamtabnahme bleiben teilweise umgesetzt.
Der bestehende reale StadtLand.Funk-Termin wird weder verändert noch erneut angelegt.

Eine bewusste Zielbestätigung plant eine freigegebene Postfassung mit genau einem
ursprünglichen Bild. Quelle ist die ausgewählte veröffentlichte Website-Fassung
oder ein eigener sichtbarer Vereinskalendertermin. Titel und Text stammen aus
der freigegebenen Social-Fassung; Beginn, bekanntes Ende und Ort aus dem
ursprünglichen Termin. Ein unbekanntes Ende bleibt unbekannt. Kalenderteilnehmer,
Antworten, Berichtsnotizen und private Manageradressen werden nicht übernommen.

Der verschlüsselte Auftrag bindet Vereinszuordnung, aktuelle Autorität, Freigabe,
Quellenkennung und -fassung, ursprünglichen Snapshot, JPEG-Bytes sowie das genaue
persönliche Konto und Heimatgebiet. Derselbe ursprüngliche Termin hat je Verbindung
höchstens einen Auftrag. Ein zweiter Entwurf oder eine andere Freigabe darf diesen
Auftrag weder ersetzen noch eine zweite Veröffentlichung erzeugen. Die Auswahl
eines vorhandenen identischen Auftrags löst keinen Anbieteraufruf aus.

## Planen und prüfen

Im gespeicherten Veranstaltungsbeitrag führt „Planung öffnen“ direkt zur Freigabe.
„StadtLand.Funk planen“ öffnet Zeitpunkt und ausdrückliche Zielbestätigung. Beide
Felder beginnen leer. Der Zeitpunkt wird in Europe/Berlin geprüft; eine nicht
existierende Uhrzeit wird abgewiesen. Bei einem Fehler bleiben die Eingaben
erhalten. Eine inzwischen geänderte Fassung braucht eine neue Zielbestätigung.
Redaktionsplanung und Anbieterauftrag werden gemeinsam gespeichert oder gemeinsam
zurückgenommen. Ein vorhandener Auftrag hat einen direkten Verlaufslink.

„Planung & Verlauf“ zeigt zehn genaue ursprüngliche Aufträge pro Seite, mit
Plattform, Inhaltsart und tatsächlichem Status. Plattform-/Statusfilter und
Seitenwahl funktionieren auch ohne JavaScript. Nach dem Planen öffnet sich die
Seite mit dem konkreten Auftrag und dessen Tastaturfokus. Frühere freigegebene
Titel werden durch aktuelle private Bearbeitungen nicht ersetzt. Vorschlagspläne
bleiben aufklappbar pausierbar und fortsetzbar. „Redaktion öffnen“ führt zum
aktuellen Editor; „Veröffentlichung öffnen“ zum bestätigten StadtLand.Funk-Termin.

„Status abgleichen“ liest das vorhandene Ergebnis. Eine noch frische Übernahme
oder ein lediglich eingeplanter Auftrag bekommt keine solche Aktion. Meta-Aufträge
haben ausschließlich ihren Meta-Abgleich. Interne Foto-Referenzen, Tokens und
Protokollgründe erscheinen nicht im Verlauf.

Der eigene private CLI-Modus ist von Mail-, Meta-, Postfach- und Websitejobs getrennt:

```sh
php /ABS/DVM/app/vereinsverwaltung/bin/native-delivery-worker.php --config=/ABS/DVM/private/worker.php --stadtlandfunk --send --limit=25
```

Die private Vereinskonfiguration und ihre Schlüssel müssen aus der Installation
stammen. Ohne `--send` bleibt der Anbietertransport ausgeschaltet.
`KULTURHUS_DISABLE_WEBSITE=1` und eine Wiederherstellungssperre stoppen ihn.
Abgeschalteter E-Mail-Versand verhindert diesen ausdrücklich gewählten Social-Weg
nicht. Der Worker bereitet keine Mail-, Meta- oder sonstigen Aufträge vor und
verändert deren ursprüngliche Zeilen nicht. Der Betriebsverlauf nennt den
tatsächlich gewählten StadtLand.Funk-Modus.

## Dauerhafter Ablauf

1. Der Worker übernimmt den fälligen Auftrag und speichert die Übernahme vor einem
   Anbieteraufruf dauerhaft. Dienstaufrufe ohne diese Übernahme oder mit verändertem
   verschlüsseltem Inhalt werden abgewiesen.
2. Das genaue freigegebene JPEG geht über das persönliche App-Protokoll an den
   festen temporären Foto-Endpunkt. Temporäre und endgültige Bildkennung sowie
   die bestätigte Laufzeit werden gespeichert. In dieser Phase wird kein Termin
   angelegt. Ein vor der Terminanlage abgelaufenes Foto kann neu hochgeladen werden.
3. Ein späterer Worker-Lauf verwendet den gespeicherten Fotostand für genau einen
   Anlageversuch. Die Foto-Referenz ist zu diesem Zeitpunkt bereits dauerhaft
   gespeichert. Ein anderer Entwurf, Quellenwechsel, Rückzug, eine Absage oder
   geänderte Rechte bzw. Kontobindung erlauben keinen neuen Anlageversuch.
4. Ein unabhängiger Einzelabruf muss Anbieterkennung, eigenen Ersteller,
   Heimatgebiet, Titel, Text, ursprüngliche Zeiten, genaues Bild und Ort bestätigen.
   Eine abweichende, gesperrte oder unvollständige Antwort bleibt unklar.

Ein verlorenes Ergebnis, ein Prozessabbruch oder eine widersprüchliche Kennung
wird niemals automatisch erneut veröffentlicht. Der bewusst angeforderte Abgleich
liest ausschließlich: bekannte Kennung direkt, sonst höchstens vier vollständige
eigene Listenseiten mit je 50 Originalen. Nur genau ein vollständig passender
Originaltermin bestätigt den Auftrag. Fehlende oder mehrdeutige Nachweise bleiben
unklar. Eine über zehn Minuten alte unterbrochene Übernahme kann auf diesem Weg
geprüft werden; sie wird dabei nicht wieder für den Versand freigegeben.

Eine private Website-Änderung berührt die bisher veröffentlichte Originalfassung
nicht. Eine neue Veröffentlichung ändert dagegen die Quellenfassung und sperrt
den früher eingeplanten Auftrag. Der Vereinskalender verwendet unabhängig seine
aktuellen Sichtbarkeitsregeln und seine genaue ursprüngliche Version.

## Anbieter- und Prüfumfang

Das öffentlich ausgelieferte App-Protokoll wurde am 10. Oktober 2026 erneut
lesend geprüft: `POST /media/temp`, Multipartfeld `file`,
`POST /grapevine/event/happeningCreateRequest` mit `customLocationDefinition`
sowie unabhängiges `GET /grapevine/post/{id}`. Es wird kein Partner-Schlüssel
und kein dauerhafter Browser verwendet. Fremder Clientcode wird nicht übernommen.

Die automatisierten Prüfungen verwenden ausschließlich frische isolierte
MySQL-Datenbanken, eigene Bilder und einen ausdrücklich synthetischen Transport.
318 Dienst-/API-/Abbruchverträge, 90 zusätzliche Planungs-/Workerverträge und
180 tatsächliche Browserprüfungen beider Zugänge bis 320 Pixeln mit Touch,
Tastatur, 200 % Zoom und ohne JavaScript bestehen. Acht eigene zukünftige
Browserpläne haben null Anbieter-Versuchszähler; Konten, übrige Originale,
ursprüngliche Freigabefassungen und Bildbytes bleiben unverändert. Der allgemeine
persönliche Leseadapter bleibt auf eigene Listen und Kommentare begrenzt;
Einzelabrufe sind ausdrücklich an den Veröffentlichungsdienst gebunden.
Eine zweite tatsächliche Verbindung prüft die bereits gespeicherte Übernahme
und den Fotostand vor jedem Schreiben. Ein separater PHP-Prozess wird nach einer
simulierten akzeptierten Anlage beendet; der spätere lesende Nachweis erhält
alle Inhalts-, Fassungs-, Bild- und verschlüsselten Auftragsoriginale.

Konkrete Anbieterbedingungen, persönliche Kontorechte und eine abschließende
reale Veröffentlichung sind gesondert zu prüfen. In der Entwicklung werden keine
realen Termine, Kommentare oder Nachrichten gesendet und keine Zugangsdaten
aus historischem Bestand übernommen.
