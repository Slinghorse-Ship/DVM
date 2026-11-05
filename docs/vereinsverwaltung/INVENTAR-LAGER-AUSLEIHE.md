# Inventar, Lagerorte und Ausleihe

Stand: 5. September 2026. Status: lokal mit synthetischen Daten umgesetzt; Grenzen und Abnahmebelege im Umsetzungsbericht.

## Nutzerentscheidung

Die Vereinsverwaltung benötigt eine eigene Gegenstandsverwaltung für Kulturhus Jaderberg e. V. Gegenstände müssen Lagerorten und Verantwortlichen zugeordnet und ausgeliehen werden können. Vereinseigene Gegenstände gehören zur Vermögensübersicht. Diese Anforderung ergänzt den bisherigen WISO-Abgleich zu freien Inventarlisten (MW-O041).

## Platz in der Anwendung

- **Orga → Inventar & Ausleihe** als eigener Bereich mit den Unteransichten Gegenstände, Lagerorte und Ausleihen.
- **Finanzen → Vereinsvermögen** als verknüpfte Übersicht der eigenen Gegenstände und ihrer dokumentierten Anschaffungs-/Zuwendungswerte. Geldbestände bleiben getrennt erkennbar.
- Verweise aus Sachspenden und Anschaffungsbelegen zum zugehörigen Gegenstand; gemeinsame Stammdaten statt mehrfacher Erfassung.

## Erforderliche Funktionen

1. **Gegenstände:** Inventarnummer, Bezeichnung, Kategorie, Beschreibung, Foto, Seriennummer, Zustand, Anschaffungs-/Zugangstag und zugehörige Dokumente. Einzelgeräte und gleichartige Bestände mit Mengen sollen verwaltbar sein.
2. **Eigentum:** Kennzeichnung als Vereinseigentum oder fremde Leihgabe. Fremdes Eigentum wird im Bestand verwaltet, aber nicht zum eigenen Vereinsvermögen addiert.
3. **Lagerorte:** Sowohl Vereinsräume als auch private Aufbewahrungsorte bei Mitgliedern/Kontakten unterstützen. Ein Anzeigename wie „bei Sonja“ mit verknüpftem Kontakt genügt; eine vollständige Privatadresse ist keine Pflicht. Gebäude, Raum und Regal/Fach können ergänzt werden. Zuordnung und Umlagerung sind nachvollziehbar; Stammlagerort und aktueller Aufenthaltsort bleiben unterscheidbar.
4. **Verantwortung:** Eigentümer, aufbewahrende Person, zuständige Person/Abteilung und gegebenenfalls Ausleiher getrennt erfassen. Die dauerhafte Lagerung eines Vereinsgegenstands bei einem Mitglied ist keine Ausleihe und erzeugt keinen überfälligen Rückgabetermin. Je Person müssen die dort aufbewahrten Gegenstände auffindbar sein.
5. **Ausleihe:** Empfänger aus Mitgliedern/Kontakten, Ausgabe- und vereinbartes Rückgabedatum, Menge, Verwendungszweck, Zustand bei Ausgabe und Rückgabe sowie ausgebende/entgegennehmende Person. Rückgaben, Teilrückgaben und Schäden dokumentieren.
6. **Verfügbarkeit:** Verfügbar, reserviert, ausgeliehen, in Wartung und ausgesondert; offene und überfällige Ausleihen anzeigen. Überschneidende Reservierungen und Ausleihen über den verfügbaren Bestand hinaus verhindern.
7. **Finanzzuordnung:** Anschaffung mit vorhandenem Beleg, Sachspendeneingang mit vorhandener Sachspende verknüpfen. Anschaffungspreis beziehungsweise dokumentierter Zuwendungswert und dessen Herkunft bleiben nachvollziehbar. Die Inventarzuordnung erzeugt keine zusätzliche Kontotransaktion.
8. **Vermögensübersicht:** Nach Kategorie, Lagerort und Verantwortung filterbar; Links auf Gegenstand, Anschaffungsbeleg und Sachspende. Eine Inventarwertsumme ist klar vom Bank-/Kassenbestand und von steuerlichen Buchwerten zu unterscheiden. Eine automatische Abschreibungs- oder Buchwertberechnung ist erst nach gesonderter fachlicher Spezifikation vorzusehen.
9. **Nachvollziehbarkeit:** Ausgabe, Rückgabe, Umlagerung, Zuständigkeitswechsel, Schaden und Aussonderung mit Datum und Akteur protokollieren. Aussonderung erhält die Historie.
10. **Rechte:** Eigene Berechtigungen für Inventar lesen, Bestand verwalten und Ausleihen bearbeiten; Zugriff auf Finanzbelege und Werte folgt zusätzlich der Finanzberechtigung. Kein automatischer Zugriff durch die Redaktionszentrale.
11. **Handkassen:** Eine physisch aufbewahrte Handkasse kann mit einem eigenen Kassenkonto unter „Bank & Kasse“ verknüpft werden. Aufbewahrungsort und aufbewahrende Person getrennt von Kassenführung und Finanzberechtigungen führen. Der Geldbestand stammt ausschließlich aus dem verknüpften Kassenbuch und wird nicht zusätzlich als Gegenstandswert gezählt. Eine Übergabe der Kasse dokumentiert Datum, beteiligte Personen und den bestätigten Zählbestand; ein reiner Orts-/Verantwortungswechsel erzeugt keine Einnahme oder Ausgabe.
12. **Zuordnung zu Mitgliederprofilen:** Aufbewahrer, Verantwortliche und Ausleiher über eine durchsuchbare Auswahl bestehender Mitglieder zuordnen; Name und Mitgliedsnummer dienen zur eindeutigen Auswahl. Die Zuordnung verweist auf die stabile Profil-ID und bleibt bei einer Namensänderung erhalten. Es werden dafür keine separaten Personenstammdaten angelegt. Im Mitgliederprofil zeigt eine verknüpfte Übersicht die aufbewahrten, verantworteten und ausgeliehenen Gegenstände sowie gegebenenfalls eine aufbewahrte Handkasse; Finanzwerte sind nur mit Finanzberechtigung sichtbar. Historische Zuordnungen bleiben bei einem Austritt nachvollziehbar. Die genannten Vornamen allein lösen noch keine automatische Zuordnung zu konkreten Profilen aus.

