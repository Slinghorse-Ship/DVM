# Bestehende Vereinsmodule

Mit `bootstrap.php` beginnen. Es registriert `autoload.php` und lädt die bereits
benötigten Fachklassen. `SourceFiles.php` und `module-files.json` halten die
bestehenden `Kulturhus\Verein`-Klassennamen beim Ordnerumzug erreichbar.
Das Inventar enthält 402 bestehende Klassen-/Controllerdateien; zusätzliche
Unterklassen und das vorhandene `DocumentWorkflow` verwenden ihre direkten Pfade.

| Ordner | Zuständigkeit / wichtige Einstiege |
|---|---|
| `Finance/Accounting` | `Finance`, `Accounting`, Berichte und Finanzabläufe |
| `Finance/Banking` | Bankimport, FinTS, SEPA, Zahlungen und Zuordnungen |
| `Finance/Balance` | [Bilanzmodul](Finance/Balance/README.md), Journal und Perioden |
| `Finance/Assets` | Anschaffung, Abschreibung, Bewertung und originale Nachweise |
| `Finance/Invoices` | Eingang/Ausgang, elektronische Originale, Freigabe und Zahlungen |
| `Finance/Tax` | `TaxFilings`, Steueraufbereitung, Kataloge und ERiC-Prozess |
| `Finance/Contributions`, `Finance/Donations` | Beiträge bzw. Spenden und Nachweise |
| `People/Members` | Mitglieder, Familien, Anträge, Import und persönliche Bankdaten |
| `Inventory` | Inventarbestand, Anschaffung, Zuweisung und Zubehör |
| `Documents`, `DocumentWorkflow` | Originaldateien, Schutz, Downloads und HSE-Abläufe |
| `Communication`, `Communication/Chat` | Redaktion, Mail-/Kontaktwege, Chat und Push |
| `Events`, `Events/Calendar`, `Governance` | Kalender/Gutscheine/GEMA, [einzelne Kalenderquellen](Events/Calendar/README.md) bzw. Berichte und Protokolle |
| `Auth`, `Settings` | Rechte bzw. Vereinseinstellungen und Briefpapier |
| `Web` | Navigation, gemeinsame PHP-Ansichtshilfen und `Templates` für stabile Ansichtsschlüssel |
| `Infrastructure` | Datenbank, Laufzeit und technische Medienhilfen |
| `../native/Integration`, `Website` | Verbindung der gemeinsamen Website- und Fachdienste |
| `Catalog`, `Shared` | Katalogimport bzw. kleine gemeinsame Hilfen |

Neue Entwicklung findet bevorzugt in `../native/` statt: dort entsprechen
Namespace und Ordner einander, und `Web/` ist von den Fachdiensten getrennt.
Bestehende Klassen behalten vorerst ihre öffentliche API. Neue Klassen mit
modularem Namespace können direkt über den passenden relativen Pfad geladen
werden. Für weitere bestehende Klassenumzüge die Dateizuordnung, direkte
Includes, CLI-/Testwerkzeuge und Ressourcenpfade gemeinsam ändern.

Die vollständige [Projektstruktur](../../docs/PROJEKTSTRUKTUR.md) zeigt auch
HTTP-Einstieg, native Module, Werkzeuge und Abhängigkeiten.
