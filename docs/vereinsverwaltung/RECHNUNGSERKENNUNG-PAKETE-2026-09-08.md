# Rechnungserkennung: Paketpositionen und getrennte PDF-Spalten

## Anlass und Korrektur

Fünf neu eingegangene Original-PDFs wurden visuell und mit der vollständigen PHP/Python-Laufzeit abgeglichen. Bei einer historischen Rechnung fehlte eine bepreiste Paketposition mit der Nummer `2.0`. Bei einer anderen Rechnung wurden getrennte Netto-/Bruttosummen, eine unnummerierte Artikelzeile sowie räumlich getrennte Rechnungsnummer und Datum nicht erkannt.

- Dotted Paketnummern und Artikelkennungen ohne Markenanhang werden erkannt. Unbepreiste Unterpositionen stehen als Paketbestandteile am übergeordneten Artikel; sie erzeugen keine weiteren Beträge. Die Zuordnung wird in einem linearen Durchlauf gesammelt.
- Eine einzelne beschriftete Artikelzeile erhält nur dann eine eindeutige Nettobasis, wenn Artikelbetrag, Nettosumme, Steuer und Bruttosumme exakt zusammenpassen. Inklusivversand wird nicht nochmals als Kostenposition hinzugefügt. Bei mehreren Einheiten wird kein durchschnittlicher Einzelpreis erfunden.
- PDFium liest Rechnungsnummer und Rechnungsdatum rechts neben dem jeweiligen sichtbaren Label auf derselben Zeile. Die Reihenfolge von Beschriftungs- und Wertespalten wird nicht geraten. Fehlende, widersprüchliche oder ungültige Angaben bleiben offen.
- Der Summenvergleich im Formular verwendet eine gemeinsame Bruttobasis. Echte Centabweichungen und ungeklärte Preisbasen bleiben sichtbar.
- Die gelabelte Zahlungsart erscheint als Hinweis. Klarna/PayPal setzen keinen Bezahltstatus; allgemeine Fußzeilenkonten werden dabei nicht vorausgewählt. Prüfsummengültige Konten bleiben Kandidaten zur bewussten Prüfung.
- Parser-Version 5 verwirft Ergebnisse älterer Parser. Originalrevision und SHA256 bleiben Bestandteil der Cachebindung. Die Erkennung erzeugt keine Buchung, Freigabe, Zahlung oder neue Firma.

## Prüfung

- Alle fünf einseitigen Originale: zwölf bepreiste Positionen, zwei zusätzliche unbepreiste Paketbestandteile, korrekte Originaljahre und Netto-/Steuer-/Bruttosummen. Lieferantenangaben und vorhandene Bankkonten am Original überprüft.
- Vollständige lokale PHP/Python-Auslese der fünf PDFs: native Textschicht, etwa 0,26–0,34 Sekunden je Datei. Das ist eine Messung dieser Text-PDFs auf dem lokalen Rechner, kein allgemeiner OCR- oder Hosting-Leistungswert.
- `invoice-recognition-parser.py`: bestehende Regressionen plus Pakete, Netto-/Steuerkonflikte, eingeschlossener Versand, Mengenfaktoren und Zahlungswege.
- `invoice-recognition-pdf-header.py`: synthetische echte PDFs; fehlende Spaltenwerte, datumsartige Referenz, widersprüchliche Labels, ungültiges Datum und mehrere Werte auf einer Zeile.
- `invoice-recognition-cache-version.php`: veralteter Cache, Originalbindung und keine Finanzschreibvorgänge.
- `invoice-recognition-net-browser.cjs`: fünf Prüffälle in der tatsächlichen lokalen Oberfläche mit synthetischen Erkennungsantworten, einschließlich Desktop/Mobil und unverändert notwendiger manueller Prüfung.
- Bestehende PHP-Prüfungen für Erkennung, Uploads, Sperren, Lieferantendaten und Positionsübernahme sowie XML-Felder/-Positionen bestanden. Der zusätzliche eingebettete-PDF-Test benötigte eine separate Fixture und wurde in diesem Lauf nicht ausgeführt.

Private Originale und Prüfresultate bleiben ausschließlich in der ignorierten lokalen Prüfablage. Im Git stehen synthetische Tests. Ein bereits zuvor vorhandener Beleg und einer der neuen Eingänge enthalten dieselbe historische Rechnungsnummer, dasselbe Datum und denselben Betrag; die zwei Vorlagen dürfen nicht als zwei Ausgaben erfasst werden.

## Grenzen

Erfolgreiche Extraktion bedeutet weiterhin einen zu prüfenden Vorschlag. Die Korrektur ersetzt keine allgemeine Rechnungsvollständigkeitsprüfung, Buchungsfreigabe oder Zahlungsprüfung. Die ursprünglichen Datei-, Seiten-, Prozess- und Speichergrenzen bleiben bestehen; es wurde keine zusätzliche externe Erkennungsdienstleistung eingerichtet.

Der Verein besteht bereits länger; die Erklärung 2025 ist nach Auskunft des Nutzers noch nicht abgegeben. Historische Rechnungsdaten werden beim Prüfen in ihrem Originaljahr angezeigt. Die hier durchgeführte Erkennungsprüfung erfasst keine Ausgaben und reicht keine Steuererklärung ein.

## Veröffentlichung

Am 08.09.2026 veröffentlicht: Runtime-Commit `71b96adebaca6e3e75c50136663d1319a9f2a803`, fünf Datei-Ziele, Schema 62 ohne Migration. Vorheriger Live-Inhalt stimmte mit dem Git-Ausgangsstand überein; Sicherung unter `/tmp/kulturhus-verein/rollback-invoice-recognition-packages-20260908`. Alle veröffentlichten Bytes wurden zurückgelesen.

Anschließend wurden alle fünf Originale über die angemeldete Live-Oberfläche mit Parser-Version 5 erneut ausgelesen. Zwölf Positionen erscheinen mit passenden Bruttosummen im Formular; die Roll-up-Rechnung zeigt das Originaljahr 2025, die Paketposition ihre beiden unbepreisten Bestandteile. Lieferant, Nummer, Datum, ausgewiesene Fälligkeit, Netto/Steuer/Brutto, Zahlungsweg, Positionen und vorhandene Bankkandidaten wurden zusätzlich aus dem Live-Cache mit den visuell verifizierten Ergebnissen verglichen. Ein nicht konkret datiertes Zahlungsziel wird dadurch nicht zu einem erfundenen Fälligkeitsdatum.

Die fünf Original-SHA256 sowie der Finanz- und Inventarstand stimmen weiterhin mit dem Stand vor der Prüfung überein. Es wurden nur Erkennungsergebnisse erneuert, keine Ausgaben, Firmen, Freigaben, Zahlungszuordnungen oder Mails erzeugt. Der authentifizierte Zusatzprüfer war nur lesend und wurde anschließend entfernt. Nachweise stehen privat in `.local/new-invoice-audit/live-fixed-verification.json` und `.local/invoice-recognition-packages-deployment-result.json`.
