# Sechs originale Gegenstände statt einer langen Bestandstabelle

Inventar → Gegenstände zeigt sechs vollständige Originale pro gezählter Seite.
Namen, Nummern, ursprüngliche Seriennummern, Art/Eigentum und alle bisherigen
Bestandsangaben sind als kurze Karten erreichbar. Gesamtinhalt, verfügbar,
reserviert, ausgeliehen, gesperrt und Mindestbestand behalten ihre Fachbedeutung.
Direkte Seitenwahl und Vorherige/Nächste machen sämtliche Gegenstände erreichbar.

Suche einschließlich Stückseriennummern, Kategorie, Gruppen und Aktiv-/Archiv-/
Mindest-/Sperrfilter verwenden die bisherigen Regeln und Reihenfolge. Der
bestehende Livefilter setzt bei einer neuen Auswahl die Seite zurück; ohne
JavaScript funktioniert die ausdrückliche Filteraktion. Nach dem Öffnen eines
Originals führt der bestehende Rücklink zur ursprünglichen Seite und Auswahl.
Quellensuche, Bestandseingabe und erfolgreicher Gegenstandsvorgang behalten diesen
Rückweg. CSV verwendet weiterhin den vollständigen gefilterten Bestand.

`itemsPage()` liest einen kleinen vollständigen Index aus Namen, Nummern,
Seriennummern und Bestandsfakten, danach nur sechs ganze Originale. Ein eigener
MySQL-Lesestand verbindet Auswahl und Originale. Aktuelle Vereins-/Inventarrechte
werden erneut geprüft; Finanzangaben werden nach dem Lesen gemäß der aktuellen
Rolle entfernt. Der kleine Index bleibt im PHP-Speicher; keine allgemeine
SQL-/Hochlastabnahme behauptet. Die ältere vollständige `items()`-API bleibt für
andere Aufrufer erhalten.

Die Prüfung fand einen echten bisherigen SQL-Fehler: Unter `ONLY_FULL_GROUP_BY`
war die Gruppierung nur über `i.id` mit dem nativen zusammengesetzten Schlüssel
nicht gültig. Bestand und kompakter Mitglieder-/Ausleihkatalog gruppieren jetzt
über `i.club_id,i.id`. Der strenge MySQL-Modus bleibt aktiv, keine Schemaänderung.

Abnahme: 41 Fach-/Originalprüfungen einschließlich 19 bestehender Bewegungsverträge,
81 tatsächliche Browserprüfungen, 24 Handy-/Tablet-Schriftansichten mit/ohne
JavaScript, admin und reiner Inventarleser, null POSTs. 1.002 aktive Originale
auf 167 Seiten, jedes genau einmal. Jede ausgewählte ganze Zeile und alle alten
Filter mit `items()` verglichen; ursprüngliche Notizen, Stückwerte, Bestände,
Bewegungen und Finanzzeilen erhalten. Der zunächst doppelte Rücklink wurde auf
den bestehenden Link mit ursprünglichem Kontext zusammengeführt; der Browser
prüft diesen wirklichen Rückweg.

Sechs betroffene native Gruppen zusammengenommen grün, einschließlich 80
Quellen-/Stückentwurf- und 88 Bewegungsverlauf-Browserprüfungen; sechs ursprüngliche
Inventar-/Bestands-/Einzelstück-/Archiv-/Lösch-/Preisgruppen grün. Übrige
Lagerort-/Aufenthalts-/Ausleihansichten und gesamte weitere UI bleiben gesondert
weiter zu bearbeiten. Keine Zahlung, Nachricht oder Bestandserfassung ausgelöst.
