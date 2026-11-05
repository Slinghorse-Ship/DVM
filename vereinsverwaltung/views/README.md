# Bestehende Manageransichten

Die 318 PHP-Ansichten sind denselben Fachbereichen wie `../src` zugeordnet.
`Shell/layout.php` enthält die gemeinsame Hülle; `Shell/module.php` delegiert
die bisherigen Modulrouten. Neue native Oberflächen liegen in `../native/Web`.

| Ordner | Inhalt |
|---|---|
| `Shell` | Gemeinsame Hülle, Navigation, Dashboard und bisherige Moduldelegation |
| `Finance/Accounting`, `Finance/Balance` | Buchungen/Berichte bzw. Journal, Abschluss und Perioden |
| `Finance/Assets`, `Finance/Tax` | Anlagen-/Originalansichten bzw. Steuerabgabe und Prüfnachweise |
| `Finance/Banking`, `Finance/Invoices` | Bank/FinTS/SEPA bzw. Rechnungen und elektronische Originale |
| `Finance/Contributions`, `Finance/Donations` | Beiträge bzw. Spenden |
| `People/Members` | Kontakte, Mitgliedschaft, Anträge, persönliche Bankdaten |
| `Inventory` | Bestand, Reservierung, Ausgabe und Rücknahme |
| `Events`, `Governance` | Kalender/Veranstaltungen/GEMA bzw. Protokolle/Tätigkeiten |
| `Documents` | Ablage, HSE/Workflows, Vorschau, Schutz und Nachweise |
| `Communication`, `Communication/Chat` | Bisherige Redaktion/Briefe bzw. Chat |
| `Settings`, `Auth` | Einstellungen bzw. Konto-/Sitzungswege |
| `Website`, `Catalog`, `Help` | Website, Fachkataloge und bisherige Handbuchansichten |

Bestehende Ansichten behalten ihre Dateinamen und Formular-/Routenverträge.
`templates.json` dokumentiert die Zuordnung ihrer bisherigen Schlüssel zu den
aktuellen Dateien. `Kulturhus\Verein\Web\Templates::path($key)` löst solche
Schlüssel auf; nur bekannte Schlüssel werden akzeptiert. Normale Teilansichten
verwenden die tatsächlichen relativen Pfade ihrer Fachordner. Beim Umzug auch
Ressourcen, Tests und Quellfingerprints gemeinsam anpassen.

Dieser Bestand ist die bestehende Oberfläche. Eine erhaltene PHP-Datei beweist
keinen freigeschalteten nativen Ersatz für eine noch abgeschaltete Altroute.
Große gemischte Teilansichten werden innerhalb dieser Modulgrenzen weiter
aufgeteilt; die Ordnerordnung ersetzt diesen fachlichen Abgleich nicht.
