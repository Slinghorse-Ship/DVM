# Persönliche Sprache im Verwaltungsrahmen

Unter **Mein Konto → Oberflächensprache** Deutsch oder Englisch auswählen und
speichern. Die Auswahl gilt für die persönliche Identität im eigenen Verein,
auch wenn die öffentliche Website nur Deutsch anbietet. Andere Personen und
öffentliche Inhalte behalten ihre eigene Sprache.

Der gemeinsame Rahmen beider Verwaltungszugänge verwendet dieselben expliziten
Sprachpakete: Seitentitel, Navigation, Websitebereich-Tabs, Suche, Konto- und
Vorschaulinks, Bildschirmleser-Beschriftungen, globale Dialoge, Ladefehler und
Suchstatus. Begriffe wie „password“ finden die dazugehörigen tatsächlichen
Funktionen auch über Suchsynonyme. Die native Websiteansicht und der vollständige
Manager verwenden dieselbe persönliche Sprache.

Abbrechen oder Escape lässt ungespeicherte Eingaben erhalten. Nach einem
Ladefehler bleiben die ursprünglichen Formularwerte sichtbar. Erst bewusstes
Verwerfen öffnet den anderen Bereich. Ändert eine zweite Kontoseite die Sprache,
übernimmt die nächste Bereichsnavigation die aktuelle Auswahl: Der vollständige
Manager aktualisiert Rahmen, Dokumentensprache und Clienttexte gemeinsam; die
native Websiteansicht öffnet ihren vollständigen Rahmen einmal in der neuen
Sprache. Unabhängige Eingaben bleiben durch die vorhandene Rückfrage geschützt.

Die native Kontoverknüpfung, Website-Vorschau und die Symbole in der oberen
Managerleiste haben mindestens 44 Pixel große Ziele. Der Änderungsverlauf bleibt
für berechtigte Personen auch auf dem Handy erreichbar. Der Dashboardkopf liest
den tatsächlich eingestellten Vereinsnamen statt eines festen Designvereins.

## Gemeinsame Such- und Auswahlhilfen

Die eingebundenen Listenfilter und durchsuchbaren Auswahlfelder verwenden ebenfalls
die persönliche Sprache: Trefferzahlen, Seitenschalter, leere Ergebnisse und
der Hinweis auf die ersten 100 Treffer. Suchtext, Listennamen und auswählbare
Originalnamen werden weder übersetzt noch verändert. Eine erfolglose Suche
ersetzt die bestätigte Auswahl nicht. Die übermittelte Kennung bleibt dieselbe.

Ohne JavaScript bleiben die ursprünglichen Links und nativen Auswahlfelder
bedienbar. Tastatur und Touch verwenden denselben tatsächlichen Auswahlwert.
Die Prüfung verwendet echte Listen- und Inventarformulare, keine nachgebauten
Demoformulare. Physische Geräteabnahmen bleiben getrennt.

## Ursprüngliche Inhalte und Rechte

Nur feste Beschriftungen an ausdrücklich angeschlossenen Ausgabestellen werden
übersetzt. Vereins- und Personennamen, Seitentitel aus dem CMS, eingegebene Texte,
Nachrichten, ursprüngliche Hinweise und Fehlermeldungen sowie Geschäftsoriginale
werden weder durchsucht noch automatisch umgeschrieben. Die maskierte Ausgabe
bleibt erhalten; auch ein überprüftes Sprachpaket liefert keinen ausführbaren
HTML- oder JavaScript-Inhalt.

Die bestehende Sprachverwaltung kann die deklarierten Beschriftungen als
versionierte Sprachpakete anpassen. Unbekannte Schlüssel bleiben gesperrt.
Die persönliche Sprachauswahl erweitert weder Fachrechte noch die Berechtigung
zur Verwaltung öffentlicher Sprachen. Der Client liest ausschließlich die vom
Server ausgegebenen festen Beschriftungen; Geschäftsinhalte werden nicht als
Übersetzungsquelle verwendet.

## Wiederholbare Prüfung und verbleibender Umfang

```sh
DVM_MANAGER_INTERFACE_BROWSER=1 bash tools/ci/local-native.sh --core native-manager-interface
DVM_NAVIGATION_BROWSER=1 bash tools/ci/local-native.sh --core native-navigation
bash tools/ci/local-native.sh --core native-languages native-webspace-installer
bash tools/ci/local-native.sh --package
```

Die eigene Browsergruppe bedient die tatsächlichen Kontoformulare beider
Anwendungen mit und ohne JavaScript, beide Sprachen, sechs Bildschirmgrößen und
beide Schriftgrößen. Sie misst sichtbare Touch-Ziele, prüft tatsächliches
Abbrechen/Verwerfen und fehlgeschlagene Bereichsnavigation sowie einen
Sprachwechsel in einer zweiten Kontoseite. Die Prüfung schützt alle ursprünglichen
Geschäfts-, Inhalts-, Rechte- und Schlüsselbestände auch im Fehlerfall. Nur die
eigene Sprachpräferenz und technische Sitzungsuhren dürfen sich ändern.

