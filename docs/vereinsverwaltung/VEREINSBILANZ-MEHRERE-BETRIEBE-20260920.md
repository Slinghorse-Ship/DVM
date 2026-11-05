# Allgemeine Vereinsbilanz: Bereiche und mehrere Betriebe

Entwicklungsstand 27.09.2026, **kein Produktivrelease**. Kulturhus bleibt bei EÜR.
Der Nutzer hat eine auf einen Geschäftsbetrieb begrenzte Erstversion ausdrücklich
abgelehnt. Ein erfolgreicher Teiltest erfüllt nicht diese Gesamtanforderung.

## Aktueller Abnahmestand

Neuester Schritt: [gemeinsame Anlagen mit belegten Voll-/Teilabgängen](GEMEINSAME-ANLAGEN-ABGAENGE-20260927.md).
Verkauf, Ausmusterung, Lizenzende und nachgewiesener Komponentenabgang sind mit
Nutzungsanteilen, abweichenden Steuerwerten, Folgejahren und Nebenrechnung geprüft.
Sphärenübergreifende Vermögens-/Kostenaufteilung und Nutzungswechsel bleiben offen.

Ergänzt: [steuerliche Nebenrechnung mit Prüfverlauf und Oberfläche](STEUERLICHE-NEBENRECHNUNG-20260927.md).
Bewertungsunterschiede, beleggebundene außerbilanzielle Korrekturen und
steuerlicher Vorjahresvortrag werden getrennt berechnet. Änderungen entwerten
abhängige Freigaben. Der Jahresabschluss-Download enthält die Nachweise.
Inzwischen verbunden: [amtliche XBRL-Darstellung und Freigabeablauf](EBILANZ-NEBENRECHNUNG-20260927.md).
Die Nebenrechnungsvariante besteht sieben native Prüfungen. Keine Vollfreigabe.

Neu ergänzt: [beleggebundener Ergebnisabgleich bei Verrechnungen](ERGEBNISABGLEICH-20260927.md).
Gesamtverein, sämtliche steuerpflichtigen Betriebe und übrige Bereiche werden
zeilen- und kontengenau abgestimmt. Vorschüsse und Rückzahlungen erzeugen keinen
Gewinn. Aktuelle Vorstandsprüfung, Vorjahresbindung und beide Exportnachweise
sind angebunden. Dies ist noch das Buchführungsergebnis vor Steueranpassungen,
nicht die abschließende steuerliche Darstellung oder eine XBRL-Freigabe.

Weiterentwickelt: [laufende rückzahlbare Verrechnungen](BEREICHS-VERRECHNUNG-20260920.md).
Rechenkern, MySQL-Revisionsspeicherung, Rollen-/Vorstandsprüfung, Oberfläche und
Jahresabschluss-Arbeitsnachweis sind angebunden. Der interne Bereichsabgleich und
der nichtleere Vortrag sind jetzt einschließlich Teilrückzahlungen, drei Jahren,
geänderter Quellrevisionen, Vorstandsprüfung und Wiederherstellung isoliert geprüft.
Die steuerliche Darstellung dieser Vorschüsse fehlt weiterhin. Solche Nachweise
dürfen keine E-Bilanz freigeben; dieser Releaseblocker bleibt offen.

Neu ergänzt: [gemeinsam genutzte Anlagen innerhalb derselben steuerlichen Sphäre](GEMEINSAME-ANLAGEN-20260920.md).
Nutzungsnachweis, centgenaue Aufteilung, Journalabstimmung, Folgejahr,
Freigabeoberfläche und unabhängiger E-Bilanz-Nachweis sind angebunden.
Neben linearen Anlagen werden jetzt auch bestätigte GWG und degressive
Originalpläne einschließlich Folgejahren und unveränderter Methodenbindung
unterstützt. Die Nutzungsanteile erzeugen keine neuen GWG-Grenzen.
Bereichsübergreifende Nutzung, Nutzungswechsel und Anlagenabgänge sind damit
noch nicht vollständig abgedeckt; keine allgemeine Produktionsfreigabe.

Vorher ergänzt: [endgültige interne Mittelübertragungen mit Belegbezug](BEREICHS-MITTELUEBERTRAGUNG-20260920.md),
einschließlich Gegenbuchungen, Jahresvortrag, Freigabe, Aufhebung und unabhängigem
E-Bilanz-Nachweis. Das deckt **nicht** zeitweilige/rückzahlbare Verrechnungen oder
die vorgenannten Anlagen-Sonderfälle ab und ist keine allgemeine Produktionsfreigabe.

Die nachstehenden Abschnitte sind ein Entwicklungsverlauf, keine fortlaufend
gültige Liste bereits erledigter Blocker. Aktuell bestanden: Mehrbetriebsaufteilung,
Gesamtverein plus SGEB, gegenläufiger Kapitalvortrag, zwei vollständige isolierte
MySQL-Läufe, native ERiC-Prüfungen und **ein tatsächlicher kombinierter Testversand
über PHP-FPM mit offiziellem Testzertifikat**. Produktionsfreigabe bleibt aus.

Der tatsächliche Testversand ist damit für den jetzt geprüften kombinierten
Datensatz nicht mehr offen. Eine besondere spätere `EBilanzRueckmeldung` ist für
diesen SGEB enthaltenden Datensatz laut amtlicher SSb nicht vorgesehen. Das ist
keine ausstehende Empfangsquittung; technische Annahme und fachliche/steuerliche
Anerkennung bleiben getrennt. Für andere Profile ist die Rückübermittlung weiter
gesondert zu behandeln.

Noch zu entwickeln und anschließend in ihrem endgültigen Zusammenspiel abzunehmen:
steuerliche Darstellung laufender Verrechnungen zwischen Bereichen, weitere Fälle gemeinsam genutzter Anlagen,
weitere Konten-/Anlagen-/Steuersonderfälle, abweichende Wirtschaftsjahre und
Korrekturabschlüsse. Danach folgen die vollständige Manager-Bedienprüfung und
die Freigabe des gesamten Releasepakets. Keiner dieser Punkte wird durch das
Sperren nicht unterstützter Fälle als erledigt bezeichnet.

Abdeckung der aktuellen exakten Zuordnungsregistry (Revision 9), nicht mit der
Anzahl grüner Tests verwechseln: 2025 gibt es 135 explizit geregelte Kontencodes
bei 1.666 im Jahreskatalog buchbaren Einträgen, 2026 sind es 138 bei 1.679.
Jeweils 20 geregelte Codes haben eine zusätzliche Bedingung/Rückfrage.
Reservierte/mehrdeutige und Vortrags-/Statistikkonten sind im Nenner ausgeschlossen.
Fehlende automatische Regel bedeutet nicht automatisch unbuchbar; geprüfte
manuelle Zuordnungen sind teilweise möglich. Es ist aber insbesondere kein
Beleg für eine vollständige automatische Kontenabdeckung.

## In diesem Schritt umgesetzt

- Bis zu 32 eindeutig benannte Bereiche/Betriebe, darunter mehrere Betriebe
  derselben Sphäre. Grundlage ist das unveränderte, festgeschriebene Vereinsjournal.
- Jede Originalzeile mit Soll/Haben, Buchungskennung und Zeilenordinal bleibt
  nachweisbar. Positive ganze Centanteile mit Begründung müssen den Originalbetrag
  exakt ergeben. Konten, Seiten und ursprüngliche GuV-Sphären dürfen sich nicht ändern.
