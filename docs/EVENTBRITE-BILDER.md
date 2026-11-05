# Neue Eventbrite-Bilder übernehmen

Unter **Website → Veranstaltungen → Eventbrite-Verbindung** die Auswahl
**Anbieterbilder übernehmen** aktivieren und synchronisieren. Die Auswahl ist
standardmäßig aus. Ohne sie gleicht der bestehende Ablauf nur die Termindaten ab.
**Verbindung einrichten** ist für Personen mit Verwaltungsrechten erreichbar.

Es werden höchstens zehn neue Fotos pro Durchlauf verarbeitet; weitere Fotos
lassen sich mit einem erneuten bewusst ausgewählten Abgleich übernehmen.
Zusätzlich begrenzt ein gemeinsamer Zeitrahmen die Bilddownloads. Bekannte
unveränderte Fotos werden wiederverwendet. Fehlgeschlagene oder nicht bestätigte
Uploads werden nicht automatisch wiederholt.

Der neue Bildverweis gehört zunächst zur privaten Terminfassung. Eine bereits
veröffentlichte Fassung behält ihr ursprüngliches Bild und ihre Texte. Eine
geänderte Anbieterdatei ersetzt in einem neuen privaten Entwurf nur den vorherigen
Anbieterbildverweis; andere Fotos bleiben erhalten. Lokale Terminbearbeitungen,
Archivierung, Absagen, geänderte Vereine oder Verbindungen und während des
Downloads geänderte Fassungen werden abgefangen. Eine fehlgeschlagene atomare
Zuordnung entfernt nur die eben neu erzeugten Varianten und erzeugt keine
halb gespeicherte Bildfassung.

Die **Bildangaben** sind wie bei anderen neuen Fotos erreichbar. Der gezählte
Verwendungsnachweis führt direkt zum ursprünglichen Eventbrite-Termin. Neue
Bildoriginale speichern den Anbieter, dessen genaue Kennung, Termin-ID,
Quellhash, Bild-URL und Prüfsumme der heruntergeladenen Datei. Rechtefreigaben,
Lizenzen oder Einwilligungsnachweise werden nicht erfunden. Die globale optionale
Rechte-/Angabenprüfung bleibt beim Veröffentlichen wirksam und behindert den
privaten Upload nicht. Dafür ist kein Schema-Upgrade erforderlich.

Die HTTP-Quelle ist ausschließlich `https://img.evbuc.com` über TLS und Port 443.
Keine Weiterleitungen oder API-Zugangsdaten im Bildabruf. Öffentlich aufgelöste
IPv4-/IPv6-Adressen werden für den konkreten cURL-Aufruf festgehalten; lokale,
private, reservierte und abgebildete IPv4-Adressen werden abgewiesen. Download
höchstens 8 MiB, begrenzte Header und Zeit. Native GD-Verarbeitung prüft die
tatsächlichen Bildbytes und entfernt fremde Metadaten vor der Speicherung.

64 isolierte Fach-/Rechte-/Original-/HTTP-Grenzen-/Abbruch-/Wiederaufnahmeverträge
und 68 tatsächliche Browserverträge bei drei Breiten, 200 % Zoom und ohne
JavaScript bestehen. Die Browserfälle verwenden ausschließlich eigene lokal
erzeugte Anbieterfixtures; sämtliche vorherigen Datenbankzeilen und Bildbytes
bleiben unverändert. Die echte CDN-Anfrage ist in CI und der Testumgebung
gesperrt. Dies ist keine reale Eventbrite-Konto-/CDN-Abnahme.

Prüfwege: `native-eventbrite-images` sowie
`tests/support/provider-images-app-fixture.php prepare`,
`tests/provider-images-app-browser.mjs` und anschließend in beiden eigenen
Laufzeiten `provider-images-app-fixture.php check`. Die tatsächliche Testsitzung
vor einer Originalbaseline erneuern, falls sie abgelaufen ist. Weitere externe
Zuordnungen, Hochlast-/Anbieterabnahme, endgültige Medienlöschung, Aufbewahrung
und die übrigen Registerlücken bleiben offene Arbeit.
