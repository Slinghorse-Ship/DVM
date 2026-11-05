# Startseite im DVM gestalten

Unter **Website → Seiten** die Seite mit Adresse `start` öffnen. Im vorhandenen
Seitenformular **Startseite gestalten** aufklappen und **Individuelle Startseite
verwenden** auswählen. Beide Managerzugänge verwenden dieselben lokalen
Bootstrap-6-Alpha-1-Controls.

1. Hero-Überschrift, formatierten Hero-Text und die drei Plakatzeilen bearbeiten.
   Leere Hero-Angaben verwenden den Seitentitel bzw. die Einleitung.
2. Die fünf Plätze von oben nach unten belegen: Vereinsschwerpunkte, nächste
   Veranstaltung, aktuelle Beiträge, Seiteninhalt und Newsletter-Einstieg.
   Jeden Bereich höchstens einmal wählen; **Ausblenden** lässt einen Platz frei.
   Der Hero bleibt zuerst. Auch ohne JavaScript lassen sich die Plätze ändern.
3. Bis zu acht Vereinsschwerpunkte als einzelne Zeilen eintragen. Die Überschriften
   für Veranstaltungen, Beiträge und Newsletter sowie die Newsletter-Einleitung
   sind separat bearbeitbar. `{jahr}` in der Veranstaltungsüberschrift zeigt das
   Jahr des nächsten veröffentlichten Termins, sonst das aktuelle Jahr.
4. **Entwurf speichern**, dann **Gespeicherte Startseite privat ansehen** öffnen.
   Die private Vorschau zeigt die gespeicherte aktuelle Fassung mit Vereinsgestaltung;
   Veranstaltungen, Beiträge, Menü und deren Bilder bleiben veröffentlichte Originale.
   Sie benötigt aktuelle eigene Website-Leserechte, verwendet `no-store` und `noindex`.
   Noch nicht gespeicherte Formularänderungen sind keine Vorschaufassung.
5. Erst **Veröffentlichen** macht die gespeicherte Gestaltung öffentlich.
   Zum Rückwechsel individuelle Gestaltung abwählen, speichern, prüfen und
   ausdrücklich veröffentlichen. Inhalte und Standardbereiche bleiben erhalten.

Die nächste öffentliche Veranstaltung zeigt wieder ihr vorhandenes freigegebenes
Bild. Entwürfe erscheinen weder hier noch in der öffentlichen Beitragsauswahl.
Ohne eingeblendeten Terminbereich entsteht kein verwaister `#termine`-Link.
Der Newsletter-Bereich benötigt den veröffentlichten Newsletter-Menüeintrag.

Die Gestaltung liegt im Seitenoriginal `home_layout`, einschließlich bereinigtem
Hero-HTML und der genauen Bereichsfolge. Vorhandene Seiten ohne diese Angabe behalten
ihre Vorgaben. Speicherung, Veröffentlichung, private Kopie, Originalvergleich und
Wiederherstellung verwenden die bestehenden Seitenversionen und aktuellen Rechte.
Eine ältere Fassung ohne individuelle Gestaltung lässt sich als neuer privater
Standardentwurf laden; die öffentliche Fassung bleibt dabei erhalten.

Der Aufbau ist auf die benötigte Website begrenzt: `Cms/HomeLayout` prüft Werte,
`Web/Website/HomeForm` übersetzt das Formular, `HomePage` rendert die Startseite,
`HomePreview` die private Vorschau. `PublishedFields` stellt individuelle Felder
gemeinsam für Seiten und Vorschau dar. Ein universeller Repeater-/Plugin-Baukasten
ist hierfür nicht erforderlich. Die tatsächliche Altinhaltsübernahme bleibt ein
separater Bestandsweg; synthetische Tests sind kein Import der Online-Website.

## Nachweise

57 isolierte MySQL-/Managerverträge prüfen originale öffentliche Bytes, unveränderte
private Vorschau, RichText-Bereinigung, Reihenfolge/Sichtbarkeit, Grenzen,
Veröffentlichung, Kopie, Vergleich, Restore, konkurrierende Versionen, fremde Vereine
und aktuellen Rechteentzug. 88 tatsächliche Browser-/HTTP-Verträge prüfen beide
Manager bei 1440/820/390 Pixeln einschließlich JavaScript-freier gültiger und
ungültiger Formulare, privater Vorschau und zwei ausdrücklich veröffentlichten
synthetischen Gestaltungen mit anschließendem bytegleichem Original-Restore.
Keine externen Anbieteraktionen.

```sh
KH_NATIVE_CI=1 KH_NATIVE_CI_PASSWORD=synthetic-ci-only \
  php vereinsverwaltung/tests/ci-native.php native-home-layout
```

Die Browser-Fixtures unter `tests/support/home-layout-browser-fixture.php` akzeptieren
nur die registrierten eigenen Cloud-Demodatenbanken und bekannten synthetischen
Originale. `tools/cloud/bootstrap.sh --verify` richtet die privaten Originalverweise
für beide Zugänge ein und führt `native-home-layout-browser.mjs` aus. Screenshots
bleiben unter `.local/browser-tests/` außerhalb Git.
