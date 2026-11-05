# Native CMS-Plattform: Funktionspaket vom 15. Oktober 2026

Dieses Paket implementiert P-01, P-05, P-06 und P-19 vollständig im nativen
Vertrag. P-02/P-03/P-04/P-07/P-11/P-12 erhalten weitere geprüfte Funktionen.
Paketgeneration 2026101526, CMS 18, Sprachmodul 1, 268 registrierte Kerngruppen.
Die P-Matrix zählt sieben implementierte und 18 teilweise implementierte Punkte.
Die K-Matrix bleibt bei 34 implementierten Punkten und dem entfallenen K-16.
Das gesamte Projekt ist damit weiterhin nicht vollständig abgeschlossen.

## Tatsächliche Funktionen

- Geschützte Seitenhierarchie und Lebenszyklus, atomare Kopien bis 500 Seiten,
  wiederaufnehmbare Kopiervorbereitung bis 5000 Seiten, bewusste Bereinigung
  geschlossener Aufträge und referenzsicheres endgültiges Löschen.
  Sprachoriginale werden gemeinsam entfernt; alte Adressen bleiben reserviert.
  [Verträge](CMS-SEITEN-LEBENSZYKLUS.md).
- 17 frei definierbare Typen, Mehrfachwerte, Repeater bis drei Ebenen und
  PageTable mit stabilen Itemkennungen/-versionen und eigenen Bearbeitungsrechten.
  Passwortwerte verlassen die entsprechenden internen Fachdienste nicht.
  [Feldverträge und Bedienung](CMS-MEHRFACHWERTE-DATENSAETZE.md).
- Vorlagenkontexte, Familienregeln, kumulative Seiten-/Vorlagenrechte,
  öffentliche Sichtbarkeit, Theme- und Cachezuweisung; aktuelle Regeln gelten
  auch für Bilder/PDFs, Listen, Feeds und bereits gefüllte Caches.
  [Vorlagenregeln](CMS-VORLAGENREGELN.md).
- Versionierte Sprachen, Fallbacks, eigene Sprachrechte, Originalbindung und
  Freigabe der Übersetzungen, lokalisierte Pfade und tatsächliche Websiteausgabe
  einschließlich öffentlicher Suche. Konto und ausgewählte Navigation sprechen
  Deutsch/Englisch; die Übersetzung aller weiteren Fachoberflächen bleibt offen.
  [Verwaltung](MEHRSPRACHIGE-WEBSITE.md), [Ausgabe](CMS-SPRACHDARSTELLUNG.md),
  [Suche](CMS-OEFFENTLICHE-SPRACHSUCHE.md).
- Kompakter konfigurierbarer Lister mit exakter Zählung, privaten versionierten
  Suchansichten, typisierten Filtern/Sortierung/Spalten und bewusster Vorbereitung
  des vorhandenen originalgebundenen Papierkorbstapels.
  [Bedienung](CMS-INHALTSLISTEN.md).
- Originalgebundene Frontendbearbeitung aus tatsächlicher privater Vorschau,
  ein kanonischer Feldsave, erhaltende Konflikt-/Fehlerwege und gesonderte
  ausdrückliche Veröffentlichung der gespeicherten Gesamtfassung.
  [Bedienung](CMS-FRONTEND-BEARBEITUNG.md).

## Nachweis und Korrekturen

Alle Prüfbestände sind neue eigene synthetische MySQL-Datenbanken. Es wurden
keine echten Mails, Zahlungen, Steuer-/Bank-/Socialaktionen oder Veröffentlichungen
ausgelöst. Bestehende Vorschauen wurden weder migriert noch neu befüllt.

Die Prüfungen liefen aus unveränderlichen Git-Index-Exporten. Laufende spätere
API-/Datei-/Rechte-/Kommentar-/Routingarbeiten wurden nicht hineinkopiert.

