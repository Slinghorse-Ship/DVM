# ADR001 – DVM mit konfigurierbarem Verein

DVM ist ein eigenständiger Vereinsmanager mit integrierter Website. Jede
Installation erhält eigene Vereinsdaten, Domain, MySQL-Datenbank, persönliche
Konten und private Schlüssel. Kulturhus bleibt eine auswählbare Website-Vorlage.
Die benötigten neuen Inhalte werden in DVM angelegt.

`native/` enthält Anmeldung, Website und gemeinsame Plattformfunktionen; `src/`
die bestehenden Fachmodule. Die Integration erhält ihre ursprünglichen Daten,
aktuellen Rechte, Freigaben und Versionsverträge. Interne dauerhafte Kennungen
werden durch eine redaktionelle Bereinigung nicht umgeschrieben.

[Architektur](../ARCHITECTURE.md), [Modulstruktur](../PROJEKTSTRUKTUR.md),
[Installation](../WEBSPACE-INSTALLATION.md),
[Hosting-Zeitpläne](../HOSTING-ZEITPLAENE.md).
