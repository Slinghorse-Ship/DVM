# Strukturierte E-Rechnungen und eingebettete XML

Stand: 7. September 2026. Lokale Prüfung mit synthetischen Dokumenten und öffentlichen Standardbeispielen; keine Mitgliedermails, keine produktiven Buchungen.

## Gemeinsamer Weg

Manueller Rechnungseingang, Rechnungsupload am Gegenstand und Mailanhänge speichern ihre Originale über Documents. Die Rechnungsansicht prüft nach dem Dokumentleserecht dieselbe Dateiversion. XML wird direkt erkannt; bei PDF liest die bestehende isolierte Python-/PDFium-Laufzeit den eingebetteten XML-Anhang aus. Auch die Auslese direkt am Gegenstand und vor dem Speichern eines Rechnungsuploads verwendet diesen Vorrang: erkannte eingebettete XML wird strukturiert verarbeitet, direkte XML ohne Python-Aufruf. Es erfolgt kein Raster-OCR für erkannte eingebettete Rechnungsdaten. Die Erkennungsversion 4 verwirft frühere OCR-Ergebnisse im Cache. Der Upload erzeugt keine automatische Buchung oder Freigabe.

Die Auswertung liefert Lieferantenname, Anschrift, E-Mail, Telefon, Rechnungsdaten, Netto-/Bruttobetrag, Steuerbetrag und getrennte Bankverbindungen sowie Positionen. Mehrere Bankkonten werden nicht automatisch auf das erste reduziert. Angaben bleiben Vorschläge. Preisbasis, Steuersonderfälle und Rundungen müssen am Original geprüft werden.

## Original und formale Prüfung

Das PDF bleibt unverändert gespeichert und wird heruntergeladen bzw. mit der Endung PDF im Belegpaket ausgegeben. Revision und SHA-256 der formalen Prüfung gehören zum PDF-Original; KoSIT erhält ausschließlich den daraus ausgelesenen XML-Inhalt. Sein unveränderter Bericht enthält den Prüfbezug auf diese XML. Ein Ersatz des Originals macht das frühere Prüfergebnis veraltet.

Die vorhandene KoSIT-Konfiguration enthält das Szenario EN16931 (CII). Das öffentliche CII_example1.xml aus dem Tag validation-1.3.16 des ConnectingEurope-Projekts wurde in ein synthetisches PDF eingebettet, über den tatsächlichen Worker extrahiert und mit dem echten Java-Validator erfolgreich geprüft: valid, EN16931 (CII), keine Prüfmeldungen. Dies belegt die XML-Auswertung für das EN16931-Profil; es ist keine Prüfung der PDF/A-Verpackung oder der Übereinstimmung von sichtbarem PDF und XML. Andere Profile sind nur dann formal geprüft, wenn das konfigurierte Regelwerk sie tatsächlich akzeptiert. Ohne passendes Prüfprofil zeigt die Oberfläche ausdrücklich „fachlich ungeprüft“.

## Sicherheitsgrenzen

Maximal 10 MiB PDF, 16 Anhänge und genau eine XML-Datei mit höchstens 2 MiB entpackten Bytes. Mehrere XML-Anhänge werden nicht willkürlich ausgewählt. Vor der Pufferallokation wird die von PDFium gemeldete Größe begrenzt. Anhangnamen werden nie als Dateipfade verwendet. Native PDF-Auswertung findet nach Aktivierung der vorhandenen Sandbox und Ressourcenlimits statt; keine weiteren externen OCR-Dienste oder Pakete sind erforderlich.

Die anschließende XML-Prüfung begrenzt Tiefe und Knotenzahl, verbietet DTD/Entities und verwendet bekannte Namespaces. Unbekannte oder uneindeutige Anhänge werden nicht als gültige Rechnungen ausgegeben. Formaterkennung ist keine Regelprüfung; Regelprüfung ist keine Absender- oder Leistungsprüfung und kein Virenscanner.

## Nachweise

- `tests/einvoice-party-fields.php`: 58 kombinierte XML-/Positions-/Lieferantenprüfungen bestanden.
- `tests/einvoice-embedded.py`: fünf echte PDFium-Fälle bestanden: exakter Inhalt, PDF ohne XML, mehrere XML, entpackte Übergröße, zu viele Anhänge.
- Zusätzlicher tatsächlicher Worker/PHP-Negativlauf: PHP-Text mit XML-Anhangnamen wird vor KoSIT abgewiesen.
- `tests/einvoice-validation.php`: 47 kombinierte Prüfungen mit echter lokaler KoSIT-Laufzeit bestanden, einschließlich falscher Summen, Rechte, Hash und unveränderter Prüfberichte.
- `tests/einvoice-embedded.php`: optionale echte Worker-/KoSIT-Integration mit `KULTURHUS_EMBEDDED_TEST_PDF` und `VEREIN_KOSIT_RUNTIME`. 34 kombinierte Prüfungen tatsächlich bestanden (Exit 0), einschließlich PDF-Hashbindung, Zugriffsrecht, CII-Validierung, exaktem PDF-Originalexport und veraltetem Prüfergebnis nach Ersatzversion.
- Unabhängiger Browserlauf des Finanzagenten: XML und PDF mit eingebetteter XML ergeben jeweils Formularvorschläge einschließlich Kontakt/Bank und vier Positionseinheiten; synthetische Kontakt-/Ausgabenanlage erfolgreich. Mobilbreite 390 px ohne horizontalen Überlauf. Keine echte Mailzustellung getestet.

Quellen: [PDFium-Anhang-API](https://pypdfium2.readthedocs.io/en/stable/_modules/pypdfium2/_helpers/attachment.html), [offizielles CII-Beispiel](https://github.com/ConnectingEurope/eInvoicing-EN16931/blob/validation-1.3.16/cii/examples/CII_example1.xml). Downloadquelle und Prüfsumme der lokalen Standardfixture stehen in `.local/einvoice-cii-source.json`. Private lokale Laufprotokolle sind nicht Teil des Handbuchs.

Nachprüfung gemeinsamer Inventarpfad: 16 Staging-/Rechte-/Cacheprüfungen bestanden; tatsächlicher Standard-Workeraufruf mit PDF ergibt `method=xml`, `source=embedded_xml` und 20 Positionen des öffentlichen CII-Beispiels. XML-Anhangbytes gelangen nicht in API oder Cache. Ein als XML getarnter PHP-Anhang wird ohne OCR-Rückfall abgewiesen.
