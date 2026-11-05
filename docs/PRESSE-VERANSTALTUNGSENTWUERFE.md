# Veranstaltungen als Presseentwurf

Unter **Presse → Veranstaltungen** stehen das veröffentlichte Website-Programm
und im integrierten Manager die zugänglichen Vereinskalendertermine zur Auswahl.
Eigene sowie bereits importierte Website-Veranstaltungen verwenden denselben
Ablauf. Quelle, Suche, Zeitraum und Seite bleiben beim Wechsel und Neuladen erhalten.
Die Auswahl zeigt sechs Veranstaltungen pro Seite; auch lange Originaltexte werden
erst nach der begrenzten Auswahl der Kennungen geladen. Die bisherigen öffentlichen
und Social-Veranstaltungslisten behalten ihre Seitengröße.

**Presseentwurf vorbereiten** übernimmt Titel, Anfang und Ende in der ursprünglichen
Zeitzone, Ort und Adresse, Einleitung, tatsächliche Beschreibung und das erste
vorhandene Veranstaltungsbild. Abgesagte Termine behalten ihre Kennzeichnung.
Interne Kalendertermine verlangen vor der Übernahme eine ausdrückliche Auswahl
für diesen Pressezweck. Teilnehmer, Rückmeldungen, Berichtsnotizen und private
Manager-URLs werden nicht kopiert. Freie Mitteilungen bleiben direkt erreichbar.

Der neue Entwurf bleibt privat und ohne Freigabe, Empfänger oder Versandauftrag.
Text und Bild lassen sich anschließend normal bearbeiten; Empfänger, Freigabe
und Versand werden im vorhandenen Presseablauf ausdrücklich gewählt.
Unter **Veranstaltungsquelle** stehen Herkunft, Titel und genaue Originalfassung.
Eine spätere Änderung, Rücknahme oder Löschung des Veranstaltungseintrags schreibt
weder Presseentwurf noch Herkunft um. Eine inzwischen geänderte Auswahl wird
abgewiesen; beide Anwendungen zeigen die aktuellen gefilterten Möglichkeiten
zum erneuten Auswählen. Auch bereits offene Datenbanktransaktionen dürfen keine
inzwischen überholte Originalfassung kopieren.

Presse-Leserechte erlauben die Ansicht, Presse-Bearbeitungsrechte die Übernahme.
Website-Leserechte und Kalenderrechte werden jeweils eigenständig geprüft.
Presse mit Kalenderzugang benötigt weder Website- noch Social-Rechte.
Ein reines Website-Konto kann keinen Manager-Kalenderzugang übernehmen.
Fremde Vereinsdaten und aktuelle Rechteentzüge werden abgewiesen.

Die neue Herkunft wird in `native_press_event_origins` getrennt von den
Social-Herkünften gespeichert. Veranstaltungsbilder bleiben dort als Verwendung
sichtbar, selbst wenn ein Mensch das Bild später aus dem Presseentwurf entfernt.
Der globale Entfernungsschutz berücksichtigt diese Originale ebenfalls.
Ein verschlüsselter Umzug bewahrt die ursprünglichen Herkunftsbytes und
Bildverwendung; alte Pressefassungen können ihre eigenen ursprünglichen Bilder
weiterhin über den begrenzten privaten Bildzugang anzeigen.

Kommunikationsschema **14** ergänzt genau diese leere Tabelle über die
bestehende ausdrückliche Installation beziehungsweise das Paketupgrade.
Vorherige Originale bleiben erhalten; der Zugriff auf eine noch ältere
Installation führt keine Schemaänderung auf einer GET-Seite aus.
Paketgeneration **2026101308**. Kein Altbestandimport erforderlich.

52 Fach-/MySQL-Prüfungen bestehen, einschließlich tatsächlicher großer Originale,
getrennten Rechten, veralteten und fremden Quellen, offenen älteren Transaktionen,
Bildverwendungen sowie dem ausdrücklichen Upgrade eines befüllten Schemas 13.
222 tatsächliche Browserprüfungen mit zehn Formularaktionen bestehen über beide
Anwendungen, mit und ohne JavaScript. Website- und Kalenderauswahl sind bei
320/390/820 Pixel und doppelter Schrift geprüft: insgesamt 36 Ansichtsstände,
Touch und Tastatur, Filter, Wiederaufnahme, Bestätigung und Lesekonten.
Keine echten Mails oder Provideraufrufe.

Lokale Nachprüfung:

```sh
DVM_PRESS_EVENT_SOURCES_BROWSER=1 bash tools/ci/local-native.sh --core native-press-event-sources
bash tools/ci/local-native.sh --core native-social-event-sources native-events native-event-catalogue native-media-usage native-media-references native-media-removal-guard
bash tools/ci/local-native.sh --core native-installer native-webspace-installer native-recovery native-package-upgrade native-restore-resume
```

Die konkret benötigte Presse-Fotoanlage und die vollständige Fach-/Rollen-/UI-
Gesamtabnahme bleiben eigene nächste Aufgaben; dieses Feature schließt K-30 nicht
insgesamt ab. Apps folgen nach dem bestehenden Ausbau, die gemeinsame Statusseite
zuletzt. Gefährdungsbeurteilung und operative Gefährdungsprüfung bleiben getrennt.
