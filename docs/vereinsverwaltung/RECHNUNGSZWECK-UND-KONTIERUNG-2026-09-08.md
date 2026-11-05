> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Rechnungseingang: zuerst den Zweck verstehen

Der Rechnungszweck steht am Anfang der Erfassung, vor Texterkennung, Firma und Positionen. Eine neue Rechnung beginnt mit „Bitte Rechnungszweck auswählen“. Danach folgen „Angaben und Positionen prüfen“ und „Rechnung speichern“. Auf Desktop bleibt das Original rechts; auf Mobilgeräten beginnt das Formular mit dem Zweck, die Sprunglinks wechseln zwischen Angaben und Original.

Die Zweckauswahl schlägt nur eine Kostenart vor: Inventar → Inventaranschaffungen, Versicherung → Versicherungen, Künstler → Künstlerhonorare & Bands, GEMA → GEMA / Musikrechte, andere Veranstaltung → Kulturveranstaltungen. Allgemeine und gemischte Rechnungen bleiben zunächst allgemein. Eine selbst gewählte Kostenart bleibt erhalten. Kostenart, Sphäre, Buchhaltungskonto und Umsatzsteuerbehandlung sind getrennte Angaben.

Eine kleine aufklappbare Hilfe direkt beim Zweck erklärt Sphäre anhand der tatsächlichen Verwendung, Steuerkonto anhand der Art der Ausgabe und BU als Buchungsschlüssel für die Umsatzsteuerbehandlung. Von jeder Positionskontierung führt ein Link zurück zu derselben Hilfe und öffnet sie, ohne das Formular zu verlassen. Bruttobeträge und Steuersatz laut Original begründen keinen automatischen Vorsteuerabzug.

Historische Vorschläge berücksichtigen jetzt auch den Rechnungszweck. Bei Veranstaltungsrechnungen müssen Zwecktyp und konkrete Veranstaltung übereinstimmen. Andere Zwecke verwenden keine veranstaltungsgebundene Historie. Gemischte Rechnungen erhalten keine automatische Kontierung, und gemischte bzw. gegengebuchte Historie wird nicht weiterverwendet. BU wird weiterhin niemals automatisch übernommen.

Bei Zweckwechsel werden automatische Zeilenkontierungen entfernt, auch wenn die Kostenart gleich bleibt. Neue Antworten gelten nur noch für die aktuelle Kombination aus Zweck, Veranstaltung, Firma, Datum, Kostenart und Positionsangaben. Eigene Kontierungen bleiben erhalten; ein sichtbarer Hinweis fordert die erneute Prüfung an und die Positionsbestätigung wird zurückgesetzt. Jede Position behält ihre eigenen Beträge, Gegenstände, Sphäre und Konten. Die neue Auswahl „Mehrere Zwecke / gemischte Rechnung“ und der Inventarzweck werden als Zweckhinweis dokumentiert.

Keine Migration erforderlich, Schema bleibt 61. Bestehende Belege werden nicht umkategorisiert. Der Vorschlagsendpunkt ist weiterhin lesend, sitzungs-/CSRF-geschützt und mandantengebunden. Ältere Clients ohne purpose-Parameter behalten ihren allgemeinen Kontext; neue ungültige oder unvollständige Zweckreferenzen werden zurückgewiesen.

Prüfung: 46 neue Zweck-/Kontierungstests, bestehende Finanz-, Vorschlags-, Veranstaltungs-, Eingangs- und Positionsworkflows erfolgreich. Neun unabhängige lokale Browserprüfungen verwenden echte Dateidatenbank-Historie und echte HTTP-Vorschläge: Reihenfolge, automatische und eigene Kostenart, Zweck- und Veranstaltungswechsel, gemischte Positionen, Hilfe, Desktop und 390 Pixel Breite sowie Pflichtauswahl ohne JavaScript und Erhalt der Eingaben nach einem abgelehnten Speicherversuch. Keine API-Mocks, keine Testmails und keine fiktiven Live-Buchungen. Handbuch und Finanzhilfe sind auf den neuen Ablauf abgestimmt.

Fachlicher Hintergrund der Hilfe: [DATEV: SKR42 und Zuordnung der Vereinsbereiche über KOST1](https://www.datev.de/web/de/shop/produkt-details/branchenpaket-fuer-vereine-stiftungen-gmbhs-72346), [DATEV: Kontenrahmen und Bedeutung der Steuerschlüssel](https://www.datev.de/web/de/shop/produkt-details/12901), [BMF: Vereinstätigkeit und Zuordnung unternehmerischer bzw. nichtunternehmerischer Verwendung](https://usth.bundesfinanzministerium.de/usth/2024/A-Umsatzsteuergesetz/I-Steuergegenstand-und-Geltungsbereich/Paragraf-2/inhalt.html). Die Hilfe ersetzt keine fallbezogene Entscheidung; sie weist den Kassenwart auf die nötige Unterscheidung hin.

Veröffentlicht am 08.09.2026 aus Commit `e9103e7`. Vorheriger Live-Stand `f44cbd5` wurde für alle betroffenen Dateien abgeglichen; 16 private bzw. öffentliche Dateiziele wurden gesichert, ersetzt und gegen die committed Git-Bytes zurückgelesen. Es gab keine Datenbankmigration. Sicherung: `/tmp/kulturhus-verein/rollback-invoice-purpose-20260908`.

Nach der Veröffentlichung: PHP-FPM 8.3.33, Datenbankschema 61, Integritätsprüfung `ok`, keine Fremdschlüsselfehler. Zehn ausgelieferte JavaScript-/CSS-Dateien entsprechen dem Git-Stand. Startseite, Anmeldung, Galerie und Programmarchiv antworten mit HTTP 200. Die sieben Zweckoptionen, die neue Kostenart „Versicherungen“ und die Finanzhilfe wurden in der echten FPM-Laufzeit geprüft. Der kurzzeitig eingerichtete, geschützte Prüfendpunkt ist wieder entfernt.

Im angemeldeten Live-Rechnungseingang separat bestätigt: bewusste Zweckauswahl vor Angaben und Positionen, direkt erreichbare Kontierungshilfe, Originalvorschau und abschließender Speicherschritt. Die vorhandene offene Rechnung wurde nicht gespeichert oder geändert. Keine Testmail und keine fiktive Live-Buchung. Die neun Interaktions- und Fehlerszenarien liefen ausschließlich lokal mit synthetischen Daten; dieser Nachweis behauptet keinen Live-Speichertest. Das neu gebaute Handbuch enthält 20 Kapitel, 239 Aufgaben und 35 Bilder.
