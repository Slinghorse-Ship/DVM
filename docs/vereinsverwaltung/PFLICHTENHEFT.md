# Pflichtenheft: Kulturhus Vereinsverwaltung

Stand: 5. September 2026. Entwurf für eine spätere Umsetzung durch Codex.

## 1. Ziel und Grenzen der Aussage „wie WISO“

Die eigene Anwendung soll die Aufgaben von WISO MeinVerein **Web** im Verein abdecken. Die recherchierte Baseline steht in [Mitglieder/Organisation](WISO-MITGLIEDER-ORGANISATION.md) und [Finanzen](WISO-FINANZEN.md). Sie erfasst öffentlich dokumentierte Funktionen, nicht jede interne Produktregel. Ein vollständiger Funktionsgleichstand muss zusätzlich an realen, autorisiert zugänglichen Abläufen geprüft werden. WISO Desktop ist kein stillschweigend mitgemeinter zweiter Produktumfang.

Die folgenden Architektur-, Qualitäts- und Sicherheitsanforderungen sind **unsere eigenen Vorgaben**. Sie sind keine Behauptung über die interne WISO-Implementierung. Die Spezifikation ist keine steuerliche oder rechtliche Zertifizierung.

### Zusammengehörige Funktionsbereiche

| Bereich | Geplanter Platz in unserer Anwendung |
|---|---|
| Stammdaten, Anträge, Mitgliedschaft, Kontaktpersonen und Strukturen | Mitglieder und Kontakte |
| Listen, Dokumente, Kommunikation und Vereinsorganisation | Organisation |
| Termine, Rückmeldungen, Chat, Umfragen und mobile Nutzung | Vereinsleben und Mitgliederportal |
| Beiträge, Rechnungen, offene Posten und Mahnwesen | Finanzen |
| Konten, Umsätze, Zuordnung, Zahlungen und Lastschriften | Finanzen / Banking |
| Belege, Kontierung, Auswertungen und Steuerberaterexport | Buchhaltung |
| Förderungen, Geld-/Sachspenden und Bestätigungen | Spenden |
| Rollen, Einstellungen, Nachweise und Datenübergabe | Administration |

Diese Übersicht ersetzt keine einzelne Anforderung der Kataloge. Insbesondere dürfen App-Funktionen, Importe, Exporte, Fehlerbehandlung und Berechtigungen nicht hinter einer schönen Startseite fehlen.

### Architekturvorgabe K-01


Die Pfeile sind geplante Schnittstellen, keine schon eingerichteten Verbindungen. Eine zusätzliche Subdomain kann die Bedienung vereinfachen, ist aber allein keine Prozess- oder Dateirechtsisolation. Ein vertretbarer Aufbau benötigt nachgewiesene Grenzen; Details in [Banking](BANKING-FINTS.md).

### Integrationsregeln K-02 bis K-06

- **K-02:** Bestehende Redaktionszentrale erhält höchstens rollenabhängige Einstiegspunkte. Ein Website-Redakteur wird dadurch nicht zum Kassenwart. Finanzdaten dürfen nicht in allgemeinen CMS-Seiten, Suchindex, öffentlichen APIs, Feeds, Fehlerseiten oder Caches erscheinen.
- **K-03:** Eventbrite bleibt nach bestehender Architektur führend für öffentliche Event-Kerndaten. Ein interner Vereinstermin ist ein anderes Objekt. Verknüpfungen sind erlaubt, ein zweites konkurrierendes Ticket-/Reservierungssystem wird nicht unterstellt.
- **K-04:** Öffentliches Beitritts-/Spendenformular und interne Bearbeitung sind getrennt. Der Formulareingang darf Anträge übermitteln, aber weder bestehende Mitglieder exportieren noch Zahlungen auslösen. IBAN-Eingabe nach Möglichkeit direkt auf dem isolierten Portal, nicht durch einen CMS-Proxy.
- **K-05:** Bestehende Newsletter-Einwilligung und Mitgliedschaft sind unterschiedliche Sachverhalte. Kein automatisches Marketing-Abonnement bei Eintritt. Versand aus der Vereinsverwaltung darf bestehende Sperren nicht umgehen.
- **K-06:** Der dokumentierte bisherige WISO-Sprunglink/CSV-Weg bleibt bestehen, bis eine ausdrücklich freigegebene Migration ihn ersetzt. Keine stillschweigende Parallelpflege mit widersprüchlichen führenden Systemen.

## 3. Rollen und Rechte: eigene verbindliche Baseline

