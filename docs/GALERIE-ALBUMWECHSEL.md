# Durchgehender Bildwechsel im Album

Die Großansicht wechselt vorwärts und rückwärts zwischen den Bilderseiten des
Albums. Nach Foto 6 folgen Foto 7 und Foto 8, danach wieder Foto 1; rückwärts
entsprechend. Pfeiltasten, Bildwechselbuttons und Wischen benutzen denselben
Ablauf. Die Anzeige nennt die tatsächliche Position im ganzen Album. Auch die
drei Vorschaufotos der Albumübersicht können in die weiteren Fotos übergehen.
Die normale Übersicht und die geöffnete Bilderseite bleiben dabei kurz.

Jeder benötigte Seitenschritt liest eine begrenzte vorhandene Bilderseite über
den jeweiligen echten privaten oder öffentlichen Controller. Er lädt keine
vollständige Liste aller Fotos vor. Bereits sichtbare Bilder wechseln sofort.
Nicht nutzbare Fotos werden übersprungen; pro Schritt werden höchstens fünf
kurze Bilderseiten geprüft. Gibt es dort kein zugängliches Bild, bleibt das
vorherige sichtbar und das Album kann ausdrücklich neu geöffnet werden.

Die geladenen Bildangaben gehören weiterhin zur anfänglich geöffneten
Albumfassung, zum selben Album und zur gleichen privaten oder öffentlichen
Ansicht. Eine geänderte Fassung ersetzt keine bereits geöffnete Originalansicht.
Der echte Server prüft aktuelle Zugangs- und Nutzungsrechte bei jedem Abruf.
Private aktuelle Bildangaben und die öffentliche veröffentlichte Fassung bleiben
getrennt. Alternativtexte, sichtbare Beschreibungen, Credits, Quellen und
Lizenzlinks wechseln gemeinsam mit dem jeweiligen Bild.

Während eines laufenden Abrufs starten weitere schnelle Bildwechsel keine
zusätzliche Anfrage. Schließen und Escape brechen den Vorgang ab und geben den
Fokus an das ursprüngliche Foto zurück. Wird die Großansicht danach erneut
geöffnet, darf keine verspätete Antwort des vorherigen Vorgangs ein Bild oder
seinen Ladezustand ersetzen. Fehler erhalten das letzte Bild, seine Angaben und
den ursprünglichen Zähler. „Album neu öffnen“ führt auf den richtigen vorhandenen
Albumweg. Der Link und der Zähler bleiben auch am kleinen Handy mit großer Schrift
getrennt bedienbar.

Nachgeladene HTML-Antworten werden ausschließlich als inaktiver Templateinhalt
gelesen. Ihre Skripte, Bilder und Frames werden nicht eingebunden. Nur die
überprüften Bildbeschreibungen und der originale bereits erzeugte Fotocredit
werden für das tatsächlich gewählte Bild verwendet. Es gibt keine neue
CMS-Plattform-API, Tabelle, Zahlungs-/Versandfunktion oder Schemaänderung.
Ohne JavaScript bleiben die bestehenden kurzen Bilderseiten und tatsächlichen
direkten großen Bildlinks vollständig bedienbar.

11 Fach-/397 tatsächliche Browserprüfungen und zwei vollständige UI-Original-
prüfungen bestehen. Beide echten privaten Controller und beide öffentlichen
Galeriewege, mit und ohne JavaScript. 64 Ansichten bei 320/390/820 Pixeln und
200 % Schrift, 38 begrenzte GET-Bildseitenversuche; keine Formular-POSTs,
externen Aufrufe oder Aktivierung eingeschleuster Ressourcen. Normaler Wechsel,
Seitengrenzen und Vorschauübergang, geänderte Fassung, HTTP-Ablehnung,
verzögerte/parallele Eingaben, tatsächlicher Abbruch und frische Wiederöffnung,
entzogene echte öffentliche PHP-Leserechte und sichere bewusste Wiederholung.
Sämtliche vorherigen Fachoriginale, verschlüsselte Einwilligungen, veröffentlichten
Albumfassungen und Bilddateien bleiben erhalten.

Generation 2026101405, weiterhin 190 registrierte Suiten. Paket-/Verlagerungs-
prüfung: 229 Installer-, 52 HTTP-Installer-, 182 Recovery-, 142 Upgrade- und
27 Fortsetzungsprüfungen, 90 tatsächliche Upgrade-Browserprüfungen und drei
echte Prozessabbrüche. 13 verschiedene native Gruppen einschließlich der fünf
Paketgruppen sowie Inventar-/Paket-/Offlineprüfung grün. Kein neuer
vollständiger Lauf aller 190 Suiten und kein Gesamtabschluss. Übrige Fach-/
UI-Arbeit bleibt offen; Apps danach, gemeinsame Statusseite zuletzt.
