# Optionale Bildprüfung und einfache Uploads

Unter „Alle Bilder“ lässt sich „Bildprüfung für alle Bereiche“ öffnen.
Zwei getrennte Schalter stehen standardmäßig auf Aus:

- Geklärte Rechte und Einwilligung vor Verwendung prüfen.
- Credit, Quelle und Lizenz vor Verwendung verlangen.

Die Prüfung gilt für Website, Social, Newsletter und Presse, einschließlich
bereits gespeicherter Bildfassungen und aktueller öffentlicher Bildabrufe.
Pflichtangaben werden vor der Verwendung geprüft, nicht vor dem Upload.
Auch bei aktiven Schaltern können neue Bilder ohne Rechteformular hochgeladen
und ihre Angaben anschließend ergänzt werden. Ein ausdrücklich gespeicherter
Widerruf, abgelaufene Nutzungsfrist oder ausgeschlossener Kanal bleibt wirksam;
das Abschalten der zusätzlichen Pflichtprüfung entfernt keine solche Entscheidung.

Die Mediathek unterstützt Einzel- und Mehrfachauswahl in beiden Redaktionen,
auch ohne JavaScript. JavaScript überträgt bis zu 50 ausgewählte Bilder einzeln;
dadurch hängt die Gesamtmenge nicht an einem einzigen großen PHP-POST.
Ohne JavaScript gilt zusätzlich die Dateianzahl-/POST-Grenze des Webspaces.
Fehlerhafte Dateien verhindern die Aufnahme anderer gültiger Dateien nicht.
Die Rückmeldung zeigt höchstens fünf einzelne Fehler. Bereits bestätigte
Uploads werden nicht automatisch wiederholt.

Ein Alternativtext ist beim Upload optional. Ohne Eingabe dient der Dateiname
als vorläufige Bildbeschreibung; Bildrechte, Lizenz oder Einwilligung werden
nicht erfunden. Credits und genaue Beschreibungen lassen sich danach ergänzen.
Alle bisherigen Bilder, veröffentlichten Fassungen, privaten Nachweise und
Prüfversionen bleiben erhalten. Die globalen Einstellungen besitzen eigene
unveränderliche Prüfversionen; ältere Formulare können keine neuere Einstellung
überschreiben. Reine Leser und normale Redakteure können die Schalter nicht ändern.

CMS-Schema 12 ergänzt ausschließlich die beiden privaten Einstellungstabellen.
57 Fachverträge, zwölf betroffene native Suiten und 56 Browserverträge bestehen.
Die Browser führen 30 tatsächliche eigene Änderungen aus: 18 neue Uploads und
zwölf aktivierte/zurückgesetzte Einstellungen. Eine fehlerhafte Datei zwischen
zwei gültigen Bildern, Einzeluploads, 390/320 Pixel, Touch und beide Redaktionen
mit/ohne JavaScript sind geprüft; sämtliche außenliegenden Datenbank-/Bildoriginale
bleiben unverändert. Der integrierte JSON-Upload fragt nach einem Mediathekbild
keine Albumversion mehr ab und meldet eine erfolgreiche Aufnahme korrekt.

62 tatsächliche Installer-/CMS-Upgrade-, 39 verschlüsselte Wiederherstellungs-,
49 tatsächliche Webspace-/Browser- und 27 Produktionscontroller-Verträge bestehen.
3845 Inventarhashes, 3882 Paketdateien, statische Abhängigkeiten sowie
6 Inventar-/9 Paketverträge sind geprüft. Der GitHub-Actions-Lauf
`38106934926` (historischer Lauf außerhalb dieses öffentlichen Repositorys)
am Vorgängercommit `185175a` ist vollständig grün, einschließlich aller
101 damaligen nativen Suiten und tatsächlicher Browserinstallation. Der neue
Stand registriert 102 Suiten; dies ist noch kein vollständiger 102-Suiten-Lauf.

HEIC/HEIF, RAW, die weiteren Uploadbereiche und die zusätzliche erweiterte
Bildbearbeitung werden als nächster eigener Zwischenstand umgesetzt.
