# Bankanbindung: FinTS ohne kostenpflichtigen API-Mittler

Stand: 9. September 2026. Der aktuelle Manager- und Banking-Code ist live veröffentlicht; ein echter Bankzugang wurde noch nicht abgerufen. Die echte Bibliothek 4.1.0 ist separat mit Lockfile unter [banking/](../../banking/README.md) eingebunden und ohne Banknetzwerk geprüft. Umsatzabruf sowie direkte Überweisungen und CORE-Lastschriften sind technisch in den geschützten Dialog integriert. Die private FinTS-Metadatenkonfiguration für Volksbank Jade-Weser eG ist vorbereitet. Ein echter Banklauf bleibt von Zugangsdaten, Bankfreischaltungen und einem ausdrücklich freigegebenen Test abhängig.

## 1. Entscheidung in einem Satz

Die Bibliotheksauswahl `nemiah/php-fints` ist vom Nutzer bestätigt. Version 4.1.0 ist per Composer-Lockfile fixiert; der Bankadapter samt TAN-/App-Dialog, Empfängerprüfung und Zustandsverwaltung ist integriert. Schreibvorgänge werden ausschließlich aus versionierten, intern freigegebenen Aufträgen aufgebaut.

Die Vereinsverwaltung soll über einen **isolierten eigenen Banking-Dienst mit einer kostenlosen PHP-FinTS-Bibliothek** auf das Vereinskonto zugreifen. Die berechtigte Person bestätigt erforderliche Vorgänge mit dem von ihrer Bank vorgesehenen TAN-/App-Verfahren. Ein kommerzieller API-Zwischenanbieter ist für diesen Ansatz nicht vorgesehen.

Das ist eine technische Zielarchitektur, keine Garantie für jede Bank oder jedes Konto. FinTS-Funktionen hängen vom Institut, dem konkreten Zugang, Vollmachten, Auftragsart und TAN-Verfahren ab. Ein Bibliotheksaufruf allein schafft weder eine Lastschriftberechtigung noch einen geprüften Bankdialog.

## 2. Was tatsächlich belegt ist

| Punkt | Befund | Grenze |
|---|---|---|
| Bank | Die Volksbank Jade-Weser eG beschreibt FinTS einschließlich Lastschriften und PIN/TAN | Die Nutzerangabe „Jade Weser Ems“ und das konkrete Vereinskonto müssen abschließend zugeordnet werden |
| FinTS-Produkt | Die Deutsche Kreditwirtschaft verlangt eine Registrierung der fachlichen Anwendung; KulturhusConnect hat die Registrierungs-ID `DEINE_FINTS_PRODUKT_ID` erhalten | Die ID wird exakt 25-stellig ausschließlich in HKVVB/Produktbezeichnung verwendet; Registrierung ersetzt keine Bankfreischaltung |
| PHP-Bibliothek | `nemiah/php-fints`, MIT-Lizenz; Implementierungen für Einzel-/Sammelüberweisung, Einzel-/Sammellastschrift und CAMT-Abruf eingebunden | Keine Garantie für jede Bank; konkrete Bankkompatibilität und Freischaltung noch ungeprüft |
| Hosting | CLI-PHP und benötigte Erweiterungen wurden geprüft; ausgehendes TLS zu einer Nicht-Bank-Adresse funktionierte | Keine Prüfung des konkreten Bankendpunkts, der Web-Laufzeit oder vollständigen Isolation |
| Finanzieller Rahmen | Für den ausgewählten Bibliotheksansatz kein API-Abo vorgesehen | Vereinskontotarif, notwendige Isolation und laufende Pflege sind noch nicht abschließend kalkuliert |

