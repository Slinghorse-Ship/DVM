# Ausmusterung bei getrennten Buch- und Steuerwerten

Entwicklungsstand vom 18. September 2026. Nicht produktiv veröffentlicht.
Kein Zugriff auf die produktive Datenbank, kein ELSTER-Versand und keine Freischaltung
der Echtabgabe in diesem Entwicklungsschritt.

## Abgedeckter Fall

Vollständige, beleggebundene Ausmusterung **ohne Erlös** einer linear abgeschriebenen
Ausstattung auf 06300 oder 06500, im gesondert bilanzierten Geschäftsbetrieb (Bereich 4).
Eine fachlich bestätigte steuerliche Nutzungsdauer darf vom Buch-Anlagenplan abweichen.
Kosten, Restwert, Anschaffungsdatum und der bereits festgestellte Abgang bleiben gleich.
Der Export bleibt auf die vorhandenen geprüften Jahreskataloge 2025/2026 beschränkt.

Die Feststellung des Abgangs benutzt den vorhandenen Beleg- und Berechtigungsablauf.
Diese Erweiterung entscheidet weder über die Zulässigkeit eines Steuerplans noch
über eine steuerliche Umqualifizierung des Vorgangs. Eine Schenkung, ein Teilabgang,
ein Verkauf oder eine Entschädigung wird nicht als Ausmusterung ohne Erlös behandelt.

## Berechnung und Unveränderlichkeit

- Abschreibung bis zum belegten Abgangsmonat und verbleibender Restwert werden
  für Buch- und Steuerwerte separat berechnet. Unterschiede erhalten getrennte,
  ausgeglichene Überleitungsbuchungen; Restwertabgänge benutzen 68950.
- Buchabschluss, ursprünglicher Anlagenplan, Anschaffungsrechnung und Abgangsnachweis
  werden nicht umgeschrieben. Der originale und der steuerliche Brutto-Anlagenspiegel
  bleiben nebeneinander erhalten, einschließlich ihrer Herkunftsnachweise.
- Anfangsunterschiede werden aus dem bestätigten Vorjahr übernommen. Im Abgangsjahr
  verbleibt kein Vermögenswert; im Folgejahr gibt es keine erneute Abschreibung oder
  zweite Ausbuchung. Ein steuerlicher Restwert ist auch dann möglich, wenn der
  Buchgegenstand schon vollständig abgeschrieben war.
- Bei neuen Quellen wird das Konto 68950 schon vor dem Abschluss reserviert, auch
  wenn der Buch-Restwert null ist. Das erzeugt keine künstliche Null-Euro-Buchung.
  Ein gespeicherter Marker hält die Quell-Prüfsumme bei erneuter Prüfung stabil.
  Bereits früher gebundene Quellen ohne Marker behalten ihren bisherigen Inhalt.
- Benötigt die Überleitung einen Restwertabgang, muss genau ein passendes Aufwandskonto
  68950 im festgeschriebenen Kontenplan vorhanden sein. Fehlende oder mehrdeutige Konten
  werden nicht nachträglich erfunden. Alte Abschlüsse sind gegebenenfalls fachlich zu klären.
- Python prüft Pläne, Originalnachweise, beide Anlagenspiegel, Einzelbewegungen,
  Überleitungsbuchungen, Kontensalden und Ergebnis unabhängig vom PHP-Rechenkern.
  Manuelle Doppelkorrekturen oder veränderte Herkunftsnachweise verhindern den Export.

Die Oberfläche zeigt Abgangsdatum und ausgebuchten Restwert für Buch- und Steuerwerte
im bestehenden aufklappbaren Buchhaltungsbereich. Freigabe, Rollenprüfung,
Versionskonfliktschutz und erneute Prüfung nach Entwurfsänderungen bleiben unverändert.

## Lokale Prüfungen

- 129 kombinierte PHP-Prüfungen: 69 bestehende/erweiterte Steuerplanprüfungen plus
  60 Abgangsprüfungen. Diese Zahlen nicht nochmals addieren.
- 18 Python-Testmethoden für getrennte Steuerwerte, mit 14 positiven Ausgangsfällen
  und negativen Unterfällen (Manipulation, fehlende/doppelte Buchung, Nachweisfehler).