Die konkrete WISO-Rollenmatrix ist teilweise widersprüchlich dokumentiert. Folgende Matrix ist daher unser Vorschlag; Vorstand bestätigt ihn vor Produktivbetrieb. Rechte müssen für UI, API, Export, Hintergrundauftrag und einzelne Datensätze identisch gelten.

| Rolle | Erlaubter Schwerpunkt | Nicht automatisch erlaubt |
|---|---|---|
| Mitglied | Eigene Daten, freigegebene Dokumente/Termine, eigene Anträge | Andere Mitglieder, Gesamtfinanzen, fremde Bankdaten |
| Mitgliederverwaltung | Anträge, Mitgliedschaften, zugewiesene Kontakte/Strukturen | Bankzugänge, Zahlungen, Ausstellung steuerlicher Bestätigungen |
| Kommunikation/Organisation | Freigegebene Verteiler, Nachrichten, Termine und Dokumente | Kontenumsätze, unbeschränkte Personendatenexporte |
| Kassenwart | Forderungen, Belege, Zuordnung, Vorbereitung von Zahlungen | Fremde Vereine oder automatisch jede bankseitige Vollmacht |
| Zahlungsfreigabe | Geprüfte Aufträge im eigenen zulässigen Bereich | Änderung bereits freigegebener Auftragsdaten |
| Kassenprüfung | Lesender, zeitlich/sachlich begrenzter Nachweiszugang | Neue Buchungen, Änderungen, Überweisungen |
| Vereinsadministration | Einstellungen, Benutzer und freigegebene Rechtevergabe | Bank-PIN/TAN, Umgehung der Freigabekette |
| Technischer Betrieb | Deployment, gesicherte Wiederherstellung nach Verfahren | Regelmäßiger Einblick in Finanzgeheimnisse oder Zahlungsfreigabe |

**K-07:** Rechte nach Aufgaben und Vereins-/Abteilungsgrenze, standardmäßig verweigern. Eigene Rechteerhöhung und kritische Rollenvergabe benötigen nachvollziehbare Kontrolle. Ein Betriebskonto mit Zugriff auf sämtliche Schlüssel ist eine zusätzliche Vertrauensinstanz, nicht durch eine UI-Rolle wegdefiniert.

Mehrvereinsfähigkeit kann mit synthetischen Testvereinen abgenommen werden. Der zunächst autorisierte Betriebszweck bleibt Kulturhus; ein produktiver Verwaltungs-/Banking-Dienst für fremde Vereine ist damit nicht freigegeben.

## 4. Domänenmodell und unverzichtbare Regeln

Dies sind Modellvorgaben, keine fertige Datenbankmigration. Bezeichnungen und Tabellenpfade erst nach Ist-Prüfung festlegen.

| Domäne | Benötigte Objekte / Beziehungen |
|---|---|
| Verein | Verein, Konfiguration, Strukturknoten, Rollenbindung, Freigaberegel |
| Person/Kontakt | Person oder Organisation, Kontaktperson, Anschrift, Erziehungs-/Vertretungsbeziehung, Datenfreigabe |
| Mitgliedschaft | Mitgliedsnummer, Antrag, Status und Zeitraum, Beitragszuordnung, Austritt, Änderungsverlauf |
| Forderung | Versionierter Tarif, Berechnungslauf, Rechnung und Position, Fälligkeit, Zahlungspflichtiger, Mahnung, Storno |
| Mandat | Zahlender, verschlüsselte Bankverbindung, Mandatsreferenz, Erklärungsversion, Nachweis, Datum, Änderung/Widerruf |
| Geldfluss | Konto, Umsatz, Zuordnung, Zahlungsvorbereitung, Bankauftrag, Einzelposition, Bankantwort, Rückgabe |
| Buchhaltung | Beleg, Journal, Kontenplanversion, Aufteilung, Kosten-/Bereichszuordnung, Abschluss-/Sperrzeitraum |
| Spende | Spendenart, Zweckbindung, tatsächlicher Eingang/Übernahme, Bewertung, Bestätigung, Storno/Ersatz |
| Zusammenarbeit | Liste, Termin/Serie, Einladung/Rückmeldung, Nachricht, Sendung, Dokumentversion, Umfrage/Antwort |
| Betrieb | Importlauf, Exportlauf, Versandauftrag, Auditereignis, Zustimmung, Sperre, Aufbewahrungsentscheidung |

### Datenregeln K-08 bis K-16

