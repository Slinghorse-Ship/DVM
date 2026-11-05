# Wiederkehrende zusätzliche Tarife

Unter **Einstellungen → Beiträge & Spenden oder Leistungen → Abrechnungsautomatik**
eine eigene Tarifzuweisung öffnen. Erstes Abrechnungsdatum vollständig als
JJJJ-MM-TT, ganzen oder anteiligen Betrag und Zahlungsfrist von 0 bis 90 Tagen
angeben. Null bedeutet Fälligkeit am Abrechnungstag. **Vollständige
Automatikänderung prüfen** zeigt den ganzen Kontakt, die ursprüngliche
Tarifquelle, den persönlichen Preis, Steuerkonto, Termine und aktuellen Plan.
Die Prüfung ausdrücklich bestätigen und **Geprüfte Automatik freigeben**
wählen. Suche, Entwurf, Prüfung und Freigabe erzeugen keinen Beleg und starten
keinen Abrechnungslauf.

Für eine Pause **Automatikpause prüfen** öffnen, den vollständigen bestehenden
Plan prüfen und die Pause gesondert bestätigen. Eine Pause erhält Termine,
bisherige Preisgrundlage und ursprüngliche Freigabe. Sie erstellt keinen
Stornobeleg. Eine neue bewusste Freigabe kann die Automatik wieder aufnehmen.
Finanzschreibrecht und Mitgliederleserecht reichen für beide Änderungen;
allgemeine Einstellungsrechte oder Mitgliederschreibrechte sind nicht nötig.
Mit Finanz- und Mitgliederleserecht bleiben aktuelle Pläne, Preise und tatsächliche
Verläufe ohne Änderungsfelder erreichbar.

Monatliche, vierteljährliche, halbjährliche und jährliche Zusatz-Tarife verwenden
ihren bestehenden Zeitraum und ihre Preisstaffeln. Am Monatsende wird der
ursprüngliche Tag nötigenfalls gekürzt: Auf den 31. Januar folgen im Schaltjahr
der 29. Februar und wieder der 31. März. Einmalige Tarife werden manuell
abgerechnet. Verknüpfte Einzel- und Familienjahresbeiträge bleiben im bestehenden
Jahresbeitragslauf.

Die Freigabe bindet die aktuelle vollständige Zuweisung, Tarifversion,
Preis-/Steuerregeln, persönliche Preisgrundlage und den gesamten aktuellen Plan.
Auch ein tatsächlicher Lauf kann das nächste Datum ohne neue menschliche
Freigabe ändern. Ein inzwischen überholter Vorschlag benötigt deshalb eine
neue bewusste aktuelle Prüfung. Bei einer Freigabe bleiben die gewünschten
Daten erhalten; bei einer Pause wird bewusst der aktuelle bestehende Plan
übernommen. Frühere vollständige Vorschläge bleiben getrennt lesbar.

Geburtstage und Mitgliedschaftsdauer dürfen unter denselben freigegebenen Daten
die nächste vorhandene Preisstufe erreichen. Ändern sich Tarif, Zuweisung,
Kontaktfassung oder relevante Mitgliedschaftsdaten, pausiert der fällige Lauf
zur erneuten Prüfung. Freigebender und ausführender Zugang benötigen weiterhin
aktuelle Finanzschreib- und Mitgliederleserechte. Neue Freigaben beginnen
frühestens heute; sie veranlassen keine rückwirkende Nachberechnung.

## Originale und Wiederaufnahme

Jede neue Freigabe oder Pause hat eine persönliche erste Bestätigung mit
vollständigen Eingaben, damaliger Prüfung, tatsächlich gespeichertem Plan,
Konto, Zeitpunkt und SHA-256. **Original-JSON herunterladen** lädt exakt diese
erste Datei. Spätere Pausen, Preise oder Läufe schreiben sie nicht um.
**Eigene ursprüngliche Automatikbestätigungen** besitzt eine eigene Suche und
25er-Seiten. Aktuelle Kontaktrechte gelten auch für bekannte Kennungen und alte
Tabs. Archivierte Kontakte sperren persönliche Quellen, alte Vorschläge,
Bestätigungen und Downloads; die gespeicherten Bytes bleiben erhalten.

Bei verlorener Speicherantwort zuerst **Ursprünglichen Automatik-Ausgang
lesend prüfen** öffnen, bei Bedarf in einem neuen Tab. Eine bereits angenommene
Kennung liefert ihre erste Bestätigung und erzeugt keine weitere Planfassung.
Ein sicher ungespeicherter Entwurf lässt sich ausdrücklich verwerfen. Zwei Tabs
haben getrennte Entwürfe; ein älterer Vorschlag überschreibt keine spätere
Freigabe. Frühere zwischengespeicherte Ein-Schritt-Formulare bleiben unangenommen
und verlangen vollständige Prüfung und gesonderte Zustimmung.