Die [eingebetteten Kontobereiche](KONTO-SPRACHE.md) sind inzwischen ebenfalls
angeschlossen. Die Listenoberfläche einschließlich Formularen, Formelhilfe,
Fehlern, Import, Freigaben und Archiv verwendet nun dieselbe persönliche
Sprachauswahl. Gespeicherte Listen- und Spaltennamen, Zelltexte und Formeln
bleiben davon getrennt. Anleitung: [Freie Listen](vereinsverwaltung/handbuch/20-freie-listen-hilfe.md).

P-12 bleibt teilweise: Die übrigen Fachtexte folgen als eigene Modulgruppen.
Diese Erweiterung ist kein Nachweis einer vollständig übersetzten gesamten
Verwaltungsoberfläche und keine physische Geräteabnahme.

## Kalenderansichten und Termin-/Serienformulare

Die persönliche DE-/EN-Sprache ist gezielt an die Kalender-Bedienelemente,
Monats-/Wochentags-/Quellenbeschriftungen, Termin-/Serienformulare,
Teilnehmer-/Einladungsempfängersuche, Rückmeldekontrollen und die Bedienung der
Mail-/Briefvorschau angeschlossen. Feste Serverfehler und neu erzeugte
Kalenderbestätigungen verwenden dieselbe Auswahl. Originale Titel, Orte,
Tagesordnungen, Empfänger, Standardbetreff, Nachrichten und private Serien-JSON
werden weder übersetzt noch als UI-Vorlagen interpretiert. Die Zeitrechnung und
die bestehende Freigabe externer Aktionen bleiben unverändert.

Die eigenständige Gruppe `native-calendar-interface` prüft tatsächliche native
Anmeldung und persönlichen Sprachwechsel über beide vorhandenen Dispatcher.
`DVM_CALENDAR_INTERFACE_BROWSER=1` ergänzt DE/EN mit/ohne JavaScript,
Teilnehmer-/Empfängerseiten, ursprüngliche Einladungs-/Briefvorschauen,
Serienschutz und -originale, ungültige Ortszeiten, Korrektur/Speichern,
Tastatur-/Touchwege und tatsächlich vergrößerte Texte. Die Bestände sind
synthetisch und isoliert. Prüfungen erzeugen keine externen Sendungen oder
Druckaufträge. Handbuch: [Kalender](vereinsverwaltung/handbuch/13-kalender-versammlung-protokolle.md).

Eingebettete GEMA-/Dokumentenbereiche und fachlich erzeugte Quellenzusätze
bleiben eigene Fachoberflächen. P-12 und P-25 sind weiterhin teilweise offen.

Nachweis der festen Kalenderfassung: 526 Browserbedingungen/128 Ansichten,
24 Dienst-/Originalbedingungen. Die Auswahlregressionen bestehen mit 1.003
Kontakten einschließlich tatsächlicher PDF-Erstellung und Wiederholung in
isolierten Testdaten; die Seriengruppe besteht 44 Dienst- und 49 Browserbedingungen.
Generation 2026101573, 326 registrierte Kerngruppen; keine Schemamigration.

## GEMA: Originalschutz und persönliche Sprache

Die GEMA-Erledigung behält ausdrücklich gespeicherte verneinte Prüfangaben,
Originalnotizen, Musiklisten und Vorgangsnummern. Eine Begründung für eine nicht
erforderliche Anmeldung wird separat und bewusst eingegeben. Neue ungeprüfte
Gemeinnützigkeits-/Sitzangaben sind unbekannt; kein Bestandsreset.
GEMA verwendet eigene deklarierte Sprachschlüssel. So kann derselbe deutsche
Ausdruck im Zahlungs- und Rückmeldekontext unterschiedlich übersetzt werden,
ohne bestehende Kalenderbeschriftungen zu verändern. Der Client bekommt nur
benötigte Live-Texte; sämtliche Serverbeschriftungen bleiben im privaten
versionierten Sprachkatalog einschließlich eigener geprüfter Overrides vorhanden.

308 Browserbedingungen/80 Ansichten und 28 Dienst-/Originalbedingungen bestehen
über die tatsächlichen bestehenden Dispatcher, DE/EN, mit/ohne JavaScript,
Fehler-/Wiederaufnahmewege und vergrößerte Texte. Tatsächliche Excel-Downloads
behalten ursprüngliche Zeichenfolgen und amtliche Auswahlwerte. Die bisherigen
GEMA-, Kalender- und Sprachgruppen bestehen ebenfalls. Generation 2026101574,
327 registrierte Kerngruppen, keine Schemamigration. Gemeinsame Dokumenten-/Foto-/
PDF-Werkzeuge und weitere Fachoberflächen bleiben eigene Restaufgaben.
[Handbuch](vereinsverwaltung/handbuch/14-gema.md).


