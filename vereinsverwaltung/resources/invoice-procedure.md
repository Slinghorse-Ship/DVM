# Rechnungseingang und Rechnungsfreigabe

Technische Verfahrensbeschreibung · Stand 7. September 2026

Diese Beschreibung erläutert den implementierten Ablauf im Kulturhus Vereinsmanager. Sie gehört zum Belegpaket. Vereinsinterne Zuständigkeiten, tatsächliche Kontrollen, Aufbewahrungsregeln, Sicherungsbetrieb und eine fachliche Abnahme sind zusätzlich zu dokumentieren. Die Beschreibung ist keine Zertifizierung des Gesamtsystems.

## 1. Erfassung und Herkunft

Rechnungen werden manuell unter Dokumente hochgeladen oder aus einem ausdrücklich eingerichteten Rechnungspostfach übernommen. IMAP dient dem Empfang; die optionale separate SMTP-Verbindung dient dem Versand von Beitragsrechnungen und Erinnerungen. Beide Funktionen sind standardmäßig ausgeschaltet. Die Einrichtung setzt Finanzbearbeitungs- und Einstellungsrecht voraus. Passwörter liegen verschlüsselt vor und erscheinen nicht im Formular, Änderungsverlauf oder Belegexport.

Der Postfachabruf liest Nachrichten ohne Löschen oder Änderung ihres Gelesen-Status. Er beginnt beim gewählten Eingangsdatum und verarbeitet begrenzte Mengen. PDF-, XML- und Bildanhänge werden nach der Dokumentprüfung als Eingangsbelege in der Finanzablage gespeichert. Die ursprünglichen Dateibytes und ihre SHA-256-Prüfsumme bleiben erhalten. Bei XML wird nur die sichere, wohlgeformte Struktur geprüft; eine fachliche Prüfung als XRechnung oder ZUGFeRD findet nicht statt.

Die Importherkunft umfasst Postfachkennung, Nachrichtennummer einschließlich UIDVALIDITY, Absender und Betreff laut Nachricht, Eingangs-/Importdatum und vorhandene Message-ID. Absenderangaben sind kein Echtheitsnachweis. Die zu übernommenen Belegen gehörenden Originalnachrichten werden zusätzlich als RFC822-Daten aufbewahrt. Sie werden nicht als aktives HTML im Programm ausgeführt.

Identische Dateiinhalte werden innerhalb eines Vereins nur einmal importiert. Wiederholungen und Fehler werden protokolliert. Beschädigte oder nicht unterstützte Dateien bleiben im ursprünglichen Postfach und müssen dort geprüft werden. Auch Nachrichten ohne übernehmbaren Anhang benötigen eine manuelle Prüfung, wenn der Mailtext selbst eine Rechnung oder relevante Angaben enthält. Ein fehlgeschlagener Abruf wird nicht als erfolgreicher Import gewertet.

## 2. Inhalt prüfen und zuordnen

Ein Import erzeugt keine Buchung, Zahlungsfreigabe oder Überweisung. Der Dateiname dient zunächst als Titel, das Eingangsdatum als vorbelegtes Dokumentdatum. Die bearbeitende Person prüft Originalrechnung, Lieferant, Rechnungsdatum, Betrag und vorhandene Buchungsunterlagen, berichtigt die Angaben und verknüpft den Beleg mit der passenden Ausgabe. Mehrere Dateien können zu einer Rechnung gehören.

Ein Treffer einer vorhandenen Belegnummer ist nur ein Zuordnungsvorschlag. Er wird nicht ungeprüft übernommen. Bereits erfasste Zahlungen werden gesondert dem Finanzbeleg zugeordnet. Die Erfassung einer tatsächlich erfolgten Zahlung dokumentiert den Geldfluss und ist kein Auftrag an eine Bank.

## 3. Ausdrückliche Freigabe

Nur ein aktuell bestätigtes, aktives Vorstandsamt mit Finanzbearbeitungsrecht oder der Superuser kann eine Rechnung freigeben oder die Freigabe zurücknehmen. Allgemeines Einstellungsrecht oder reines Bankschreibrecht ohne Finanzbearbeitungsrecht reichen nicht.

Für die Freigabe muss mindestens ein aktiver, lesbarer Original-Rechnungsbeleg als PDF, XML oder Bild hinterlegt sein. Ein interner erzeugter Vorgangsnachweis ersetzt die Rechnung nicht. Papierbelege sind hierfür zuerst zu digitalisieren und inhaltlich mit dem Original abzugleichen. Sämtliche verknüpften Unterlagen müssen für die prüfende Person zugänglich sein.

