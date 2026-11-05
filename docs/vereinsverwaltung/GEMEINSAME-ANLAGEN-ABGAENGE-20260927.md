# Gemeinsam genutzte Anlagen: Abgänge und Folgejahre

Stand 27.09.2026. Entwicklung und isolierte Prüfung; keine Veröffentlichung.
Kulturhus bleibt EÜR. Die allgemeine Vereinsbilanz ist damit noch nicht abgenommen.

## Implementiert

Die Nutzungsaufteilung innerhalb derselben steuerlichen Sphäre berücksichtigt
jetzt belegte vollständige Ausmusterungen, Verkäufe mit Gewinn oder Verlust,
beendete Softwarelizenzen und den bereits unterstützten belegten Teilabgang
einer linear abgeschriebenen Komponente. Lineare, bestätigte GWG- und degressive
Originalpläne behalten ihre bisherigen Voraussetzungen. Die Nutzungsteilung
führt weder ein neues steuerliches Wahlrecht noch zusätzliche Gegenstände ein.

Alle elf Bewegungswerte werden mit dem ursprünglichen Anlagenverzeichnis
abgestimmt. Verkaufsbeträge und ausgebuchte Restwerte müssen zusätzlich je
Betrieb auf den nachgewiesenen Originalkonten stimmen. Die Gewinn-/Verlustseite
eines Verkaufs richtet sich weiterhin nach dem ursprünglichen ganzen Gegenstand,
nicht nach eventuell unterschiedlich gerundeten Teilbeträgen.

Beim Teilabgang werden abgeschriebener Anteil und Restwert getrennt innerhalb
der bisherigen Wertanteile verteilt. Eine begrenzte monotone Höchstzahlverteilung
verhindert negative Restanteile. Die weitere Abschreibung setzt genau an diesem
festen Abgangsstand an. Weder Vorjahreswerte noch Anschaffungsplan werden neu
aufgeteilt. Alle Centbeträge bleiben erhalten; Folgejahre übernehmen den
ursprünglichen Abgangsnachweis unverändert und buchen keinen zweiten Abgang.

Oberfläche und Arbeitsunterlagen zeigen den Abgangstyp und den ausgebuchten
Restwert im jeweiligen Jahr. Die CSV enthält zusätzlich ursprüngliche Kosten,
kumulierte Abschreibung und Buchwert des Abgangs. Rollen, Vorstandsfreigabe,
Vereinsgrenzen, EÜR-Sperre und Versionsschutz bleiben bestehen. Der neue Rechenweg
ist Bestandteil des Freigabe-Fingerprints.

## Ausgeführte Prüfungen

- 8.428 PHP-Assertions: acht Abgangsvarianten, Folgejahr, Quellenmanipulation,
  verschobene Verkaufs-/Restwertanteile, Grenzen und Centbeträge. Darunter
  systematische Gegenprüfung der begrenzten Rundung mit einem langsamen
  Einzelcent-Verfahren und 60 kleine Komponentenfälle mit Vorjahr und gesamter
  Restnutzungsdauer. Das sind Assertions, nicht 8.428 unterschiedliche Steuerfälle.
- Sieben unabhängige Python-Testmethoden; 78 Profil-/Variantenkombinationen
  (SGEB, Gesamtverein und kombiniert), zusätzlich acht Nebenrechnungsfälle mit
  offenem internem Vorschuss. Einschließlich abweichender linearer Steuerwerte,
  deren Vortrag, strenger Belegprüfung und Rundungsgrenzen. 61 bestehende
  Python-Regressionsmethoden zusätzlich bestanden.
- 26 kombinierte und acht Nebenrechnungs-E-Bilanzen mit nativer ERiC-Prüfung:
  alle Code 0, einschließlich abweichender Steuerwerte und Verrechnungen.
  Nur Validierung, keine Zertifikate und keine Übermittlung.
  Nachweise: `audit/ebilanz-shared-disposal-native-20260927.json` und
  `audit/ebilanz-shared-disposal-subsidiary-native-20260927.json`.
- Isolierter Webserver-/MySQL-Lauf erfolgreich:
  `audit/ebilanz-mysql-host-20260918-c9819ce89fce92d9.json`.
  Quellenpaket: `a2cff2f792c09578cd78d78c753645b755b3eda2f6ba93f0b9e083fe3f165ef4`.
  92 Workflow-Assertions einschließlich bestehender Teilabgangsbuchung und neuer
  gespeicherter Nutzungsaufteilung über 2025/2026/2027; außerdem 8.428 neue,
  5.740 bestehende Anlagen- und 48 Nebenrechnungs-/Freigabeprüfungen.
  Tatsächliche PHP-Ansicht, Escaping, Rollen und unverändertes Originaljournal
  geprüft. Das ist kein beobachteter Nutzertest oder umfassender Browser-/Mobiltest.
- Die dauerhaft freigegebene synthetische Datenbank wurde direkt weiterverwendet:
  keine erneute Sicherung oder Rücksicherung. Keine Verbindung zur Produktiv-DB,
  geschützte Produktivdateien unverändert; privates Testverzeichnis entfernt.
- 112 Laufzeit-Fingerprint-Assertions; 55 Fingerprint-Dateien und 14 Python-
  Abhängigkeiten geprüft. 1.025 PHP-Dateien ohne Syntaxfehler.

## Weiterhin gesperrt/offen

Sphärenübergreifende Vermögens-/Kostenaufteilung, Nutzungswechsel, mehrere
Teilabgänge desselben Gegenstands und weitere bislang nicht unterstützte
Anlagenarten werden hiermit nicht freigeschaltet. Insbesondere sind ein
belegter Komponentenabgang, eine pauschale Wertminderung und eine Schenkung
nicht austauschbar. Die allgemeine Kontenabdeckung, abweichende Wirtschaftsjahre,
Korrekturabschlüsse und abschließende Gesamtabnahme bleiben Release-Voraussetzungen.
Rechentests für Folgejahre schalten keine zusätzlichen ELSTER-Erklärungsjahre frei.