## Gemeinsame Dokumentfelder und PDF-Vorschau

Gemeinsame Vorgangskarten, Dokumentfelder und PDF-Kontrollen verwenden einen
eigenen deklarierten Sprachbereich aus dem aktuellen authentifizierten Katalog.
Ursprüngliche Titel, Notizen, Dateinamen, Ordnerpfade und Kennungen durchlaufen
diesen Adapter nicht. Eigene Sprachtexte bleiben maskierter Literaltext.
Die PDF-Vorschau blendet interaktive Kontrollen erst nach dem Modulstart ein;
ohne JavaScript und nach einem Ladefehler bleibt die konkrete Originalfassung
über den separaten Link abrufbar.

318 Browserbedingungen/80 Ansichten und elf Rechte-/Originalbedingungen bestehen
über beide vorhandenen Dispatcher, DE/EN und mit/ohne JavaScript. Echte zwei
PDF-Seiten, frühere Fassung, Download-Klick, Tastatur, Zwei-Finger-Zoom, Fehler-/
Wiederaufnahme und wirkliche Textverdopplung der gemeinsamen Komponenten sind
geprüft. Vollständige Originalzeilen, Dateien und Schlüssel bleiben erhalten.
Generation 2026101575, 328 Kerngruppen, keine Schemamigration. Die übrige Ablage,
Upload-/Fotooberflächen und weitere Fachansichten einschließlich der bei
200-Prozent-Probe erkannten umgebenden Finanz-UI bleiben gesonderte Restarbeit.
[Handbuch](vereinsverwaltung/handbuch/11-dokumente.md).


## Persönliche Freigaben einer geprüften Dateifassung

Die persönliche Freigabekarte einschließlich gespeicherter Zustände, Konflikt-
und Korrekturhinweise verwendet DE/EN. Personennamen bleiben ursprüngliche Werte.
Die alte Freigabe bleibt bei einem neuen Upload an ihrer bisherigen Datei
gebunden. Jede echte Empfängeränderung erhöht die Dokumentmetadatenversion,
ohne Datei oder Dateifassung zu verändern. Beide ursprünglich geprüften
Versionen sind Teil der bewussten Entscheidung; aktuelle Werte werden bei einer
alten Anfrage nicht automatisch eingesetzt. Originale Empfängerdetails sind
auch im allgemeinen Verlauf nur für den Vorstand zugänglich.

294 Browserbedingungen/88 Ansichten und 46 Dienst-/Originalbedingungen bestehen
über beide Dispatcher, DE/EN und mit/ohne JavaScript einschließlich tatsächlicher
Zwei-Tab-Konflikte, neuer Originaldatei, Wiederprüfung, Archivwiderruf, realer
Textvergrößerung und Berliner Tagesgrenze. Zwei getrennte Datenbankprozesse
beweisen die Sperre in beiden Reihenfolgen; eine unterbrochene zusammengesetzte
Speicherung hinterlässt keine Teilfreigabe. Ganze frühere Zeilen, Dateifassungen,
Benachrichtigungen und Schlüssel bleiben exakt. Generation 2026101576, 329
Kerngruppen, keine Schemamigration. Weitere Dokument-/Upload-/Fotooberflächen
und die übrigen Fachansichten bleiben offen.
[Handbuch](vereinsverwaltung/handbuch/11-dokumente.md).


## Dokumentablage, Ordner und Originalzeichen

Dateiliste, Suche, Seitennavigation, Ordnerformulare, Metadaten, Historien,
Archiv und Papierkorb verwenden den eigenen Dokumentsprachbereich. Gespeicherte
Titel, Notizen, Pfade und Dateinamen durchlaufen keine Übersetzung. Downloads
setzen ursprüngliche Titel genau einmal in ihre ARIA-Beschriftung ein.
Ungültige Such-/Ordnerangaben liefern 422, fehlende Rechte 403 und alte
Dokumentfassungen 409 mit erhaltenen ursprünglichen Eingaben. Die bewusste
Wiederaufnahme öffnet die aktuellen Angaben neu. Gültiger UTF-8-Text wird nach
Zeichen statt nach Bytes begrenzt; die Notiz 0 bleibt sichtbar.