- Anfangsbestände und jede Buchung müssen je Betrieb ausgeglichen sein.
  Keine erfundenen Ausgleichsbuchungen, keine Rundungsdifferenzen, keine Umdeutung
  fehlender Angaben als null. Konto- und sphärenweise Gesamtabstimmung.
- Eindeutige Ein-Sphären-Buchungen werden nur zur ausdrücklichen Übernahme
  vorgeschlagen. Keine automatische Auswahl zwischen zwei Betrieben derselben Sphäre.
- Aufteilung getrennt vom Journal gespeichert: additive MySQL-Migration 98,
  unveränderliche Revisionskette, Hashbindung zum Journal, native UPDATE-/DELETE-
  Sperren, Versionierung, Vereinsgrenzen und Audit in derselben Transaktion.
- Finanzleserecht zum Lesen, Finanzschreibrecht und Abschlussart Bilanz zum
  Bearbeiten. Betreiberfreigabe des Bilanzmoduls bleibt erforderlich. Nur der
  berechtigte Vorstand gibt vollständige Aufteilungen ausdrücklich frei.
  Änderungen oder weggefallene Freigaberechte entziehen die aktuelle Freigabe.
- Vortrag aus der exakt freigegebenen Vorjahresrevision. Vorjahreserträge werden
  Kapital, nicht neue Erträge. Bestehende Vorjahreszuordnungen können nicht durch
  eine erste manuelle Folgejahreszuordnung umgangen werden.
- Nach Vorjahresänderungen: Folgejahresfreigabe ungültig, erneute Vorjahresfreigabe
  erforderlich. Vorstand kann die Anfangszuordnung begründet neu übernehmen.
  Laufende Zuordnungen und sämtliche alten Versionen bleiben erhalten; erneute
  Folgejahresfreigabe nötig. Keine rückwirkende Änderung am abgeschlossenen Journal.
- Oberfläche mit drei Abschnitten, großen Eingaben und gesonderter Handyansicht.
  Fehlerhafte Betragseingaben bleiben escaped erhalten, ohne eine veraltete
  Formularversion stillschweigend auf den neueren Stand zu setzen.
- Steuer-Arbeitsunterlagen enthalten bei vorhandener Aufteilung eine Übersicht,
  Kontennachweis, zeilengenaue Zuordnungsnachweise und JSON-Revisionsdaten.
  Ganze Centbeträge; Schutz gegen CSV-Formeln; Entwurfskennzeichnung. EÜR-Pakete
  bleiben ohne Bilanzaufteilung. Die Dateien sind an den Paket-Hash gebunden.
- Aufteilungs-Rechenkern und Persistenz sind im Steuer-Laufzeit-Fingerprint
  berücksichtigt. Eine Aufteilungsfreigabe ist ausdrücklich keine Steuerabgabe.

## Noch keine vollständige allgemeine Vereinsbilanz-Freigabe

Die neue Aufteilung schließt eine Datenmodelllücke, nicht sämtliche Release-Lücken:

1. Der XBRL-Testpfad verarbeitet jetzt auch die nachgewiesene Zusammenführung
   **sämtlicher steuerpflichtiger Geschäftsbetriebe eines steuerbegünstigten Vereins**
   aus der freigegebenen Gesamtaufteilung, einschließlich Anlagen, Steuerwerten
   und Vortrag. Das ist ein SGEB-Teilausweis, nicht die optionale Übermittlung einer
   zusätzlichen Gesamtvereinsbilanz/GuV. Ein gesonderter Gesamtvereins-Testpfad
   für nicht steuerbegünstigte Vereine ist inzwischen ergänzt (siehe unten),
   weiterhin mit der begrenzten geprüften Konten-/Anlagenabdeckung.
2. Eine umfassende, jahresgebundene SKR42-/Taxonomiezuordnung und alle benötigten
   Anlagen-/Steuersonderfälle fehlen weiterhin. Gesperrte Fälle sind nicht unterstützt.
3. Gegenläufige Bereichssalden, bereichsübergreifende Verrechnungen und
   Kontenänderungen beim Vortrag brauchen einen eigenen beleggebundenen Vorgang;
   aktuell wird statt verdeckter Saldierung abgebrochen. Abweichende Wirtschaftsjahre
   und Korrekturen festgeschriebener Gesamtjahre bleiben gesonderte Entwicklungsfälle.
4. Für die allgemeine Mehrbetriebsfunktion fehlen native ERiC-Gesamtabnahme,
   tatsächlicher Testversand mit den zutreffenden Rückmeldungen und abschließende
   Freigabe-/Wiederherstellungsprüfung des vollständigen Releasepakets.
5. Die komplette Menü-/Bedienprüfung des Vereinsmanagers folgt erst auf die
   tatsächlich fertige Bilanzfunktion. Die hier geprüfte Teilmaske ersetzt sie nicht.

Produktivschalter, EÜR-Auswahl, echte Belege, Konten und Steuererklärungen wurden
nicht geändert. Keine echten Mails, Bankaufträge oder Steuerabgaben ausgelöst.

## Ergänzung: Aufteilung an E-Bilanz angebunden

