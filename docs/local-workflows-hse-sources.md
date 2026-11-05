# HSE-Referenzen und lokale Formularvorlagen

Prüfstand: 7. Oktober 2026. Arbeitsverzeichnis: `C:/Users/wehla/.codex/worktrees/eb9a/Kulturhus`, Branch `layoutauswahl`. Diese Recherche hat ausschließlich öffentlich zugängliche Quellen lesend geprüft. Es wurden keine Konten verwendet, keine Formulare abgeschickt und keine Markenassets übernommen.

## Ergebnis

Die HSE-Grundstruktur einer Gefährdungsbeurteilung ist als fachliche Referenz mit OGL-Herkunft verwendbar. Eine HSE-Urheberschaft oder offizielle HSE-Vorlage namens **Stop Think Act** wurde bei dieser Recherche nicht belegt. Die lokale zweite Vorlage ist deshalb ausdrücklich ein eigener Kurzcheck. Beide Vorlagen enthalten eigene deutsche Formulierungen und keine tatsächlichen Betriebsdaten.

Die Ressourcen liegen in `vereinsverwaltung/resources/document-workflows/hse-templates.json`. Sie sind Daten für eine CMS-unabhängige Formularschnittstelle. Aus einer internen Prüfung oder Freigabe des Dokuments folgt keine sicherheitsfachliche Freigabe einer Tätigkeit.

## Öffentlich geprüfte Quellen

