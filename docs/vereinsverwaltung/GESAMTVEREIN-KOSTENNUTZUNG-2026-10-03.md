# Gemeinsame Nutzung originaler Kostenanlagen

Die normale Vereinsaufteilung unterstützt jetzt belegte Nutzungsanteile von
Kostenanlagen innerhalb derselben steuerlichen Sphäre. Sie teilt Buchwerte und
jedes ursprüngliche Bewertungsereignis centgenau auf. Anschaffung, Originalplan,
Belege und Steuerbewertung bleiben als vollständige Originalanlage erhalten.
Die Zusammenfassung aller steuerpflichtigen Betriebe enthält die Originalwerte;
aus Nutzungsanteilen entstehen keine neuen Anlagen oder Steuerwahlrechte.

Wertminderungen, Wertaufholungen und wiederholte Abgänge werden zeitlich aus dem
Originalverlauf rekonstruiert. Die gerundeten Schlusswerte jeder Einheit werden
unverändert in den nächsten Zeitraum übernommen. Begrenzte Bewegungen können
keinen Anteil unter null bringen oder über die verbliebenen Anschaffungskosten
erhöhen. Die tatsächlichen Gegenkonten werden mit der Belegaufteilung abgestimmt.
Dies gilt für Kalenderjahre und aufeinanderfolgende abweichende Zeiträume.

81 tatsächlich exportierbare zusammengesetzte Fälle bestehen die native
ERiC-Prüfung mit Code 0. Sie enthalten alle vier Vereinsbereiche und zwei
steuerpflichtige Betriebe, einschließlich gemischter gewöhnlicher Anlagen,
getrennter Buch-/Steuerwerte und Folgezeiträume. Anders als im vorherigen
Nachweis sind die 28 Zeitraumfälle hier vollständige zusammengesetzte Ausgaben.
Der Prüfer lief ausschließlich mit Flags 2, ohne Zertifikat oder Versand.

Nachweis: `audit/ebilanz-cost-shared-native-20261003.json`, unveränderter
geprüfter Quellstand. Der neue vollständige Offline-Lauf besteht mit allen
54 Programmen; der tatsächliche MySQL-Nutzungsablauf wird getrennt ausgewertet
und steht noch aus.

Die Anzeige unterscheidet Kostenbewertung von gewöhnlicher Abschreibung.
Wertminderung, Wertaufholung und ausgebuchte Restwerte bleiben sichtbar.
Bei Kostenanlagen wird kein Formular für einen noch nicht unterstützten
datumsbezogenen Nutzungswechsel angeboten. Jahres- und Zeitraum-CSV enthalten
die Bewertungsbewegungen zusätzlich zu den bisherigen Wertkomponenten;
gewöhnliche Anlagen ohne Kostenanlage behalten ihr bisheriges CSV-Format.
Der lokale gemischte Jahresfall prüft sämtliche 13 exportierten Wertkomponenten
und die Darstellung für schreibende und lesende Nutzer. Dieser Rendernachweis
ist unter `audit/bilanz-cost-use-render-export-20261003.json` gespeichert und
ersetzt keine vollständige Browser- oder Rollenabnahme des Vereinsmanagers.
Der ergänzte Nachweis `audit/bilanz-cost-use-carry-render-export-20261003.json`
prüft außerdem, dass die unverändert vorgetragene Jahresnutzung nur lesbar
angezeigt wird und kein noch nicht unterstützter Nutzungswechsel angeboten wird.

Der vollständige erste Offline-Nachlauf enthält 54 Programme: 52 bestanden,
zwei nicht. Die Cent-Beträge stimmen bereits in 657 unabhängigen Nachprüfungen
überein; der betreffende Test normalisiert jetzt ausschließlich eine leere
PHP-Ereigniszuordnung `[]` zur leeren Python-Zuordnung `{}`. Der andere Fehler
war eine technische Ausnahme bei einem fehlenden Anlagenverzeichnis.
Eine zwischenzeitlich zu strenge Reparatur wurde durch weitere Regressionen
erkannt und der Nachlauf ausdrücklich abgebrochen; sein Fehlerbericht und
Abbruchnachweis bleiben unverändert erhalten. Die aktuelle Prüfung akzeptiert
die gültige leere PHP-Anlagenliste und lehnt fehlende oder falsch typisierte
Verzeichnisse mit einem fachlichen Prüffehler ab. Alle 31 gezielten
Gesamtvereinsprüfungen bestehen. Der neue vollständige Lauf ist unter
`audit/bilanz-shared-offline-final2-20261003.json` mit 54 von 54 Programmen
und unverändertem geprüftem Quellstand erfolgreich abgeschlossen. Dieser
Rechen- und XML-Lauf ersetzt den gespeicherten MySQL-Ablauf nicht.

