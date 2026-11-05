# Dokumenten- und HSE-Fristen im gemeinsamen Kalender

Der Kalenderfilter **Dokumenten- & HSE-Fristen** liest vorhandene Prüftermine
und Entscheidungs-/Klärungsfristen direkt aus dem Dokumentenmodul. Berücksichtigt
werden `review_date` und `deadline`, wenn die für das Dokument gespeicherte
Vorlagenfassung sie als Datumsfelder definiert. Datum der Beurteilung,
Freigabedatum und Freitext werden nicht als Termine ausgelegt. Ergänzte
strukturierte Maßnahmenfristen pro Gefährdungszeile erscheinen bis zum
ausdrücklichen Erledigungsdatum; [Bedienung](HSE-MASSNAHMENFRISTEN.md).

Ganztägige Fristen gelten in Europe/Berlin. Fallen zwei fachliche Fristen auf
denselben Tag, nennt ein Kalendereintrag beide Beschriftungen. Entwürfe,
eingereichte, zurückgegebene und intern freigegebene Dokumente behalten ihren
Originalstatus. Eine interne Freigabe bedeutet keine abgeschlossene Tätigkeit
und entfernt den nächsten Prüftermin nicht.

Der Eintrag öffnet die betreffende Originalfassung unter **Dokumente →
Gefährdungsbeurteilung**. Spätere Änderungen erscheinen sofort im Kalender;
alte freigegebene Fassungen bleiben erhalten. Der lesende Zugriff erzeugt keine
Terminkopie, PDFs, Erinnerungsaufträge oder Dokumentrevisionen. Nur Titel,
Fristbeschriftungen, Status und Originalverweis verlassen das Dokumentenmodul.

Der optionale Filter erscheint erst nach expliziter Installation des
Dokumentenschemas und nur mit aktuellen Modulrechten. Ein persönlicher
Unterschriftenzugang erlaubt keine allgemeine Einsicht. Leser benötigen keine
Vorstandsberechtigung; entzogene Rechte wirken auch auf bestehende Kalenderdienste.
Die Fristenauswahl ist unabhängig von der auf 500 Dokumente begrenzten
allgemeinen Katalogseite.

`WorkflowStore::deadlines()` prüft Zeitraum und Originalrechte. Die kleine
Klasse `WorkflowDeadlines` hält die Fachregeln für gespeicherte Datumsfelder;
`Events/Calendar/WorkflowSource` bildet ausschließlich Kalendermetadaten ab.

Prüfung: `bash tools/tests/run-mysql.sh calendar-workflows.php`. Der echte
MySQL-Ablauf umfasst Versionswechsel, festgelegte Vorlagenfassungen, über 500
Originaldokumente, Monats-/Zeitwechsel, Lese-/Fremdvereinsrechte und unveränderte
Originaltabellen. Der angemeldete Browser öffnet dieselbe freigegebene Fassung
per Tastatur bei 1440/820/390 Pixeln und prüft die Abweisung eines Mitglieds.
Der Browserablauf ist in `tools/cloud/bootstrap.sh --verify` enthalten.

Weitere HSE-Fachabläufe bleiben im Umsetzungsregister. Ein Altimport entfällt.
Freitext erzeugt weiterhin keine Termine; dafür stehen eigene Datumsfelder in
den Gefährdungszeilen bereit.
