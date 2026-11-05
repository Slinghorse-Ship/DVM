# ELSTER-Zertifikatsablage – Betrieb und Wiederherstellung

Stand: 15.09.2026. Optional; eine einmalige PFX-/P12-Auswahl bleibt möglich.

## Bedienung

Unter **Steuerdaten & Freistellung** (`?view=settings&tab=&section=tax`)
steht **ELSTER-Zertifikat des Vereins** direkt unter dem Steuerdatenformular.
Speichern, Ersetzen und Entfernen führen wieder zu diesen Steuereinstellungen.
Alternativ weiterhin: Finanzen → Steuern → Steuererklärungen →
**ELSTER-Zertifikat hinterlegen oder ändern**.
Nur Finanz-Schreibrecht zusammen mit aktuellem Vorstand/Superuser erlaubt
Status, Speicherung, Ersetzung, Entfernung und Nutzung der hinterlegten Datei.
Die vorhandenen zusätzlichen Abgabeprüfungen bleiben unverändert.

Die Speicherung prüft Datei und Passwort lokal über `EricHoleZertifikatEigenschaften`.
Alle zurückgegebenen Zertifikatsteile müssen aktuell gültig und vom
Registrierertyp `Organisation` sein. Testzertifikate werden nicht hinterlegt.
Die ausdrückliche Bestätigung bindet die Auswahl an diesen Verein; die technische
Prüfung allein beweist nicht, dass eine beliebige Organisation dieser Verein ist.

Bei einer geprüften echten Erklärung ist die hinterlegte Version vorausgewählt.
Das Passwort ist jedes Mal erforderlich. Ein neues Zertifikat ersetzt nicht
unbemerkt eine bereits im Formular ausgewählte Version. Bei einem Konflikt muss
die Seite erneut geöffnet werden. Testabgaben verwenden ausschließlich eine
einmalig ausgewählte Datei.

## Schutz und Speicherformat

- `VEREIN_ELSTER_CERTIFICATE_ROOT`: ausdrücklich privat, außerhalb des Webroots,
  alternativ `${VEREIN_PRIVATE_ROOT}/elster-certificates`.
- Das Verzeichnis muss existieren und auf Unix Modus `0700` haben. Neue
  Installationen legen es an; bestehende Installationen müssen es bereitstellen.
- Datei: `sha256(club_id).enc`, Modus `0600`, eigener Lock je Verein. Kein
  öffentlicher Download und keine Speicherung in der MySQL-Datenbank.
- PFX wird zusätzlich mit XChaCha20-Poly1305 authentifiziert verschlüsselt.
  Argon2id v1.3 leitet aus dem bei der Speicherung eingegebenen PFX-Passwort einen
  32-Byte-Schlüssel ab: 2 Durchläufe, 64 MiB, zufälliger 16-Byte-Salt; 24-Byte-Nonce.
  Parameter sind im Code festgelegt, nicht durch die Datei konfigurierbar.
- AAD bindet Format, Vereinskennung, zufällige Revision, Gültigkeit und Speicherzeit.
  Nur Revision/Gültigkeit/Speicherzeit liegen als Metadaten vor. Es werden weder
  Passwort noch abgeleiteter Schlüssel noch Zertifikat-Klartext dauerhaft gespeichert.
- Einmalige PHP-Uploads werden unmittelbar eingelesen und entfernt. Das Passwort
  wird aus dem POST entfernt, nicht in HTML zurückgespiegelt, nicht protokolliert
  und nicht in Sitzung/DB/Prozessargumente/Umgebungsvariablen geschrieben.
- Ersetzung ist atomar; Entfernung ersetzt die Datei durch einen Tombstone mit
  neuer Revision, ohne Zertifikatsmaterial. Damit können alte Formulare keine
  entfernte Version unbemerkt wiederherstellen.
- Schutzgrenze: Verschlüsselung schützt ruhende Dateien; eine kompromittierte
  Server-/Browserlaufzeit könnte das gerade eingegebene Passwort abgreifen.
  Kein Ersatz für Server-, Endgeräte- und Zugangsschutz.

## Sicherung und Wiederherstellung

Die tägliche **verschlüsselte vollständige Recovery-ZIP-Sicherung** nimmt exakt
die versiegelten `.enc`-Dateien unter
`private/verein/elster-certificates/` auf. Locks und temporäre Uploads werden
nicht gesichert. Ein reiner SQL-Export enthält diese Dateien **nicht**.
Vollständige Dateien werden vor dem Schließen der ZIP als unveränderlicher
Byte-Snapshot übernommen, auch wenn gleichzeitig ein Zertifikat ersetzt wird.

Wiederherstellung: Eintrag aus der Recovery-ZIP mit dem Sicherungspasswort
entnehmen, in die private Ablage mit Modus `0600` übernehmen, ursprüngliche
Vereinskennung beibehalten. Zum Entschlüsseln/Nutzen ist zusätzlich das ursprüngliche
Zertifikats-Passwort erforderlich. Ein separater geheim gehaltener Serverschlüssel
ist nicht nötig. Eine sichere Kopie der ursprünglichen PFX-Datei soll der Verein
unabhängig davon behalten. Bei Passwortverlust kann der Betreiber es nicht
rekonstruieren. Erneuerte Zertifikatsdatei/Passwort über die Ersetzen-Funktion
hinterlegen; keine manuelle Bearbeitung des Umschlags.

Entfernung betrifft die aktive Ablage, nicht bereits verschlüsselte Sicherungen.
Diese unterliegen der Sicherungsaufbewahrung (aktuell 35 Tage für Recovery-ZIPs).
Entfernung ist kein Widerruf bei ELSTER; ein notwendiger Widerruf erfolgt dort.

## Verifikation

- `tests/tax-certificate-settings.php`: 128 Prüfungen einschließlich der
  112 Vault-/Abgabeprüfungen; Platzierung, getrennte Formulare, CSRF-Felder,
  leeres Passwortfeld, Rückwege und unveränderte Berechtigungen/Steuerdaten.
- `tests/tax-certificate-vault.php`: 112 Prüfungen einschließlich bestehender
  Abgabekette, Rollen, Vereinsgrenzen, Ersatz/Löschung, falscher PIN, Manipulation,
  veralteter Formulare, SQL-Protokoll ohne Geheimnisse und verschlüsseltem ZIP-Restore.
- `tests/eric-bridge-test.py`: 18 reine Tests einschließlich Zertifikatseigenschaften.
- Amtliches Organisations-Testzertifikat unter ERiC 44.3.6.0 lokal und im
  PHP-Webprozess geprüft; falsches Passwort abgewiesen. Kein Steuertransport.
- `tests/install-production.php`: 66 Prüfungen; private Ablage für Neuinstallation.
- `tests/tax-auto-preparation.php`: 58 Prüfungen; bestehende Jahresvorbereitung.

Amtliche Grundlage: [ELSTER – Umgang mit persönlichen und Organisationszertifikaten](https://www.elster.de/elsterweb/infoseite/sicherheit_%28allgemein%29),
ERiC 44.3.6.0 `API-Rueckgabe-Schemata/EricHoleZertifikatEigenschaften.xsd`.
Lizenzierte Zertifikate/Bibliotheken sind keine Repository-Dateien.
