# Native Feldrechte

P11 ergänzt jedes deklarative Zusatzfeld um optionale native Website-Rechte. Im Bereich **Felder & Vorlagen → Feld bearbeiten → Zugriff auf dieses Feld** werden Lesen und Bearbeiten gewählt. Die Vorgaben erhalten das bisherige Verhalten.

| Einstellung | Vorgabe | Zulässige zusätzliche Website-Berechtigung |
| --- | --- | --- |
| Lesen | Lesen | Redaktion, Veröffentlichung, Administration |
| Bearbeiten | Redaktion | Veröffentlichung, Administration |

Die normale Website-Leseberechtigung bleibt für jeden Zugriff erforderlich. Änderungen benötigen zusätzlich Website-Redaktion. Lesen und Bearbeiten eines Feldes müssen gemeinsam erlaubt sein; eine Bearbeitungsfreigabe übergeht keine Lesesperre. Native Berechtigungen werden bei jedem Vorgang aktuell geprüft. Eine neu erteilte oder entzogene Berechtigung gilt auch für bereits angemeldete Personen.

Für jedes Original gelten sowohl die aktuelle Felddefinition als auch die gespeicherte Definition seiner ausgewählten Fassung. Das gilt rekursiv für Datensatz-Unterfelder. Eine Lockerung des aktuellen Modells macht eine mit strengeren Rechten gespeicherte Angabe nicht automatisch sichtbar. Autorisierte neue Originalfassungen werden weiterhin über die gewöhnliche Modellnormalisierung gespeichert. Unveränderte gesperrte Werte und ihre gespeicherten Definitionen bleiben bei anderen Änderungen exakt erhalten.

Nicht lesbare Angaben fehlen im Editor, in privaten DTOs, Versionsansichten und Metadatenangeboten. Lesbare, nicht bearbeitbare Angaben erscheinen ohne erfolgreiche Formcontrols. Formatierter schreibgeschützter Text hat eine statische Ansicht. Geschützte Datensatz-Unterfelder verschwinden auch aus Zusammenfassungen; das Entfernen solcher Datensätze wird nicht angeboten. Browser mit JavaScript und gewöhnliche HTML-Formulare verwenden dieselbe Originalprüfung.

`display` in Vorlagenkontexten steuert die Editoranzeige. Tatsächliche rollenabhängige Rechte werden mit `access.read` und `access.edit` vergeben. Gespeicherte öffentliche Anzeige- und Feldtypen bleiben Teil der ursprünglichen Fassung.

Private Seitenleser, Vorschau, Wiederherstellung, Kopie, Teilbaumvorbereitung und endgültiges Einfügen prüfen die tatsächliche aktuelle Berechtigung. Direkte bekannte IDs und Feldnamen umgehen diese Prüfung nicht. Kopierformulare bieten nur berechtigte gespeicherte Grundlagen an. Fehlende geschützte Formcontrols erhalten Werte; manipulierte Änderungen und Löschungen scheitern vor dem Speichern. Abgelehnte Vorgänge verändern weder Originale noch Versionen.

SQL-Selektoren und Inhaltslisten wenden geschlossene Feldprädikate vor Vergleich, Aggregat und Seitenauswahl an. Nicht lesbare Sortierwerte werden als SQL-NULL behandelt. Auch Definitionen, Feldoptionen und wiederholbare Unterdefinitionen werden vor DTO-Ausgabe gefiltert. Vergleichsliterale gegen nicht lesbare Quellen gelangen nicht in Browserdefinitionen.

Öffentliche Ausgaben verwenden eine eigene anonyme Projektion. Administratorrechte erweitern sie nicht. Private Felder, Passwortprüfsummen, interne Datensätze, redaktionelle Bedingungen und dadurch unzulässige Dateimetadaten gelangen nicht in öffentliche DTOs. Seiten, Beiträge, Veranstaltungen, Originalstreams, öffentliche Suche und Sprachprojektionen wenden diese Regel vor der Darstellung beziehungsweise Trefferauswahl an. Der Entzug einer Feldfreigabe erhöht die Veröffentlichungs-Generation atomar und wirkt damit auch auf vorhandene HTML- und Feed-Caches.