553 Browserbedingungen in 128 Ansichten über beide tatsächlichen Dispatcher,
DE/EN und mit/ohne JavaScript bestehen. Sie umfassen echte Anmeldung/Sprachwahl,
Seitennavigation, Ordneranlage/-änderung, Korrektur nach Fehler, zwei konkurrierende
Metadatenformulare, bewusste Wiederprüfung, Archiv/Wiederherstellung, kontextuelle
Suche und 150-Zeichen-Suche, ursprüngliche Downloads, Papierkorb, Rückfragen und
gezieltes Löschen eigener synthetischer Duplikate. Tastatur-/Touchziele und
wirkliche 200-Prozent-Schrift bei 320/820 Pixeln sind für Liste und geänderte
Detailansicht geprüft. 47 Dienst-/Originalbedingungen schützen alle früheren
Geschäfts-/Ordnerzeilen, Dateikörper, Historien, Anfragen, Benachrichtigungen und
Schlüssel. Frühere Daten werden weder normalisiert noch neu befüllt.
PDF-/Freigaberegressionen (318/80 und 294/88 sowie elf und 46 Originalbedingungen),
fünf ältere Dokumentgruppen und sechs native Nachbargruppen bestehen.
Generation 2026101577, 330 Kerngruppen, keine Schemamigration. Upload-/Fotooberflächen,
HSE-/Fristen-/Workflow-Sonderabläufe und andere Fachansichten bleiben offen.
[Handbuch](vereinsverwaltung/handbuch/11-dokumente.md).


## Gemeinsame Uploadbedienung und geprüfte Stapelziele

Übertragung, Dateizeilen, Wiederholen/Entfernen, einzelne Ablageziele,
Verschieben und Bestätigungen verwenden den deklarierten Dokumentsprachbereich.
Bekannte JSON-Fehler werden mit der tatsächlichen persönlichen Sprache gerendert,
auch wenn der Upload aus einer Finanzansicht stammt. Originalnamen und Inhalte
werden nicht durch die Übersetzung oder erneut durch eine Vorlage ausgewertet.

319 Browserbedingungen/116 Ansichten und 52 Dienst-/Originalbedingungen
bestehen über beide Dispatcher, DE/EN und mit/ohne JavaScript. Tatsächlicher
Verlust einer bereits angenommenen Uploadantwort, Wiederholung derselben
Dateikennung, abgewiesene Dateitypen, Entfernen, zwei getrennte Ziele, Verschieben,
Korrektur und endgültige bewusste Bestätigung sind geprüft. Vier zusätzliche
Rechnungseingangsabläufe behalten die ursprüngliche PDF-Datei ohne Buchung.
Echte 200-Prozent-Schrift bei 320/820 Pixeln ist für Dokumentupload und gemeinsame
Dialoge geprüft; die umgebende Finanzseite ist kein Bestandteil dieser Abnahme.
Genau 20 bewusste Dateien entstehen. Frühere Geschäfts-/Ordnerzeilen, Dateien,
Anfragen, Historien, Notizen, frühere Hinweise und Schlüssel bleiben exakt.
Nur die vier neuen Eingangsdateien erhalten je einen internen Hinweis; keine
externen Aufträge.
Kamera-, Freigabe-, Stapel-, Sprach- und Wiederaufnahme-Regressionen bestehen.
Generation 2026101578, 331 Kerngruppen, keine Schemamigration. Fotoeditor/-Bibliothek,
HSE-/Fristen-/Workflow-Sonderabläufe und andere Fachoberflächen bleiben offen.
[Handbuch](vereinsverwaltung/handbuch/11-dokumente.md).


## Optionaler Fotoeditor und lokale Importmeldungen

Eigene photo:-Browserschlüssel und photo_ui_-Katalogschlüssel trennen
Fotobeschriftungen von anderen Fachbedeutungen. Insbesondere bleibt Redo von
Restore unabhängig. Eigener Bildtext, Originalnamen, Datei-/Werkzeugkennungen,
Decoderfehlerwerte und Pixeloperationen werden nicht durch Sprachwahl verändert.
Eigene Sprachtexte bleiben maskierter Literaltext. Vollständige ursprüngliche
Dateinamen sind auch in sichtbaren Konvertierungsfehlern erhalten.

572 Browserbedingungen/144 Ansichten und 32 Dienst-/Originalbedingungen
bestehen über beide Dispatcher, DE/EN und mit/ohne JavaScript. Tatsächliche
100-/200-Prozent-Schrift bei 320×740, 640×360 und 820×460 Pixeln prüft Dialog,
Regler und sichtbare Werkzeugbereiche. Behobene Überläufe und abgeschnittene
Arbeitsbereiche werden dadurch tatsächlich reproduziert und erneut geprüft.
Abbrechen, Tastatur-Tabs, Undo/Redo, alle Werkzeuge, eigener Bildtext,
Exportfehler/Korrektur, transparente PNG-Datei, tatsächlicher Upload und Download
sowie ein eigenes HTML-/vorlagenähnliches Sprachpaket sind geprüft. Acht bewusste
Fotos entstehen; ganze frühere Zeilen, Originaldateien, Metadaten, Hinweise und
Schlüssel bleiben exakt. 111 Pixel-/Touch- und 98 Importbedingungen sowie native
Sprach-/Import-/Kopierstempelgruppen bestehen. Generation 2026101579, 332 Gruppen,
keine Schemamigration. Foto-Bibliothek, Bearbeitungskopien vorhandener Bilder,
weitere Fachoberflächen und Geräteabnahmen bleiben offen.
[Handbuch](vereinsverwaltung/handbuch/11-dokumente.md).


