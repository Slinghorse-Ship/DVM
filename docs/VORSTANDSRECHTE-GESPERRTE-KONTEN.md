# Vorstandsrechte gesperrter Konten

In Einstellungen → Rollen das persönliche Konto auswählen. Ein gesperrtes
Konto zeigt sein bisheriges Vorstandsamt, die ursprüngliche Bestätigung und
seinen Sperrstatus. Mit „Stimmrecht entziehen / Amt entfernen“ entweder das
vorhandene Amt ohne Stimmrecht behalten oder ausdrücklich „Amt entfernen“
auswählen. Der technische Login bleibt gesperrt. Vereinsrolle, persönliche
Zuordnung und alte Abstimmungen ändern sich nicht.

Anschließend bei einem aktiven anderen Konto das freie Amt wählen und seine
aktuelle Zuordnung ausdrücklich bestätigen. Das Stimmrecht gehört weiterhin
einer persönlichen Person; mehrere Konten derselben Person erhalten keine
zweite Stimme. Ein gesperrtes Konto kann kein neues Amt und kein neues
Stimmrecht erhalten. Die zusätzlichen Vereinsrechte und das technische
Kontoformular bleiben eigene Vorgänge.

Veraltete oder abgewiesene Formulare erhalten ihre eingereichte Version und
bleiben bei dem ausgewählten Konto geöffnet. Bei gesperrten Konten stehen nur
das gespeicherte Amt und seine Entfernung zur Auswahl; das Aktivieren des
Stimmrechts ist gesperrt. Die Bedienung funktioniert mit und ohne JavaScript.

## Fachliche Abnahme

Der frühere Dienst konnte die Rechte einer bereits gesperrten Person nicht
entziehen. Deren aktiv gespeichertes Amt blockierte einen Nachfolger. Zwei
tatsächlich gleichzeitig gestartete PHP-Prozesse konnten außerdem beide das
gleiche Amt vergeben; fünf gezielte Prüfungen am ursprünglichen Dienst haben
diese Fehler belegt. Der neue Vorgang sperrt die ursprüngliche Vereinszeile
innerhalb der Transaktion, prüft die aktuellen Rechte erneut und liest die
eigene Zielperson einschließlich ihres Sperrstatus. Bestehende Amtsversionen,
Prüfnachweise und persönliche Einzigartigkeit bleiben erhalten.

23 Fachprüfungen und drei Prüfungen vollständiger UI-Originale bestehen,
einschließlich realer Kontosperrung, Entzug, Entfernung, aktivem Nachfolger,
veralteten Formularen, unveränderlichen Bestätigungen, derselben Person mit
zwei Konten, aktuellem Rechteentzug, angenommenem Commit mit verlorener Antwort
und tatsächlichem atomarem Verlaufsfehler. Zwei echte parallele PHP-Prozesse
nehmen genau eine Amtsvergabe an. 49 tatsächliche Browserprüfungen umfassen
sechs angenommene und sechs abgewiesene HTTP-Formulare, mit und ohne JavaScript,
320/390/820 Pixel Breite und normaler/doppelter Schrift in zwölf Ansichten.
Alle früheren ganzen Konten-, Auth-, Profil-, Rollen-, Aufnahme- und Finanzzeilen
bleiben unverändert. Keine externe Aktion wird ausgelöst.

Neun betroffene native Suiten und fünf ursprüngliche Fachgruppen bestehen.
Die verschlüsselte Wiederherstellung ergänzt vier Nachweise zu den exakten
Konto-, Login-, Profil-, Amts- und unveränderlichen Bestätigungszeilen,
persönlichem Stimmrecht und abgewiesenen alten Amtsformularen.
Installer/HTTP-Installer/verschlüsselte Verlagerung/Upgrade/Fortsetzung bestehen
mit 231/52/196/144/27 Prüfungen, 90 tatsächlichen Upgrade-Browserprüfungen
und drei echten Prozessabbrüchen. Abschließende Inventar-/Paket-/Offlineprüfung
bestanden.

Keine Schemaänderung: Identity 8 bleibt bestehen. Generation 2026101409,
194 registrierte Suiten; daraus folgt kein vollständiger neuer Gesamtlauf
oder Gesamtabschluss der beiden Umsetzungsregister.