| Quelle | Befund und Verwendung |
| --- | --- |
| [HSE: Risk assessment template and examples](https://www.hse.gov.uk/simple-health-safety/risk/risk-assessment-template-and-examples.htm), Seitenstand 10.06.2024 | Offizielle Ausgangsquelle für Betroffene, bestehende Schutzmaßnahmen, zusätzliche Maßnahmen, Zuständigkeit und Frist. HSE warnt vor unverändert übernommenen Beispielen: Die konkreten Gefährdungen und Maßnahmen müssen zum Betrieb passen. |
| [HSE: Steps needed to manage risk](https://www.hse.gov.uk/simple-health-safety/risk/steps-needed-to-manage-risk.htm), Seitenstand 10.06.2024 | Ergänzt die Felder für Gefährdungen, Einschätzung und Überprüfung. Die Wirksamkeit von Maßnahmen und Änderungen der Arbeitsbedingungen gehören zur Überprüfung. |
| [HSE: Copyright](https://www.hse.gov.uk/help/copyright.htm), Seitenstand 29.08.2025 | Crown-Sachinformationen sind grundsätzlich unter OGL wiederverwendbar. Fremde Abbildungen und das HSE-Logo sind davon nicht pauschal umfasst. Die lokale Ressource enthält weder Logo noch Abbildungen. |
| [The National Archives: Open Government Licence v3.0](https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/) | Erlaubt unter Bedingungen auch Bearbeitung und kommerzielle Nutzung. Quellenanerkennung und Lizenzverweis gehören dazu. Eine amtliche Billigung darf nicht suggeriert werden; Dritt- und Markenrechte bleiben ausgenommen. |
| [UMASH: Farm Safety Check – STOP-THINK-ACT](https://umash.umn.edu/farm-safety-check-stop-think-act/), April 2020 | Der veröffentlichende Universitätsverbund nennt WSPS und Imperial Oil für seine Kampagnenfassung und weist auf eine erteilte Erlaubnis hin. Das belegt weder eine HSE-Urheberschaft noch die ursprüngliche Erfindung des allgemeinen Methodenbegriffs. Nur Herkunftsabgrenzung; kein Kampagneninhalt übernommen. |
| [WSPS: Website Terms of Use](https://www.wsps.ca/about-us/policies/website-terms-of-use) | Keine allgemeine offene Lizenz. Die beschränkte Erlaubnis betrifft unter anderem bestimmte zugelassene Endnutzer und nichtkommerzielle Bildungszwecke. Sie wird hier nicht als Erlaubnis zum Kopieren oder Übersetzen ausgelegt. |

Die HSE-Seite verlinkt [eine DOCX-Vorlage](https://www.hse.gov.uk/simple-health-safety/assets/docs/risk-assessment-template-2019.docx) und [eine ODT-Vorlage](https://www.hse.gov.uk/simple-health-safety/assets/docs/risk-assessment-template.odt). Der Recherche-Reader konnte diese Binärformate nicht lesen. Es wurde deshalb keine heruntergeladene Vorlage verarbeitet: Die eigene Feldstruktur stützt sich auf die oben lesbaren HSE-Webseiten. Der direkte Reader-Aufruf der National-Archives-Lizenz antwortete mit HTTP 403; der vollständige vom Suchdienst gelieferte Text derselben offiziellen Lizenzseite war lesbar. Diese Abrufgrenzen sind kein Nachweis einer künftigen Quellenverfügbarkeit.

## Herkunft und Nutzungsbedingungen im Produkt

Für `hse-risk-assessment` steht die von HSE bevorzugte Anerkennung in `provenance.attribution`:

> Contains public sector information published by the Health and Safety Executive and licensed under the Open Government Licence.

Quellenlink, Lizenzlink, Änderungsbeschreibung und Prüfdatum sind ebenfalls Teil der Ressource. Die Anwendung sollte diese Angaben bei Auswahl, Ansicht und Export der Vorlage beziehungsweise des ausgefüllten Dokuments erhalten. Die Lizenz betrifft die verwendeten HSE-Sachinformationen; sie legt keine Lizenz für den gesamten Vereinsmanager fest.

Für `stop-think-act-own` verweisen `url` und `licence_url` auf den Herkunftsbeleg und die Nutzungsbedingungen der nicht übernommenen Kampagnenreferenz. Das Feld `licence` sagt ausdrücklich, dass keine Wiederverwendungslizenz für diese Materialien beansprucht wird. Es darf nicht als Kennzeichen einer OGL-lizenzierten HSE-Vorlage ausgegeben werden. Fragen, Feldzuschnitt und Hinweise sind eigene deutsche Formulierungen; Poster, Kampagnentexte, Logos und Gestaltung wurden nicht kopiert oder übersetzt.

Am Prüftag wurden unter anderem die Suchanfragen `site:hse.gov.uk "Stop Think Act"` und `site:hse.gov.uk "Stop" "Think" "Act"` geprüft. Kein Treffer belegte die gesuchte offizielle HSE-Vorlage. Das ist ein begrenztes Rechercheergebnis, kein Beweis, dass HSE den Ausdruck niemals verwendet hat. Der alte aus UMASH verlinkte WSPS-Pfad leitete inzwischen zum [Agriculture & Horticulture Safety Centre](https://www.wsps.ca/how-we-help/agriculture-safety-centre) um.

## Formularvertrag und Grenzen

- Top-Level: Array mit zwei Vorlagen. Jede hat `key`, `title`, `description`, `provenance` und `fields`.
- Herkunft: `title`, `url`, `licence`, `licence_url`, `attribution`, `adaptation`, `verified_at`.
- Felder: `key`, `label`, `type`, `required`, optional `help` und bei Auswahllisten `options`. Die Ressourcen nutzen `text`, `textarea`, `date` und `select` aus dem vereinbarten Typvorrat.
- Beide Vorlagen besitzen `hazard`, `control`, `action`, `owner`, `deadline` und `review`. Die Gefährdungsbeurteilung erfasst zusätzlich Betroffene, Einschätzung, Kontext, Wiedervorlage und offene Annahmen.
- Mehrere Gefährdungen werden in Textfeldern über manuell gepflegte Kennungen zugeordnet. Es gibt keinen Repeater und keine automatische Prüfung, ob G1 in allen Feldern vorkommt. Insbesondere die Fristen der Gefährdungsbeurteilung sind Text je Kennung, noch keine terminierbaren Einzelmaßnahmen.
- Der Kurzcheck hat nur dokumentierende Entscheidungen. Keine Option erklärt Arbeit für sicher. Der Bezug zur Gefährdungsbeurteilung ist ein Textverweis, noch kein Fremdschlüssel.
- Pflichtfelder prüfen Vollständigkeit im Formular, weder fachliche Richtigkeit noch tatsächliche Wirksamkeit. Keine automatische Risikomatrix, keine elektronische Unterschrift und keine rechtliche oder sicherheitsfachliche Zertifizierung.
- Für einen realen Betrieb fehlen konkrete Tätigkeiten, Orte, Beteiligte, Zuständigkeiten, vorhandene Maßnahmen, Qualifikationen und anzuwendende betriebliche Regeln. Bis zur fachkundigen Anpassung sind nur synthetische lokale Beispiele vorgesehen. Aus den britischen Quellen wird keine pauschale Erfüllung deutscher Anforderungen abgeleitet.

## Prüfung der Ressourcen

Die JSON-Ressource wurde lokal als UTF-8 eingelesen und gegen den vereinbarten Strukturvertrag geprüft: zwei eindeutige Vorlagen, je 15 eindeutige Felder, vollständige Herkunftsangaben, zulässige Feldtypen, boolesche Pflichtfeldangaben und nichtleere Auswahlwerte. Beide Vorlagen enthalten alle sechs vereinbarten Gefährdungsfelder. Diese Prüfung bewertet die Datenstruktur; eine fachliche Betriebsabnahme wurde nicht durchgeführt.
