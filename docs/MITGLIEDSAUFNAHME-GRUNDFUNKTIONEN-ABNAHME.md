# Abnahme der Mitgliedsaufnahme-Grundfunktionen

Der benannte Softwareumfang K-10 ist vollständig implementiert. Die frühere
pauschale Restnotiz wird durch die folgenden konkreten Originalverträge ersetzt.
Neue Inhalte und eigene Vereinsvorgaben werden redaktionell eingerichtet;
eim früheren CMS-Altbestandimport ist nicht beauftragt.

| Benötigter Ablauf | Vorhandener Fachweg und Prüfung |
| --- | --- |
| Eigenes öffentliches Formular und Freischaltung | `MembershipApplications`, `PublicMembershipForm`, `Web/Membership`, gemeinsame native öffentliche Route; `native-membership`, `native-membership-family`, `native-public-router`. |
| Eigener Vereinsname, individuelle Felder und Hinweise | Aktuelle gemeinsame Identität, konfigurierte Pflicht-/freiwillige/versteckte Felder und ursprüngliche Einwilligung; [Vereinsauftritt](MITGLIEDSANTRAG-VEREINSAUFTRITT.md). |
| Einzel- und Familienbeiträge, eigene Beträge | Gemeinsame `ContributionRates`, ungültig gewordene alte Formulare, ein Familienbeitrag, erhaltene frühere Forderung; ursprüngliche `contribution-rates.php`, `application-family-linking.php`, `families-and-application-pdf.php`. Eigene 60-/66-/90-Euro-Varianten sind ebenfalls geprüft. |
| Persönlicher Antrag und gemeinsame Familie | Getrennte Anträge, Familienzahler und Angehörige, erhaltene Zuordnungsangaben; `native-membership-family`, ursprüngliche Familienverknüpfung und aktuelle `native-admission-choices`. |
| Bestätigung vor Vorstandsvorlage | `ApplicationConfirmation`, einmalige 48-Stunden-Verbindung, getrennte unbestätigte Anfrage, wiederholbare ursprüngliche Bestätigung; `application-confirmation.php`, `registration-workflow.php`, `native-application-mail-preflight`. |
| Eigenständige freiwillige Newsletter-Einwilligung | Eigene bewusste Checkbox, keine automatische Mitgliedschaft oder Newslettereinwilligung durch Familienzuordnung; `native-membership-family` und ursprüngliche Bestätigungs-/Anmeldeverträge. |
| Vollständiger privater Vorstandskatalog | Sechs gezählte Treffer auch jenseits 500, eigene Suche/Status-/Seitenwahl, erhaltene Originalentscheidung und persönliche aktuelle Vorstandsrechte; `native-application-catalogue`. |
| Online-Beschluss oder dokumentierter externer Beschluss | `AdmissionVotes`, `BoardAccess`, `MembershipApplications::review`; ursprüngliche `admission-votes.php`, `admission-board-checks.php`, `admission-access.php`. |
| Familie und Profil bei der Aufnahme bewusst zuordnen | Eigene ursprüngliche Version, sechs gezählte Treffer und vollständige Eingabeerhaltung; [Auswahl und Touchprüfung](MITGLIEDSAUFNAHME-ZUORDNUNG.md). |
| Manuelle Originalanträge und Dokumente | Ursprüngliche PDF-/Scanablage, eigene Einwilligungsbestätigung, pending ohne vorzeitige Aufnahme; `manual-admission.php`, `families-and-application-pdf.php`, aktuelle tatsächliche Auswahlbrowser. |
| Mitgliedschaft, Jahresforderung, Rechnung und Willkommensauftrag | Bestehende Originaldienste, ein Familienbeitrag und keine zweite Familienangehörigenrechnung; `applications.php`, `membership-welcome.php`, `application-family-linking.php`, `registration-workflow.php`. |
| Persönliche Konten und Zugangsmail | Vorhandene native Konto-/Familienidentitäten und Workeraufträge; [Kontoabnahme](KONTEN-GRUNDFUNKTIONEN-ABNAHME.md), `native-membership-preflight`. |
| Lokale Mailfehler und Wiederaufnahme | Bekannte ungesendete Aufträge bleiben vorgemerkt, bestätigte Originale wiederholen sich nicht, unklarer begonnener Transport wird nicht automatisch wiederholt; drei native Antrags-/Mitglieds-Mailvorprüfgruppen. |

Aktuelle Aufnahmeauswahl: 45 Fach-/67 Browserprüfungen, 20 Formularaktionen,
zwei zusätzliche Fehleranfragen und 24 mobile Schriftansichten unter 64 MiB.
Der bisherige Katalog besteht separat 25 Fach-/66 Browserprüfungen und seine
beiden bewusst dokumentierten Originalablehnungen. Die zwölf ursprünglichen
Gruppen enden in getrennten Läufen mit neun beziehungsweise drei erfolgreichen
Gruppen und jeweils leerer Fehlerliste; die native Mailvorprüfung mit
`{"suites":3,"failed":[],"live_transport":false}`. Diese verschiedenen Nachweise
werden nicht als künstlicher Gesamtprozentwert oder neuer vollständiger
240-Gruppen-Lauf ausgegeben. Die öffentliche befüllte Familien-/Formularabnahme
mit 373 HTTP-/Original- und 67 Browserverträgen ist zusätzlich im Vereinsauftritt
benannt. Frühere wegen fehlender lokaler PDF-Konfiguration abgebrochene
Ursprungsprüfungen gelten nicht als bestanden; die erfolgreichen Läufe verwenden
die vorhandene eingerichtete ReportLab-Laufzeit.

Die Software ist von einer realen Postfach-/Anbieterzustellung getrennt. Die
lokalen Prüfungen erfassen ausschließlich eigene synthetische Anträge und lokale
Prüfempfänger. Private Vereins- und Einwilligungsoriginale werden nicht importiert
oder ersetzt. Übrige Fach-/UI-Arbeit, Apps und gemeinsame Statusseite behalten
ihren eigenen Stand. Keine neue Tabelle; Generation 2026101514, 240 Suiten.
