# Karussells bearbeiten und vollständig bei Meta veröffentlichen

In der Social-Redaktion ist das Format **Karussell** auswählbar. Zwei bis zehn
Bilder gehören zu einem Beitrag. Jede Karte erhält eigene menschliche Titel,
Texte und optional ein eigenes Foto. Die gemeinsame lokale Fotoerkennung und
HEIC-/RAW-Konvertierung bleibt verfügbar; die Rechteprüfung bleibt optional.
Die Karussellvorlage beginnt mit einem privaten leeren Entwurf.

Die mobile Bearbeitung zeigt eine aktive Karte und eine direkte Bildauswahl.
Reihenfolge, Hinzufügen, Entfernen und Rückgängig sind verfügbar. Das Bildstudio
bearbeitet jede Karte unabhängig als 1080 × 1350-PNG/JPEG. Die Originalhistorie
enthält höchstens sechs Fassungen je Seite. Bilderzeugen, gesamte Freigabe und
Einplanen sind auch ohne JavaScript möglich. Formatwechsel und Sortierung ohne
JavaScript schreiben noch keinen Entwurf. Fehler erhalten die eigenen Texte,
Reihenfolge und ausdrücklich eingereichte Versionsnummer; ein Konflikt setzt
keine neuere gespeicherte Fassung zurück.

Eine Freigabe umfasst alle Originalkarten in exakter Reihenfolge. Fehlende
PNG/JPEG-Dateien, geänderte Gestaltung, alte Inhalte, abgelaufene Zugänge oder
entzogene Rechte verhindern die Veröffentlichung. Jeder ausgewählte Kanal
bekommt genau einen dauerhaft verschlüsselten Auftrag für den ganzen Beitrag.
Erneutes Einplanen derselben Fassung erzeugt weder weitere Jobs noch Zugänge.
Frühere unklare Ergebnisse desselben Kanals müssen zuerst geprüft werden.

Facebook erhält unveröffentlichte Seitenfotos und danach **einen** Seitenbeitrag
mit der gesamten geordneten Bildsammlung. Instagram erhält einzelne
Karussell-Bildcontainer, einen gemeinsamen CAROUSEL-Container und dessen
Veröffentlichung. Die JPEG-Zugänge sind zeitlich begrenzt und an den ganzen
Originalauftrag, die aktuellen Vereinsdaten, Verbindung und Berechtigungen
gebunden. Eine Änderung oder ein sicher gestoppter Auftrag macht sie ungültig.
Der Wechsel zu einem anderen Format bleibt eine private neue Revision; frühere
Karussellbilder bleiben erhalten. Er umgeht kein unklares Veröffentlichungsergebnis.

Vor jedem Anbieter-Schreibschritt wird seine Absicht dauerhaft gespeichert.
Nach bestätigter Antwort werden Anbieterkennung und nächster Worker-Zustand
zusammen gespeichert. Das geschieht außerhalb der zurückgerollten
Transporttransaktion. Jeder Worker-Schritt prüft aktuelle Quellen, Rechte,
Konten und Originaldateien erneut; bestätigte Schritte können sofort folgen.
Eine noch laufende Verarbeitung wird später gelesen. Der abschließende
Veröffentlichungsstatus beruht auf einem unabhängigen Anbieterabruf.

Eine verlorene Antwort auf einen Schreibaufruf löst keine automatische
Wiederholung aus. Ein bekanntes Instagram-Karussell wird über seinen ursprünglichen
Container geprüft. Facebook wird über den bekannten ganzen Beitrag oder höchstens
vier Seiten mit je 50 eigenen Beiträgen abgeglichen. Titel/Text, eigene Seite und
sämtliche ursprünglichen Foto-IDs müssen exakt passen. Ein unvollständiger Abruf
oder mehrere Treffer bestätigt keine eindeutige Veröffentlichung. Der Abgleich
sendet ausschließlich GET-Anfragen an Meta. Nur vollständig bestätigte
Vorbereitungsschritte dürfen anschließend weiterlaufen.

Nach einem harten Worker-Abbruch bleibt die gespeicherte Verarbeitung gesperrt.
Ein Abgleich übernimmt keinen frischen laufenden Auftrag; nach zehn Minuten
kann er den ursprünglichen abgebrochenen Auftrag als unklar prüfen. Eine
unklare private Vorbereitung lässt sich ausdrücklich beenden, sofern die
gespeicherten Schritte eine mögliche öffentliche Veröffentlichung ausschließen.
Dabei bleiben Originaldateien, Quellen und sämtliche Fortschritte erhalten.

Kommunikation **12** ergänzt ausschließlich zwei eigene leere Tabellen für
Fortschritt und gebundene Dateizugänge. Das Upgrade benötigt den Installer;
normale Webanfragen führen keine DDL aus. Die verschlüsselte Wiederherstellung
nimmt ganze Originalfreigaben, genaue Anbieterabsichten, Zugänge und PNG/JPEG-Dateien mit.

Lokale Abnahme: 62 ursprüngliche Bild-/Freigabeverträge, 249 Meta-/MySQL-Verträge
mit getrenntem Verbindungsnachweis vor jedem POST, verlorenen öffentlichen
Antworten, tatsächlich beendetem Worker und verlorenem angenommenem COMMIT.
Dazu fünf Formularverträge und 59 tatsächliche Chromium-Prüfungen beider
Controller: 320/390/820 Pixel, 200 % Textgröße, 44-Pixel-Touchziele, Tastatur,
JavaScript sowie vollständige Erstellung/Freigabe/Planung ohne JavaScript.
Frühere komplette Datenbankzeilen und Originaldateien werden bytegleich verglichen.

Alle API-Verträge verwenden ausdrücklich synthetische Provider. Ein echtes
Meta-Konto mit erforderlichen Berechtigungen, App-Freigabe und funktionierendem
privatem Worker-Zeitplan ist eine Betriebsvoraussetzung, kein durch diese Tests
behaupteter Live-Versand. Weitere Social-Funktionen, übrige Registeraufgaben,
danach Apps und abschließend Statusseite werden getrennt weiter abgearbeitet.

Alle 169 nativen Suiten bestehen einschließlich Installer/HTTP/Recovery
217/52/138. Finale Formatwechsel-/Duplikatschutzkorrektur separat mit acht
betroffenen Suiten und 59 tatsächlichen Browserprüfungen bestanden.
