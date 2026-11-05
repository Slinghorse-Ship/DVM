# Felder abhängig von anderen Angaben anzeigen und verlangen

Unter **Website → Felder & Vorlagen** ein Feld öffnen und **Anzeige und Pflicht
nach anderen Angaben** aufklappen. Zwei getrennte Regeln sind möglich:

- **Nur anzeigen, wenn** blendet das Feld abhängig von anderen Angaben ein.
- **Zusätzlich erforderlich, wenn** macht ein sichtbares optionales Feld zum Pflichtfeld.

**Bedingung verwenden** aktivieren, die andere Angabe auswählen und einen Vergleich
festlegen. Eine Regel verbindet bis zu zehn Bedingungen mit **Alle Bedingungen
müssen passen** oder **Mindestens eine muss passen**. Bedingungen lassen sich
hinzufügen und entfernen. Ein Beispiel: die Kontaktnummer nur zeigen, wenn die
Kontaktart „Telefon“ ist; eine Bemerkung verlangen, wenn eine Zahl über dem
festgelegten Betrag liegt.

Ja/Nein und feste Auswahlwerte erhalten passende Auswahlfelder, Datum einen
Datumseingang. Für Seiten und Bilder steht die gemeinsame private Referenzsuche
bereit. **Ist leer** und **Ist ausgefüllt** benötigen keinen Vergleichswert.
**Enthält Text** verwendet gewöhnlichen, groß-/kleinschreibungsabhängigen Text,
auch bei formatierten Angaben. Größer/kleiner und mindestens/höchstens sind für
Zahlen und Datum verfügbar. Dezimalzahlen akzeptieren Punkt oder Komma und werden
mit bis zu 18 Vor- und sechs Nachkommastellen ohne Rundung verglichen.

Alle Bedingungsangaben müssen in derselben Seitenvorlage enthalten sein. Ein Feld
kann nicht sich selbst verwenden; Kreise zwischen Sicht- oder Pflichtregeln werden
abgewiesen. Eine Änderung des Quelltyps darf vorhandene Vergleiche nicht ungültig
machen. Regeln werden mit der Felddefinition versioniert und in ihrer Historie
erhalten. Die Änderung einer Regel verlangt eine bewusste neue Speicherung des
Seitenentwurfs vor der nächsten Veröffentlichung. Bisher veröffentlichte Inhalte
bleiben unverändert.

Im Seitenformular wechseln Sichtbarkeit, native Pflichtprüfung und Hinweise mit
den Angaben. Ausgeblendete Werte bleiben im Formular und in der gespeicherten
Originalrevision erhalten. Auch ausgeblendete vorhandene Werte müssen weiterhin
ihrem Feldtyp und den Grenzen entsprechen. Das Ausblenden steuert die Redaktion;
es ersetzt keine Zugangs- oder Veröffentlichungsrechte. Ohne JavaScript bleiben
alle Angaben sichtbar und erreichbar; die Pflichtprüfung gilt weiterhin auf dem
Server.

## Gemeinsame Umsetzung und Prüfung

`Cms/FieldConditions` prüft die geschlossene Regelstruktur, bindet Vergleichswerte
an aktuelle eigene Feldtypen und prüft Vorlagen, Kreise sowie Sicht-/Pflichtzustände.
`DataModel` speichert die versionierte Definition und validiert tatsächliche
Seitenwerte; `Web/ContentModel` verwendet dieselben Verträge für Formulareingaben.
Ohne Regeln bleibt die bisherige Definition einschließlich ihrer Originalform
erhalten. `Web/FieldRuleEditor` und `Web/assets/field-conditions.js` liefern die
gemeinsame Bootstrap-Bedienung beider Redaktionszugänge.

`native-field-conditions.php` prüft 82 MySQL-/Dienstverträge, einschließlich aller
zwölf Quelltypen, AND/OR, exakter großer Dezimalwerte, sichtbarer RichText-Zeichen,
eigener Referenzen, ungültiger Regeln, atomarer Ablehnung, Versionen, Rechteentzug
und unveränderter Originalveröffentlichung. Es prüft zusätzlich, dass die
eingebundenen Feld-/Vorlagenformulare tatsächlich an ihren nativen Managerweg
senden. `native-field-conditions-browser.mjs` prüft 123 reale Abläufe beider
Redaktionszugänge, einschließlich gefälschter
HTTP-Pflichtangaben, private Inhalts-/Regelspeicherung und erneutes Laden,
JavaScript-freier Erreichbarkeit, Referenzsuche und Bootstrap bei 1440/820/390 Pixeln.
Eigene Fixtures und Browser sind in `tools/cloud/bootstrap.sh --verify` enthalten;
es erfolgen keine echten Anbieteraktionen.

[Verschachtelte Formulargruppen](CMS-FORMULARGRUPPEN.md) verwenden dieselben
Regeln und öffnen neu erforderliche leere Angaben sowie Pflichtfehler.
Mehrfach-/Relationsfelder, Repeater, Sprachfassungen,
Frontend-Editing und die vollständige Abnahme aller Bedienelemente bleiben eigene
Registerpunkte. Die Bedingungen sind feste Datenregeln ohne ausführbaren PHP-Code.
