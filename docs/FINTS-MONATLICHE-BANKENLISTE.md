# Monatliche FinTS-Bankenliste

Die technische Vereinsverwaltung öffnet im bestehenden Manager **Finanzen →
Bank & Kasse → FinTS-Bankenliste aktualisieren**. Es gibt dafür keine zweite
Verwaltungsoberfläche. Monat auswählen, die ZIP aus der E-Mail oder deren CSV
hochladen, Vorschau prüfen und mit **Übernehmen** bestätigen. **Verwerfen**
verlässt die Vorschau; der aktive Stand bleibt erhalten.

Der normale Upload benötigt weder ein Bankpasswort noch eine registrierte
FinTS-Verbindung. Die Bankanbindung selbst benötigt weiterhin ihre eigene
Einrichtung und Freigabe. Die Übersicht zeigt nur Stand, Anzahl und Quelldatei;
der ausführliche Prüfbericht bleibt zuklappbar. Die Bankenliste wird nicht
automatisch aus irgendeinem E-Mail-Anhang übernommen.

## Unterstützte Monatsdatei

ZIP bis 16 MiB mit genau einer CSV; alternativ CSV bis 8 MiB. Die CSV verwendet
Semikolon und UTF-8 oder Windows-1252. Benötigte Spalten sind `BLZ`, `BIC`,
`Institut`, `Ort`, `PIN/TAN-Zugang URL` und `Version`. Die zusätzlichen Spalten
der gelieferten Institutsdatei sind erlaubt. Führende Nullen der BLZ bleiben
erhalten; bekannte Platzhalter für fehlenden BIC werden als fehlend behandelt.
Mehrere Bankstellen mit gleicher BLZ und gleichem normalisiertem Endpunkt werden
zusammengeführt. Der FinTS-3-Adapter übernimmt ausschließlich FinTS-3-PIN/TAN-
Zugänge; fehlende PIN/TAN-URLs und andere Protokollversionen werden ausgewiesen.

Die am 18. Oktober 2026 bereitgestellte ZIP wurde mit dem tatsächlichen Parser
geprüft: 4.777 Dateizeilen, 1.251 ohne PIN/TAN, eine nicht unterstützte Version,
1.810 doppelte Bankstellen und **1.715 nutzbare FinTS-3-Endpunkte**. Neun Zeilen
enthalten keinen verwendbaren BIC. Diese Werte sind keine feste Vorgabe für
spätere Monate. XLSX/PDF im Paket bleiben als Originalbytes erhalten und werden
weder ausgeführt noch als Anweisung gelesen. Das Originalpaket wird nicht im
öffentlichen Repository verteilt.

## Bestehende Bankzugänge erhalten

Die aktive Liste liegt außerhalb des Webroots unter
`VEREIN_FINTS_ROOT/institutions/active.json`. Ohne diese Variable wird das
bestehende private Banking-Verzeichnis verwendet. Eine vorhandene alte
`banking/fints-institutions.json` bleibt bis zur ersten bewussten Aktivierung
als Lesefallback nutzbar und wird nicht überschrieben.

Eine unveränderte Kombination aus BLZ und normalisierter URL behält ihre bisherige
Kennung. Ein anderer Server erhält eine andere Kennung. Gespeicherte Bankzugänge
verwenden weiterhin die private bestätigte Bankkonfiguration, wenn ihre alte
Kennung nicht mehr vorkommt. Der Monatsupload überschreibt keine Konten, PINs,
Dialoge, Buchungen oder Bankenschlüssel und startet keine Bankverbindung.

Vorschauen sind an den tatsächlichen Zugang und Verein gebunden. Die Übernahme
prüft die aktuellen Rechte erneut, hält die maßgeblichen SQL-Rechte während der
Aktivierung mit Lesesperren und verwendet eine eigene Dateisperre. Eine seit der
Prüfung geänderte Liste erzeugt einen Konflikt; dann die Datei erneut prüfen.
Die Aktivierung ersetzt die vollständige aktive Datei atomar. Originalupload,
Prüfplan und vorheriger aktiver Stand bleiben im privaten Versionsverzeichnis.
Ein wiederholter abgeschlossener Auftrag bestätigt die Übernahme und setzt
keinen späteren Stand zurück. Fehlt nach der Aktivierung die Bestätigung, wird
sie anhand des tatsächlich aktiven Originalstands nachgeholt.

## Prüfung und Grenzen

`native-fints-directory` und der registrierte Browserlauf
`DVM_FINTS_DIRECTORY_BROWSER=1` verwenden eigene MySQL-Daten und die tatsächliche
gemeinsame Manager-Anwendung. Sie prüfen reale Multipart-Uploads, Bestätigung,
CSRF, Rechte, fremde und veraltete Vorschauen, Wiederholung, Privatpfade und
Originalschutz. Der Dateiparser prüft außerdem das bereitgestellte Monatsarchiv,
wenn dessen privater Pfad über `DVM_FINTS_REFERENCE_ARCHIVE` angegeben ist.

45 Dienst-/Originalbedingungen und 48 echte Browseransichten mit 417 Bedingungen
und 16 POST-Anfragen werden im abschließenden eingefrorenen Lauf geprüft.
Drei bestehende FinTS-Fachsuiten, der echte Webspace-Installer mit 55 Bedingungen
und 52 Transportgrenzen bestehen auf der zugehörigen Prüffassung. Die vollständigen
429 vorhandenen Geschäftstabellen und privaten Bankdateien bleiben unverändert.
Es wurde keine Bank oder andere externe Schnittstelle angesprochen.

Die allgemeine optionale Postfachverwaltung für beliebige E-Mails mit Antworten
und Zuweisungen ist eine zusätzliche Nutzeranforderung. Das bereits bestehende
Social-Hub-Mailantwortmodul und das Rechnungspostfach ersetzen sie nicht.
Sie wird ebenfalls in den bestehenden Manager integriert; ihre vollständige
Umsetzung bleibt bis zur eigenen Abnahme offen.