Das berechtigte Vorstandsmitglied oder der Superuser öffnet den Finanzbeleg, prüft Rechnung, Betrag, Empfänger und Unterlagen und wählt ausdrücklich „korrekt und zur Zahlung freigeben“. Wenn etwas unklar ist, wird „zur Klärung zurückstellen“ mit einem kurzen Grund gespeichert; die Rechnung bleibt offen und kann erst nach erneuter Prüfung freigegeben werden. Gespeichert werden Zeitpunkt, handelnder Zugang, Nachweis des damaligen Amts, Entscheidung, Belegstand, Positionen sowie Kennungen, Versionen und Prüfsummen der zugehörigen Dokumente. Rücknahmen benötigen einen Grund und werden als weitere Entscheidung angehängt. Frühere Entscheidungen werden nicht überschrieben.

Ändert sich der Belegstand, eine Datei, ihre Zuordnung oder die zugrunde gelegte Amtsberechtigung, gilt die frühere Freigabe nicht mehr als aktuelle Freigabe. Auch eine Teilzahlung kann eine erneute Prüfung des Restbetrags erforderlich machen. Bereits vollständig bezahlte oder abgeschlossene Vorgänge werden nicht nachträglich als neue Zahlungsaufträge freigegeben.

Eine Freigabe löst keine Zahlung aus. Danach kann ein berechtigter Zugang im Abschnitt „Überweisung vorbereiten“ Empfänger, Betrag, Konto, Ausführungstag und Verwendungszweck prüfen und einen verschlüsselten Auftrag speichern. Vor der Freigabe und dem Export wird die aktuelle Rechnungsfreigabe erneut mit `InvoiceApprovals::assertApproved()` geprüft. Das erzeugte SEPA-XML wird entweder im Onlinebanking eingereicht oder – bei eingerichteter FinTS-Verbindung – im geschützten Bankdialog mit der von der Bank verlangten PIN-, TAN- oder App-Freigabe bestätigt. Der Beleg wird erst durch den später importierten und zugeordneten Bankumsatz als bezahlt geführt.

## 4. Prüf- und Belegpaket

Unter Finanzen → Export kann für den ausgewählten Zeitraum ein Belegpaket heruntergeladen werden. Es enthält die Finanzliste, ein maschinenlesbares Zuordnungsverzeichnis, zugängliche Originalbelege sowie die Freigabehistorie einschließlich gespeicherter Prüfstände. In Freigaben referenzierte frühere Dateifassungen werden ebenfalls aufgenommen, soweit sie für den Exportierenden zugänglich sind. Die Mailherkunft und zugehörige Originalnachrichten werden bei importierten Belegen mitgeführt.

Fehlende oder nicht zugängliche Dateien werden im Paket benannt; sie werden nicht stillschweigend durch aktuelle Fassungen ersetzt. Der Exportierende muss diese Hinweise prüfen. Das Paket ist kein vollständiges Systembackup und kein DATEV-Importformat. Der gesonderte DATEV-Export hat eigene Prüfregeln.

## 5. Betrieb und Grenzen

Der Verein muss sicherstellen, dass relevante Originalunterlagen vollständig erfasst, Fehler im Rechnungseingang bearbeitet und Exporte/Sicherungen geschützt aufbewahrt werden. Datenbank, Dateiversionen und Anwendungsschlüssel gehören gemeinsam zum Sicherungs- und Wiederherstellungskonzept. Ein Verlust des Schlüssels kann den Zugriff auf verschlüsselte Zugangsdaten verhindern. Änderungen an Verfahren, Berechtigungen und Softwareständen müssen nachvollziehbar bleiben.

Die Anwendung verhindert das nachträgliche Ändern oder Löschen der Freigabeentscheidungen über die normale Datenbankoperation. Ein Serveradministrator mit vollständigem Zugriff bleibt technisch in der Lage, Datenbank und Programm zu verändern; deshalb ersetzen die Prüfsummen keine organisatorischen Kontrollen oder unabhängigen Sicherungen.

Der echte IMAP-/SMTP-Verbindungstest des optionalen Rechnungspostfachs steht bis zur Bereitstellung eines Postfachzugangs aus. Bankautorisierung, tatsächliche Zahlungsbeauftragung und ELSTER-Übermittlung sind nicht Bestandteil dieses Ablaufs.

Als fachlicher Bezugsrahmen dienen die [GoBD des Bundesfinanzministeriums](https://ao.bundesfinanzministerium.de/ao/2025/Anhaenge/BMF-Schreiben-und-gleichlautende-Laendererlasse/Anhang-33/inhalt.html) und deren [Änderung vom 14. Juli 2025](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.html). Die tatsächliche Erfüllung hängt auch vom Betrieb und den ergänzenden Verfahren des Vereins ab.
