# Unterhaltungen finden und persönlich antworten

Der Vereinschat zeigt sechs eigene Unterhaltungen je gezählter Seite. Die Suche
findet Titel im vollständigen zugänglichen Bestand; Sonderzeichen sind normale
Suchzeichen. **Ungelesen** filtert Gespräche mit Nachrichten anderer Personen
nach dem eigenen gespeicherten Lesestand. Die direkte Seitenwahl erreicht alle
Originale, auch über 500 Gespräche hinaus. Fremde Vereine und Gespräche ohne
eigene Teilnahme erscheinen nicht.

Die ausgewählte Unterhaltung bleibt unabhängig von ihrer Listenseite geöffnet.
Ihre Gesprächsliste ist zunächst eingeklappt. Zwanzig ursprüngliche Nachrichten
stehen je Abschnitt bereit; **Ältere Nachrichten** führt zu tatsächlichen älteren
Originalen, **Zu den neuesten Nachrichten** zurück. Die globale Nachrichtennummer
anderer Gespräche erzeugt keinen leeren Altverweis. Der Live-Status lädt eine kleine
Gesprächsseite und behält zugleich die gesamte eigene Ungelesen-Anzahl.

**Antworten** wählt genau die ursprüngliche Nachricht und zeigt ihren eigenen
Text als Bezug. Die Antwort beginnt leer. Auswahl und **Antwort verwerfen**
erhalten bereits eingegebenen persönlichen Text. Das funktioniert auch ohne
JavaScript: Die Knöpfe gehören zum tatsächlichen Nachrichtenformular. Suche
und Seitenwahl derselben Unterhaltung erhalten ebenfalls Text und Sendekennung.
Diese Auswahlschritte senden nichts und ändern keine Lesestände.

**Nachricht senden** speichert den eigenen Text mit dem genauen Antwortbezug.
Ein fehlerhafter Anhang erhält Text, Bezug und ursprüngliche Sendekennung. Die
Datei muss ohne JavaScript erneut gewählt werden. Ein Versionskonflikt bei einer
eigenen älteren Korrektur erhält den eingegebenen Text und die eingereichte alte
Version. Eine neue gespeicherte Fassung wird nicht stillschweigend überschrieben.
Ungültige Listen-/Abschnittsangaben werden vor Fachänderungen abgewiesen.

Eine verlorene Antwort nach bereits angenommener Nachricht lässt den ursprünglichen
Sendevorgang erhalten. Seine genaue Wiederholung liefert dieselbe Nachricht;
geänderter Inhalt unter derselben Kennung wird abgewiesen. Es entsteht kein zweites
Reply. **Bis hier als gelesen markieren** bleibt eine bewusste eigene Aktion;
Suchen, Lesen, Auswählen und Senden markieren keine älteren Nachrichten als gelesen.

Touchknöpfe und aufklappbare Überschriften bleiben mindestens 44 Pixel hoch.
Beschriftungen und Eingaben umbrechen bei 320 Pixeln und doppelter Schrift.
Die Kontrollen bewegen sich nicht durch eine Scrollanimation. Fehler werden
unmittelbar als Fehler angesagt. Neue Antworten enthalten keine vorgefertigten Texte.

30 Fach-/MySQL-Prüfungen bestehen. 110 tatsächliche Browserprüfungen mit
27 Formularaktionen prüfen beide vollständigen Controller, mit/ohne JavaScript,
24 Handy-/Tablet-/Schriftzustände. Geprüft sind tatsächliche Suche und direkte
Seitenwahl, native Antwortauswahl/Abbruch, fehlerhafter Anhang, endgültiges Reply,
angenommener verlorener HTTP-Response und wirkliche Wiederholung des gesendeten
HTTP-Originals. Neun weitere Datenbankprüfungen lesen die wirklich aus diesen
Bedienwegen entstandenen Originale. Jede Antwort ist genau einmal gespeichert;
Bezug und ursprünglicher menschlicher Text stimmen überein. Sämtliche außenliegenden
Nachrichten, Fassungen, Dateien, Personen, Rechte, Queues und Lesestände bleiben exakt.

Die verschlüsselte Relokation erhält alle Gesprächs-/Reply-/Revisions-/Teilnehmer-/
Lesestand-/Anhang-/Benachrichtigungsoriginale. Die Suche zeigt dieselben ungelesenen
Originale. Die ursprüngliche Dateianlage wird bytegleich aus dem neuen privaten
Verzeichnis heruntergeladen. Keine neue Tabelle oder Modulversion: Chat bleibt 1,
Kommunikation 14; Paketgeneration 2026101311 und 182 registrierte native Suiten.

Weitere tatsächlich benötigte Uploadbereiche und Geräte-/Zustellvarianten sowie
die vollständige Fach-/UI-Endabnahme bleiben offen. Kein neuer vollständiger Lauf
aller 182 Suiten, keine echte Anbieterzustellung oder Gesamtabnahme behauptet.

```sh
DVM_CHAT_CATALOGUE_BROWSER=1 bash tools/ci/local-native.sh --core native-chat-catalogue
bash tools/ci/local-native.sh --core native-chat native-chat-workflows native-navigation native-website-workspace production-runtime
bash tools/ci/local-native.sh --core native-installer native-webspace-installer native-recovery native-package-upgrade native-restore-resume
```