**Tatsächlich gespeicherter Automatikverlauf** und **Tatsächlich ausgeführte
Abrechnungsläufe** haben getrennte Sechserseiten. Die ursprünglichen rohen
Planfelder und tatsächlichen Laufpreise sind einzeln herunterladbar. Frühere
kanonische Fassungen beweisen keine damalige vollständige Vorschau oder
Zustimmung. Ungültige Suchwerte und Seiten werden vollständig zur Korrektur
angezeigt und wählen keine Ersatzoriginale. Suche und Quellenwechsel erhalten
die vollständigen gewünschten Daten auch ohne JavaScript.

## Getrennter lokaler Lauf

Der private Lauf legt tatsächliche lokale Originalbelege mit Empfänger,
Bruttoposition, Steuer, Nummer, Fälligkeit und ursprünglicher Preis-/
Periodenzuordnung an. Eine vorhandene überlappende manuelle Abrechnung wird
verknüpft und bleibt unverändert. Ein bereits tatsächlich ausgeführter Termin
wird bei erneuter Freigabe lesbar angezeigt; ein weiterer Lauf übernimmt den
vorhandenen Nachweis und geht zum nächsten Termin weiter.

Ein Aufruf bearbeitet höchstens zwanzig Zuweisungen und jeweils einen fälligen
Zeitraum je Zuweisung. Nach Betriebsausfällen werden frühere fällige Perioden
mit weiteren privaten Läufen abgearbeitet. Beleg, Positionen, Nummer, Verlauf
und nächstes Datum werden gemeinsam gespeichert; Fehler rollen alles zurück.
Verlorene Bestätigungen und parallele Aufrufe erzeugen keinen zweiten Beleg.
Die Oberfläche führt keine Jobs aus. Aktivierung und Lauf senden keine
Rechnungsmail, Zahlung, Bankanforderung oder Veröffentlichung.

Identity 6 hat die drei optionalen Tabellen für Pläne, Änderungen und Läufe
bei Installation oder Paketupgrade eingeführt. Neue persönliche erste
Bestätigungen verwenden die vorhandene Identity-10-Ablage. Gewöhnliche
Anfragen installieren keine Tabellen und erfinden keine frühere Freigabe.

In der bestehenden privaten Worker-Konfiguration `catalog_actor` auf den
eigenen aktuellen Finanz-/Mitgliederzugang setzen. Alternativ ist der
vorhandene `worker_actor` mit ausdrücklich gültiger Jahresbeitragsautomatik
verwendbar. Ein eigener `catalog_actor` verlangt keine Aktivierung dieser
Jahresbeitragsautomatik. MySQL-, Vereins-, HTTPS-Domain- und Schlüsselangaben
bleiben erforderlich; Konfiguration außerhalb des Webroots mit Dateirecht 0600.

```sh
php vereinsverwaltung/bin/native-delivery-worker.php \
  --config=/privater/pfad/worker.php --catalog-billing --limit=20
```

Diesen getrennten Aufruf im Hosting-Scheduler konfigurieren. Vermischte Dienstmodi
werden vor Verarbeitung abgewiesen. Wartung und die ausdrückliche Betriebssperre
nach Wiederherstellung blockieren auch diesen Lauf. Die private Worker-Historie
enthält Modus und begrenzte Zähler ohne Empfänger, Originaltexte oder Geheimnisse.
In der Cloud wurde kein realer Scheduler aktiviert.

## Prüfung und Grenzen

Eigene synthetische MySQL-Datenbanken prüfen tatsächliche lokale Belege,
frühere manuelle Originale, persönliche Preise, aktuelle Rechte auch hinter
älteren Datenbankansichten, vollständige Originale, kanonische Änderungen,
Rücknahme bei tatsächlichem SQL-Fehler und angenommene COMMITs mit verlorener
Antwort. Zwei echte PHP-Prozesspaare prüfen gleiche und konkurrierende
Freigaben. Die Browserprüfung umfasst beide Zugänge, DE/EN, JavaScript-freie
Bedienung, zwei Tabs, verlorene HTTP-Bestätigung, Tastatur, 44-Pixel-Ziele und
200-Prozent-Schrift. Verschlüsselte Verlagerung prüft die Helfer unabhängig aus
dem tatsächlich wiederhergestellten Paket und erhält die ganzen Originalzeilen.
Übrige Fachoberflächen, Apps und abschließende Finanz-/Betriebsabnahme bleiben
im gemeinsamen Register offen. Reale Bank-, Steuer- und Anbieterprüfungen sind
mit diesen lokalen Prüfungen nicht erfolgt.