Bild- und PDF-Downloads benötigen nach der aktuellen Feldprojektion eine tatsächlich erlaubte Bindung in einer aktuellen öffentlichen Originalfassung. Die Auswahl liest bis zu 25 Kennungen und jeweils nur einen vollständigen Snapshot. Eine unabhängige erlaubte Nutzung kann gemeinsame Originalbytes weiter freigeben; sie autorisiert keine Metadaten einer gesperrten anderen Quellseite. RSS allein autorisiert das tatsächlich eingebettete erste Titelbild. Sekundäre Bilder, Zusatzfeldbilder und Dokumente ohne Website-Nutzung erhalten dadurch keine Freigabe. Bild- und PDF-Originaldateien bleiben unverändert.

Sprachfassungen behalten eigene serverseitige Felddefinitionen für die damals aktuelle Freigabe und für die gebundene Quellfassung. Bei mehreren Quellwechseln bleiben zusätzlich alle bisherigen nativen Rollen als begrenzte Mengen erhalten; jede Rolle muss erfüllt sein. Private Übersetzungstexte bleiben dadurch auch nach einer Modelllockerung oder dem Wechsel auf eine offenere Quellrevision geschützt. Normale Übersetzungsänderungen erhalten ausgelassene gesperrte Texte. DTOs, Historie, Wiederherstellung, Kopie und öffentliche Überlagerung prüfen dieselben Rechte; eine berechtigte öffentliche Originalangabe kann als echte Sprachrückfallquelle erhalten bleiben. Jede ältere Revision behält ihre eigene damalige Freigabe.

Der native Übertragungsweg exportiert vollständige Sprachentwürfe nur mit Verwaltungs-, Redaktions-, Sprach- und sämtlichen gespeicherten Feldrechten. Das Paket enthält ausschließlich logische Feldkennungen und begrenzte Lesens-/Bearbeitungsrollen. Der Import bindet diese Rechte innerhalb der gemeinsamen Transaktion an genau die erste private Fassung einer neuen Seite. Bereits vorhandene Sprachoriginale werden nicht überschrieben; Genehmigungen und fremde Personenkennungen werden nicht übertragen.

Übertragene Basisseiten erhalten denselben Schutz für ursprüngliche Felder und verschachtelte Datensätze. Das Paket enthält dafür begrenzte logische Feldpfade und native Rollenmengen. Jede Rolle wird unabhängig von den Paketangaben tatsächlich verlangt. Die erste private Originalfassung erhält serverseitige `access_history`-Bindungen; spätere gewöhnliche Änderungen, Veröffentlichung, SQL-Auswahl, Downloads und erneuter Export erhalten diese Bindungen. Ein gelockertes heutiges Globalmodell macht alte private Inhalte dadurch nicht öffentlich. Nur diese zusätzlichen Rollenmetadaten werden aus dem strukturellen Modellvergleich entfernt; ihre eigentliche Rechteprüfung bleibt bestehen.

Die Freigaben liegen im bestehenden JSON der Definitionen und Snapshots. Feldprüfungen erzeugen keine Tabellen und führen kein Runtime-DDL aus. Originalmedien, andere Seiten, Geschäftsdaten und ältere Revisionen werden durch Rechteprüfungen nicht neu geschrieben.

## Kanonische Schnittstellen