## Bildbibliothek und gespeicherte Bearbeitungskopien

Die eigenen media_ui_-Katalogschlüssel und media:-Clientschlüssel trennen
Bildbibliothek und Fotoeditor. Fest deklarierte Beschriftungen und Fehler werden
übersetzt; ursprüngliche Credits, Alternativtexte, Quelltitel, Dateinamen und
private Nachweise bleiben Literaltext. Speicherzahlen folgen der persönlichen
Sprache, Speicherzeiten nennen Europe/Berlin. Der Verwendungstitel 0 und
Linkbeschriftungen älterer unbeschriebener Bilder bleiben erreichbar.

479 Browserbedingungen in 264 Ansichten prüfen beide Dispatcher, DE/EN und
mit/ohne JavaScript. Ganze 500-Zeichen-Angaben, 120-Zeichen-Suche, echte
Textverdopplung, Touch/Tastatur, aktuelle und historische Angaben, ursprüngliche
Konfliktversionen, bewusstes Neuöffnen, Archiv/Entfernen/Zurückholen und lesende
Speicherprüfung sind enthalten. Ein aktuelles Lesekonto erhält die Originalangaben
ohne private Nachweise und ohne Schreibrecht; ein fremder Verein bleibt abgewiesen.

Vier tatsächlich gespeicherte PNG-Bearbeitungskopien und vier Uploads mit
verlorener Antwort werden nur durch ausdrücklich gewählte GET-Prüfung bestätigt.
Kein weiterer POST entsteht. Vier native Uploads funktionieren ohne JavaScript.
Bestätigte ungültige Bilddateien hinterlassen keine offene Uploadabsicht. Ganze
frühere Zeilen, Originalbilder, Quellbindungen, Revisionen, Nachweise und Schlüssel
bleiben exakt; nur acht akzeptierte Metadatenänderungen erhöhen den bestehenden
Cachezähler. 14 angrenzende Mediengruppen und 127 Sprachbedingungen bestehen.
Generation 2026101580, 333 registrierte Gruppen, keine Schemamigration. Album- und
weitere Fachoberflächen bleiben offen.
[Handbuch](vereinsverwaltung/handbuch/17-website.md).


## Alben und gemeinsame Inhaltswerkzeuge

572 ausdrücklich deklarierte content_ui_-Katalogtexte und 85 content:-Clienttexte
führen die privaten Albumwerkzeuge in DE/EN. Bildauswahl, Verknüpfungssuche,
Versionsvergleich, Planung, Kopieren und Papierkorb verwenden bekannte UI-Texte;
ursprüngliche Felddefinitionen, Texte, Bildbeschriftungen und Credits bleiben
Literaltext. Statische Editoren erhalten die persönliche Sprachfunktion vom
Albumaufrufer. Die öffentliche Galerie behält ihre konkrete veröffentlichte
Fassung und ist unabhängig von der persönlichen Oberflächensprache.

425 Browserbedingungen in 230 Ansichten prüfen beide Dispatcher, DE/EN und
mit/ohne JavaScript, volle 200-/500-Zeichen-Angaben, wirkliche Textverdopplung,
Touch/Tastatur, ein HTML-/vorlagenähnliches Originalsprachpaket, korrigierbare
Eingaben und ursprüngliche Konfliktversionen. Eine normale Albumbeschreibung
bleibt ohne JavaScript bearbeitbar; unveränderter Text erhält seine Formatierung.
Aktives JavaScript deaktiviert einen inert geparsten Ersatzwert. Bildwechsel über
die nächste begrenzte Seite, Netzfehler mit bewusstem Neuöffnen und Focusrückkehr,
Editor-Undo, tatsächliche Auswahl und Uploads, verlorene angenommene Antworten ohne
zweiten POST, Albumfolge, private Kopien, Papierkorb/Zurückholen und Planung/Abbruch
bestehen. 520 Dienst-/Originalbedingungen, 24 gesonderte native Bedingungen,
397 zusätzliche Original-Browserbedingungen/64 Ansichten und 14 Originalbedingungen
sowie zwölf Fachgruppen und 55 Sprachrendererbedingungen bestehen. Ganze frühere
Zeilen, Originalvarianten, Nachweise, Veröffentlichungen und Schlüssel bleiben
exakt; der vorgesehene Cachezähler wächst nur durch die acht bewussten Entscheidungen.
Generation 2026101581, 334 Kerngruppen, keine Schemamigration. Weitere Website- und
Fachansichten, HSE-/Fristen-/Workflow-Sonderabläufe und Geräteabnahmen bleiben offen.
[Handbuch](vereinsverwaltung/handbuch/17-website.md).


