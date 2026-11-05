# Pressefotos als Dateianlagen

Unter **Presse → Redaktion → Fotoanlagen** lassen sich Fotos unabhängig vom
Mailtext auswählen und hochladen. Ein Foto darf zugleich im Text stehen und als
vollständige Datei angehängt sein; die zusätzliche Dateianlage wird bewusst gewählt.
Die Bildauswahl enthält den vollständigen eigenen Bestand mit Suche und sechs
Treffern pro Seite. Ausgewählte Fotos erscheinen in vierteiligen Seiten und lassen
sich verschieben oder entfernen. Die Anzahl in der Zusammenfassung aktualisiert
sich unmittelbar. Größere Mehrfachuploads dürfen als Entwurf gespeichert werden;
die Grenze von zehn Anlagen pro E-Mail gilt erst vor Annahme des Versandauftrags.

Die vorhandene Fotoverarbeitung erkennt tatsächliche Bilddaten. HEIC und RAW
werden im Browser automatisch umgewandelt; der freiwillige Fotoeditor bleibt
verfügbar. Die Auswahl und eigene Texte bleiben erhalten. Eine verlorene
Upload-Antwort führt zur lesenden **Uploads prüfen**-Abfrage der eigenen
Upload-Kennung. Das bereits angenommene Foto kann anschließend zur Auswahl
hinzugefügt werden. Die Abfrage lädt es nicht erneut hoch.

Ohne JavaScript stehen die normale Mehrfachauswahl und der gemeinsame
Mehrfachupload für direkt unterstützte Bilddateien zur Verfügung. Beim Wechsel
oder Suchen von Presseempfängern werden gleichzeitig ausgewählte Uploads als
eigene Fotos angenommen und in der ungespeicherten Auswahl erhalten. Die
Pressefassung und ihre ursprüngliche Versionsnummer bleiben dabei unverändert.
Das eigentliche Speichern verbindet Upload und Entwurf atomar. Fehler behalten
Auswahl, eigene Texte und eingereichte Version. Formulare ohne das neue Anlagenfeld
entfernen keine bereits gespeicherten Fotoanlagen.

Freigabe, Empfänger und Versandzeitpunkt folgen dem bestehenden Presseablauf.
Beim Einplanen werden genau die Originaldateien der ausgewählten eigenen Fotos
zusätzlich zum tatsächlichen Mailtext verschlüsselt festgehalten. Dateinamen passen
zum erkannten gespeicherten Format; Dateisystempfade werden nicht übernommen.
Originalhash, vorhandene Bildangaben, optional konfigurierte Rechteprüfung,
aktuelle Veröffentlichungserlaubnis und Empfänger werden geprüft.
Mehr als zehn Anlagen, zu große Nachrichten, fremde Fotos oder veränderte
Originaldateien erzeugen keinen teilweisen Versandauftrag.

Bereits angenommene Mailoriginale werden bei einer wiederholten Anfrage aus ihren
festgehaltenen Bytes weiterverwendet. Spätere Änderungen im Bildspeicher schreiben
das Original nicht um. Bestätigte Zustellung wird nicht wiederholt; ein unbekanntes
SMTP-Ergebnis bleibt offen und wird nicht automatisch erneut gesendet.
Der gemeinsame MIME-Baukasten erzeugt tatsächlich separate Dateianlagen.
Fotos, die nur als Anlage gewählt wurden, werden nicht zusätzlich unsichtbar in
den Mailtext eingebettet. Die Auswahl bleibt eine eigene Bildverwendung und ist
auch im globalen Entfernungsschutz enthalten.

In Lesefassung und ursprünglicher Vorschau zeigt **Fotoanlagen** sechs Dateinamen
pro Seite. Die Downloadadresse gehört genau zur eigenen Pressefassung und ihrer
ursprünglichen Anlagenauswahl. Presse-Lesekonten benötigen dafür keinen allgemeinen
Mediathekzugang. Fremde oder nicht ausgewählte Fotos, unpassende Fassungen und
aktuelle Rechteentzüge werden abgewiesen. GET/HEAD bleiben privat und ohne Cache;
HEAD liefert keine Datei. Die Downloadantwort kennzeichnet eine Dateianlage.

37 Fach-/MySQL-/MIME-Prüfungen bestehen. 105 tatsächliche Browserprüfungen mit
21 Formularaktionen prüfen beide Anwendungen, mit/ohne JavaScript, 24 Zustände bei
320/390/820 Pixel und doppelter Schrift. Tatsächliche HEIC-/RAW-Konvertierung,
Mehrfachupload, Empfängersuche, Annahme eines Uploads mit verlorener Antwort,
Speichern, Freigabe, vorgemerkter Versand und private Originaldownloads sind
geprüft. Zehn zusätzliche Prüfungen lesen die tatsächlich aus diesen Bedienwegen
entstandenen Mailoriginale und bauen daraus die vollständigen separaten MIME-
Dateianlagen. Kein echter Mailversand.

Die verschlüsselte Wiederherstellung bewahrt Originalciphertext, Job-/Quellenbindung
und vollständige Datei-MIME-Bytes. Presse-Lesekonten lösen die ursprünglichen
Dateien unter dem neuen privaten Speicher auf. Keine neue Tabelle oder
Modulversion; Kommunikation bleibt 14, Paketgeneration 2026101309.
180 native Suiten registriert; kein neuer vollständiger Lauf aller 180 Suiten.

```sh
DVM_PRESS_PHOTO_ATTACHMENTS_BROWSER=1 bash tools/ci/local-native.sh --core native-press-photo-attachments
bash tools/ci/local-native.sh --core native-press-delivery native-press-recipients native-press-event-sources native-media-usage native-media-references native-page-photo-upload native-page-photo-status native-upload-outcomes
bash tools/ci/local-native.sh --core native-installer native-webspace-installer native-recovery native-package-upgrade native-restore-resume
```

Der konkrete Presse-Fotoanlagenweg ist umgesetzt. Andere Dateitypen und allgemeine
Dateifeld-/Plugin-Parität sind kein dadurch erfüllter Zusatzauftrag. Weitere
benötigte Grundabläufe und die vollständige Fach-/Rollen-/UI-Abnahme bleiben anhand
beider Register zu prüfen. Apps folgen nach dem bestehenden Ausbau, die gemeinsame
Statusseite zuletzt. Gefährdungsbeurteilung und operative Gefährdungsprüfung bleiben
getrennt.

## Originalgetreue Bildauswahl, 16. Oktober 2026

Die Presseauswahl bestimmt Feldnamen, Uploadnamen und Beschriftungen vor der
Ausgabe. Bildbeschreibungen und Originaldateinamen werden dabei nicht ersetzt;
auch Wörter wie „Bilder auswählen“ oder „native-page-media“ bleiben im eigenen
Text unverändert. Die gewählte Reihenfolge bleibt erhalten. Der gewöhnliche
Seiten-/Albumauswahldialog und der Presseupload ohne Website-Bearbeitungsrecht
verwenden weiterhin ihre bestehenden Eingabefelder und Rechte.

Die kuratierte Fassung besteht 47 Fotoanlagen- und 21 Uploadrechtebedingungen.
Zehn neue Prüfungen lesen die tatsächlichen Auswahl-DOMs, sichern Reihenfolge,
Feldzuordnung und aktuelle Rechteentzüge und vergleichen 429 ursprüngliche
Tabellen sowie 64 Original-/Variantdateien exakt. Diese Ergänzung behauptet
keine neue Browsermatrix und löst keinen Mailversand aus. Paketgeneration
2026101542; unverändert 298 registrierte Kerngruppen.
