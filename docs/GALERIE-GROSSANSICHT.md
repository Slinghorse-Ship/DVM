# Galerie: Originale in der Großansicht

Albumfotos behalten eigenständige Alternativtexte und sichtbare
Bildbeschreibungen. Die Großansicht zeigt dazu den jeweiligen Fotocredit,
Quelle und Lizenz mit den ursprünglichen Links. „Vorheriges Bild“,
„Nächstes Bild“, Pfeiltasten und Wischen wechseln auch die dazugehörigen
Bildangaben. Schließen oder Escape gibt den Fokus an das geöffnete Foto zurück.

Private Alben verwenden die aktuellen eigenen Bildangaben. Die öffentliche
Galerie verwendet die Angaben aus der tatsächlichen veröffentlichten Fassung;
ein später korrigierter privater Fotocredit überschreibt keine öffentliche
Originalangabe. Private Einwilligungsnachweise werden in keiner Bildansicht
angezeigt. Die sichtbare Beschreibung wird als Text ausgegeben, einschließlich
möglicher Zeichen wie `<` und `>`; sie wird nicht als HTML ausgeführt.

Auch ohne JavaScript öffnet „Großes Bild öffnen“ die tatsächlich berechtigte
Bilddatei. Im integrierten Manager verwendet der Link dessen privaten
Bild-Endpunkt und die aktuelle Anmeldung. Der direkte öffentliche Bildaufruf
bleibt an die veröffentlichte Fassung und die aktuellen Nutzungsrechte gebunden.

Jede Bilderseite enthält sechs Fotos. Die direkte Seitenwahl erreicht auch die
letzten Fotos eines größeren Albums. Der durchgehende Bildwechsel zwischen Bilderseiten ist inzwischen ergänzt;
[Bedienung und aktuelle Prüfung](GALERIE-ALBUMWECHSEL.md). Bildbeschreibungen und Nachweise passen in eine begrenzte
Großansicht, während große Schrift, Schließen und Bildwechsel bedienbar bleiben.

In der privaten Albumübersicht wurden zwei falsche Rechteaufrufe korrigiert:
Administratoren sehen ihre erlaubten Links zu entfernten Bildern und zur
Speicherprüfung. Ein lesender Zugang erhält diese Verwaltungsaktionen nicht.
Die Galerie hält jetzt auch ihre eigene Datenbankverbindung für diese Prüfung.

11 Fachprüfungen und 269 tatsächliche Browserprüfungen bestehen, zusätzlich
zwei Prüfungen sämtlicher ursprünglicher Datenbankzeilen und der vollständigen
veröffentlichten Albumfassung. Beide echten privaten Controller und beide
öffentlichen Galeriewege wurden mit und ohne JavaScript geprüft. 48 Ansichten
bei 320/390/820 Pixeln und 200 % Schrift, tatsächliche Pfeiltasten, reale
Touch-Ereignisse, genaue verlinkte Bilddateien und direkte zweite Bilderseite.
Kein Formular-POST und kein externer Aufruf bei der Anzeige. Frühere Pixel,
verschlüsselte Einwilligung, Metadatenrevisionen und veröffentlichte Fassung
bleiben erhalten. Acht betroffene native Fach-/Routinggruppen bestehen.

Die verschlüsselte Verlagerung prüft zusätzlich die genaue öffentliche
Originalbeschreibung, den aktuellen privaten Alternativtext/Credit und
unveränderte vollständige wiederhergestellte Zeilen. Paketstand und abschließende
Prüfungen: 229 Installer-, 52 HTTP-Installer-, 182 Recovery-, 142 Upgrade- und
27 Fortsetzungsprüfungen, 90 Upgrade-Browserprüfungen und drei tatsächliche
Prozessabbrüche. Inventar-/Paket-/Offlineprüfung bestanden; 13 verschiedene
betroffene native Gruppen einschließlich der fünf Paketgruppen. Keine neue Tabelle oder Schemaversion;
Generation 2026101404, 190 registrierte Suiten. Kein neuer vollständiger Lauf
aller 190 Suiten oder Gesamtabschluss. Der übrige Fach-/UI-Umfang bleibt offen;
Apps folgen danach, die gemeinsame Statusseite zuletzt.
