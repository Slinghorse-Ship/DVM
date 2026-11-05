# Papierkorb für Website-Inhalte

Seiten, Beiträge, Veranstaltungen und Alben verwenden denselben Papierkorb.
Im jeweiligen Editor steht unter **Papierkorb** eine ausdrücklich zu bestätigende
Aktion. Sie nimmt die gespeicherte Seite offline und entfernt sie aus der aktiven
Redaktion. Das erfordert Veröffentlichungsrechte. Ungespeicherte Angaben vorher sichern.
Über **Papierkorb öffnen** lassen sich alle Inhaltsarten in gezählten 25er-Seiten finden.

Unterseiten und typisierte Seitenverweise anderer gespeicherter Entwürfe oder
veröffentlichter Originalfassungen blockieren das Verschieben. Der Editor nennt
die betroffenen Inhalte. Ein nur privat entfernter Verweis genügt nicht, wenn er
noch im veröffentlichten Original steht. Freie Text-/HTML-Links sind keine
Typverweise und werden nicht automatisch umgeschrieben oder repariert.

Die Originalkennung, reservierte Adresse, Pfadhistorie, genaue gespeicherte Inhalte,
frühere Revisionen, Bilddateien und externe Importbindungen bleiben erhalten.
Öffentliche Seite, Menü-/Termin-/Medienfreigabe werden zurückgezogen, alte noch
wartende Veröffentlichungspläne abgebrochen. Kopieren, Bearbeiten, Verschieben,
Veröffentlichen und neue Veröffentlichungsplanung sind im Papierkorb gesperrt.
Ein Importabgleich kann eine entfernte Originalseite nicht automatisch wiederbeleben.

**Privat zurückholen** benötigt Bearbeitungsrechte. Die früher gespeicherte Fassung
kehrt als unveröffentlichter Entwurf mit derselben Kennung zurück. Ein früherer
Archivstatus bleibt erhalten; Beitragsfreigaben müssen erneut geprüft werden.
Übergeordnete und typisiert verknüpfte Seiten müssen zuerst zurückgeholt werden.
Historische Felddefinitionen werden beim Zurückholen nicht stillschweigend ersetzt.
Ein geändertes Modell erfordert weiterhin die bewusste Prüfung vor Veröffentlichung.

Alle Änderungen prüfen aktuelle Rechte und Originalversion unter demselben
Vereinsmutex wie normale Seiten- und Modelländerungen. Konflikte erhalten den
ursprünglichen Versionsstand des Formulars; vor einem neuen Versuch neu laden.
Eine fehlende Bestätigung oder ein CSRF-Fehler ändert keine Originale.

Das zusätzliche Schema wird ausdrücklich über `Installer/SchemaRegistry`
(CMS-Version 6) eingerichtet, einschließlich wiederholbarer Installation.
Keine Schemaänderung im normalen Webrequest und kein neues Release für Git-Pushes.

## Nachweise und verbleibende Arbeit

56 tatsächliche MySQL-/Dienst-/Controller-/Darstellungsprüfungen mit privaten
Rücknahmen, erhaltenen Originalbytes, geplanten Veröffentlichungen, historischen
Referenzen und Felddefinitionen, Rechten, Konflikten, Archivstatus, gezählten
25er-Seiten und echten gleichzeitig schreibenden Prozessen bestehen.
Betroffene Hierarchie-/Kopier-/Veröffentlichungs-/Menü-/Terminregressionen bestehen.
313 tatsächliche Browser-/HTTP-Prüfungen beider Redaktionszugänge bei
1440/820/390 Pixeln mit 56 Lebenszyklusänderungen bestehen. Darunter sind
Tastatur, sichtbare Bestätigung, JavaScript-freie Formulare, konkurrierende Tabs,
konkrete Unterseitenblocker und Mitgliedsrechte. Anschließende Datenbank-/Byteprüfungen
erhalten gespeicherte Inhalte, sämtliche vorigen Revisionen, Originalfotos und
die unveränderten blockierenden Eltern-/Unterseiten.

Dauerhaftes Löschen mit eigener Aufbewahrungsentscheidung, freie Textlinkprüfung,
Medienlöschung sowie größere Teilbaumkopien sind weitere Arbeit.
Wiederaufnehmbare Papierkorb-/Rückholstapel sind inzwischen ergänzt:
[Stapelbedienung](CMS-SEITEN-STAPEL.md).
Der Papierkorb löscht keine historischen Originale automatisch.
