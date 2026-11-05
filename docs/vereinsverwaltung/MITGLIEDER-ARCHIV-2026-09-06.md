> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Mitglieder, Kontakte und Archiv – 06.09.2026

Mitglieder können mit Stammdaten, Eintrittsdatum bzw. bekanntem Eintrittsjahr und optionaler Familienzuordnung in einem Vorgang angelegt werden. Ungültige Mitgliedschaftsangaben rollen den gesamten Vorgang zurück. Kontakte und Mitglieder haben getrennte Standardlisten und Anlageaktionen. Ehemalige behalten Mitgliedsnummer, Eintritt und Austritt.

Archivierung verschlüsselt die persönlichen Profilfelder mit AES-256-GCM und dem privaten Anwendungsschlüssel, blendet das Profil aus und deaktiviert zugeordnete Verwaltungszugänge. Finanz- und Dokumentverweise bleiben bestehen. Historische Belege und bereits versandte Nachrichten werden nicht nachträglich anonymisiert. Wiederherstellung aktiviert weder eine beendete Mitgliedschaft noch einen gesperrten Zugang automatisch. Der Anwendungsschlüssel muss mit den privaten Sicherungen aufbewahrt werden.

Dokumentordner unterscheiden Kontakte von Mitgliedern. Bei Übernahme und Wiederherstellung wird derselbe Ordner weitergeführt. Grundordner für Bankkonten, Kassen, Steuern, Beiträge, Einnahmen, Ausgaben und Spenden werden ergänzt. Archivierte Profile werden durch einen erneuten Katalogabgleich nicht aktiviert.

Prüfung: Mitgliedschaft/Archiv einschließlich Formular 20, Dokumente 74, Mitgliederübersicht 45, manuelle Zugänge 22, Beitragstarife 27 und Basisfunktionen 72 Prüfungen erfolgreich. Die Tests arbeiten mit isolierten Daten; keine echten E-Mails oder Bankaufträge.

Bestandskorrektur autorisiert: fehlende Mitgliedschaften der neun echten Personen mit Eintrittsjahr 2015; vorhandenes genaues Datum von Paul beibehalten; Testkontakt geschützt archivieren. Ausführungsstand wird nach Live-Prüfung ergänzt.

Live ausgeführt und geprüft: neun echte Kontakte zu Mitgliedern übernommen; insgesamt zehn aktuelle Mitglieder, keine weiteren normalen Kontakte. Pauls 2015-12-12 und die bestehenden Familien bleiben erhalten. Testkontakt verschlüsselt archiviert. Keine E-Mails versandt. Laufzeitstand f1c18b9, Schema 36; 32 öffentliche/geschützte HTTP-Prüfungen erfolgreich. Private Dateidatenbank-Sicherung vor Migration und Bestandsumstellung vorhanden.

Der einseitig gelieferte Originalbescheid ist unverändert unter /Finanzen/Steuern/2025 abgelegt und per SHA-256 geprüft. Finanzamt Nordenham, Bescheid 01.09.2025 für 2022 bis 2024; ausdrücklich keine steuerlichen Bestätigungen für Mitgliedsbeiträge. Bescheid und Zeichnungsberechtigung hinterlegt; Papierverfahren bleibt aktiv. Keine Zuwendungsbescheinigung ohne reale Zahlung ausgestellt. Original-Uploads jetzt bis 10 MB statt 2 MB.

## Website-Benutzernamen und Profilanordnung

Laufzeit 9e413dd: neue Mitgliedszugänge verwenden vorname.nachname, bei belegtem Namen Suffix 2, 3 usw. Umlaute ae/oe/ue und ß ss. Bei mehrteiligen vollständigen Namen wird der erste und letzte Namensteil verwendet; Bindestriche bleiben erhalten. Namensvergabe wird im früheren CMS durch eine Datenbanksperre serialisiert, Wiederholungen über die interne Kontoanlagekennung abgefangen. Die tatsächliche Namensvergabe wird in beiden Aufnahmewegen gespeichert.

Zehn bestehende Mitgliedszugänge einschließlich der zwischenzeitlich neu angelegten Martina Preuss-Wehlage live umbenannt. Konto-IDs, Rollen und Passwortwerte unverändert geprüft; keine Mail versandt. Nach ausdrücklicher Bestätigung des bisherigen Benutzernamens paul wurde auch dieser bestehende Website-Zugang in paul.wehlage umbenannt und Pauls Mitgliederprofil zugeordnet. Konto-ID 1063, Passwortdaten, Website-Rollen und Superuser-Berechtigung unverändert geprüft; private Sicherung und Audit-Eintrag vorhanden. Familie & Beitrag steht jetzt direkt nach Mitgliedschaft vor Inventar und weiteren Verknüpfungen.


## Eintritt manuell korrigieren

Live 4c26f91: Unter Mitgliedschaft → Eintritt bearbeiten können Mitgliederverwalter das genaue Datum oder das bekannte Eintrittsjahr ändern, auch bei Ehemaligen. Die interne Mitgliedschafts-ID und Mitgliedsnummer werden nicht geändert; Familie und Zahlungszuordnungen bleiben erhalten. Veraltete Formularstände, fremde Profile und Eintritt nach Austritt werden abgewiesen. Änderung wird protokolliert. 12 gezielte Prüfungen sowie 20 Mitgliedschafts-/Archivprüfungen und 72 Basisprüfungen erfolgreich; 32 HTTP-Prüfungen nach Deployment erfolgreich.

Vorgabe für die Eingabephase: Keine automatische Neunummerierung. Eine künftige ausdrücklich beauftragte Änderung einer Mitgliedsnummer muss die bisherige Nummer als historischen Zuordnungsschlüssel erhalten. Die aktuelle Datumskorrektur verändert keine Nummern und stellt keine Rechnungen neu aus.