## Zentrale Übersicht: Wo ist was?

Der aktuelle Aufenthaltsort ist die Hauptinformation der Inventarübersicht. Pro Gegenstand beziehungsweise Teilbestand werden **Bezeichnung/Inventarnummer, Menge, aktueller Ort, aufbewahrendes Mitglied, Status sowie Zeitpunkt und Person der letzten Standortbestätigung** angezeigt. Ort und Mitglied sind getrennte Angaben: Auch bei einem zuständigen Mitglied kann sich ein Gegenstand gerade an einem Veranstaltungsort oder bei einem Ausleiher befinden. Raum, Regal/Fach oder andere Ortsdetails sollen den Standort bei Bedarf präzisieren.

- Suchen und filtern nach Gegenstand, Mitglied, Lagerort, Veranstaltung/Einsatz und Status.
- Eine bestätigte Ausgabe, Übergabe, Umlagerung oder Rückgabe aktualisiert den aktuellen Standort und die betroffenen Mengen atomar und schreibt den Verlauf fort. Eine Reservierung allein verändert den tatsächlichen Aufenthaltsort nicht.
- Bei einer Rückgabe ist der tatsächliche Rückgabeort anzugeben; das System darf nicht ungeprüft den Stammlagerort als aktuellen Ort einsetzen.
- Verteilt gelagerte Mengen erscheinen je Standort getrennt, beispielsweise sechs Bänke am Lagerort und vier am Veranstaltungsort. Die Summe muss dem erfassten Gesamtbestand entsprechen.
- Während einer offenen Ausleihe sind tatsächlicher Aufenthaltsort, Ausleiher und Rückgabetermin direkt sichtbar; der Stammlagerort bleibt ergänzend verfügbar.
- Unbekannte oder noch nicht bestätigte Standorte werden ausdrücklich als solche angezeigt. „Aktuell“ bedeutet den zuletzt dokumentierten und bestätigten Stand; eine automatische Ortung wird nicht vorausgesetzt.
- Inventarliste, Mitgliederprofil, Lagerortansicht und verknüpfte Handkasse beziehen ihren aktuellen Standort aus derselben Zuordnung, sodass keine widersprüchlichen Parallelstände entstehen.

