# Vier-Augen-Freigabe für Beiträge

Unter **Website → Design & Verbindungen → Freigabe für Beiträge** kann ein berechtigter
Administrator „Vier-Augen-Freigabe verlangen“ aktivieren. Die Regel gilt für
alle News des Vereins, einschließlich vorhandener Entwürfe. Neue Installationen
starten mit optionaler Prüfung; bestehende veröffentlichte Originale ändern
sich beim Umschalten nicht. Die Einstellung hat eigene Versionen und eine
begrenzte Änderungshistorie. Ein unverändert gespeicherter Wert erzeugt keine
künstliche Fassung.

Bei aktiver Regel prüft ein anderes identifiziertes Konto mit aktuellem
Veröffentlichungsrecht die genaue gespeicherte Inhaltsfassung. Der letzte
tatsächliche Inhaltsbearbeiter kann seine eigene Fassung nicht freigeben.
Statusänderungen und Veröffentlichungen verändern diese Zuordnung nicht.
Nach einer Bearbeitung, Kopie, Wiederherstellung oder Archiv-/Papierkorbrückkehr
ist eine neue Prüfung nötig. Geänderte Vereinsregeln verlangen ebenfalls eine
neue Freigabe; dafür erscheint bei einem bereits freigegebenen Beitrag
„Erneut freigeben“.

Die Prüfung veröffentlicht noch nicht. Autor und Prüfer sehen den aktuellen
Stand im Artikel- und allgemeinen Seiteneditor. Veröffentlichungs- und
Planungsbuttons bleiben bis zur gültigen Freigabe gesperrt. Der Server prüft
dies auch bei unmittelbar übermittelten Formularen. Bestehende Planungen
benötigen bei geänderter Regel eine neue Bestätigung; der Worker überprüft die
aktuelle Regel erneut und veröffentlicht einen unzulässigen Plan nicht.
Inhaltsseiten, Veranstaltungen und Alben behalten ihre eigenen Fachregeln.

Freigaben werden privat als unveränderliche Nachweise neben der genauen
Seitenrevision gespeichert: Inhaltsrevision, Inhaltshash, Bearbeiter,
Prüfer, Regelversion und Zeitpunkt. Der Inhaltshash ordnet Objektfelder
deterministisch; Seiten-/Veröffentlichungsmetadaten gehören nicht zum
geprüften Inhalt. Nutzerfelder können keinen Nachweis herstellen. Die
öffentlich gespeicherten Payloads erhalten keine neuen Freigabeobjekte,
und historische Planfingerprints bleiben erhalten.

Aktuelle Rechte und Regel-/Inhaltsstände werden innerhalb desselben
Vereinsmutex gelesen, auch nach einem älteren Repeatable-Read-Snapshot.
Das CMS-Schema **9** ergänzt drei private Tabellen; der explizite
Installer-/Upgradeweg führt die Änderung aus. Öffentliche Requests führen
keine Schemaverwaltung aus. Wiederherstellung erhält die Nachweise und ihre
ursprünglichen Revisionen.

60 isolierte MySQL-/Dienst-/Formularprüfungen in
`native-news-policy.php` prüfen unter anderem Selbstfreigabe, aktuelle
Berechtigungen, Vereinsgrenzen, geänderte Inhalte und Regeln, echte
Worker-Ausführung sowie zwei gleichzeitig geöffnete MySQL-Verbindungen.
112 tatsächliche Browserprüfungen in `native-news-policy-browser.mjs` verwenden
zwei angemeldete Managerrollen,
vier Breiten bis 320 Pixeln, Tastatur, Touch, 200 % Zoom und Formulare ohne
JavaScript. Die Installer-/Recovery-Suiten enthalten tatsächliche
CMS-8-Upgrades und die verschlüsselte Wiederherstellung vollständiger
Freigaben. Die allgemeine News-, Medien-, UI- und Betriebsabnahme wird
weiter anhand beider Register bearbeitet.

Reine Leser und Prüfer sehen die genaue gespeicherte Beitragsfassung im Artikel-
und allgemeinen Seiteneditor: Beitragsdatum, Kanalwahl, Kurztext, formatierten
Inhalt, geordnete private Bilder, ursprüngliche Feldbezeichnungen und -typen,
Adresse, Menü- und Suchmaschinenangaben. Referenzen zeigen aktuell lesbare
vereinseigene Bezeichnungen. Zusätzliche Bilder und Angaben liegen in
begrenzten aufklappbaren Bereichen. Große Bilder öffnen über den geschützten
Medienweg; die Ansicht macht unveröffentlichte Bilder nicht öffentlich.
Ein direkter Link „Prüfung & Freigabe“ erreicht den Prüfbereich auch bei langen
Beiträgen mit Tastaturfokus und ohne erneutes Laden der Fassung.

Die Tablet-Navigation öffnet bis 1000 Pixeln bei Bedarf und gibt den Inhalten
mehr Platz. Ohne JavaScript öffnen und schließen native Menülinks die
rollenabhängige Navigation. Die Beitragsbereiche reagieren zusätzlich auf den
tatsächlich verfügbaren Platz, einschließlich vergrößerter Schrift.
42 zusätzliche MySQL-/Darstellungsverträge prüfen Originalbytes, aktuelle Rechte,
alte Felddefinitionen und beide Editoradapter. 324 tatsächliche Browserprüfungen
verwenden beide Zugänge, reine Leserechte und einen Publisher ohne Bearbeitungsrecht,
vier Breiten bis 320 Pixeln, 200 % Zoom, Touch, Tastatur und JavaScript-freie
Menü-/Freigabewege. Alle bestehenden Beitragsfassungen und Bildbytes bleiben
unverändert; fünf neue private Freigaben veröffentlichen nichts.

Alle 90 nativen Suiten einschließlich der neuen Leseansicht bestehen.
Ein zusätzlicher gemeinsamer Rollenlauf mit 230 tatsächlichen Ansichten und
1252 Bedienverträgen prüft die übrigen erreichbaren Menüs und Controls.
Wiederholte Seitenwechsel laden keine NoScript-Stile und entfernen alte
Menühandler. 51 Installer- und 34 Recovery-Verträge
belegen die tatsächlichen Einrichtungs-, Upgrade- und Wiederherstellungswege.