- **K-08:** Mitglied, Rechnungsempfänger, Beitragsschuldner und Kontoinhaber nicht gleichsetzen. Ein Zahler kann mehreren Mitgliedschaften zugeordnet sein; ein Familienmodell oder Haushaltsrabatt benötigt eine ausdrücklich definierte Tarifregel.
- **K-09:** Geldbeträge intern als Integer in kleinster Währungseinheit oder feste Dezimalwerte, niemals binäre Fließkomma-Geschäftsrechnung. Währung und Rundungsregel sind Pflicht. Monatsende, Schaltjahr, Ein-/Austritt und rückwirkende Änderungen testen.
- **K-10:** Fälligkeiten und Buchungsdaten als fachliche Kalendertage; technische Zeitstempel UTC, Anzeige Europe/Berlin. Vorhandene Belege ändern ihr Datum beim Öffnen nicht. „Heute“ ist nur ein Entwurfs-Default.
- **K-11:** Freigegebene Rechnungen/Bestätigungen bleiben rekonstruierbar. Korrektur durch versionierten, verknüpften Storno/Ersatz, nicht Überschreiben. Entwürfe dürfen kontrolliert gelöscht werden; Löschbarkeit ist je Objekttyp zu regeln.
- **K-12:** Nummern eindeutig und kollisionssicher; Vergabe bei fachlich festgelegtem Schritt. Abbruch/Wiederholung erzeugt keine zweite Forderung. Lücken werden nachvollziehbar, nicht heimlich durch Neunummerierung verdeckt.
- **K-13:** Imports mit Vorschau, Feldmapping, Zeilenfehlern und Dublettenprüfung. Wiederimport identischer Daten idempotent. Unsicherer Treffer braucht Bestätigung. CSV-Formeln, Dateigrößen und Zeichencodierung berücksichtigen.
- **K-14:** Exporte vollständig, dokumentiert und wiederverwendbar. Rechte gelten auch für ganze Exportdateien. Private Downloads sind kurzlebig autorisiert; keine dauerhaften öffentlichen Links.
- **K-15:** Löschung, Austritt und Aufbewahrung sind unterschiedliche Vorgänge. Aufbewahrungsmatrix je Datentyp fachlich festlegen; Legal Hold, Sperre und spätere datensparsame Löschung vorsehen. Keine pauschale ewige Speicherung oder pauschale Löschung aller Finanznachweise.
- **K-16:** Wiederherstellung darf weder Bankaufträge noch Versandaufträge erneut auslösen. Nach Restore aktive Aufträge erst abgleichen und gezielt freigeben.

**K-21 – Festschreibung:** Gesperrte Buchungsperioden und festgeschriebene Journalzeilen dürfen weder durch UI noch Import, API oder Hintergrundauftrag verändert, gelöscht oder unbemerkt rückdatiert werden. Korrekturen benötigen einen verknüpften fachlich definierten Weg; Wiederöffnung braucht eine gesonderte Berechtigung, Begründung und Protokollierung. Tests müssen auch nachträglich eingehende Bankumsätze/Rückgaben, Jahreswechsel und unberechtigte Wiederöffnung abdecken. Original-Bankdatum und Buchhaltungsentscheidung bleiben unterscheidbar. Dieses Verhalten ist eine eigene Qualitätsvorgabe, kein behaupteter WISO-Befund.

## 5. Spendenbestätigungen: gesonderte Produktionssperre

**K-17:** Vor erster echter Ausstellung müssen Freistellungs-/Feststellungsunterlagen, steuerbegünstigte Zwecke, Finanzamt, Steuernummer, Gültigkeitsdaten und zeichnungsberechtigte Person geprüft und versioniert hinterlegt sein. Ohne diese Freigabe ausschließlich deutlich markierte Musterdokumente.