## Konkretisierung durch den Nutzer

Aktuell wurde folgende verteilte Aufbewahrung genannt. Die Angaben konkretisieren das benötigte Modell; es wurden noch keine Inventar- oder Kassendatensätze angelegt.

| Gegenstand / Bestand | Aufbewahrung |
| --- | --- |
| Bühne | bei Sonja |
| Partybänke | bei Klaus |
| Lichttechnik | bei Klaus |
| Eine Handkasse | bei Paul |

Die Anzeige „bei Klaus“ soll beispielsweise sowohl Partybänke als auch Lichttechnik aufführen. Bei der Handkasse bedeutet „bei Paul“ zunächst nur die Aufbewahrung; daraus folgen keine automatische Kassenführer-Zuordnung und keine Zugriffsrechte.

## Abnahmebeispiele

- Ein per Sachspende erhaltener Projektor wird als Vereinseigentum angelegt, der Sachspende zugeordnet und in einem konkreten Regal verortet.
- Der Projektor wird an ein Mitglied ausgegeben; sein Stammlagerort bleibt sichtbar, der aktuelle Aufenthaltsort nennt die Ausleihe. Eine zweite gleichzeitige Ausgabe wird verhindert.
- Bei Rückgabe wird der Zustand dokumentiert; bei einem Schaden kann der Projektor für weitere Ausleihen gesperrt werden.
- Zehn gleichartige Stühle erlauben eine Ausgabe von vier und anschließend eine Teilrückgabe von zwei; verfügbare und noch ausstehende Mengen bleiben korrekt.
- Eine fremde Leihgabe kann ausgeliehen beziehungsweise zurückgegeben werden, erscheint aber nicht in der Summe des Vereinseigentums.
- Eine Verknüpfung mit einem bereits gebuchten Anschaffungsbeleg verändert weder Einnahmen/Ausgaben noch Bank-/Kassenstände ein zweites Mal.
- Dauerhaft privat gelagerte Vereinsgegenstände erscheinen beim jeweiligen Aufbewahrer und bleiben für Reservierung/Ausleihe verfügbar, sofern keine andere Sperre besteht.
- Eine Handkasse kann an einen anderen Aufbewahrungsort übergeben werden, ohne ihren Geldbestand zu verdoppeln oder eine fiktive Einnahme/Ausgabe zu erzeugen.
- Nach einer bestätigten Übergabe erscheinen der neue Ort und das aufbewahrende Mitglied übereinstimmend in „Wo ist was?“, im Mitgliederprofil und in der Lagerortansicht. Eine bloße Reservierung lässt den Standort unverändert.
- Werden nur Teile eines Bestands zu einer Veranstaltung gebracht, zeigt die Übersicht die exakten Mengen an beiden Orten; eine Teilrückgabe aktualisiert beide Mengen korrekt.

## Umsetzungsstand

Der Kernumfang ist lokal umgesetzt: Gegenstände, private/vereinseigene Lagerorte, Profilzuordnungen, Bestandsbewegungen, Ausleihen und Teilrückgaben, „Wo ist was?“, Sachspenden-/Belegverknüpfung, dokumentierte Vermögenswerte und Handkassenübergabe mit Zählabgleich. Details, Prüfungen und verbleibende Grenzen stehen in [Umsetzung und Prüfung](INVENTAR-UMSETZUNG-2026-09-05.md). Zeitgenaue Reservierungsplanung, automatische Erinnerungen, digitale Unterschriften und feinere Abteilungsrechte bleiben offen. Keine Produktiv- oder vollständige WISO-Abnahme.