- `FieldPolicy::prepare(PDO, Access, definitions, values, previous, validationOnly=false)` bindet Änderungen an unveränderte Originalwerte und gespeicherte Rechte.
- `FieldPolicy::internalSource` ist ausschließlich für interne PHP-Abläufe bestimmt. HTTP-Ausgaben verwenden `privatePayload`; erlaubte Passwortprüfsummen werden dort stets entfernt.
- `FieldPolicy::publicSource` liefert die gefilterte interne anonyme Darstellung, `publicPayload` die geschlossene öffentliche DTO-Projektion.
- `FieldPolicy::queryGuard` und `definitionFilter` liefern gebundene SQL-Prädikate und Parameter für Seitenfelder beziehungsweise aktuelle Definitionen.
- `FieldPolicy::modelDefinitions` und `modelTemplate` filtern aktuelle Schema-Metadaten, Kontexte und Gruppen.
- `FieldPolicy::exportHistory`, `normalizeHistory` und `bindHistory` übertragen ausschließlich historische Einschränkungen an vorhandene ursprüngliche Feldpfade. `withoutHistory` entfernt nur diese serverseitigen Rollenmetadaten für den strukturellen Modellvergleich.
- `Pages::editorDefinitions` bindet tatsächliche Originaldefinitionen an aktuelle Controls; `Pages::copyBases` liefert aktuell nutzbare Kopiergrundlagen.
- `Translations::privatePayload` projiziert auch gespeicherte API-Wiederholungsantworten erneut. `exportDrafts`, `normalizeFieldHistory` und `importDraft` bilden den geschlossenen serverseitigen Sprachübertragungsvertrag.

Die neue Fachgruppe `native-field-policy` prüft die geschlossene Normalisierung, echte Dienste und SQL-Abfragen, Livefreigaben, kumulative Originalrechte, Sprachwechsel, Kopierfortsetzungen, öffentliche Dateien und den Erhalt von Originalbytes. Mit `DVM_FIELD_POLICY_BROWSER=1` startet sie eine weitere eigene frische Datenbank für beide echten Anwendungen, JavaScript und HTML-Formulare, echte GET/POST/CSRF, Frontendbearbeitung, 320/390/1024 Pixel und 200 Prozent Textgröße. Bestehende Entwicklungs- oder integrierte Managerdatenbanken werden nicht verwendet.

## Geprüfter Abschluss von P-11

Die abschließende Fachgruppe besteht 150 Bedingungen. Beide tatsächlichen Anwendungen bestehen mit JavaScript und gewöhnlichen HTML-Formularen 666 Browserbedingungen in 72 Ansichten sowie 28 echte POST-Vorgänge. 415 ursprüngliche fremde Tabellen, acht ältere Revisionen, Medien und beide Schlüssel bleiben bytegleich; externe Requests und JavaScript-Fehler treten nicht auf. 15 benachbarte Gruppen und nach der letzten Sprachkorrektur drei Sprachgruppen bestehen ebenfalls. Die API wird separat mit 95 Bedingungen einschließlich erneut projizierter Übersetzungsantworten geprüft; ihre öffentliche Integration folgt in ihrem eigenen Paket.

Belegdateien und SHA-256:

- Fachgruppe: `kulturhus-p11-final-history-contract.log`, `4edcdbce229278e928a70da3f6886a4351fed153fa7a5173938af4f1e8eb31b4`.
- Tatsächliche Bedienung: `kulturhus-p11-browser-complete-final.log`, `4d38849e8aefcdc9b03b83e75f45a4b872d87fbd09c354794ef6f29a60b6aae4`.
- 15 benachbarte Gruppen: `kulturhus-p11-complete-neighbors.log`, `3156bad1942e92513f8b22abd20f999a348bf5f377901f9e19b49dd02e17e3e6`.
- Drei Sprachgruppen: `kulturhus-p11-languages-history-final.log`, `6af71ed8e14b7a3fbdab31ae6137ac7321f7bfc3709d73ff14fc0a4a2af820eb`.

Die Register enthalten weiterhin die offenen übrigen P-Punkte; dieser Abschluss ist kein Gesamtabschluss.

Die getrennte kuratierte Auslieferungsfassung besteht zusätzlich alle 22 gewählten Fach-/Browser-/Paketgruppen, darunter erneut 150 Feldrechte-, 666 Browser-, 256 Installations- und 150 Upgradebedingungen mit 93 HTTP-/Bedienungsschritten. Ihr kleinerer Modulbestand enthält 395 unveränderte fremde Tabellen statt des parallel erweiterten Arbeitsbaums. Beleg: `kulturhus-p11-isolated-feature-package.log`, SHA-256 `1d28d22b46d72ce47697ac413342ab469940ddb5e29726c0d96ab8c9e65df59a`. Das endgültige Quellen-/Offline-Inventar wird nach den abschließenden Dokumentationsänderungen nochmals geprüft.
