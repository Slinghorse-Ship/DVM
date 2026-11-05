# Eigene Website-PDFs

Eine Inhaltsseite unter **Website → Seiten** öffnen. Unter **PDF-Dokumente →
PDF hinzufügen** eine oder mehrere eigene PDF-Dateien auswählen und den
**Entwurf speichern**. Dateien, Titel und Inhalt werden gemeinsam gespeichert.
Die Auswahl funktioniert auch ohne JavaScript. Pro Auswahl sind zehn Dateien
bis jeweils 10 MiB und insgesamt 20 MiB möglich; eine Seite kann bis zu zwanzig
Dokumente enthalten. Einen Linktitel bearbeiten oder **Entfernen** ankreuzen,
um die nächste private Fassung anzupassen.

**Veröffentlichen** macht die zugehörigen Dokumente öffentlich. Ein neuer
Entwurf oder eine private Entfernung verändert die veröffentlichte Fassung
nicht. **Offline nehmen**, Archiv und Papierkorb beenden den öffentlichen
Zugriff. Die Rückkehr in die Bearbeitung veröffentlicht keine Dateien. Frühere
Versionen und Seitenkopien behalten ihre ursprünglichen Dokumentverweise.

Uploads bleiben eigene, bytegleiche Originale außerhalb des Webroots; keine
Satzungen oder Rechtsinhalte werden erzeugt oder importiert. `document_links`
speichert höchstens zwanzig geordnete Original-IDs, Linktitel, ursprüngliche
Dateinamen, genaue Größen und SHA-256-Prüfsummen. Namen und Hashes kommen vom
Server. Neue Dateien und die Seitenfassung werden in einer Transaktion
gespeichert; bei Fehlern oder veralteten Versionen verschwinden ausschließlich
die dabei neu angelegten Kopien. Vorherige PDFs und Inhaltsfassungen bleiben
erhalten. Fehlerhafte Uploads erhalten die Texteingaben mit und ohne JavaScript;
Dateien nach einem gewöhnlichen HTML-Fehler erneut auswählen. Nicht bestätigte
Speicherungen werden nicht automatisch wiederholt.

Die öffentliche Route `/website-document/{id}` prüft den aktuellen eigenen
veröffentlichten Originalverweis einschließlich Dateiname, Größe und Hash.
Private Entwürfe und fremde Vereine bleiben gesperrt. GET liefert die genaue
PDF als Download mit `nosniff`, Sandbox und `no-store`; HEAD liefert die
ursprüngliche Länge ohne Inhalt. Andere Methoden sind gesperrt. Die private
Redaktionsausgabe verwendet denselben geprüften Originalspeicher und aktuelle
Website-Leserechte.

CMS 14 ergänzt ausschließlich `khcms_documents`, ohne bestehende Dokumente,
Seiten, Bilder, Rechte oder Freigaben zu verändern. Explizite Wartung über den
bestehenden Installer installiert die Tabelle; normale Webanfragen führen keine
Schemaänderung aus. Die verschlüsselte Sicherung und Wiederherstellung enthalten
öffentliche und private Original-PDFs samt ihren genau erhaltenen Zuordnungen.

66 Fach-/Original-/Rechte-/HTTP-Verträge bestehen; 209 tatsächliche
Browserverträge speichern sechzehn neue PDFs auf acht eigenen Seiten. Alle
früheren Datenbankzeilen, Revisionen und Dateibytes sind unverändert.

`native-website-documents` prüft Upload, Rechte, Originalbytes, Kopien,
Versionskonflikte, Rückholabläufe, Fehleransichten und echte HTTP-Ausgabe
einschließlich `/verein/satzung/`. `website-documents-app-browser.mjs` prüft
beide tatsächlichen Anwendungen bei 390/320/820 Pixeln, 200 % Zoom, Touch und
ohne JavaScript. Frische eigene Fälle aus
`tests/support/website-documents-app-fixture.php prepare` verwenden und danach
mit `check` sämtliche vorherigen Datenbankzeilen, Revisionen und Dateien
nachweisen. Private Admin-Sitzungen vor der Baseline erneuern.