Amtliche Vorlagen sind maßgeblich. Einschlägige Pflichttexte nicht frei umformulieren; Betrag in Ziffern/Worten, Sachspendenangaben sowie Einzelaufstellung bei Sammelbestätigungen beachten. Ein nachvollziehbares Doppel ist aufzubewahren. Die konkrete Vorlagenversion muss vor Implementierung erneut geprüft werden. [BMF: Muster und Verwendung](https://esth.bundesfinanzministerium.de/esth/2025/B-Anhaenge/Anhang-37/I/anhang-37-I.html)

Maschinelle Bestätigungen ohne eigenhändige Unterschrift setzen nach R 10b.1 Abs. 4 unter anderem eine Anzeige des Verfahrens beim Finanzamt, passende Unterschriftseinblendung, geschütztes Verfahren und abstimmbare Verknüpfung mit der Buchhaltung voraus. Diese Regelung gilt nicht für Sach- und Aufwandsspenden. **K-18:** Dafür unterschiedliche Freigabe-/Unterschriftswege implementieren; ein generierter PDF-Download allein genügt nicht. [BMF: R 10b.1](https://lsth.bundesfinanzministerium.de/esth/2025/A-Einkommensteuergesetz/II-Einkommen-2-24b/5-Sonderausgaben-10-10g/Paragraf-10b/r-10b-1.html)

Beiträge nicht automatisch als Spenden deklarieren. Bei Kulturvereinen hängt die Abzugsfähigkeit unter anderem von der gesetzlichen Einordnung der Betätigung ab; „Kultur“ allein erlaubt weder eine pauschale Zusage noch einen pauschalen Ausschluss. **K-19:** Bescheinigungsfähigkeit von Beitragstypen erst nach fachlicher Prüfung konfigurieren. [§ 10b EStG](https://www.gesetze-im-internet.de/estg/__10b.html)

**K-20:** Bestätigung nur gegen nachgewiesenen Eingang bzw. fachlich bestätigte Sach-/Aufwandszuwendung, nicht gegen ein Zahlungsversprechen. Doppelbescheinigung, Rücklastschrift nach Ausstellung, Jahreswechsel, Namenskorrektur und Ersatz müssen eigene Tests haben. Der Buchhaltungsnachweis bleibt vom Versandstatus getrennt.

## 6. Bedienung und Design

- **UX-01:** Kulturhus-Gestaltung wiederverwenden, Formulare jedoch klar und ruhig gestalten. Deutsches Datum, Komma-Beträge und verständliche Begriffe. „Vorgemerkt“, „eingereicht“ und „gebucht“ klar unterscheiden.
- **UX-02:** Testbreiten 360, 390, 768, 1024 und 1440 Pixel; zusätzlich 200 % Zoom. Kein doppeltes Desktop-/Mobilmenü, verdeckte Aktionsknöpfe oder Verlust von Formularwerten bei Größenwechsel.
- **UX-03:** Bedienbare Tastaturreihenfolge, sichtbarer Fokus, echte Beschriftungen, Screenreader-Fehlerzusammenfassung, nicht nur Farbcodes. Tabellen brauchen eine brauchbare mobile Alternative.
- **UX-04:** Massenaktionen zuerst Vorschau mit Anzahl, Summe und Ausnahmen. Bankfreigabe benennt Konto, Empfänger/Zahler, Summe, Einzelpositionen und Fälligkeit. Gefährliche Aktionen sind nicht die Standardantwort auf Enter.
- **UX-05:** Autosave nur bei Entwürfen. Eine gespeicherte Vorlage, freigegebene Rechnung oder Veröffentlichung ist niemals automatisch eine bankseitig autorisierte Zahlung.
- **UX-06:** Bei TAN/App-Wechsel Zustand erhalten; Wartezeit, Ablauf, Abbruch und unbekannten Status verständlich anzeigen. Kein hektischer „noch einmal senden“-Knopf nach Timeout.
- **UX-07:** Mitglieder sehen nur passende Aufgaben und eigene Belege. Ehrenamtliche brauchen kurze Anleitungen und nachvollziehbare Fehlermeldungen ohne technische Geheimnisse.
- **UX-08:** Native App, Push, Kamera/Datei-Upload und digitale Ausweise separat gegen den WISO-Katalog abnehmen. Reine responsive Web-Nutzung als solche kennzeichnen. Store-Veröffentlichung oder externe Push-Dienste benötigen Kosten-/Datenschutzentscheidung.

## 7. Betrieb, Datenschutz und Qualität

- **OPS-01:** Eigene Authentisierung, MFA mindestens für Finanz-/Administrationsrollen, sichere Wiederherstellung und sofortiger Sitzungsentzug bei Rollenverlust. Wiederanmeldung für kritische Änderungen. Öffentliche CMS-Sitzung genügt nicht für Banking.
- **OPS-02:** TLS, CSRF, Ausgabekodierung, parameterisierte SQL-Abfragen, Objektberechtigungen, Login-/Formular-Limits. Uploads privat, MIME-/Größenkontrolle und keine ausführbaren Dateien. Keine externen Tracker auf Finanzseiten.
- **OPS-03:** Schlüssel getrennt von verschlüsselten Daten; keine PIN/TAN, vollständigen Bankdialoge oder unmaskierten Bankdaten in Logs, Fehlern, Tests, Git oder Analyse-/KI-Diensten. Finanzdaten nur in synthetischen Testfällen.
- **OPS-04:** Audit mit Akteur, Anlass, Objektversion und Ergebnis; Finanzfreigaben nicht von denselben Anwendungsrechten stillschweigend entfernbar. Änderungen am Audit nachvollziehbar machen; ein normales Logfile ist kein Unveränderbarkeitsnachweis.
- **OPS-05:** Backup, Wiederherstellung, Updates, Sicherheitsmeldungen und verantwortliche Personen festlegen. Zielvorschlag: maximal 24 Stunden Datenverlust und Wiederanlauf binnen eines Arbeitstages; vor Betriebsfreigabe bestätigen und durch Restore-Test belegen.
- **OPS-06:** Datenschutzunterlagen und Auftragsverarbeitung für tatsächliche Anbieter prüfen, Datensparsamkeit und Berechtigungen dokumentieren. Steuerliche Verfahrensdokumentation mit Fachperson abnehmen. Keine Konformitätsbehauptung aus dem Vorhandensein einzelner Funktionen ableiten.
- **OPS-07:** Quellcode, Datenformat und Schlüssel-/Zugriffsübergabe beim Vorstandswechsel dokumentieren. Kein Vendor-Lock-in durch ausschließlich proprietären Export.
- **OPS-08:** Lasttests mit zunächst 1.000 synthetischen Mitgliedern und 100.000 synthetischen Umsatz-/Journalzeilen, festgelegter Hardware und gemessenen Antwortzeiten. Ziel: übliche Listen-/Suchaufrufe p95 unter 2 Sekunden ohne Bankwartezeit; große Exporte kontrolliert asynchron. Zahlen sind technische Startziele, keine bekannte Vereinsgröße.

## 8. Umsetzungsphasen und Abnahme

| Phase | Ergebnis | Freigabegate |
|---|---|---|
| P0 – Baseline | Alle Katalog-IDs mit Ist-Stand, Tests und offenen Produktfragen | Widersprüche/Abweichungen dokumentiert; keine pauschale Paritätszusage |
| P1 – Fundament | Betriebsgrenze, Rollen, Datenmodell, Migration, Testumgebung | Isolation und geplanter Kostenrahmen akzeptiert |
| P2 – Vereinsarbeit | Mitglieder, Anträge, Organisation, Kommunikation, Portal | Negativtests für Rechte; Import/Export und mobile Abläufe bestanden |
| P3 – Finanzen offline | Beiträge, Belege, Journal, Spenden und Bankdateien | Fachlich geprüfte Muster; keine echten Bestätigungen/Bankeinreichungen |
| P4 – Bank-Simulation | Adapter, TAN-Zustände, Abgleich, Rückgaben, Wiederanlauf | Sämtliche BANK-Tests bestanden; kein Netzwerk im Normaltest |
| P5 – Lesender Banktest | Konkretes Konto, Umsatzformat, TAN-Verfahren nachgewiesen | Eigene Freigabe, Produkt-ID, sichere Laufzeit; Nutzer bedient Geheimnisse |
| P6 – Zahlungsfreigabe | Kontrollierter, ausdrücklich autorisierter Echt-Test | Bankfreischaltung, Mandate, Limits, Vorankündigung und Rückfallplan |
| P7 – Parität/Betrieb | Alle Baseline-IDs verifiziert oder ausdrücklich entschieden | App-/Ökosystemlücken sichtbar, Migration abgestimmt, Restore und Übergabe |

P0 bis P7 sind Arbeitsabschnitte, keine Kostenschätzung oder zugesagte Termine. Ein nützlicher Zwischenstand ist zulässig, aber nicht als vollständiger WISO-Ersatz zu bezeichnen. Bei fehlender Banking-Isolation kann lokale Arbeit mit Simulation weitergehen; Live-Banking bleibt gesperrt.

### Erforderliche Abnahmedokumente

Je Anforderungs-ID: Quelle/Entscheidung, Codepfad, Testfall, Ergebnis, Version/Datum, Restlücke. Zusätzlich Rollen-Negativtests, Finanz-Testjournal, bankseitige Nachweise ohne Geheimnisse, Export-/Restore-Protokoll, Screenshots der relevanten Größen und fachliche Freigaben. Externe Kosten oder echte Transaktionen niemals zur bloßen Erzeugung eines Testnachweises eigenständig auslösen.
