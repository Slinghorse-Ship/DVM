# Gemeinsame Vereinsdaten und Mailzugänge

Im integrierten Manager werden Vereinsname und Anschrift ausschließlich unter
**Einstellungen → Vereinsdaten** bearbeitet. Neue Briefpapier-Absender und native
Website-/Kommunikationsinhalte lesen dieselben Angaben. Eigenes Briefpapier mit
bewusst abweichendem Absender bleibt möglich. Domain und Galerie-Logo bleiben
Website-Einstellungen; die beiden Bildspeicher haben verschiedene Aufgaben.

`native/Integration/ManagerClub` verbindet über die bestätigte Managerzuordnung
den nativen Verein mit `club_settings`. Standalone-Vereine behalten ihre eigenen
Angaben. Fehlen gespeicherte gemeinsame Vereinsdaten, bleiben frühere Website-
Angaben bis zur Einrichtung lesbar. Abweichende Angaben werden im Einstellungs-
bereich angezeigt und beim Speichern der Website-Erweiterung erhalten.
Das Formular prüft sowohl Website- als auch Vereinsdatenversion. Bereits
erzeugte Dokumente, Kassenbelege und ausgegebene Newsletter-Browseransichten
behalten ihre ursprünglichen Snapshots.

## Postfächer

SMTP-Zugänge, Versandzwecke und das Rechnungsfach mit IMAP werden unter
**Einstellungen → Postfächer & Versand** gepflegt. Der native Newsletter-Worker
verwendet `MailChannelSettings` und dessen aktuelle Zuordnung für `newsletter`;
das umfasst Bestätigungen, Bestätigungserinnerungen und Ausgaben. Auch ein
ausdrücklich zugeordnetes Rechnungspostfach verwendet dessen vorhandene
SMTP-Verschlüsselung. IMAP-Cursor, Originalmails und Abrufzustände werden dabei
nicht geändert.

Ein früherer separater Website-SMTP-Zugang kann in der Website-Verbindungsansicht
übernommen werden. Die Übernahme entschlüsselt den bisherigen Zugang und speichert
ihn mit der bestehenden Verschlüsselung des ausgewählten gemeinsamen Postfachs.
Beide Versionsstände und die aktuelle Administrationsberechtigung werden geprüft.
Enthält das Ziel bereits eigene Angaben oder ist ein Rechnungsfach gewählt,
wird die Übernahme mit einem Vergleichshinweis abgelehnt. Erfolgreiche Übernahme
entfernt die separate Website-Konfiguration in derselben Transaktion. Fehler
lassen beide Bestände unverändert. Es wird keine Nachricht verschickt.

Der integrierte Worker fällt bei fehlender oder beschädigter gemeinsamer
Konfiguration nicht auf den alten Website-Zugang zurück. Das gemeinsame Postfach muss eingerichtet werden. Serverzugänge werden nicht
aus Umgebungsvariablen ungefragt in die Datenbank kopiert.

## Schlüssel und Modulgrenzen

Bestehendes `APP_SECRET` behält unverändert Vorrang. Native Installationen leiten den Manager-Serverschlüssel aus ihrer dauerhaften privaten
32-Byte-Datei ab; HTTP-Laufzeit und generierter Worker verwenden dieselbe
Ableitung. Ein bestehendes `APP_SECRET` muss auch dem privaten Worker verfügbar
sein, wenn damit bereits Zugänge verschlüsselt wurden. Schlüssel werden nicht
rotiert, in Formularen ausgegeben oder ins Git übernommen.

`src/Infrastructure/Database/Transactions` enthält die gemeinsame technische
Transaktionskomposition für native Dienste und Mail-Einstellungen. Fachliche
Rechte, Versandzwecke, Einwilligungen, Finanzregeln und Unklarheitszustände bleiben
in ihren jeweiligen Diensten.

Nachweise: 19 neue MySQL-Vereinsdatenprüfungen, 25 neue Mail-/Schlüsselprüfungen,
bestehende Postfach-/IMAP-Suiten sowie angemeldete Browserwege bei 1440/820/390
Pixeln mit Tastatur, getrennten Originalen, gültigen Einstellungslinks und leeren
Passwortfeldern. Kein Live-Transport wird von diesen Prüfungen aktiviert.
