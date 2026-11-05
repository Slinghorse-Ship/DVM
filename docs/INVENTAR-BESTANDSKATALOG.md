# Kurze Bestandsübersicht „Wo ist was?“

Die Übersicht zeigt sechs ursprüngliche Teilbestände pro Seite. Direkte
Seitenwahl erreicht den gesamten gefilterten Bestand. Verfügbare, reservierte,
gesperrte und ausgegebene Mengen bleiben getrennt. Ausgegebene Stücke zeigen
den bestätigten tatsächlichen Aufenthaltsort, nicht ihren früheren Lagerplatz.
Ort, Aufbewahrung, Verantwortung, letzte Bestätigung und ausstehende Rückgabe
bleiben sichtbar. Gegenstände und vollständige Ausleihen öffnen einzeln;
der bestehende Rückweg erhält Seite und Filter. Bearbeitungs- und Belegwege
behalten diese Rückkehrangaben ebenfalls bei.

Suche, Person, Lagerort und Status verwenden dieselben Fachregeln wie die
bisherige Übersicht. Ohne JavaScript bleiben Filter und Seiten bedienbar;
mit JavaScript bleibt die vorhandene Suche aktiv. Die aktuelle Vereinszuordnung
und Leseberechtigung werden vor Ausgabe erneut geprüft.

Die vier Dashboardzahlen entstehen aus kompakten SQL-Aggregaten statt aus
vollständigen Gegenstands- und Ausleihkörpern. Aktive Gegenstände, Mindestbestand,
Reservierungen und überfällige Ausgaben behalten die ursprüngliche Berechnung.
Die Bestandsseitenauswahl verwendet weiterhin die vollständigen kompakten
ursprünglichen Aufenthaltsdaten im PHP-Speicher. Diese Änderung begrenzt die
sichtbare Liste; sie behauptet keine allgemeine SQL-/Hochlastabnahme.
Keine Schemaänderung und keine Änderung vorhandener Mengen oder Buchungen.

Abnahme: 65 Fach-/Originalprüfungen einschließlich 43 bestehender Bewegungs-/
Ausleihverträge und 81 tatsächliche Browserprüfungen. 24 Ansichten bei 320,
390 und 820 Pixeln mit normaler und doppelter Schrift; Admin und Inventarleser,
mit und ohne JavaScript. Null POSTs und unveränderte Bestands-/Ausleih-/Finanzzeilen.
Vier ursprüngliche Inventar-, Such-, Ausleihinhalt- und Bestandsgruppen grün.