## Operative Gefährdungsprüfungen

113 eigene hse_ui_-Katalogtexte übersetzen feste Bedienung und bekannte Fehler
von Erteilung, Rückmeldung, Nacharbeit, Abnahme, erneuter Prüfung, Abbruch und
Verlauf. Die Formulare bleiben normale serverseitige Formulare mit/ohne
JavaScript. Originaltitel, Vorgaben, Rückmeldungen, Personennamen und genaue
Beurteilungsbezüge bleiben unverändert. Ein eigenes HTML-/vorlagenähnliches
Sprachpaket erscheint als Literaltext. Verfügbare Personennamen werden anhand
expliziter Metadaten von einer fehlenden Zuordnung unterschieden; ein echter
Name wird nicht als vermeintlicher UI-Text übersetzt.

701 Browserbedingungen in 256 Schrift-/Breitenansichten prüfen beide Dispatcher,
DE/EN, mit/ohne JavaScript, Touch/Tastatur und tatsächliche Textverdopplung bei
320/820 Pixeln. Titel und Suche verwenden die vollen 240 Unicode-Zeichen,
Vorgaben und Rückmeldungen 8000, auch mit Emoji. Ungültige Eingaben bleiben
vollständig erhalten; nur das betroffene Formular übernimmt seine ursprüngliche
Version und abgewiesenen Werte. Eindeutige HTTP-Ablehnungen und gezieltes
Neuöffnen schützen den geprüften Stand. Ein nativer Disclosure-Umbruch behebt den
reproduzierten Überlauf bei 200-Prozent-Schrift. Der vollständige paginierte
Originalverlauf erhält auch Titel, Zuständigenkennung, Frist, Status,
Umsetzungstag und festen Beurteilungsbezug. Zeitpunkte nennen Europe/Berlin.

465 Dienst-/Originalbedingungen belegen den Erhalt aller vollständigen früheren
Geschäftszeilen, Aufträge, Verlaufsstände, Vorlagen, Beurteilungen und Schlüssel.
Sowohl der zuvor vorhandene neuere Beurteilungsentwurf als auch die gebundene
freigegebene Originalfassung bleiben exakt. Acht private Aufträge und 80
beabsichtigte Verlaufsstände entstehen. 218 bisherige HSE-Browserbedingungen
mit zehn Speicherungen und sechs native HSE-/Sprachgruppen bestehen. Die
lesende Prüfung am gemeinsamen Manager8111 bestätigt200/422/422 für normale
Ansicht, ungültige Suche und ungültige Verlaufsseite, ohne externe Anfrage.
Generation2026101582, 335 Kerngruppen, keine Schemamigration; Workflow-Schema2
bleibt erhalten. Weitere Beurteilungs-/Vorlagen-/Unterschrifts-/Fristen-/Workflow-
und Fachoberflächen sowie Geräteabnahmen bleiben offen.
[Handbuch](vereinsverwaltung/handbuch/12-fristen-nachweise.md).


## Beurteilungsübersichten und frühere Originalfassungen

Die Beurteilungsübersicht erreicht nun den gesamten Bestand über 25er-Seiten
statt nur die ersten 500 Dokumente. Vorlagen, Dokumentversionen, Aktivitäten
und Veröffentlichungsverläufe erhalten unabhängige normale GET-Seiten mit
Vor-/Zurück- und direkter Seitenauswahl. Suche, Status, Ordner und Rückweg bleiben
verbunden. Prozentzeichen, Unterstriche und Titel/Kommentare `0` bleiben
Originaltext. Ungültige Seiten, Statuswerte und überlange Suchtexte liefern vor
der HTML-Ausgabe422, behalten korrigierbare Filter und zeigen keine fremden
oder unpassenden Ergebnisse. Lesekonten und fremde Vereine erhalten keine
unzulässigen Schreib- oder Originalrechte; abgewiesene Leserkonto-Eingaben
ersetzen niemals die angezeigten gespeicherten Werte.