Quellen: [Bank: FinTS/EBICS](https://www.volksbank-jade-weser.de/banking-und-vertraege/banking/banking-firmenkunden/fints-ebics.html), [FinTS-Registrierung](https://www.fints.org/de/hersteller/faq-produktregistrierung), [PHP-Anforderungen/Lizenz](https://raw.githubusercontent.com/nemiah/phpFinTS/master/composer.json), [Lastschrift-Code](https://github.com/nemiah/phpFinTS/blob/master/src/Action/SendSEPADirectDebit.php), [CAMT-Code](https://github.com/nemiah/phpFinTS/blob/master/src/Action/GetStatementOfAccountXML.php).

Bei der Registrierung ist mit Bearbeitungszeit zu rechnen; die FinTS-Leitstelle nennt üblicherweise 10–15 Werktage, mögliche Verzögerungen eingeschlossen. Antrag erst nach Nutzerfreigabe und festgelegtem Produktverantwortlichen. Produkt-ID und Version werden konfiguriert, nicht aus einer fremden Anwendung übernommen. [Registrierungs-FAQ](https://www.fints.org/de/hersteller/faq-produktregistrierung)

## 3. Softwareauswahl und bewusste Einschränkungen

**Vom Nutzer am 5. September 2026 festgelegt:** `nemiah/php-fints` in einem eigenen privaten Adapter verwenden. Die geprüfte Upstream-Konfiguration nennt PHP ab 8.0, cURL und mbstring. Das bestehende Projekt hat seine eigene höhere PHP-Vorgabe. Version 4.1.0 ist auf den geprüften Commit per Lockfile fixiert; die Schreibaktionen werden nur über den serverseitig erzeugten, freigegebenen Auftrag aufgerufen. Der bewegliche `master`-Branch ist kein Deployment-Pin. [Upstream-Konfiguration](https://raw.githubusercontent.com/nemiah/phpFinTS/master/composer.json)

Der Hersteller-Code demonstriert das Fortsetzen eines TAN-Dialogs zwischen Webanfragen und warnt ausdrücklich vor dem öffentlichen Einsatz seines Browser-Beispiels. Wir übernehmen das Zustandsprinzip, **nicht** dessen Demo-Speicher- und Zugriffsmuster. Ein dauernder Python-Prozess ist für diesen PHP-Ansatz konzeptionell nicht erforderlich. [Web-/TAN-Beispiel](https://github.com/nemiah/phpFinTS/blob/master/Samples/browser.php)

Für SEPA-XML ist eine gepflegte Bibliothek mit validierbaren Formaten vorzusehen; `AbcAeffchen/Sephpa` ist ein zu prüfender Kandidat, kein bereits integrierter Bestandteil. [Projekt](https://github.com/AbcAeffchen/Sephpa)

**Nicht automatisch enthalten:** EBICS, PayPal, Kartenakzeptanz, eine XS2A-Abdeckung aller Banken, native Apps oder eine finanzaufsichtliche Zulassung. Dazu gehörende WISO-Paritätsanforderungen bleiben sichtbar in der Matrix. Für Funktionen außerhalb des konkreten FinTS-Zugangs sind getrennte Adapter bzw. ausdrücklich beschlossene Abweichungen erforderlich. Zahlungsdienstleistergebühren werden durch kostenlose Software nicht beseitigt.

Der Zielbetrieb ist die Verwaltung des eigenen Vereinskontos. Eine Öffnung als Banking-Service für andere Vereine oder Dritte ist ein neuer Auftrag mit gesonderter rechtlicher und betrieblicher Prüfung.

## 4. Voraussetzungen beim Verein und bei der Bank

Vor einem echten Lastschrifteinzug sind mindestens folgende Punkte schriftlich geklärt:

- Exaktes Institut und Kontoinhaber; geschäftlicher Onlinebanking-/FinTS-Zugang und tatsächlich berechtigte Personen.
- Freischaltung für **SEPA-Basislastschriften (CORE)**, Einreichungsvereinbarung, Konto-/Tages-/Sammlerlimits und Vorlauf-/Annahmeschlusszeiten.
- Gläubiger-Identifikationsnummer, eindeutige Mandatsreferenzen und gültige, nachweisbar abgegebene Mandate einschließlich Widerruf/Änderung.
- Zulässiges TAN-/App-Verfahren, verfügbare Bankgeschäftsvorfälle sowie aktuell unterstützte PAIN-/CAMT-Versionen.
- Passendes Verfahren für gemeinsam vertretungsberechtigte Personen. Eine interne Vieraugenfreigabe ersetzt keine bankvertraglich verlangte Unterschrift oder Vollmacht. FinTS nicht als EBICS-VEU ausgeben.
- Gebühren für Konto, Buchungsposten, Einzel-/Sammellastschrift, Rückgaben und gegebenenfalls FinTS; der tatsächliche Vereinstarif ist maßgeblich.

Eine Gläubiger-ID berechtigt allein noch nicht zum Einzug. [Bundesbank: Gläubiger-ID](https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/sepa/glaeubiger-identifikationsnummer/allgemeine-hinweise-602858)

Die Vorankündigung erfolgt grundsätzlich mindestens 14 Kalendertage vor Fälligkeit, sofern keine andere Frist vereinbart ist. Die Anwendung muss Frist und nachgewiesene Vereinbarung berücksichtigen. Eine neue Einreichung nach Rückgabe ist ein neuer fachlich zu prüfender Vorgang, kein technischer Wiederholungsversuch. Konkrete Bankarbeitstage und Einreichungsfristen werden nicht geraten. [Bundesbank: SEPA-Fragen und Antworten](https://www.bundesbank.de/action/de/613964/bbksearch?pageNumString=1)

### Vorbereitete Anfrage an die Bank – nicht automatisch versenden

> Wir möchten für Kulturhus Jaderberg e. V. eine eigene, registrierte FinTS-Anwendung zur Verwaltung unseres Vereinskontos verwenden. Bitte bestätigen Sie die Nutzbarkeit von Umsatzabruf sowie Einzel- und Sammel-Basislastschriften über FinTS für unseren Zugang. Welche Freischaltung/Inkassovereinbarung, Gläubiger-ID-Angaben und Mandatsnachweise benötigen Sie? Welche TAN-Verfahren, SEPA-XML-/CAMT-Versionen, Limits, Vorlaufzeiten und Gebühren gelten für unseren Vereinstarif? Ist unsere geplante persönliche bzw. gemeinsame Freigabe mit den Kontovollmachten vereinbar? Bitte nennen Sie die offiziell zu verwendenden Verbindungsdaten ohne Übermittlung von PIN oder TAN.

Die Verbindung betrifft das Vereinskonto. Mitglieder müssen nicht ihr Onlinebanking bei uns verbinden; ihr Lastschriftmandat und die erforderlichen Zahlungsdaten sind ein anderer Vorgang.

## 5. Sicherheitsgrenze und Hosting-Gate

**BANK-01:** Banking läuft in einem nachgewiesen getrennten Ausführungskontext. Das öffentliche CMS darf dessen Schlüssel, Bankzustände, Zugangsdaten und Finanzdaten nicht lesen, austauschen oder entschlüsseln. Gemeinsamer Systembenutzer plus Unterordner ist kein ausreichender Nachweis.

**BANK-02:** Vor Produktion reale Web-PHP-Version/Erweiterungen, Prozessidentitäten, Datenbankrechte, TLS, Session-Speicher, Cron/Worker und Wiederherstellung prüfen. Getrennte Datenbankkonten, Domainnamen oder `open_basedir` sind allein kein Ersatz für die Betriebsgrenze. Aus einem CLI-Erfolg folgt kein Web- oder Bank-Erfolg.

**BANK-03:** Wenn das vorhandene Paket keinen passenden getrennten Bereich ohne Mehrkosten ermöglicht: Kosten-/Betriebsentscheidung vorlegen. Alternative vorhandener isolierter Rechner oder lokale Banking-Komponente nur mit ausdrücklicher Zustimmung; sie verändert den reinen Web-Ablauf. Kein ungefragter Zusatzvertrag, keine Abschwächung der Sicherheitsgrenze zur Einhaltung des Budgets.

**BANK-04:** Finanzoberfläche separat absichern: eigene Finanzrollen, MFA, kurze Sitzungen, erneute Authentisierung für Konto-/Mandats-/Auftragsänderungen, CSRF-Schutz und Objektberechtigung pro Anfrage. CMS-Superuser ist nicht automatisch Bankberechtigter. Für besonders kritische Einstellungen und Zahlungsfreigaben unabhängige Kontrolle vorsehen.

**BANK-05:** Geheimnisse ausschließlich im isolierten Dienst, verschlüsselt mit getrennt verwaltetem Schlüssel. Im Normalbetrieb Bank-PIN nur für den notwendigen Dialog verwenden, keine dauerhafte PIN-Ablage als Standard. Fortsetzungszustand kann sensible Daten enthalten: authentisiert verschlüsseln, serverseitig, kurze Gültigkeit, an Nutzer/Konto/Dialog/Auftragsversion binden, nach Ende oder Ablauf bereinigen. Schlüsselrotation und Notfallsperre testen.

**BANK-06:** Keine PIN/TAN in Chat, Quellcode, Browser-Storage, URLs, Telemetrie, ungefilterten Debuglogs, allgemeinen Backups oder KI-Eingaben. Browser erhält nur erforderliche Anzeigen und undurchsichtige Vorgangskennungen. Kein Deserialisieren vom Client gelieferter PHP-Objekte. Bankdialoge für Diagnose nur in datensparsamer, bereinigter Form.

**BANK-07:** Bankendpunkte aus bestätigter Konfiguration, nicht frei aus Nutzereingaben. HTTPS mit Host-/Zertifikatsprüfung, begrenzte Zeit/Größe und kontrollierte Weiterleitungen. Keine generische URL-Abruf-API. XML ohne externe Entitäten/Netzzugriffe verarbeiten, Größenlimits und XSD-Prüfung.

**BANK-08:** Öffentliche und normale Vereins-Worker dürfen Zahlungen nicht selbstständig starten. Wiederkehrende Beiträge können Entwürfe und fällige Aufgaben erzeugen; eine berechtigte Person muss den konkreten Zahlungslauf freigeben. Bankseitige Freigabe wird nie umgangen, simuliert oder automatisch bestätigt.

## 6. Fachlicher Ablauf: Beitrag bis Zahlungseingang

1. **Forderungen vorbereiten.** Fällige Beiträge aus versionierten Tarifen berechnen. Bereits erstellte Perioden erkennen, Abweichungen und Austritte prüfen.
2. **Zahler/Mandate prüfen.** Zahlungspflichtiger, Kontoinhaber, IBAN-Prüfung, gültiges Mandat, Status/Datum/Referenz und Vorankündigung. Eine korrekte IBAN-Prüfsumme ist kein Eigentums- oder Mandatsnachweis.
3. **Entwurf anzeigen.** Alle Einzelbeträge, Gesamtbetrag, Konto, Fälligkeit, Mandate, ausgeschlossene Positionen und Warnungen anzeigen. Änderungen erzeugen eine neue Version.
4. **Auftrag fixieren.** Unveränderlichen Payload und Hash erzeugen; Summe/Anzahl gegen die Einzelpositionen prüfen. Freigabe an genau diese Version binden. Bank-/Zahlerdatenwechsel macht vorherige Freigaben ungültig.
5. **Bankdialog führen.** Berechtigte Person startet die Übermittlung. Falls verlangt, TAN eingeben oder Bank-App verwenden; Abbruch/Ablauf korrekt behandeln. App-Freigaben nur innerhalb bankseitiger Vorgaben abfragen.
6. **Antwort festhalten.** Annahme, Ablehnung, einzelne Teilfehler und unbekannten Status unterscheiden. Eine Annahme ist noch kein gebuchter Umsatz.
7. **Umsätze abgleichen.** CAMT/unterstütztes Umsatzformat abrufen, wiederholte Daten deduplizieren, Einzel-/Sammelbuchung und Gebühren zuordnen. Unklare Zuordnung bleibt zur Prüfung offen.
8. **Nachlauf.** Rückgaben und Gebühren als eigene, referenzierte Vorgänge führen. Forderung und mögliche Spendenbestätigung entsprechend prüfen; keine automatische erneute Belastung.

Dieselben technischen Schutzregeln gelten für Überweisungen; deren Erfassung und Freigabe dürfen nicht mit dem Lastschriftmandat verwechselt werden.

**BANK-18 – Empfängerüberprüfung (VoP):** Für Überweisungen ist zusätzlich der aktuelle Namens-/IBAN-Abgleich der Bank in den Freigabeablauf einzubeziehen. Ergebnis, Teilübereinstimmung, Abweichung und nicht mögliche Prüfung müssen vor der Entscheidung sichtbar sein; Änderung der Empfängerdaten erfordert eine neue Prüfung. Kein stilles Opt-out und kein automatisches Fortsetzen bei Warnungen. Die konkrete bankseitig zulässige Entscheidung muss von der berechtigten Person kommen. [Deutsche Kreditwirtschaft/BVR: Empfängerüberprüfung](https://intern.bvr.de/Presse/Deutsche_Kreditwirtschaft/Einfuehrung_der_Empfaengerueberpruefung)

Die integrierte FinTS-Version behandelt die von der Bank gelieferte Empfängerprüfung als eigenen Bestätigungsschritt und zeigt Ergebnis sowie Abweichung vor der TAN/App-Freigabe. Ob das konkrete Institut diesen Schritt liefert und welche Geschäftsvorfälle es für den Zugang freigibt, muss beim ersten echten Test nachgewiesen werden. Lastschrifteinzug und ausgehende Überweisung behalten getrennte Funktionsnachweise.

## 7. Zustände, Wiederholung und Doppelbelastung

**BANK-09:** Pro Bankauftrag und Einzelposition eigene, dauerhafte Zustände. Vorschlag:

```text
ENTWURF → GEPRÜFT → INTERN_FREIGEGEBEN → BANKDIALOG
                                          ├─ FREIGABE_AUSSTEHEND
                                          ├─ BANK_ANGENOMMEN → ABGLEICH → GEBUCHT → ggf. ZURÜCKGEGEBEN
                                          ├─ BANK_ABGELEHNT
                                          └─ STATUS_UNBEKANNT → MANUELLE_KLÄRUNG
```

`FREIGABE_AUSSTEHEND` wird über denselben gespeicherten Dialog fortgesetzt, nicht durch einen neuen Auftrag ersetzt. Jeder Status benötigt Zeit, Akteur/Systemursache und passende Nachweise. Teilannahmen werden auf Positionsebene abgebildet; „alles fehlgeschlagen“ darf nicht irrtümlich alle Positionen erneut einreichen.

**BANK-10:** Vor dem ersten sendenden Bankaufruf Transaktionsabsicht dauerhaft speichern: interne ID, Konto, Payload-Hash, Einzel-IDs, fachlicher Eindeutigkeitsschlüssel, Freigaben und Sperrstatus. Datenbank-Unique-Constraints, atomare Zustandswechsel und exklusive Verarbeitung verhindern Doppelklick/Parallel-Worker. Eine abgelaufene Worker-Sperre darf eine möglicherweise gesendete Zahlung nicht automatisch erneut in die Sendewarteschlange stellen.

**BANK-11:** Lokale Idempotenz ist keine zugesicherte bankseitige Idempotenz. End-to-End-ID dient der Referenz, nicht als Garantie gegen doppelte Belastung. Nach Timeout/Prozessabbruch ab möglicher Übermittlung: Zustand unbekannt; autorisiertes Status-/Umsatzabgleichen und gegebenenfalls Bankklärung. Erst nach nachvollziehbarer Klärung eine neue Einreichung manuell freigeben. Niemals blindes Retry.

**BANK-12:** „Abbrechen“ bedeutet lokal stoppen, soweit möglich, nicht zwangsläufig einen schon angenommenen Bankauftrag zurückrufen. Rückruf/Storno nach Übermittlung ist eine eigene, bankabhängige Aktion; keine Rücknahme zusagen, bevor sie bestätigt ist.

**BANK-13:** Beitrag „bezahlt“ und Spende „eingegangen“ erst durch belastbaren Abgleich bzw. autorisierte manuelle Buchung mit Nachweis setzen. Versandte XML-Datei, freigegebene TAN und angenommener Auftrag sind getrennte Tatsachen.

## 8. Adaptervertrag und Formatregeln

Die Namen sind ein Vorschlag für eine eigene Schnittstelle, keine Behauptung über vorhandene Bibliotheksmethoden:

| Operation | Erwartetes Ergebnis / Schutz |
|---|---|
| `discoverCapabilities` | Kontenberechtigung, Geschäftsvorfälle, Formate, Limits und TAN-Verfahren; kein universelles Leistungsversprechen |
| `readTransactions` | Paginierter/zeitlich begrenzter Umsatzabruf mit normalisierten und nachvollziehbaren Quellbezügen |
| `prepareCollection` | Validierter, noch nicht gesendeter CORE-Auftrag mit Positionen, Prüfsumme und Fälligkeit |
| `prepareTransfer` | Validierter Überweisungsentwurf mit unveränderlicher Freigabeversion |
| `verifyPayee` | Bankseitige Empfängerprüfung und Ergebnisbindung an genau die zur Freigabe angezeigten Daten |
| `beginApprovedOrder` | Nur autorisierten gespeicherten Auftrag starten; Empfängerprüfung, Annahme, Challenge, Ablehnung oder unbekannten Status liefern |
| `resumeChallenge` | TAN-/App-Dialog sicher fortsetzen; Ablauffrist, Eigentümer und Auftragsbindung prüfen |
| `reconcileOrder` | Verfügbare Banknachweise/Umsätze bewerten, unsichere Treffer nicht als Gewissheit verbuchen |
| `disconnect` | Dialog/Sitzung beenden und sensible Kurzzeitzustände löschen; keine Bankrücknahme behaupten |

**BANK-14:** Bankparameter/Berechtigungen (BPD/UPD) auswerten. Keine veraltete `pain.*`-Version, Sammlergröße oder Lastschriftart aus Beispielcode fest verdrahten. Formate, Auftragsarten und Limits im konkreten Kontotest nachweisen. Bei nicht unterstützter Funktion klar blockieren statt auf einen anderen Zahlungstyp ausweichen.

**BANK-15:** Geld intern exakt rechnen. Falls Bibliotheksparameter Fließkomma voraussetzen, Konvertierung nur an der geprüften Adaptergrenze; erzeugte XML-Beträge und Kontrollsumme nochmals dezimal validieren. Ungleiche Summen blockieren die Freigabe. Nicht pauschal „auf zwei Stellen runden“ als Fehlerverdeckung.

**BANK-16:** Umsatzduplikate anhand bankseitiger Referenzen, Konto und nachvollziehbarem Importkontext behandeln. Betrag/Datum/Name allein ist kein eindeutiger Schlüssel. Wiederkehrend identische Beiträge sind legitime getrennte Zahlungen. Gebuchte und vorgemerkte Umsätze unterscheiden; Sammelzahlungen/Fees und Rückgaben brauchen eine eigene Abstimmung.

**BANK-17:** Datei-Fallback: valides SEPA-XML exportieren und im Bankportal durch den Nutzer einreichen; CAMT/CSV kontrolliert importieren. Exportdateien enthalten sensible Daten und müssen geschützt werden. Export allein ist keine Bankeinreichung. Derselbe Auftrag darf nicht zusätzlich per FinTS versendet werden, solange die externe Einreichung nicht eindeutig geklärt ist. Dieser Fallback ist kein Ersatz für die gewünschte integrierte Parität ohne Nutzerentscheidung.

## 9. Mindesttests vor Live-Banking

| Test-ID | Fall | Erwartung |
|---|---|---|
| BT-01 | Falsche Rolle, fremdes Mitglied/Konto, erratene Vorgangs-ID | Serverseitig abgewiesen, kein Datenleck/Bankaufruf |
| BT-02 | Doppelklick, zwei Tabs, parallele Worker | Genau eine lokale Sendeberechtigung; keine zweite Einreichung |
| BT-03 | Absturz vor/nach Sendebeginn und nach Bankannahme vor lokaler Bestätigung | Unsicherheit wird erhalten, kein automatischer Neuauftrag |
| BT-04 | Änderung von Betrag, IBAN, Termin oder Mandat nach interner Freigabe | Freigabe ungültig, neue Prüfung erforderlich |
| BT-05 | TAN falsch/abgelaufen; App wartet/abgelehnt; Sitzung oder Rolle entzogen | Sicherer Abbruch/Fortsetzung ohne Geheimnisleck |
| BT-06 | Teilfehler im Sammler, Limit überschritten, Format nicht unterstützt | Einzelstatus und klare Blockade; kein falscher Gesamtstatus |
| BT-07 | Widerrufenes/fehlendes Mandat, Frist verpasst, Bankfeiertag | Kein unerlaubter Einzug; erklärbare Fehler |
| BT-08 | Identischer CAMT-Import mehrfach; zwei tatsächlich gleiche Beträge | Keine Doppelbuchung und keine verlorene legitime Zahlung |
| BT-09 | Rücklastschrift, Gebühren, Zahlung auf mehrere Forderungen | Korrekte, nachvollziehbare Aufteilung und offene Posten |
| BT-10 | XXE/XML-Bombe, manipulierte URL, Zertifikatsfehler | Abbruch ohne Netzwerk-/Dateizugriff außerhalb des erlaubten Ziels |
| BT-11 | Restore einer Sicherung mit offenen Aufträgen, Worker-Neustart | Sendesperre bis Abgleich; keine Wiederbelastung |
| BT-12 | SEPA-Export plus anschließender FinTS-Versuch desselben Auftrags | Zweite Einreichung verhindert bzw. Klärung verlangt |
| BT-13 | Normale Logs, Fehlerberichte, Backups, Browser-Storage | Keine PIN/TAN, entschlüsselbaren Dialogzustände oder unnötigen Bankdaten |
| BT-14 | Rollenwechsel/Vorstandswechsel, Schlüsseltausch, Notfallsperre | Zugriffe sofort entziehbar, Wiederherstellung nach Verfahren |
| BT-15 | Empfängerprüfung: Treffer, Teiltreffer, Abweichung, nicht verfügbar, Daten nachträglich geändert | Ergebnis vor Überweisungsfreigabe; keine automatische Warnungsumgehung, erneute Prüfung bei Änderung |

Simulationen verwenden synthetische Daten. Ein Test mit echter Bank ist ein eigener, freigegebener Vorgang. Zuerst lesend; erst anschließend ein fachlich vorbereiteter, betragsmäßig konkret genehmigter Zahlungstest. Auch ein Kleinstbetrag ist eine reale Zahlung und benötigt Zustimmung sowie gültige Voraussetzungen.

## 10. Kosten und offene Entscheidungen

| Bestandteil | Planungsstand |
|---|---|
| PHP-FinTS | Lizenzkostenfrei unter MIT; Lizenzhinweise mitliefern |
| FinTS-Produktregistrierung | Laut Anbieter momentan kostenlos; vor Antrag erneut prüfen |
| Zusätzlicher API-Vermittler | Nicht vorgesehen |
| Bank | Tatsächlicher Vereinstarif und Zusatzgebühren unbekannt, Bankbestätigung nötig |
| Isolierter Betrieb | Im vorhandenen Hosting noch nicht nachgewiesen; Mehrkosten nicht freigegeben |
| Pflege, Updates, Backups, rechtliche/fachliche Prüfung | Notwendiger Aufwand; kostenlose Eigenleistung ist eine Annahme, kein Anbieterpaket |
| PayPal/weitere Zahlungsarten/native App-Verteilung | Separater Paritäts-/Kostenpunkt, nicht durch FinTS abgedeckt |

**Fazit:** „Nur noch Bankgebühren“ ist erst belastbar, wenn passende Isolation und Betriebsleistungen bereits ohne zusätzliche laufende Geldausgaben verfügbar sind. Die positive CLI-Teilprüfung genügt dafür nicht. Bis dahin dürfen lokale Vereinsfunktionen und ein simuliertes Banking-Modul entwickelt werden, aber keine Produktivreife behauptet werden.
