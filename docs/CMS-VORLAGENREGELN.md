# Vorlagenregeln und Feldkontexte

P07 ergänzt das native CMS um deklarative Vorlagenfamilien, Seitenzugriff und lokale Feldeinstellungen. Es wird kein PHP-Code hochgeladen oder ausgeführt. Änderungen an Vorlagen und Feldern bleiben normale versionierte Modelldefinitionen.

## Vorlagenfamilien

`policy.parents` und `policy.children` sind entweder `null` für alle Vorlagen, eine Liste erlaubter Kennungen oder eine leere Liste für keine Vorlage. Beide Seiten einer Verbindung müssen zustimmen. `root=false` verlangt eine gültige Elternseite. Neue Seiten, Umhängen, Wiederherstellung und Kopien prüfen die Regeln im normalen Seitendienst. Eine Modelländerung, die bestehende Entwürfe oder veröffentlichte Hierarchien unzulässig machen würde, wird atomar abgelehnt.

## Seitenzugriff

`read`, `edit` und `publish` legen zusätzliche vorhandene Website-Berechtigungen fest. Sie können die jeweilige Grundberechtigung nur verschärfen. Lesen bleibt Voraussetzung für Bearbeiten und Veröffentlichen. Eine Verwaltungsberechtigung allein ersetzt keine fehlende Grundberechtigung. `inherit=true` übernimmt zusätzlich die Elternregeln; eine ausdrücklich administrierte Ausnahme kann diese Vererbung ausschalten. `public=false` sperrt veröffentlichte Originale auch für die anonyme öffentliche Projektion. Eine zusätzliche Leseregel wie `read=manage` hält Inhalte ebenfalls privat.

Die Verwaltung kann die fünf Zugriffsangaben einer einzelnen Seite über `page_policy` überschreiben. Familien-, Theme- und Cachevorgaben bleiben an der Vorlage. Die normale Seitenform enthält dafür eingeklappte Auswahlfelder. Fehlende Controls älterer Formulare erhalten die vorhandenen Einstellungen. Gefälschte Kontrollwerte sowie Änderungen ohne Website-Administration werden abgelehnt.

`TemplatePolicy::readFilter(PDO, Access, alias, public=false, capability='read')` liefert SQL und gebundene Parameter. Private Listen, Suche, Count, Lister, Referenzpicker und Aktionsauswahl wenden den Filter vor Zählung und Seiteneinteilung an. Direkte Aktionen prüfen die aktuelle Berechtigung erneut. `publicFilter(PDO, club, alias)` bietet denselben ausschließlich öffentlichen Lesefilter für bereits vereinsgebundene Worker. Eine Administratorrolle erweitert öffentliche Inhalte nicht. Kreise, fehlende geerbte Eltern und überschrittene Hierarchietiefe werden geschlossen behandelt.

Öffentliche Regeln beziehen sich auf die tatsächlich veröffentlichte Vorlage und Elternhierarchie. Eine private Änderung des Entwurfstemplates veröffentlicht keine neue Zugriffsbasis. Aktuelle Modellregeln und ausdrücklich gesetzte Seitenregeln wirken unmittelbar, einschließlich Entzug bestehender Leserechte. Echte Absagehinweise verwenden ihre behaltene öffentliche Originalrevision nur mit bestätigter abgesagter Veranstaltungsquelle.

Öffentliche Artikel, Alben, Programmzählungen und Jahresauswahl, Navigation, Suche, Feeds sowie Originalbild- und PDF-Auslieferung prüfen dieselbe Freigabe. Private Verweisquellen bleiben als generischer Löschblocker erhalten, ohne ihren Titel oder ihre Identität auszugeben. Ein eigenständig freigegebenes Menükind gibt die Kennung seiner privaten Elternseite nicht aus.

Ein kleiner installierter Vereinsindex vermeidet rekursive Policyabfragen bei unverändert unkonfigurierten Websites. Es gibt kein Request-DDL. Modell- und Seitenregeln aktualisieren diesen Index in derselben Transaktion und erhöhen die öffentliche Generation. Private Kopien dürfen unverändert übernommene, serverseitig gebundene Regeln behalten; sie können dadurch keine neuen Rechte vergeben.

## Feldkontexte

`field_contexts` verändert für ausgewählte Felder einer Vorlage Bezeichnung, Pflichtangabe, typisierte Min-/Maxgrenze, Optionen und Anzeige. Globale Felddefinitionen und alte Revisionen bleiben unverändert. Mehrfachwerte behalten ihre eigene Anzahlgrenze; Repeater behalten Originalkennungen, Versionen und Kinddefinitionen. Unbrauchbare versteckte oder schreibgeschützte Pflichtfelder werden mangels Standardwerten bereits bei der Konfiguration abgelehnt. Globale Feldänderungen prüfen bestehende Kontexte vor dem Speichern.

`display=hidden|readonly` ist eine Anzeige- und Schreibregel, keine personenbezogene Feld-Leseberechtigung. Verborgene Controls geben keine Originalwerte im Editor-HTML oder in sichtbaren Feldbedingungen aus; fehlende Controls erben den bisherigen Originalwert. Öffentliche Feldprojektionen entfernen verborgene Werte, Definitionen, Passwörter und private Datensatzteile. Echte rollenbezogene Feldrechte aus P11 bleiben eine gesonderte Erweiterung.

Geänderte Kontexte ersetzen keine veröffentlichte Originalfassung. Die Veröffentlichung eines veralteten Entwurfs verlangt erneutes Speichern mit den aktuellen Definitionen. Website-Theme und Cachewahl einer Vorlage wirken unmittelbar auf die Darstellung des richtigen veröffentlichten Templates. `cache=off` umgeht Lesen und Schreiben des HTML-Caches. Ein wiederverwendeter Renderer übernimmt das Theme nicht versehentlich auf die nächste Seite.

## Reproduzierbare Abnahme

```sh
bash tools/ci/local-native.sh --core native-template-contexts native-template-policy native-page-purge native-navigation native-article-catalogue native-public-albums
DVM_TEMPLATE_POLICY_BROWSER=1 bash tools/ci/local-native.sh --core native-template-policy
```

Die Browserabnahme erstellt ausschließlich eine zweite frische eigene synthetische Datenbank. Sie bedient die echten beiden Anwendungen mit persönlichen Sitzungen, gültigen und ungültigen CSRF-POSTs, JavaScript und ohne JavaScript. Vorlagen- und Seitenregeln werden bei 320, 390 und 1024 Pixeln sowie 200 Prozent Schrift geprüft. Tatsächliche öffentliche Bild-, PDF- und Seitenrequests prüfen sofortigen Rechteentzug und spätere bewusste Freigabe. Bestehende Revisionen, andere Geschäftstabellen, Mediendateien und private Schlüssel werden bytegenau verglichen. Es gibt keine externen Provideraufrufe.

Abnahme am 15.10.2026: 66 Policy- und 29 Kontextprüfungen bestanden. Die tatsächliche Zwei-App-Browsermatrix bestand 1262 Prüfungen in 120 Layoutzuständen mit 20 UI-POSTs, ohne externe Requests oder JavaScriptfehler; Laufzeit 144692 ms. 395 unbeteiligte Geschäftstabellen, acht bestehende Seitenrevisionen, Originalmedien, PDFs und Schlüssel blieben exakt erhalten. Der erweiterte Seitenpurge bestand zusätzlich 60 Prüfungen einschließlich Sprachrechten, passender Formularsichtbarkeit und vollständigem Rollback nach einem tatsächlichen späten SQL-Fehler.