Die Listen lesen nur begrenzte Originalmetadaten. Große frühere JSON-Fassungen
werden ausschließlich beim ausdrücklichen Öffnen vollständig geladen. Auch die
bisherigen vollständigen Dienst-APIs bleiben erhalten. Ihre Sortierung wählt
zuerst ursprüngliche Kennungen und liest anschließend die vollständigen Daten;
damit verschwindet der mit großen JSON-Originalen reproduzierte MySQL-1038-
Sortierfehler ohne Änderung der Serverpuffer. Eigene Lesetransaktionen liefern
konsistente Seiten; fremde Transaktionen werden weder abgeschlossen noch verworfen.

Eine veröffentlichte Vorlagenfassung lässt sich unabhängig vom heutigen
Arbeitsentwurf vollständig und schreibgeschützt öffnen. Ursprüngliche Kennungen,
Feldarten, Pflichtangaben, Ausfüllhilfen, Auswahlwerte, Profile und Quellen stehen
auf ausdrückliches Aufklappen bereit. Dokumentfassungen und ihre geschützten
JSON-Downloads behalten ihre vollständigen Originalbytes. Eigene Beschriftungen
folgen der persönlichen Sprache; Namen, Titel, Kommentare und Quellenwerte werden
nicht übersetzt. Auch HTML-ähnliche Sprachpakete bleiben escaped Literaltext.
Native aufklappbare Überschriften, Hinweise und Seitensteuerung bestehen bei
wirklich verdoppelter Schrift; Tabellen bleiben im eigenen Tastatur-/Touchbereich.

Die eigene isolierte Abnahme verwendet507 Dokumente,62 große Dokumentfassungen,
100 ursprüngliche Vorlagenfelder und getrennte Kommentarverläufe. Sie prüft
beide Dispatcher, DE/EN, mit/ohne JavaScript,320/820 Pixel,100/200-Prozent-Schrift,
Touch-/Tastaturnavigation, Filterrückweg, korrigierbare Fehler, vollständige erste
Originale und echte Downloads. Alle vollständigen früheren Geschäftszeilen,
ursprünglichen Werte, Versionen und Schlüssel bleiben exakt. Die gemeinsame
Laufzeit8111 besteht lesend mit200/422/422 und ohne externe Anfrage.
622 tatsächliche Browserbedingungen in224 Schrift-/Breitenansichten und549
Dienst-/Originalbedingungen bestehen. Sechs betroffene Sprach-/HSE-/Workflowgruppen
bestehen ebenfalls. Die eingefrorene Funktionsquelle besteht252 echte verschlüsselte
Wiederherstellungs-,256 Installations-,57 Webspace- und155 Upgradebedingungen
mit einer tatsächlichen Upgrade-Browserstrecke. Ein unabhängiger Prozess lädt
nur die wiederhergestellten Leseprojektionen, Vorbereitungs- und Seitenhelfer
und erhält dabei sämtliche vollständigen Datenbankzeilen.
Generation2026101583,336 registrierte Kerngruppen; Schema114 und Workflow-Schema2
bleiben erhalten. P-12/P-25 bleiben teilweise. Die vollständige Schreibabnahme,
dauerhafte Absichtserkennung beim Anlegen, tiefe Ordnerbäume, Gefährdungsregister-,
Unterschrifts-/Erinnerungs-/Fristenansichten und übrige Fachoberflächen sind
weiterzuführen. Geräte-/Finanzabnahmen und abschließende Konsolidierung bleiben
eigene offene Aufgaben. Bedienung im [Dokumentenhandbuch](vereinsverwaltung/handbuch/11-dokumente.md).

## Sichere Anlegebestätigungen in DE/EN

Neue Arbeitsordner, Vorlagen und Beurteilungsdokumente besitzen im vorhandenen
Manager eine dauerhafte persönliche Anfragekennung. Originalabsicht, ursprünglicher
Speicherstand und Ergebnis werden in derselben Datenbanktransaktion angenommen.
Identische Wiederholung liefert dieselbe Anlage, auch nach späterer Umbenennung,
Bearbeitung oder neuer Vorlagenveröffentlichung. Andere Angaben oder eine andere
Anlegeart mit derselben angenommenen Kennung liefern409 und verändern keine Anlage.
Echte konkurrierende Prozesse erzeugen genau ein Original. Eine verlorene
Commit-Bestätigung wird anhand des gespeicherten Vorgangs lesend geklärt;
ein unbestätigtes Ergebnis wird ausdrücklich kenntlich und niemals als sichere
Nichtanlage oder bestätigter Erfolg ausgegeben. Es erfolgt kein automatischer
zweiter Speicher-POST.

