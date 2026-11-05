# Veranstaltungen: Softwareabnahme K-03 und K-31

Stand 15. Oktober 2026. Die benötigten Veranstaltungs- und Anbieterabläufe sind
anhand des eigenen CMS und seiner ursprünglichen Datenverträge abgeglichen.
Neue Vereinsinhalte werden redaktionell angelegt; keim früheren CMS-Altimport.

| Benötigte Funktion | Eigener Nachweis |
|---|---|
| Öffentliche Termindetails | Status, Beginn/Ende, genaue UTC-Anker und Zeitzone, Ort/Anschrift, Eintritt, Ticketlink, Anfahrt, echtes öffentliches Bild und Event-JSON-LD |
| Programm und Archiv | Tatsächliche Jahres-/Textfilter, vollständige Trefferzahl, zwanzig Termine und zweite Seite, Archiv, Tastatur und schmale Ansicht |
| Kalender | Tatsächlich geladene einzelne ICS mit genau einem VEVENT, ursprünglichem Beginn und Ende; abgesagter Termin bleibt mit CANCELLED lesbar |
| Absage und alte Links | Eigene öffentliche Absageseite aus der vorigen Veröffentlichung, keine Ticketaktion, unveränderte Weiterleitung mit ursprünglichen Querywerten |
| Redaktion | Persönliche aktuelle Anmeldung, tatsächliche private Speicherung bei vier Breiten, erhaltener ursprünglicher Endzeitpunkt, private Folgefassung und geschützte öffentliche Originalprojektion |
| Eventbrite und Facebook | Wählbarer/wechselbarer Anbieterweg, eigene Quellkennung, Originalhashes und explizite Zuordnungen, unveränderte Wiederholung, atomare Konflikte und Abbruchwiederaufnahme |
| Eigene und externe Termine | Eigene Vereinstermine behalten ihre Website-Sichtbarkeit. Eventbrite legt Facebook-Termine extern an; eigene Tickettermine werden dort manuell angelegt und anschließend rein lesend über Meta übernommen |
| Tickets und automatische Veröffentlichung | Eigene Auswahl für externe oder native Tickets und Zahlungsanbieter; bewusste optionale Veröffentlichung der passenden Anbieterfassung, mit aktuellen Rechten und Originalschutz |
| Anbieterbilder | Ausdrücklich ausgewählte neue Bilder, zulässiger CDN-Transport, Originalschutz, atomare private Zuordnung, Wiederaufnahme und optional aktivierte Nutzungsprüfung |
| Hintergrundabgleich | Eigener privater Veranstaltungsmodus, konfigurierte Vereins-/Personenbindung, aktuelle Rechte, unabhängige interne Originale und erhaltene Zustell-/Publikationshistorie |

## Ein abgeschlossener gemeinsamer Lauf

```sh
DVM_EVENT_WORKFLOW_BROWSER=1 DVM_EVENT_PROGRAMME_BROWSER=1 bash tools/ci/local-native.sh --core native-events native-event-workflows native-facebook-events native-event-worker native-eventbrite-images native-event-catalogue native-event-publications native-event-programme
```

Alle acht Gruppen bestehen in einem gemeinsamen Lauf ohne fehlgeschlagene Gruppe.
Der Anbieterworkflow besteht **202 tatsächliche Browserbedingungen und sechs
Formularaktionen** mit/ohne JavaScript. Die ergänzte befüllte Programmprüfung
besteht **156 Browserbedingungen in 13 Abläufen** bei 1440/820/390/320 Pixeln.
Die eigenständige Filter-/Seitenwechselprüfung verwendet deaktiviertes JavaScript
und doppelte Vergrößerung bei 320 Pixeln. Sie wartet auf die wirkliche erfolgreiche
HTTP-Antwort und die geladenen Styles.

26 eigene öffentliche Termine enthalten Programm, Archiv und eine tatsächliche
Anbieterabsage. Ein wirklich dekodiertes Terminbild bleibt innerhalb des
Bildschirms; seine HTTP-Dateibytes stimmen mit der ursprünglichen eigenen
Bildvariante überein. Die vier bewussten privaten Redaktionsspeicherungen
bewahren Endzeit/Zeitzone, Originalmetadaten und die öffentliche Fassung.
Bei einem ursprünglich einfachen Text speichert das vollständige Formular die
angezeigte formatierte Editorfassung. Veröffentlichte Originale, übrige ganze
Datensätze, Quellzuordnungen, Kontakte, Rechte, Schlüssel und Fotobytes bleiben
exakt erhalten.

SHA-256 des abgeschlossenen vollständigen lokalen Logs:
`194d2b51b603f8f018f1204b29c4d8c9c9e7ac6bb1fe1854bca017161051af61`.

Der neue Programmtest verwendet eine eigene frische Datenbank, persönliche
Authentifizierung, einen eigenen freien Port und private Medien außerhalb des
Webroots. Entwicklungsdaten, feste Entwicklungsports und vorhandene Sitzungen
werden nicht benötigt. Der Kernmodus bleibt ohne Playwright ausführbar.
Anbietertransporte werden mit eigenen lokalen Vertragsadaptern geprüft;
kundenbezogene Live-Konto-/Hostingabnahmen bleiben getrennt dokumentiert.
Keine echte Anbieteranlage, Zahlung oder externe Veröffentlichung.

K-03 und K-31 sind damit im vereinbarten Softwareumfang implementiert.
Keine neue Tabelle oder Laufzeitänderung; Generation 2026101516 und 246 Gruppen.
Die übrigen Vereins-/Social-/Betriebs-/UI-Aufgaben, Apps und abschließende
Statusseite behalten ihre eigenen Anforderungen.
