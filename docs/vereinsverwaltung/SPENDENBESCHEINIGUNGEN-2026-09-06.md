# Spenden und Zuwendungsbescheinigungen

## Erstellen und aufbewahren

Unter Spenden den Geld- oder Sachspendenbeleg öffnen und
„Zuwendungsbescheinigung erstellen / verwalten“ wählen. Die Vorschau bleibt
ein Entwurf. Die neue Ausgabe „Bescheinigung zur Unterschrift erstellen“
erzeugt ein nummeriertes amtliches PDF ohne Entwurfskennzeichnung. Es muss
ausgedruckt und von der hinterlegten zeichnungsberechtigten Person
unterschrieben werden. Das unterschriebene Doppel als separates Finanzdokument
beim selben Spendenbeleg hochladen. Die Anwendung erzeugt keine Unterschrift
und behauptet keine automatische elektronische Ausstellung oder Zustellung.

Geldbestätigungen benötigen tatsächlich zugeordnete Zahlungen. Bei mehreren
Zahlungstagen eine einzelne Zahlung wählen. Sachspenden übernehmen Wert,
Datum, Herkunft und Unterlagenvermerk aus dem gespeicherten Beleg; eine
dokumentierte Wertermittlung ist erforderlich. Fehlende Anschriften und
Steuerdaten verhindern die Ausstellung, nicht die Spendenbuchung.

## Voraussetzungen

Vollständige Vereinsanschrift in Vereinsdaten speichern. Den aktuellen
Freistellungs- oder Feststellungsbescheid als PDF-Finanzdokument hochladen und
unter Steuereinstellungen auswählen. Finanzamt, Steuernummer, Bescheiddatum,
Veranlagungszeitraum und die begünstigten Zwecke aus dem Bescheid übernehmen.
Zeichnungsberechtigte Person und Prüfung der Ausstellungsberechtigung
hinterlegen. Die Abziehbarkeit von Mitgliedsbeiträgen ausdrücklich einordnen;
aus der Bezeichnung „Kulturverein“ wird sie nicht abgeleitet.

Unter **Steuerdaten & Freistellung** werden diese Angaben in einem eigenen
Entwurf vollständig geprüft und danach gesondert bestätigt. Finanz-Schreibrecht
genügt für die Einstellungen; für den Bescheid sind zusätzlich alle aktuellen
Dokument- und Ordnerrechte erforderlich. Suche, Sechserseiten und Bescheidauswahl
erhalten die übrigen Angaben auch ohne JavaScript. Die Vorschau zeigt die
tatsächliche PDF-Fassung und ihre Prüfsumme. Eine neue Datei, Umbenennung oder
Rechteänderung kann eine noch unbestätigte Prüfung entwerten.

Bei fehlender Bestätigungsantwort zuerst **Ursprünglichen Steuerdatenausgang
lesend prüfen** öffnen. Die erste persönliche Bestätigung bleibt mit ihren
ganzen ursprünglichen Angaben, damaliger Bescheidquelle und genauen JSON-Bytes
erhalten. Eine alte rohe Einstellungsfassung ist getrennt lesbar und beweist
keine damalige Zustimmung. Aktuell entzogener Zugriff auf den Bescheid sperrt
auch bekannte persönliche Bestätigungen mit dieser Quelle.

Diese Einstellungsbestätigung erstellt keine Spendenbescheinigung, erteilt keine
Zeichnungsberechtigung und übermittelt keine Steuererklärung. Technische
ELSTER-Herstellerkennung und Vereinszertifikat bleiben eigene berechtigte Vorgänge.

Die Ausgabe übernimmt die gespeicherten Steuerdaten. Der Snapshot hält
Bescheid-Dokument, Dateiversion, Prüfsumme, Einstellungenversion und
zeichnungsberechtigte Person fest. Alter und Vollständigkeit des Bescheids
werden technisch geprüft; die inhaltliche Prüfung bleibt beim Verein.

Die Ausstellung prüft die tatsächlich aktuelle PDF-Fassung auch aus einem bereits
geöffneten Vorgang. Eine inzwischen ersetzte oder archivierte Bescheidquelle kann
keinen neuen Nachweis begründen. Nach bewusstem Austausch wird die tatsächliche
neue Fassung samt Prüfsumme verwendet; frühere ausgestellte PDFs bleiben erhalten.