- 7 neue Fälle mit nativer ERiC-Validierung Code 0: Anschaffung und Abgang im selben
  Jahr, Altanlage, schnellere Steuer-AfA, Buchwert null/Steuerwert positiv,
  Steuerwert null/Buchwert positiv, Cent-Rundung mit Restwert, Folgejahr nach Abgang.
  Verfälschte Bilanzsumme wird mit 610001002 abgewiesen.
  Nachweis: `audit/ebilanz-tax-scrap-native-20260918.json`.
  Ausschließlich Prüfflag 2, kein Zertifikat und kein Transport.
- 31 PHP-Ansichtsprüfungen und 144 Browserprüfungen bei 390/1280 Pixeln.
  Netzwerk blockiert, kein Formular abgeschickt; Bildschirmdarstellung visuell geprüft.
  Das ist keine vollständige HTTP-End-to-End-Abnahme.
- Weitere 96 bestehende Python-Testmethoden für Generator, Verkauf, Ausmusterung,
  degressive Abschreibung und Umsatzsteuer sowie 8 Bridge-Prüfungen erfolgreich.
  Bestehende PHP-Prüfungen für Überleitung, Anlagenverzeichnis, Jahreskontenzuordnung,
  Rückmeldungsstatus, Formulierungen und Kompatibilität der AfA-Quellen ebenfalls erfolgreich.

## Serverabnahme

Der separate MySQL-Testlauf verwendet ausschließlich die freigegebene zweite Datenbank.
Vorher wird sie serverseitig verschlüsselt gesichert und nachher anhand aller Zeilen,
Tabellendefinitionen und Trigger identisch wiederhergestellt. Originaldaten und Schlüssel
bleiben auf dem Server. Temporärer Testcode wird erst nach erfolgreicher Wiederherstellung
entfernt. Der tatsächliche XML-Generator läuft, der Transport ist jedoch ein Offline-Ersatz;
`sent_test` in diesen Prüfungen bedeutet keinen tatsächlichen ELSTER-Versand.

Abschließender Lauf: `audit/ebilanz-mysql-host-20260918-525a3f7875da59b1.json`.
PHP 8.3.33, MySQL 8.4.11; alle sechs Testskripte erfolgreich:

- 4 Schema-/Wiederherstellungsprüfungen.
- 90 kombinierte Steueranlagen-/Abgabeprüfungen ohne Abgang.
- 96 kombinierte Prüfungen mit Ausmusterung nach bestätigtem Vorjahresplan.
- 97 kombinierte Prüfungen mit Buchwert null, positivem Steuerwert und Abgang.
- 78 kombinierte Prüfungen des vorhandenen Abgabe-/Vorjahresablaufs.
- 140 kombinierte Prüfungen des vorhandenen Rechnungs-/Anlagenverkaufsablaufs.

Die kombinierten Suiten enthalten gemeinsame Basisprüfungen und sind **nicht**
additiv als einzigartige Testfälle zu zählen. Nach dem Lauf waren die ursprünglichen
150 Tabellen und 1.393 Datensätze einschließlich Strukturen und Triggern identisch
wiederhergestellt. Der private temporäre Testcode wurde entfernt, die verschlüsselte
serverseitige Sicherung blieb erhalten. Die geschützten Produktivdateien waren unverändert.
Keine Produktivverbindung und kein öffentlicher Testendpunkt wurden eingerichtet.

## Weiterhin offen

Unterschiedliche Buch-/Steuerwerte beim Verkauf (einschließlich Wechsel zwischen Gewinn
und Verlust), Teilabgänge, Schenkungen, Umbuchungen, Versicherungsleistungen,
abweichende Bewertungsgrundlagen, nachträgliche Änderungen bestätigter Steuerpläne,
weitere Abschreibungs-/Steuersonderfälle und die vollständige Kontenabdeckung.
Auch mehrere Geschäftsbetriebe, Gesamtvereinsbilanz und abweichende Wirtschaftsjahre
sind nicht pauschal freigegeben. Eine technische ERiC-Validierung ist keine Bestätigung
materieller Steuerrichtigkeit und kein Steuerbescheid.