Fachlicher Rahmen: Mehrere steuerpflichtige wirtschaftliche Geschäftsbetriebe
einer steuerbegünstigten Körperschaft werden steuerlich zusammen behandelt
([§ 64 Abs. 2 AO](https://www.gesetze-im-internet.de/ao_1977/__64.html)).
Deshalb kein wahlweiser Versand einzelner rentabler Betriebe unter Weglassen
anderer Betriebe. Die interne Auswertung einzelner Betriebe bleibt erhalten.

- `BalanceClubTaxReport` rekonstruiert jedes Soll/Haben aus den Originalzeilen
  und Centanteilen aller Betriebe der Sphäre 4. Anfangskapital und Bestandskonten
  werden genauso übernommen wie laufende Einnahmen/Ausgaben und Stornos.
  Originaljournal und Aufteilung werden nicht verändert.
- Nur vollständig abgestimmte, aktuell vom Vorstand freigegebene Aufteilungen.
  Der Entwurf speichert Journal- und Freigabehash samt Revisionsnummern auch
  der Vorjahre. Bearbeitung/Neufreigabe entwertet den alten Entwurf; keine
  Wiederbelebung alter Freigaben durch bloß gleiche Endbeträge.
- Unveränderte beleggebundene Gegenstände der Sphäre 4, einschließlich vollständig
  abgeschriebener Gegenstände. Einzelwerte, Gesamtverein und Teilausweis müssen
  übereinstimmen. Keine automatische Umverteilung bei Widerspruch. Gemeinsame
  Mehrsphären-Anlagen benötigen weiterhin einen eigenen geprüften Aufteilungsfall.
- Abweichende steuerliche Nutzungsdauern laufen über die vorhandene getrennte
  Steuerwertrechnung, ohne die Buchwerte zu ändern. Anfangsbestand des Folgejahres
  wird gegen die vorherige Zusammenführung geprüft; Vorjahresgewinn wird Kapital.
- Der unabhängige Python-Generator baut die vollständige Original- und
  Betriebsrechnung erneut auf, prüft den Aufteilungshash und stimmt das Ergebnis
  mit den an ihn gelieferten Buchwerten/Anlagen ab. Herkunftsunterlagen aller
  Bereiche dienen nur intern der Prüfung, nicht als XML-Anlage an ELSTER.
- Oberfläche: Auswahl „Vereinsabschluss mit freigegebener Betriebsaufteilung“;
  der Entwurf zeigt ausdrücklich alle enthaltenen steuerpflichtigen Betriebe.
  Für nicht steuerbegünstigte Vereine wird dieser Teilausweis gesperrt.
- Produktionssperre bleibt unverändert. Künftige Freigabe erfordert weiterhin
  die vollständige oben gewählte allgemeine Vereinsbilanz, nicht nur dieses Profil.

Prüfungen dieser Ergänzung:

- 83 PHP-Projektionsprüfungen, 26 unabhängige Python-Generator-Testmethoden.
- Sieben amtliche ERiC-Prüfungen mit Code 0: Mehrbetriebszusammenführung,
  Anlagen, andere steuerliche Nutzungsdauer, voll abgeschriebener Gegenstand,
  Folgejahr, Storno und Verlust. **Nur Validierung, kein Testversand**.
  Protokoll: `audit/ebilanz-club-native-20260920.json`.
- Vollständiger MySQL-Lauf: elf Testprogramme erfolgreich, ohne PHP-Diagnosen;
  darin zunächst 88 Prüfungen für ursprünglichen und neuen E-Bilanz-Ablauf,
  einschließlich echter XML-Erzeugung und ausschließlich simuliertem Transport.
  `audit/ebilanz-mysql-host-20260918-018f37eeb0f5eb05.json`.
  150 Tabellen/1.393 Datensätze identisch wiederhergestellt, Live-Dateien unverändert.
- Lokale Syntaxprüfung: 925 PHP-Dateien erfolgreich. Der anschließende
  Sammeltest-Aufruf enthielt zunächst einen falsch geschriebenen Testdateinamen
  (`balance-depreciation-accounts.php`); der tatsächliche Test
  `balance-depreciation-account.php` wurde danach erfolgreich mit 15 Prüfungen
  ausgeführt. Keine unterdrückte Anwendungsfehlermeldung.
- 38 Steuer-Fingerprint-Dateien und zehn Python-Abhängigkeiten nachgewiesen;
  78 Veränderungs-/Fehlfallprüfungen des Fingerprints erfolgreich.
- Abschließender Lauf nach Speicherung der exakten Revisionsbindungen:
  `audit/ebilanz-mysql-host-20260918-8917008603fbc5f9.json`.
  91 kombinierte E-Bilanz-/Vereinsaufteilungsprüfungen, 90 Anlagen-/Steuerwert-
  und 52 allgemeine Steuerablaufprüfungen erfolgreich. Keine PHP-Diagnosen;
  erneute identische Rücksicherung, Produktionsdateien unverändert, privater
  Prüfbereich entfernt. Historische Übermittlungsprotokolle bleiben nach einer
  Aufteilungsänderung abrufbar; alte Entwürfe werden dadurch nicht sendefähig.
- Computer-Use-Sichtprüfung des tatsächlichen synthetischen MySQL/PHP-Renderings:
  beide Betriebe und passender Ergebnisbetrag sichtbar, Desktop und 390-Pixel-
  Handyansicht ohne horizontalen Überlauf, Eingabeschrift 17 Pixel, Felder etwa
  50 Pixel hoch, keine Browserwarnungen/-fehler. Keine Formularübermittlung.
  Größenoverride zurückgesetzt, Prüftab geschlossen und nur eigener lokaler
  Vorschauprozess beendet; die lokale Demo wurde nicht gestoppt.
- Quellmanifest einschließlich der acht neuen Quell-/Testdateien: 2.079 Hashes
  und statische Abhängigkeiten geprüft. Kein Git-Push und kein Produktivrelease.

Noch offen sind insbesondere allgemeine Konten-/Sonderfallabdeckung,
das zusätzliche Gesamtvereinsprofil mit SGEB, bereichsübergreifende
Verrechnungen, Gesamt-Webserverabnahme und der tatsächliche Testversand des
fertigen allgemeinen Profils. Alte Teilprofil-Testtickets sind keine Abnahme
dieser neuen Zusammenführung.

## Ergänzung: vollständiger Journalumfang für nicht steuerbegünstigte Vereine

**Weiterhin Entwicklung, kein Produktivrelease.** „Vollständig“ bezeichnet hier
den erfassten Journalumfang, nicht die abgeschlossene allgemeine Produktanforderung.

- Eigenständiger Typ `whole_taxable_club`, Grundlage `complete_club_ledger`.
  Alle Vereinsbereiche und Originalbuchungen bleiben enthalten. Keine versteckte
  Filterung auf Sphäre 4. Ein steuerbegünstigter Verein kann nicht durch Ändern
  einer Checkbox in diesen Typ überführt werden, und umgekehrt.
- `BalanceWholeClubReport` hält die vollständigen Quellen samt Anlagenverzeichnis
  und ursprünglichem Journalhash fest. Der Python-Generator rekonstruiert nicht
  nur die Buchwerte, sondern auch **jede steuerliche Wertkorrektur**, alle
  Kontenbewegungen, Ergebnissphären und Abstimmungszeilen unabhängig neu.
- Amtlicher Ausweis als Bilanz, GuV und Betriebsvermögensvergleich (B/GuV/BVV),
  nicht als SGEB-Tabelle. GKV und Körperschaftsprofil; normale Mussfeld-/Nil-
  Behandlung. Keine Übernahme der besonderen SGEB-Übermittlungsvariante für
  den Gesamtverein. Amtliche ERiC-Beispiele/Taxonomie 6.9 als Strukturreferenz,
  keine Beispiel-XML als Vorlage kopiert.
- Anlagen aller internen Bereiche werden unverändert übernommen. Abweichende
  steuerliche Nutzungsdauern behalten die ursprüngliche Sphäre ihrer Abschreibung
  und ihres belegten Abgangs. Bestehende SGEB-Pfade bleiben auf Sphäre 4 begrenzt.
- Betriebsvermögensvergleich mit einzeln nachgewiesenen Kapitalbewegungen:
  jede Kapitalzeile aus Originaljournal oder Steuerkorrektur erhält eine
  ausdrückliche Zuordnung als Zuführung, Entnahme/Ausschüttung oder reine
  Kapitalumbuchung samt Nachweis. Keine Änderung des Betrags, keine fremden Zeilen.
  Reine Umbuchungen müssen innerhalb **derselben Originalbuchung** ausgeglichen
  sein. Fehlende Zuordnung verhindert Validierung/Versand. Gewinn wird nicht aus
  ungeklärtem Kapital erfunden. Öffnungskapital ist der geprüfte steuerliche
  Anfangsbestand; Eröffnungsberichtigungen/Sondervorträge bleiben gesondert zu prüfen.
- Eigenes Formular für diese Zuordnung, in den vorhandenen CSRF-/Finanzrechts-
  und Versionsschutz integriert. Änderungen löschen XML-Prüfstand und
  Vorstandsfreigabe. Gesamtprüfung anschließend erneut nötig. Fehlgeschlagene
  Formularwerte bleiben escaped sichtbar. Keine zusätzliche Live-Aktion.
- Entwurf, Vorstandsfreigabe, Vorjahresübernahme, Inhalts-Duplikatsperre und
  historische Übermittlungsprotokolle durch den vorhandenen Ablauf integriert.
  SGEB-Vorjahre dürfen keinen Gesamtvereinsvortrag ersetzen. Die spätere
  Rückmeldung wird beim Gesamtprofil nicht fälschlich als SGEB-Ausnahme angezeigt.

Prüfungen:

- 30 PHP-Gesamtvereins-/Kapitalprüfungen, 29 unabhängige Python-Tests einschließlich
  Manipulations-, Fehlbetrags-, fehlender Zeilen-, falscher Profil- und Kapitalfälle.
- Neun eigene Gesamtvereinsfälle mit amtlicher ERiC-Prüfung **Code 0**:
  alle Bereiche, Anlagen, andere Steuerwerte, voll abgeschriebene Gegenstände,
  Vortrag 2026, Storno, Verlust, Kapitalzuführung/Entnahme/interne Umbuchung und
  abweichende steuerliche Anlagenwerte in mehreren internen Bereichen zugleich.
  `audit/ebilanz-whole-club-native-20260920.json`. Kein Transport, kein Zertifikat.
  Die sieben bisherigen SGEB-Fälle ebenfalls erneut mit Code 0 geprüft.
- Der erste native Gesamtprofilversuch meldete zwei echte Strukturfehler:
  unpassende besondere Körperschafts-Übermittlungsvariante und ein UKV-Mussfeld
  im GKV. Beide generatorseitig korrigiert, nicht durch Abschalten der Prüfung.
- MySQL 8.4.11 / Server-PHP 8.3.33: zuerst 113 kombinierte Ablaufprüfungen sowie
  90 Anlagen-, 105 gemischte Abgangs- und 52 allgemeine Steuerablaufprüfungen:
  `audit/ebilanz-mysql-host-20260918-e07c3cd0172d938c.json`.
  Nach Integration der Kapitalbearbeitung erneuter Lauf mit **122** kombinierten
  Ablaufprüfungen und denselben weiteren drei Gruppen:
  `audit/ebilanz-mysql-host-20260918-3887e6a8dc2d3253.json`.
  Alle erfolgreich, keine PHP-Diagnosen. Echte XML-Erzeugung, ausschließlich
  simulierter Transport. Kein tatsächlicher ELSTER-Testversand.
- Vor beiden Läufen die bestehende separate Datenbank serverseitig verschlüsselt
  gesichert und hinterher alle 150 Tabellen/1.393 Zeilen samt Triggern identisch
  rückgesichert. Produktionsdatenbank nicht verbunden; geschützte Live-Dateien
  unverändert. Die exakt benannten privaten Testverzeichnisse wurden danach
  entfernt; verschlüsselte Rücksicherungen bleiben verfügbar.
- 930 PHP-Dateien ohne Syntaxfehler, 84 Fingerprint-Prüfungen bestanden.
- Abschließende Generatorregression: elf Python-Testgruppen mit 195 Testmethoden
  erfolgreich; die anschließend um einen weiteren Mehrsphären-Anlagentest erweiterte
  Gesamtvereinsgruppe separat nochmals mit 29 Testmethoden erfolgreich.
  Neun PHP-Kerngruppen mit 554 Prüfungen erfolgreich. 41 Laufzeit-Dateien und
  elf Python-Abhängigkeiten im Steuer-Fingerprint nachgewiesen.
- Computer-Use-Prüfung des tatsächlichen synthetischen MySQL/PHP-Renderings:
  Gesamtprofil und Kapitalzuordnung am Desktop sowie bei 390 Pixel Breite.
  Kein horizontaler Seitenüberlauf (375 Pixel Inhalt), 17-Pixel-Eingabeschrift,
  Eingaben etwa 50 Pixel hoch. Keine Browserwarnungen/-fehler. Keine Formulare
  abgesendet. Dies ersetzt keinen Usability-Test mit einem unerfahrenen Menschen.
  Größenoverride zurückgesetzt, nur eigener Prüftab geschlossen und eigener
  Vorschauprozess beendet; die bestehende Demo blieb unberührt.
- Quellmanifest einschließlich sieben neuer Quell-/Testdateien auf 2.086
  Dateihashes gebracht; statische Abhängigkeiten vollständig nachgewiesen.

**Weiter offen:** vollständige jahresgebundene Konten-/Anlagen-/Steuersonderfälle,
zusätzliche Gesamtbilanz eines steuerbegünstigten Vereins mit SGEB, gemeinsame
Mehrsphären-Anlagen und bereichsübergreifende Verrechnungen, Gegenläufer/Kontenwechsel
beim Vortrag, abweichende Wirtschaftsjahre und Abschlusskorrekturen, fachliche
Gesamtabnahme sowie tatsächlicher Testversand des fertigen Umfangs einschließlich
zutreffender Rückmeldungen. Alle diese Lücken bleiben Freigabehindernisse.
Kulturhus bleibt unverändert bei EÜR; kein Commit, Push oder Live-Upload.

## Gesamtverein und steuerlicher Teilausweis gemeinsam (weiterer Entwicklungsstand)

Rechenkern und Generator unterstützen jetzt einen **gemeinsamen Testdatensatz**
aus originaler Gesamtbilanz/GuV und dem steuerlichen Ausweis aller steuerpflichtigen
Geschäftsbetriebe. Noch keine neue Freigabe- oder Versandoberfläche und keine
Produktivfreischaltung. Das Profil ersetzt weder die bisherigen SGEB-Profile noch
die EÜR von Kulturhus.

- `BalanceCombinedClubValues` verbindet denselben festgeschriebenen Originalabschluss
  mit seiner vollständig freigegebenen Betriebsaufteilung. Kontenbewegungen,
  Anfangsbestände und Jahresergebnis werden getrennt für Gesamtverein, steuerpflichtigen
  Buchanteil, übrige Bereiche und steuerliche Wertkorrekturen abgeglichen.
- Die Gesamtbilanz enthält ausschließlich Originalbuchwerte. Steuerliche
  Wertkorrekturen und andere steuerliche Nutzungsdauern betreffen nur den Teilbetrieb.
  Bestätigungen gelten für genau beide verbundenen Abschlüsse; kein gemischter
  Abschluss, kein ausgelassener steuerpflichtiger Betrieb und keine Umwandlung
  steuerfreier Bereiche in steuerpflichtigen Gewinn.
- Gesamtvereinsvortrag wird zusätzlich zum steuerlichen Teilvortrag gegen die
  vollständigen Original-Anfangsbestände geprüft. Keine fehlenden historischen
  Angaben als Nullbestand ergänzen. Der zukünftige Abgabedialog muss weiterhin die
  aktuelle Datenbankfreigabe und die früheren Steuerwert-/Übermittlungsnachweise prüfen.
- Python rekonstruiert nun auch die steuerlichen SGEB-Korrekturbuchungen unabhängig
  vom PHP-Ergebnis. Gleiche Endsummen bei verfälschten Soll-/Habenbewegungen werden
  nicht akzeptiert. Quellen- und Laufzeitbindung bleiben erhalten.
- Amtliche Struktur `umbrellaBodyTable`, ausdrücklicher Gesamt-Bilanzierungsstandard
  `HGBM`, Gesamt-B/GuV und separater SGEB mit eigenem Betriebsschlüssel. Beschreibung
  der Vereinstätigkeit und Bezeichnung des steuerpflichtigen Bereichs getrennt.
  Kein Betriebsvermögensvergleich, der das Gesamtergebnis als steuerpflichtig ausgibt.
  Grundlage: [amtliche FAQ, Abschnitt 4 m](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf),
  Taxonomie-6.9-Definitionen und ERiC-44.3.6-Dokumentation einschließlich XHTML-Fußnoten.
- Der strukturierte Brutto-Anlagenspiegel gehört in diesem Profil zur Gesamtbilanz
  und enthält deren Buchwerte. Die Taxonomie erlaubt dort keinen zusätzlichen
  `BgAKey`. Steuerliche Anlagenwerte einschließlich vollständig abgeschriebener
  Gegenstände bleiben als verknüpfter XHTML-Nachweis am SGEB-Anlagevermögen erhalten.
  Bezeichnungen werden als Text escaped, kein importiertes HTML. Dies ist **kein
  zweiter strukturierter steuerlicher Anlagenspiegel**. Die fachliche Zulänglichkeit
  dieses Nachweises für sämtliche Anlagen-Sonderfälle bleibt vor Freigabe zu klären.
- Keine externen XML-Teile werden übernommen. Beide Abschnitte entstehen im
  geprüften Generator; nur erzeugte SGEB-Fakten und ihre tatsächlich benutzten,
  eindeutig umbenannten Kontexte werden zusammengeführt.

Prüfstand dieses Schritts:

- 27 neue PHP-Prüfungen des kombinierten Abschlusses bestanden.
- 32 Python-Prüfungen des kombinierten Generators bestanden, darunter ausgetauschte
  Originalquellen, manipulierte Summen, fehlende Betriebe, Bestätigungen, getrennte
  Anlagenwerte und sichere XHTML-Textausgabe.
- Zwölf Python-Generatorgruppen mit 229 Testmethoden erfolgreich; anschließend die
  um zwei Bezeichnungsprüfungen erweiterte kombinierte Gruppe separat mit 32 Tests.
- Zehn PHP-Rechen-/Zuordnungsgruppen mit 581 Prüfungen sowie 88
  Laufzeit-Fingerprint-Prüfungen erfolgreich. 43 Fingerprint-Dateien und zwölf
  transitive Python-Abhängigkeiten vollständig nachgewiesen.
- Sieben kombinierte amtliche ERiC-Prüfungen mit Code 0:
  `audit/ebilanz-combined-club-native-20260920.json`. Zusätzlich die neun bisherigen
  Gesamtvereinsfälle und sieben SGEB-Fälle erneut erfolgreich. Nur native
  Validierung (`flags=2`), kein Zertifikat und keinerlei Transport.
- Erste native Versuche fanden eine veraltete HGB-Kennung, einen überflüssigen
  doppelten Kontext und die unzulässige Betriebsdimension am Anlagenspiegel.
  Diese Strukturfehler wurden korrigiert; keine Prüfroutine abgeschaltet.
- Keine Datenbankänderung, kein MySQL-Abnahmelauf und keine Oberflächenänderung in
  diesem Schritt. Ein versehentlich lokal aufgerufener MySQL-Test wurde vom
  vorgesehenen Isolationsschutz **vor jeder Datenbankverbindung** abgewiesen;
  die anschließend korrekt ausgewählten reinen Rechentests bestanden.

**Weiter nötig:** Einbindung dieses Profils in Entwurf, gemeinsame Prüfung,
Vorstandsfreigabe, Rechte-/Versionsschutz, Vorjahres-Steuerwertvortrag und Versand;
vollständige Konto-/Anlagen-/Steuersonderfälle, bereichsübergreifende Verrechnungen,
abweichende Wirtschaftsjahre/Korrekturen und fachliche Abnahme. Erst danach
Webserver-Abnahme, tatsächlicher Testversand mit passenden Rückmeldungen und
abschließende Bedienprüfung. Technischer ERiC-Code 0 ist keine fachliche
Gesamtfreigabe. Kein Commit, Push oder Live-Upload; Kulturhus bleibt EÜR.

## Kombinierter Abschluss: gespeicherter Ablauf und Oberfläche (20.09.2026)

Der zuvor nur im Rechenkern vorhandene kombinierte Abschluss ist jetzt in den
isolierten MySQL-Ablauf eingebunden. Dies schließt den oben genannten
Integrationsschritt, **nicht** die allgemeine Vereinsbilanz-Entwicklung ab.

- Neuer Entwurfstyp: Gesamtbilanz des steuerbegünstigten Vereins und sämtliche
  steuerpflichtigen Geschäftsbetriebe gemeinsam. Die vorhandenen getrennten
  Profile bleiben erhalten. Grundlage dieser Darstellung ist die amtliche
  [E-Bilanz-FAQ, Abschnitt 4 m](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf),
  erneut am 20.09.2026 abgeglichen. Technische Akzeptanz ersetzt keine fachliche
  Beurteilung des Einzelfalls.
- Original-Gesamtjournal und steuerlicher Teil sind separat gebunden. Jeder
  Vorjahresabschluss wird einschließlich des vollständigen Vereinsvortrags
  rekonstruiert; die steuerlichen Differenzen benötigen den tatsächlich
  bestätigten Vorjahresauftrag und dessen unverändertes Übermittlungsprotokoll.
- Gemeinsame Version, Inhaltsprüfung, genaue Vorstandsfreigabe und Versand.
  Gesamtverein und steuerlicher Teil erhalten getrennte Kontenzuordnungen;
  Wertkorrekturen verändern nur den steuerlichen Teil. Speichern, Planänderung
  oder erneute Prüfung machen alte XML-Prüfung/Freigabe ungültig.
- Steuerliche Nutzungsdauer kann auch im kombinierten Auftrag erfasst, vor
  Übernahme gelöscht und bestätigt vorgetragen werden. Bestätigte Vorjahrespläne
  bleiben unveränderbar. Ein handelsrechtlich bereits abgeschriebener Gegenstand
  verliert dadurch nicht seinen noch vorhandenen steuerlichen Restwert.
- Steuerlicher Anlagennachweis weiterhin als verknüpfte SGEB-Fußnote, vollständiger
  ursprünglicher Vereins-Anlagenspiegel strukturiert. Kein zusätzlicher oder
  doppelt summierter Anlagenspiegel. Fachliche Sonderfallabnahme weiter offen.
- Formulare behalten CSRF- und Versionsfelder; ungültige verschachtelte Eingaben
  werden beim erneuten Anzeigen nicht als Profilwechsel oder HTML übernommen.
  Der festgelegte Steuerbegünstigungsstatus bietet keine widersprüchliche Auswahl.
- Zusätzlich zum bestehenden datenbankweiten Anwendungsschreiblock verwendet die
  Versandreservierung denselben Vereinszeilen-Lock wie Bilanz-/Aufteilungsänderungen.
  Nach Erwerb wird die vollständige Versandbereitschaft erneut geprüft. Damit
  werden auch native Wartungsverbindungen berücksichtigt, die nicht den
  Anwendungsschreiblock verwenden. Der Lock endet vor dem externen Aufruf;
  ein unklarer Versand bleibt gesperrt und wird nicht automatisch wiederholt.

Abnahme dieses Entwicklungsschritts:


**Noch nicht releasefertig:** vollständige SKR42-/Taxonomie-Kontenabdeckung,
bereichsübergreifende Verrechnungen und gemeinsam genutzte Anlagen, weitere
Anlagen-/Überleitungsfälle, abweichende Wirtschaftsjahre und Korrekturabschlüsse,
fachliche Gesamtprüfung sowie tatsächlicher kombinierter Testversand mit
passenden Rückmeldungen. Die vollständige Manager-Bedienprüfung folgt danach.
Produktionssperren bleiben bestehen. Kulturhus bleibt EÜR; kein Live-Upload,
Commit, Push, tatsächlicher Steuerversand, Bankauftrag oder Mailversand.

## Vereinskapital und Rücklagen: Kontenzuordnung (20.09.2026)

Die Zuordnungsregistry hat Revision 8. Nach Sichtprüfung von Seite 6 der
jahresgebundenen DATEV-SKR42-Bilanz-PDFs 2025 und 2026 und Abgleich mit den
deutschen Bezeichnungen, Referenzen und Berechnungskanten der amtlichen
Taxonomie 6.9 sind 15 weitere Konten berücksichtigt. Keine freie Kontennummern-
oder Textinterpretation; die bisherigen PDF- und Kataloghashes bleiben gepinnt.

- Vereinskapital 25000, nutzungsgebundenes Kapital 23000 und
  Umschichtungsergebnisse 24000 erhalten getrennte Kapitalpositionen.
- Ergebnis-/Mittelvortrag 29700 und Verlustvortrag 29780 werden für den Verein
  gemeinsam in `bs.eqLiab.equity.SurplusOfUnspentFunds` ausgewiesen. Der einzelne
  negative Verlustsaldo bleibt in Kontennachweis und Vorjahresübernahme erhalten.
- Rücklagen 20800, 20900, 22000, 22500, 28000, 28500, 29500 und 29600 werden
  nach ihrem ausdrücklich benannten Zweck zugeordnet. Die Journalgruppe
  `reserves` ist jetzt im Generator für diese geprüften Positionen unterstützt.
- 20000 und 21000 bleiben echte Rückfragen: Die Kontobezeichnung unterscheidet
  nicht eindeutig zwischen Vorhaben/laufenden Ausgaben beziehungsweise Herkunft
  aus Vermögensverwaltung/anderen Mitteln. Je Konto werden nur zwei passende
  Auswahlmöglichkeiten gezeigt. Unterschiedliche Zwecke oder Herkunft sind
  getrennt zu buchen; kein unaufgeklärter Rest wird auf eine Unterposition geraten.
- Nicht nur die Oberfläche, auch Speicherdienst und unabhängiger Python-Generator
  lehnen eine widersprüchliche Kapital-/Rücklagenzuordnung ab. Eine Änderung setzt
  XML, Validierung und Vorstandsfreigabe wie jede andere fachliche Änderung zurück.
- Rücklagenbildung/-auflösung durch Umbuchung wird nicht als Ertrag/Aufwand oder
  externe Kapitalzuführung behandelt. Beim vollständigen steuerlichen Abschluss
  bleibt die belegbezogene Freigabe der Kapitalbewegungen erforderlich.
- Die bisher falsche Auswahlbeschriftung „Gewinnrücklagen“ für den
  Gewinn-/Verlustvortrag der Kapitalgesellschaft wurde korrigiert. Das ist kein
  Ersatz für Vereinskapital; das nummerierte Konto 25000 darf nicht dorthin wechseln.

Nachweise: [DATEV SKR42 Bilanz](https://www.datev.de/web/de/shop/produkt-details/datev-kontenrahmen-skr-42-vereine-stiftungen-ggmbh-bilanz-12901),
[amtliches Taxonomiepaket 6.9](https://www.esteuer.de/download/taxonomie_20250618/german-gaap-taxonomy-v6.9-2025-04-01-xbrl.zip).
Dies ist eine begrenzte, nachvollziehbare Kontenzuordnung, kein Nachweis der
steuerrechtlichen Zulässigkeit einer konkreten Rücklage oder ihrer Höhe.

Prüfstand dieser Erweiterung:

- 145 neue PHP-Prüfungen für beide Katalogjahre, Kontenarten, zulässige Auswahl,
  Speichervalidierung, Kapitalvergleich, Verlustvortrag und gerenderte Rückfragen.
  Alle zwölf reinen PHP-Prüfprogramme zusammen: 814 Prüfungen.
- 13 Python-Generatorgruppen, 239 Testmethoden bestanden. Die neue Gruppe prüft
  alle drei Vereinsprofile in beiden Jahren, jede Summenebene, alternative
  zulässige Zwecke, fehlende/widersprüchliche Zuordnung und negative Kontenwerte.
- 29 native ERiC-Validierungen bestanden (11 Gesamtverein, 9 kombiniert,
  9 steuerlicher Teilausweis), ausschließlich Flags 2: keine Zertifikate,
  kein Transport. Berichte `audit/ebilanz-capital-{whole,combined,sgeb}-native-20260920.json`.
- Erste vollständige MySQL-Prüfung: 555 Workflow-Prüfungen plus die damaligen
  144 reinen Zuordnungsprüfungen erfolgreich. Bericht
  `audit/ebilanz-mysql-host-20260918-6251937be2c9ac00.json`.
  Die separate Datenbank ist identisch wiederhergestellt, geschützte Live-Dateien
  unverändert, eigener privater Server-Prüfbereich entfernt.
- Die anschließende mobile Sichtprüfung zeigte gekürzte lange Auswahlnamen.
  Eindeutig zugeordnetes Kapital erscheint deshalb jetzt als umbrechender Text
  statt einer doppelten Auswahl. Die beiden Rückfragen haben kurze Antworten;
  technische Taxonomiekennungen bleiben Formularwerte, nicht sichtbare Beschriftungen.
  Bereits manuell gesetzte Werte bleiben in geschützten Formularfeldern erhalten.
- Erneuter vollständiger MySQL-Lauf nach der Oberflächenänderung: 555 Workflow-
  und 145 Zuordnungsprüfungen bestanden, keine PHP-Warnungen. Nachweis:
  `audit/ebilanz-mysql-host-20260918-34e22438287fcd60.json`.
  Wieder 150 Tabellen / 1.393 ursprüngliche Datensätze identisch rückgesichert;
  Produktionsdatenbank nicht verbunden, geschützte Live-Dateien unverändert,
  privater Server-Prüfbereich entfernt. Kein echter Versand.
- Aktuelles serverseitiges Rendering erneut im Browser geprüft: 390-Pixel-Handy
  und 1280-Pixel-Desktop, kein horizontaler Seitenüberlauf. Automatische
  Kapitalanzeigen umbrechen vollständig; die beiden kurzen Antworten passen
  in 247,5 Pixel breite, 50 Pixel hohe Eingabefelder mit 17-Pixel-Schrift.
  Keine verschachtelten Formulare oder Browserfehler. Kein Browser-POST;
  eigener Prüftab/Previewserver geschlossen und Viewport zurückgesetzt.
  Dies ersetzt weiterhin keinen tatsächlichen Test mit der Zielgruppe.
- 972 PHP-Dateien vollständig auf Syntax geprüft, danach die geänderten Dateien
  und die neue gemeinsame Kontenansicht einzeln erneut geprüft. Aktueller Bestand:
  973 PHP-Dateien. Installationsmanifest mit 2.098 Dateihashes und statischen
  Abhängigkeiten geprüft; Laufzeit-Fingerprint weiterhin 43 Dateien / 12 Python-
  Abhängigkeiten. Keine Veröffentlichung auf Live oder GitHub.

Der allgemeine Release bleibt gesperrt: Weitere Konten- und Sonderfälle,
bereichsübergreifende Verrechnungen/gemeinsame Anlagen, Korrekturabschlüsse und
die tatsächliche Übermittlungsabnahme sind damit noch nicht erledigt.
Kulturhus bleibt EÜR. Keine produktiven Buchungen, Mails, Bank- oder Steueraufträge.

## Gegenläufiges Bereichskapital und Export-Unterpositionen (20.09.2026)

Ein konkreter bislang gesperrter Jahreswechselfall ist implementiert: Ein Bereich
hat positiven Ergebnis-/Kapitalvortrag, ein anderer negativen. Auch wenn die
Gesamtsumme des Kontos null ist und deshalb im Gesamtvortrag keine Zeile steht,
bleiben beide Bereichssalden nachweisbar erhalten. Keine neue Journalbuchung,
keine erfundene Forderung/Verbindlichkeit und keine Änderung der Vereinsbilanz.

- Aufteilungsschema 2 ergänzt ausschließlich für gegenläufige Eigenkapitalsalden
  `capital_carry`: ganze Cent, Soll positiv/Haben negativ. Es wird nur durch den
  bestehenden Vortragsablauf erzeugt. Schema 1 und dessen Hashes bleiben unverändert.
- Jede Kontensumme muss exakt dem Original-Anfangssaldo entsprechen. Nur
  Eigenkapital, kein Vermögen, keine Schulden, keine negativen Rücklagen;
  ein belegtes Vorjahr ist zwingend. Laufende Buchungen behalten ihre bisherigen
  positiven Originalanteile und die bereits festgelegte Sphäre.
- Speicherung bindet den Vortrag weiterhin an die konkrete Vorjahresfreigabe.
  PHP und der unabhängige Python-Generator prüfen zusätzlich jeden einzelnen
  Bereich gegen das Vorjahr, nicht nur die Summe der steuerpflichtigen Bereiche.
  Ausgeglichene, neu gehashte Verschiebungen zwischen Betrieben werden abgewiesen.
- Erneute Abstimmung nach einer geänderten Vorjahresfreigabe kann Schema 2 auch
  wieder durch Schema 1 ersetzen; alte Stände bleiben im unveränderbaren Verlauf.
  Folgejahresfreigaben und bestehende Übermittlungsentwürfe werden nicht übernommen.
- Die Oberfläche zeigt positive/negative Kapitalwerte je Bereich. Übernommene
  Anfangswerte bieten keine Bearbeitungsformulare mehr. Der Export ergänzt einen
  getrennten Kapitalvortragsnachweis mit eindeutiger Vorzeichenerklärung.
- Synthetische Fälle: positiver/negativer/Null-Gesamtsaldo, Ein-Cent-Beträge,
  negativer Bereichskapitalbestand, Gewinn außerhalb des steuerlichen Bereichs,
  zwei aufeinanderfolgende Übernahmen, nachträglich geänderte Betriebszuordnung,
  Rollen, veraltete Versionen und verschlüsselte Wiederherstellung.

Die native ERiC-Prüfung des neuen Falls deckte außerdem Fehler **170155096** auf:
Eine werthaltige Lieferantenverbindlichkeit stand bisher nur im Summenmussfeld
`bs.eqLiab.liab.trade`. Die Gesamtvereinsbilanz benötigt auch eine passende
Unterposition. Das bloße erfolgreiche Erzeugen einer XML-Datei hatte diesen
Fehler nicht erkannt.

Registry Revision 9 entfernt die reine Summenposition als direkte Kontenzuordnung
für 12000/33000. Kunden-/Lieferantenkonten benötigen die geprüfte Unterscheidung
zwischen übrigen Forderungen/Verbindlichkeiten, Gesellschaftern und ausdrücklich
nicht weiter aufschlüsselbaren Beständen. Eine Gesellschafterbeziehung oder
fehlende Aufschlüsselbarkeit wird nicht aus Kontonummer, Betrag oder Namen geraten.
Die zulässigen Unterpositionen sind auch serverseitig und im Generator begrenzt.
Gespeicherte alte Summenpositionen werden nicht unbemerkt umgeschrieben, sondern
erfordern eine neue Zuordnung/Prüfung. Der Laufzeit-Fingerprint entwertet alte
Validierungen für den veränderten Generator.

Grundlage: deutsche Beschriftungen, DefinitionGuidance und Berechnungskanten des
[amtlichen Taxonomiepakets 6.9](https://www.esteuer.de/download/taxonomie_20250618/german-gaap-taxonomy-v6.9-2025-04-01-xbrl.zip),
zugeordnet über die [offizielle Taxonomieübersicht](https://www.esteuer.de/).
`*.misc` ist eine Auffangposition für nicht weiter aufschlüsselbare Bestände,
kein automatischer Ersatz für eine tatsächlich mögliche sachliche Zuordnung.

Prüfungen dieses Entwicklungsschritts:

- 13 PHP-Rechen-/Zuordnungs-/Fingerprintgruppen: **1.046** Prüfungen bestanden.
- 15 Python-Gruppen: **250** Testmethoden bestanden, einschließlich unabhängiger
  Rekonstruktion, manipulierten Vorträgen und unzulässigen Summenpositionen.
- **976** PHP-Dateien ohne Syntaxfehler. Unverändert 43 Laufzeitdateien und
  zwölf Python-Abhängigkeiten vollständig im Freigabe-Fingerprint enthalten.
- **41** native ERiC-Validierungen mit Code 0: 15 Gesamtverein, 13 kombinierte
  Abschlüsse, 13 steuerliche Teilabschlüsse. Berichte
  `audit/ebilanz-capital-carry-{whole,combined,sgeb}-native-20260920.json`.
  Ausschließlich Flags 2; weder Zertifikat noch Transport. Die vorausgehende
  fehlgeschlagene native Prüfung ist keine Abnahme; sie führte zum oben
  dokumentierten Unterpositions-Fix.
- Erster MySQL-Lauf vor dem Unterpositions-Fix: acht Programme, 1.034 Prüfungen
  einschließlich 364 reiner Rechenprüfungen bestanden. Bericht
  `audit/ebilanz-mysql-host-20260918-4a48c7d6a7596a63.json`.
  Die 150 Tabellen/1.393 ursprünglichen Datensätze der freigegebenen zweiten
  Datenbank einschließlich Schema/Trigger identisch rückgesichert; produktive
  Datenbank nicht verbunden, geschützte produktive Dateien unverändert.
- Zweiter vollständiger MySQL-Lauf nach dem Unterpositions-Fix: wiederum acht
  Programme und **1.034** Prüfungen bestanden, ohne PHP-Diagnosen. Bericht
  `audit/ebilanz-mysql-host-20260918-f9137e6eb2efb650.json`. Identische Rücksicherung
  aller 150 Tabellen/1.393 Datensätze samt Schema/Triggern bestätigt; produktive
  Datenbank nicht verbunden, geschützte Live-Dateien unverändert, privater
  Prüfbereich entfernt. Verschlüsseltes verifiziertes Sicherungsarchiv bleibt
  ausschließlich auf dem Server.
- Zusätzliche native Prüfung der bisher geprüften Konten mit Registry 9:
  **247** Fälle mit Code 0 sowie eine gezielt verfälschte Gesamtsumme mit der
  erwarteten Ablehnung 610001002. Bericht
  `audit/ebilanz-reviewed-codes-r9-native-20260920.json`. Flags 2, kein Versand.
  Die Anzahl ist eine Fallzahl, keine vollständige SKR42-Kontenabdeckung.
- Tatsächliches synthetisches PHP/MySQL-Rendering im Browser geprüft: positive
  und negative Bereichskapitalwerte sichtbar, 1280-Pixel-Desktop und 390-Pixel-Handyansicht ohne
  Seiten-/Zellenüberlauf, 16-Pixel-Tabellenwerte, keine verschachtelten Formulare
  oder Browserwarnungen/-fehler. Keine Formularübermittlung. Technische Sichtprüfung,
  kein Nutzerexperiment mit der Zielgruppe. Größenoverride zurückgesetzt,
  eigene Prüftabs geschlossen und eigener Vorschauprozess beendet.

### Tatsächliche kombinierte Webserver-Übermittlung

- 13 ausschließlich synthetische Fälle über tatsächliches PHP-FPM/CGI 8.3.33,
  privaten aktuellen Adapter/Generator und originale ERiC-44.3.6-Bibliotheken:
  alle amtlich mit Code 0 validiert. Darunter Gesamt-/Teilwerte, abweichende
  steuerliche Nutzungsdauer, voll abgeschriebene Gegenstände, Rücklagen, Storno,
  Verlust und die neuen positiven/negativen Kapitalvorträge.
- Genau **ein** synthetischer kombinierter Datensatz mit zwei Anlagen, 112
  strukturierten Anlagen-Fakten und gesondertem steuerlichem Anlagen-Nachweis
  übertragen. Code 0, bestätigter Testmodus, gebundener XML-Hash und
  Transferticket vorhanden. Es wurde kein zusätzliches Beleg-PDF angehängt.
  Test- und Nutzdatenkennung sowie technische Serverantwort bleiben nachweisbar;
  die Annahme ist kein Steuerbescheid und keine Sonderfall-Vollabnahme.
- Bericht: `audit/ebilanz-combined-club-webserver-20260920-c0df99d610e8a592.json`.
  XML SHA-256: `54f1135335ff87c202a29563b166a9e3c324d924a92055d3579aa2c475bef84e`.
  Die vollständige synthetische Antwort bleibt in der privaten lokalen
  Prüfablage; keine produktiven Vereinsdaten wurden verwendet oder heruntergeladen.
- Kein Datenbankzugriff und kein Produktions-Bootstrap. Eigene zufällige,
  geheime und zeitlich begrenzte Prüfadresse; unbefugter Aufruf liefert 404.
  Exklusive lokale und serverseitige Versandreservierungen verhindern blinde
  Wiederholung. Prüfadresse und privater Prüfbereich einschließlich offizieller
  Testzertifikatskopie nach Erfolg entfernt; geschützte Live-Dateien unverändert.
- Amtliche `EBilanzRueckmeldung-SSb-V1.1.pdf`, 30.10.2025, Seiten 2–3, vollständig
  gelesen und visuell geprüft: Rückübermittlung betrifft geänderte Steuerwerte,
  nicht eine allgemeine Eingangsquittung. Enthält der Ausgangsdatensatz steuerliche
  Gewinnermittlung für WGB/BgA, ist dieser Rückkanal ausgeschlossen. Deshalb
  keine erfolglose Abholschleife oder angeblich ausstehende Rückmeldung für
  diesen kombinierten SGEB-Test. Bestehende Anwendungspolitik passt dazu.
- Abschließende 17 Rückmeldungs-Policy-Prüfungen bestanden. Entwicklungsmanifest
  einschließlich fünf neuer Quell-/Testdateien aktualisiert: 2.103 Dateihashes
  und statische Abhängigkeiten geprüft. 43 Fingerprint-Dateien und zwölf Python-
  Laufzeitabhängigkeiten vollständig erfasst. Keine Produktiv- oder GitHub-Freigabe.

**Keine vollständige Freigabe:** Dies ersetzt nicht die noch fehlenden laufenden
bereichsübergreifenden Verrechnungen, gemeinsam genutzten Anlagen, weiteren
Konten-/Steuersonderfällen, Korrekturabschlüssen, abweichenden Wirtschaftsjahren
und der erneuten Gesamtabnahme nach deren Integration. Der oben dokumentierte
Testversand gilt für den konkreten jetzigen Datensatz, nicht für zukünftige Änderungen.
Die fachliche Abschlussprüfung muss weiterhin die tatsächlichen Vereinsverhältnisse
abdecken. Kulturhus bleibt EÜR. Kein Live-Upload oder GitHub-Push.

## Prüfprotokoll

Alle Daten in Rechen-/Formulartests sind synthetisch. Die freigegebene separate
MySQL-Testdatenbank wird vor jedem Lauf serverseitig verschlüsselt gesichert und
danach einschließlich Tabellen, Zeilen und Trigger identisch wiederhergestellt.

- 183 reine Aufteilungs-/Vortragsprüfungen und 25 Exportprüfungen bestanden.
  Mit den bestehenden Rechen-, Konten-, Umsatzsteuer-, Abschreibungs-, Software-
  und Fingerprint-Tests: 515 Prüfungen in acht PHP-Testprogrammen bestanden.
- 854 PHP-Dateien ohne Syntaxfehler; neun Python-Generatorgruppen mit insgesamt
  141 Testmethoden bestanden. 36 Laufzeit-Dateien und neun Python-Abhängigkeiten
  im Steuer-Fingerprint geprüft; 74 Fingerprint-Veränderungs-/Fehlfallprüfungen.
- Abschließender MySQL-Lauf: zehn Testprogramme, 725 Prüfungen bestanden,
  keine PHP-Warnungen. Davon 61 für neue Aufteilung/Persistenz, 47 Bilanzjournal,
  57 Quellenintegration, 6 Schemarücksicherung, 124/125 Lizenzende, 58 Teilabgang,
  90 steuerliche Anlagenwerte, 105 gemischte Abgänge, 52 Steuerübermittlung
  ausschließlich mit Offline-Transport-Fixture. Nachweis:
  `audit/ebilanz-mysql-host-20260918-a4d43e2b384c323e.json`.
  Der Datumspräfix stammt vom bestehenden Prüfoperator; der Lauf erfolgte am 20.09.
- Separate Datenbank mit zuvor 150 Tabellen und 1.393 Datensätzen nach jedem
  Lauf identisch wiederhergestellt. Produktionsdatenbank nicht verbunden,
  geschützte Live-Dateien unverändert, temporärer Server-Prüfbereich entfernt.
- Früherer Lauf `deece06e83e1e2a8` war nicht grün: Zwei ältere Fehlersimulationen
  ließen die absichtliche Manipulation im in-place rückgesicherten Testbestand
  zurück; außerdem fehlte die Export-CSS im privaten Prüfpaket. Fehlersimulationen
  jetzt mit Transaktion und Rollback im `finally`, Originaltests unverändert streng.
  CSS mitgeliefert; Warnungen lassen den Prüfoperator jetzt ebenfalls fehlschlagen.
  Dieser ältere Lauf ist Diagnose, nicht Abnahme; auch dort Rücksicherung erfolgreich.
- Browser-Sichtprüfung des tatsächlichen MySQL/PHP-Renderings mit den lokalen
  Produktions-Styles: 1280-Pixel-Desktop und 390-Pixel-Handyansicht. Zunächst
  abgeschnittene mobile Tabellenwerte behoben: Aufteilung erscheint auf dem Handy
  in beschrifteten Karten. Kein Seiten- oder Zellenüberlauf mehr; 16-Pixel-
  Tabellenwerte, 17-Pixel-Eingaben, mindestens 48-Pixel-Eingabefelder, 44-Pixel-
  Links in den Handy-Karten. Aufklappen zeigt die passenden Teilbilanzwerte.
  Keine Browserwarnungen oder -fehler; kein Browser-POST. Prüftab geschlossen,
  Größenoverride zurückgesetzt, ausschließlich eigener Previewserver beendet.
- Entwicklungs-Quellmanifest auf 2.071 Dateihashes aktualisiert und statische
  Abhängigkeiten vollständig geprüft. Kein Commit, Push oder Produktiv-Upload.

Die Browserprüfung ist eine technische Sichtprüfung, kein durchgeführter
Usability-Test mit einem unerfahrenen Vereinsmitglied.