## Schutz vor Doppelbescheinigung und Korrektur

Migration 032 ergänzt ein Register mit eindeutiger Belegnummer, Antragsschlüssel,
Aussteller und Dokumentverknüpfung. Aktive Zuordnungen reservieren jede
bescheinigte Zahlungszuordnung beziehungsweise Sachspende. PDF-Ablage und
Registereintrag werden gemeinsam in einer Datenbanktransaktion gespeichert.
Wiederholungen desselben Formulars liefern dasselbe Original; ein neues
Formular kann dieselbe Zuwendung nicht nochmals bescheinigen.

Bescheinigte Zahlungen lassen sich nicht aufheben oder gegenbuchen, solange
die Bescheinigung aktiv ist. Ein begründetes Storno am Spendenbeleg archiviert
das Dokument und protokolliert die Korrektur. Das Original bleibt unverändert.
Danach sind Korrektur und neue Ausstellung möglich. Bereits ausgehändigte
Exemplare müssen separat zurückgerufen werden; Storno versendet keine Mail.
Erzeugte Bescheinigungen können nicht über die allgemeine Dokumentbearbeitung
umbenannt, neu zugeordnet oder überschrieben werden.

## Grenzen

Einzelbescheinigungen für eingegangene Geldspenden und erfasste Sachspenden
sind umgesetzt. Aufwandsspenden ohne Geldfluss und Sammelbescheinigungen
werden durch diesen Ausbau nicht als fertig behauptet. Eine elektronische
Ausstellung ohne eigenhändige Unterschrift ist nicht aktiviert.

## Prüfung

75 bestehende Formularprüfungen, 29 neue Register-/Ausstellungsprüfungen,
3 Prüfungen der Finanzrollen in der Formularansicht sowie 15 PDF-Prüfungen.
Bestehende Suites: Grundlagen 72, Finanzen 60, Matching 53, Kontierung 56,
Einstellungen 87, Finanz-Leseansichten 45, Beitragssätze 27,
Verknüpfungen 30, Dokumente 74. Test-PDFs beider Formulararten wurden
gerendert und visuell geprüft. Alle Testdaten bleiben isoliert; keine echte
Spende oder Bescheinigung wurde als Test in die Live-Buchhaltung geschrieben.

## Quellen und Betriebsdaten

Die amtlichen Originale bleiben erhalten. Maßgeblich sind die
[BMF-Hinweise zur Verwendung der Muster](https://esth.bundesfinanzministerium.de/esth/2025/B-Anhaenge/Anhang-37/I/anhang-37-I.html).
Vereinsanschrift und Vereinsregister wurden am 6. September aus dem
[Live-Impressum](https://www.kulturhus.de/impressum/) geprüft:
Georgstraße 78, 26349 Jaderberg; Amtsgericht Oldenburg (Oldenburg), VR 201645;
vertreten durch Paul Wehlage, 1. Vorsitzender. Steuerbescheid noch ausstehend.

Der Nutzer hat am selben Tag den Eingang der ausdrücklich beauftragten
Outlook-Testmail bestätigt. Das bestätigt den tatsächlichen Mailtransport
bis zum Empfänger, nicht den noch ausstehenden gesamten Aufnahme- und
Rechnungslauf.

## Live-Veröffentlichung

Commit `49a2f15` wurde am 6. September live veröffentlicht: 16 Laufzeitdateien
gegen den Vorzustand geprüft, privat gesichert, PHP-syntaktisch geprüft und
nach Veröffentlichung bytegenau zurückgelesen. Migration 032 ist aktiv;
keine Fremdschlüsselverletzungen. Private Sicherung:
`/tmp/kulturhus-verein/rollback-donation-receipts-20260906/`.

Die geprüften Vereinsdaten aus dem Impressum wurden über den normalen
Einstellungsdienst mit Revision und Audit gespeichert (Vereinsdatenversion 1).
Keine Steuerdaten oder Bankdaten erfunden. Der Live-Renderer erzeugt ein
gültiges PDF (63.942 Bytes im isolierten Entwurfstest). 32 öffentliche,
Gast- und Privatpfad-Prüfungen bestanden. Das Live-Bescheinigungsregister
bleibt leer. Hinterlegter Steuerbescheid und fachlich bestätigte
Zeichnungsberechtigung fehlen weiterhin; deshalb wurde keine echte
Bescheinigung ausgestellt.