**Meine Anlegevorgänge** zeigt nur angenommene Anlagen des aktuellen Kontos und
Vereins in begrenzten25er-Seiten. Reine Lesekonten, andere Vereinskonten und selbst
andere Schreibkonten desselben Vereins erhalten die privaten Anlegeangaben nicht.
**Anlage prüfen** öffnet die lesende Prüfung separat. Ursprünglicher Titel,
Speicherfassung und gebundene Vorlagenveröffentlichung bleiben unabhängig vom
heutigen Stand sichtbar. Vollständige ursprüngliche Angaben und ein geschützter
JSON-Download erlauben die bewusste Prüfung. Fehlerhafte oder veränderte
Originalprüfsummen werden abgewiesen. Auch fehlerhafte numerische Feldkarten
werden nicht in die frühere gültige Reihenfolge umgedeutet. Formulare erhalten
feste Kennungen über ihre tatsächlichen Anlegelinks. Nach einer fehlenden Antwort
führt der native Zurück-Weg zur Verwaltung und ihrer persönlichen Anlegeprüfung.

Unvollständig übertragene Anträge und Vorlagenfelder werden vor einer Anlage
abgelehnt. Korrigierbare Eingaben behalten vollständig übertragene Texte und die
ursprüngliche Kennung. Ordnernamen und Titel erlauben240 Unicode-Zeichen,
Beschreibungen6000, Feldbeschriftungen200 und Ausfüllhilfen2000 einschließlich
Emoji. Die HTML-Grenzen schneiden diese gültigen Zeichen nicht nach UTF-16 ab.
Eigene Steuerelemente und gewöhnliche Vorlagenvorschau folgen DE/EN. Ursprüngliche
Namen, Felddefinitionen, Inhalte und Bewertungsprofile bleiben erhalten. Eine
lange native Auswahlbeschriftung bricht auch ohne JavaScript bei wirklicher
Textverdopplung innerhalb des Formulars um.

Workflow-Modul3 ergänzt ausschließlich die eigene Anlegeprotokolltabelle.
Das Basisschema bleibt114. Ein tatsächlich gefüllter Workflow-2-Bestand wird
additiv aktualisiert; sämtliche früheren Tabellen, vollständigen Geschäftszeilen,
andere Modulstände und Schlüssel bleiben exakt. Frühere Anlagen erhalten keine
erfundenen Bestätigungen. Die gemeinsame eigene Laufzeit8111 besitzt diese
leere neue Tabelle und Modulstand3; ihre früheren Originale und Schlüssel sind
vollständig unverändert nachgeprüft. Die vorhandenen Dienst-APIs bleiben ohne
Anlegekennung kompatibel; aufrufende Dienste verwenden für diese Garantie die
neue eigene Kennung.

446 tatsächliche Browserbedingungen in160 Schrift-/Breitenansichten und497
Dienst-/Originalbedingungen bestehen einschließlich44 gezielter Fachbedingungen.
Beide Dispatcher, DE/EN und mit/ohne JavaScript prüfen24 bewusste Anlagen,
volle Unicode-Grenzen, korrigierbare Fehler, echte Verbindungsabbrüche beziehungsweise
eine verlorene angenommene Antwort hinter einer Gateway-Fehlerseite, den tatsächlichen
nativen Zurück-Weg und die genaue manuelle Prüfung über die sichtbare Prüfkennung.
Die Prüfung zeigt stets genau die angenommene ursprüngliche Anfrage und sendet
keinen zweiten automatischen POST. Ganze frühere Geschäftszeilen, Schlüssel,
Beurteilungen und Unterschriften bleiben exakt; nur die24 beabsichtigten
Anlegeprotokolle und ihre acht Ordner, Vorlagen, Veröffentlichungen und Dokumente
mit jeweils erstem Versions-/Aktivitätseintrag entstehen. Acht betroffene native
Sprach-/HSE-/Workflowgruppen bestehen ebenfalls. Die eingefrorene Funktionsquelle
besteht254 echte Wiederherstellungs-,257 Installations-,57 Webspace- und155
Upgradebedingungen mit einer tatsächlichen Upgrade-Browserstrecke. Ein unabhängiger
wiederhergestellter Prozess erhält die drei ursprünglichen Anlegebestätigungen
trotz späterer Änderungen und wiederholt ihre ursprünglichen Anfragen ohne
Änderung einer einzigen wiederhergestellten Zeile. Die gemeinsame Laufzeit8111
besteht weiterhin lesend mit200/422/422, ganzer Suchangabe und ohne externe Anfrage.
Generation2026101584,337 registrierte Kerngruppen. P-12/P-25 bleiben teilweise;
tiefe Ordnerbäume, vollständige weitere Schreib-/Vorlagen-/Gefährdungsregister-,
Unterschrifts-/Erinnerungs-/Fristenoberflächen und übrige Fachansichten folgen.
Geräte-/Finanzabnahmen und abschließende Konsolidierung bleiben eigene Aufgaben.
Bedienung im [Dokumentenhandbuch](vereinsverwaltung/handbuch/11-dokumente.md).