Alle 81 zusammengesetzten Kosten-Nutzungsfälle bestehen erneut auf dem aktuellen
Quellstand in `audit/ebilanz-cost-shared-final2-native-20261003.json`.
Die gespeicherten Jahres- und Zeitraum-Nutzungsfälle werden ausschließlich
nach Ende und Bereinigung des laufenden vollständigen MySQL-Tests gestartet.
Ihre XML-Dateien müssen anschließend bytegenau durch denselben nativen
Prüfer laufen. Bis dahin ist auch dieser gespeicherte Nutzungsnachweis offen.

Das neue Format wird nur für tatsächliche Kostenanlagen verwendet. Gewöhnliche
Originalbuchwert-Abschlüsse behalten ihr bisheriges Format und ihre Hashes.
Geänderte Nutzung einer Kostenanlage, Bereichswechsel und Verkäufe mit Erlösen
benötigen weitere eigene Bewegungsnachweise und werden nicht stillschweigend
aus dem Nutzungsmaßstab abgeleitet. `09000` und weitere Bewertungsfamilien sind
dadurch nicht freigegeben. Eine allgemeine Release-, Übermittlungs- oder
vollständige UI-Abnahme ist mit diesem Nachweis nicht erklärt.

Der Gesamt-MySQL-Lauf `2b24ecdf1f996286` ist unvollständig beendet: 77 von
170 Programmen wurden abgeschlossen, 75 bestanden und zwei nicht. Der
Zeitlimitfehler bei `balance-period-area-sales.php` bleibt erhalten. Der
Kalender-Korrekturtest wurde nach mehr als drei Stunden kontrolliert über seine
synthetische Kindverbindung unterbrochen. Der ursprüngliche SSH-Clientbericht
und der unvollständige Servercheckpoint werden nicht nachträglich zu einem
vollständigen oder erfolgreichen Lauf umgeschrieben.

`audit/ebilanz-mysql-recovered-2b24ecdf1f996286.json` enthält den getrennten
Wiederherstellungsnachweis: Testbesitzer und Kindverbindungen zweimal nicht mehr
vorhanden, Produktivdateien unverändert, temporäre Testkopie entfernt. Die
Rechnungsprüfer stellen jetzt das konfigurierte Zeitlimit des Aufrufers wieder
her; ihre eigenen Prozessgrenzen bleiben wirksam. Eine wiederhergestellte
verbleibende Zeitspanne wird damit nicht behauptet.

Der neue Lauf `f361ad0da8d602ad` prüft den vollständigen aktuellen Bestand mit
172 Bilanzprogrammen sowie fünf Laufzeit-/Demo-Programmen. Kein Bilanzprogramm
ist wegen des vorherigen Abbruchs ausgelassen. Er läuft ausschließlich auf der
permanent synthetischen MySQL-Datenbank, greift nicht auf die Produktivdatenbank
zu und bindet den wiederhergestellten Vorgängerbericht mit dessen SHA-256.
Anschließend müssen die fünf tatsächlich gespeicherten Kosten-XML-Dateien
getrennt den nativen ERiC-Prüfer bestehen. Dieser Gesamtnachweis ist noch offen.
Kulturhus bleibt EÜR; Produktivdateien und echte Übermittlungen werden nicht
aus Teilnachweisen heraus aktiviert.

Die rekursive Abschluss- und Vortragsprüfung nutzt jetzt den vorhandenen
konsistenten Lesespeicher innerhalb einer einzelnen Prüfung. Der Speicher wird
vor einer anschließenden Änderung des Entwurfs vollständig verworfen und nicht
über Anfragen hinweg wiederverwendet. Der reine Lebensdauertest besteht mit
15 Prüfungen; die zusätzliche native MySQL-, Korrektur- und Kalenderkette bleibt
gesondert nachzuweisen. Aus dieser Änderung wird noch keine gemessene
Laufzeitverbesserung behauptet.

Der spätere vollständige Rechenbestand mit 173 Bilanzprogrammen, die erhaltenen
negativen Läufe und die erfolgreichen Wiederholungen sind jetzt im
[Betriebsnachweis vom 05.10.2026](BILANZBETRIEB-2026-10-05.md) gebunden.
Alle fünf tatsächlich gespeicherten Kosten-XML-Dateien bestehen dort erneut
die native Prüfung mit unveränderten Bytes. Die oben beschriebenen fachlichen
Grenzen bleiben bestehen; der neue Lauf macht aus einer noch nicht unterstützten
Bewertung keine freigegebene Funktion.