| Prüfung | Ergebnis |
|---|---|
| Erster unveränderter Funktionslauf | 47 Gruppen, 45 bestanden; zwei Fehler ausdrücklich erhalten |
| Korrigierter Funktionsnachweis | zehn Gruppen bestanden: Album-Restore, große Kopieraufträge mit beiden echten Apps, öffentliche Assetkanäle, Feedbilder, Nachrichtenkanäle, Mediennutzung, PDFs, öffentlicher Router, Veröffentlichung und öffentliche Alben |
| Separate echte native Eingänge | drei Gruppen bestanden: Inhaltslisten, Frontendbearbeitung, typisierte Felder; jeweils frischer eigener Bestand |
| Installer einschließlich CMS-17→18 | 256 Bedingungen bestanden; komplette frühere Geschäftstabellen bleiben exakt |
| Upgrade einschließlich echter Browserweiterführung | 150 Bedingungen bestanden; keine externe Aktion |
| Verschlüsselte Sicherung und Wiederherstellung | 221 Bedingungen bestanden; tatsächliche Quellen, Schlüssel und Originalbytes erhalten |
| Unterbrochene Wiederherstellung | 27 Bedingungen und drei tatsächliche Prozessabbrüche bestanden |
| Offline-Inventar und Extraktion | sechs Inventar- und neun Paketprüfungen; vollständige Byteprüfung des dokumentierten Git-Dateibestands |

Die zwei ersten Fehler wurden nicht nachträglich aus dem Lauf entfernt:
Der Album-Restore musste zusätzlich den aktuellen Vorlagenrenderer prüfen.
Außerdem fehlte die tatsächliche Lifecycle-Browserdatei im ersten Indexexport;
sie ist jetzt ausdrücklich Teil des Pakets. Der ergänzte öffentliche Kanaltest
belegte eine weitere echte Lücke: Ein RSS-only-Beitrag erlaubte auch seine
weiteren Bilder. Jetzt autorisiert RSS ausschließlich sein tatsächliches
Titelbild; Websiteverwendungen werden eigenständig geprüft.

Die erweiterte Installerfixture benötigte zwei reine Prüfkorrekturen:
Den vorhandenen Originalhashhelfer vor seinem ersten neuen Aufruf laden und
leere neue Tabellen in umgekehrter Fremdschlüsselreihenfolge entfernen. Der
anschließende unabhängige Installerlauf besteht alle 256 Bedingungen.
Diese späteren Belege werden keinem früheren unveränderten Gesamtlauf zugerechnet.

Die direkten Appmatrizen umfassen zusammen 530 Featureansichten und 8909
Browserbedingungen: Inhaltslisten 96/3050, Frontendbearbeitung 98/1966,
Sprachen 144/2061, Vorlagenregeln 120/1262, typisierte Felder 24/196 und
Seitenlebenszyklus 48/374. Dazu kommen 40 Bedingungen des separaten typisierten
HTTP-Editorwegs. Handy/Tablet/Desktop, normale/verdoppelte Schrift und
JavaScript/kein JavaScript sind enthalten. Originale in 392 bis 401 fremden
Tabellen sowie vorhandene Revisionen, Schlüssel und Mediendateien bleiben exakt;
kein externer Request oder JavaScriptfehler wurde beobachtet.

Private vollständige Prüflogs sind durch SHA-256 festgehalten:

- Erster 47-Gruppen-Lauf, einschließlich beider Fehlschläge:
  `b32f955738e39140738c27fd9220f77d797e16feab9d99de924f5d715ff63cc4`.
- Korrigierte zehn Funktionsgruppen:
  `c66654e83e008999af6fd083d55042efdc6ba52063be35e696ba7844e7b9485d`.
- Separate drei native Eingänge:
  `58d9aa7f69639dd30fc0e4551a1edaebc0e222b0803ab2a2ef1157727766ba05`.
- Abschließender Installer:
  `8d10b0c93e279ef5f22c2773bebb0710d7cfa3a0ee7ac89a8efa79d251da735d`.

## Offene Grenzen

Allgemeine API, kumulative Feldrechte, allgemeine Dateiversionen, weitere
Routingverträge, Kommentare, registrierbare Fieldtypes/Renderer und die übrigen
P-Verträge werden als eigene Funktionen weiter umgesetzt. Die Original-ERiC-
Bibliothek sowie Xcode-/physische iOS-/Android-Abnahmen fehlen weiterhin.
Gezählte Suiten und implementierte Teilbereiche sind keine Produkt-Gesamtquote.
