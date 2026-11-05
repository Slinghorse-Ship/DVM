# Mehrsprachige Website und Oberflächensprache

Stand: 18. Oktober 2026. P-12 besitzt einen eigenen nativen Sprachdienst.
Die Sprachverwaltung öffnet unter **Einstellungen → Sprachen**. Sie arbeitet
mit nativer Anmeldung, aktuellen Vereinsrechten, CSRF und gewöhnlichen Formularen
auch ohne JavaScript. Website-Sprachen und die persönliche Oberflächensprache
sind getrennte Entscheidungen.

## Website-Sprachen

Die Verwaltung legt bis zu zwanzig Sprachkürzel, Namen, Aktivierung und eine
Rückfallsprache fest. `en-gb → en → de` ist beispielsweise möglich. Fehlende,
deaktivierte oder kreisförmige Rückfälle werden abgewiesen. Bestehende Sprache
deaktivieren; ihre bisherigen Adressen bleiben gegen Wiederverwendung geschützt.
Eine veröffentlichte Originalsprache wird nicht durch Umbenennen der
Konfiguration neu interpretiert. Konfigurationsänderungen erhalten eigene
unveränderliche Fassungen.
Sprachkürzel sind höchstens sechzehn Zeichen lang; Sprach-, Schrift- und
Regionsangaben wie `zh-hant-tw` werden nach demselben Vertrag geprüft.

Originalseiten behalten ihre bisherige Adresse, beispielsweise
`/ueber-uns/`. Eine englische Übersetzung kann `/en/about/` verwenden.
Die interne `/site/`-Adresse bleibt als Websitezugang verfügbar; die kanonischen
Adressen und Sprachwechsel verwenden die vorhandenen öffentlichen Kurzpfade.
Eine bestehende Originalseite oder frühere Originaladresse darf keinen neuen
Sprachpräfix blockieren. Neue Originalseiten und frühere Adressen können
reservierte Sprachadressen nicht übernehmen. Ein einmal veröffentlichter
übersetzter Pfad bleibt seiner ursprünglichen Seite zugeordnet. Seine
Weiterleitung führt direkt zur weiterhin gültigen aktuellen Übersetzung.

In der Übersetzungsansicht zunächst Sprache und Seite wählen. Die Liste zeigt
höchstens zwanzig Treffer, die eigene Suche bleibt erhalten. Jede Übersetzung
benennt ihre genaue Originalfassung. Einzelne Textfelder auswählen und
übersetzen; nicht ausgewählte Felder verwenden den festgelegten Rückfall.
Weitere Text- und Richtextfelder sowie vorhandene Inhaltsabschnitte besitzen
eigene Eingaben. IDs, Bilder, Links, Referenzen, Zahlungsdaten und Beträge bleiben
in ihrer ursprünglichen Fassung. Formatierter Text wird erneut bereinigt.
Auf der Startseite lassen sich auch Einstieg, Postertexte, Hervorhebungen und
Abschnittsüberschriften übersetzen. Reihenfolge, Anzahl und Verknüpfungen des
ursprünglichen Startseitenaufbaus bleiben erhalten. Die festen Beschriftungen für
Startseite, Navigation, SEO und Abschnittstexte folgen der persönlichen deutschen
oder englischen Oberflächensprache. Eigene Feldnamen und gespeicherte Inhalte
bleiben wörtlich erhalten. Unter **Originalfassung** lässt sich zu jedem
Startseitentext der ursprüngliche Wert direkt neben der Übersetzung aufklappen. Öffentliche Beitrags-,
Veranstaltungs- und Galerielisten verwenden die bestätigten Sprachfassungen;
die Veranstaltungssuche berücksichtigt deren aktuelle Titel und Orte.

**Speichern** erstellt nur einen privaten Übersetzungsentwurf.
**Veröffentlichen** und **Zurückziehen** sind eigene bestätigte Aktionen mit
eigenem Recht und Versionsabgleich. Eine Übersetzung kann ausschließlich zur
genau passenden aktuell veröffentlichten Originalfassung erscheinen. Nach
Veröffentlichung einer neuen Originalfassung alte Übersetzung anhand der
gewählten neuen Originalfassung prüfen, speichern und erneut veröffentlichen.
Ein privater Originalentwurf verändert die bisher öffentlich gültige Übersetzung
nicht. Zurückgezogene, archivierte und gelöschte Originalseiten erscheinen auch
über Sprachpfade nicht.

Der öffentliche Rückfall liest ausschließlich veröffentlichte Original- und
Übersetzungsrevisionen. Bei `en-gb → en → de` können einzelne fehlende Felder
auf Englisch und anschließend auf das deutsche Original zurückfallen.
Unbestätigte Übersetzungen und private Originalentwürfe gelangen nicht in diese
Ausgabe. Öffentliche Sprachwahl und `hreflang` nennen ausdrücklich veröffentlichte
aktuelle Sprachadressen. Sprachkennzeichnung, kanonische Adresse und Navigation
werden im nativen Renderer erzeugt; öffentliche Cachegenerationen werden bei
relevanten Freigabe-, Rücknahme- und Konfigurationsänderungen ungültig.

## Abgewiesene Anfragen erneut prüfen

Fehler und Bestätigungen folgen ebenfalls der persönlichen Oberflächensprache.
Nach einer abgewiesenen Speicherung zuerst den gespeicherten Stand prüfen.
**Neu laden** öffnet die betreffende Sprache und Seite oder den ursprünglichen
Einstellungsbereich. **Sprachübersicht** führt aus einer nicht mehr verfügbaren
Auswahl zurück. Diese Links lesen den Stand; sie lösen keine neue Speicherung
oder Veröffentlichung aus. Ein ungültiger Seitenbezug führt zur Seitenliste,
eine nicht mehr gültige Sprachpaketseite zum Beginn des Pakets.

## Rechte und ursprüngliche Fassungen

Website-Verwaltende konfigurieren Sprachen, Rechte und Sprachpakete. Andere
Redakteure benötigen sowohl das ursprüngliche Website-Bearbeitungsrecht als
auch **Bearbeiten** für die betreffende Sprache. Veröffentlichen benötigt beide
ursprünglichen und sprachbezogenen Veröffentlichungsrechte. Eine Person mit
englischem Recht erhält dadurch kein Recht für `en-gb`. Widerrufene Rechte
werden innerhalb der gesperrten Schreibtransaktion erneut geprüft.

Bei aktivierter Vier-Augen-Regel für Beiträge muss eine andere Person mit
Sprach-Veröffentlichungsrecht die Übersetzung freigeben. Die unveränderliche
Übersetzungsfreigabe hält Regelversion, Autor, prüfende Person und exakte
Originalfassung fest. Die ursprüngliche Beitragsfreigabe bleibt unverändert.

Übersetzungshistorien zeigen zwanzig Fassungen je Seite. Wiederherstellen legt
eine neue private Fassung an und ändert die aktuelle öffentliche Übersetzung
nicht. Einzel- und Teilbaumkopien übernehmen Sprachtexte als private neue
Fassungen mit Herkunft und ausdrücklichem Prüfbedarf. Vor einer Freigabe müssen
sie geprüft und für die neue Originalfassung gespeichert werden; frühere
Routen und Freigaben werden nicht mitkopiert. Fortsetzbare Teilbaumkopien binden
zusätzlich einen Fingerabdruck der gewählten Sprachfassungen. Eine zwischenzeitliche
Sprachänderung pausiert den Auftrag ohne teilweise angelegte Zielseiten.
Die ausdrücklich bestätigte endgültige Löschung einer Originalseite entfernt
ihre eigenen Sprachfassungen in derselben Transaktion. Aktuelle, historische
und bereits gespeicherte vorgeschlagene Sprachadressen bleiben dauerhaft
reserviert. Fremde Verknüpfungen, historische Kopiernachweise und unabhängige
Geschäftsoriginale sperren die Löschung weiterhin. Ein fehlgeschlagener Vorgang
behält Original- und Sprachfassungen gemeinsam.

## Persönliche Oberflächensprache und Sprachpakete

Die Sprachverwaltung bietet Deutsch und Englisch. Die persönliche Auswahl gilt
für die eigene aktive native Identität im eigenen Verein. Ein anderer Benutzer
und ein anderer Verein behalten ihre Auswahl. Installierte Deutsch-/Englischpakete
bleiben unabhängig davon verfügbar, ob die Website eine öffentliche englische
Fassung anbietet. Für weitere aktivierte Sprachen lassen sich explizite
Nachrichtenschlüssel überschreiben. Die Bearbeitung zeigt höchstens zehn Texte
je Seite und speichert Änderungen als neue Konfigurationsfassung. Unbekannte
Schlüssel und ausführbarer Code sind ausgeschlossen. Ausgaben werden jeweils
am vorgesehenen Ort maskiert, nicht nachträglich im gesamten HTML ersetzt.
Die persönliche Auswahl ist außerdem unter **Einstellungen → Konto →
Oberflächensprache** erreichbar. Sie führt zum eigenen nativen Kontobereich mit
dem dort gültigen CSRF-Schutz. Für diese persönliche Auswahl ist keine
Website-Verwaltungsberechtigung erforderlich. Auch die nicht angemeldeten
nativen Zugangsformulare bieten eine feste Deutsch-/Englischauswahl.

**Abnahmegrenze:** Der explizite Deutsch-/Englischkatalog deckt zunächst diese
Sprachverwaltung, die festen Navigationsgruppen und bekannten Menüeinträge,
sowie die zentralen nativen Konto- und Anmeldeformulare ab. Ergänzt sind beide
gemeinsamen Verwaltungsrahmen einschließlich eingebetteter Website-Tabs, Suche,
Dialogen, Ladefehlern, Touch-Zielen und Wechsel der tatsächlichen Dokumentensprache.
[Bedienung und Prüfgrenzen](VERWALTUNGSRAHMEN-SPRACHE.md). Die vorhandenen
Fachansichten und einige eingebettete Konto-Unteransichten enthalten weiterhin
deutsche Texte. Eine vollständig umschaltbare
gesamte Verwaltungsoberfläche ist damit noch nicht abgenommen; ihre übrigen
Beschriftungen müssen einzeln an den Nachrichtenkatalog angeschlossen und in
echten Fachansichten geprüft werden. P-12 darf deshalb nicht allein wegen des
implementierten Sprachmoduls als vollständige Gesamtoberflächenübersetzung
gekennzeichnet werden.

Der verbleibende Anschluss wird in prüfbaren Modulgruppen umgesetzt:

1. Übrige eingebettete Konto-, Sicherheits- und Benachrichtigungsansichten;
   die gemeinsamen Rahmen-, Such-, Website-Tab- und Dialogtexte sind angeschlossen.
2. Website/CMS einschließlich Medien, eigener Felder, Vorlagen und Versionen.
3. Mitglieder, Organisation und Kalender.
4. Finanzen, Bank, Belege und Beiträge.
5. Kommunikation, Social Hub und Chat.
6. Workflows, Gefährdungsbeurteilung/-prüfung, Status und Systemverwaltung.

Jede Gruppe erhält eigene feste Katalogschlüssel und explizite Anschlüsse an
den tatsächlichen Textstellen. Bestehende fachliche Fehler erhalten am
Controller einen definierten Nachrichtenschlüssel; Nutzereingaben werden als
getrennte maskierte Werte ausgegeben. Noch nicht angeschlossene Texte bleiben
als tatsächliche Lücke erkennbar. Je Gruppe gehören Deutsch-/Englischbedienung,
aktuelle Rechte, echte Fehlerfälle, Bedienung ohne JavaScript und auf kleinen
Displays sowie unveränderte ursprüngliche Daten zur Abnahme. Erst nach diesen
Anschlüssen kann die gesamte Adminsprachpaket-Anforderung abgeschlossen werden.

## Installation und lokale Prüfung

Schema `language` Version 1 ergänzt acht eigene Tabellen. Installation erfolgt
nur im ausdrücklichen Wartungsweg, niemals beim Öffnen einer Seite. Eine normale
Wiederaufnahme installiert und befüllt keine Daten. Die Sprachverwaltung
verändert keine ursprünglichen Seitenrevisionen oder Schlüssel und führt keine
Anbieter-, Versand-, Bank-, Zahlungs- oder Steueraktion aus.

```sh
bash tools/ci/local-native.sh --core native-languages
DVM_LANGUAGES_BROWSER=1 bash tools/ci/local-native.sh --core native-languages
```

Die Fachprüfung verwendet ausschließlich frische eigene MySQL-Testbestände.
Sie prüft Originalbytes, sprachbezogene Rechte einschließlich Entzug während
eines Schreibversuchs, private und öffentliche Fassungen, Fallbacks,
Weiterleitungen, Originalrevisionwechsel, Vier-Augen-Freigabe und Seitenkopien.
Die gezielte Löschprüfung berücksichtigt auch Sprachpfade mit der maximalen
Länge und fremde unveränderliche Kopiernachweise.
Die Browserprüfung verwendet die tatsächlich geschützte Manageroberfläche und
öffentliche Website mit echten lokalen POSTs, sechs Größen von 320 bis 1536 Pixel,
normaler und doppelter Schrift sowie aktiviertem und ausgeschaltetem JavaScript.
Sie zählt externe Anfragen und vergleicht ursprüngliche Geschäftstabellen und
beide Schlüssel vor und nach der Bedienung. Der konkrete grüne Lauf wird im
zugehörigen Feature-Nachweis dokumentiert; der Befehlsweg allein ist kein
Abnahmeergebnis.

Der abschließende frische Lauf vom 15. Oktober ist grün: **127 Fachbedingungen,
144 tatsächliche geschützte Ansichten und 2.061 Browserbedingungen**. Dazu
gehören die realen Manager-/Kontosprachwechsel und beide öffentlichen
Suchrouter einschließlich 20+1-Pagination. Alle **391** erfassten ursprünglichen
Geschäftstabellen und beide Schlüssel bleiben exakt erhalten; externe Anfragen
und JavaScriptfehler: **0**. Die Playwright-Routenüberwachung sperrt fremde
Anfragen. Für feste eigene CSS-/JS-/Schriftdateien verwendet sie die jeweils
erste echte Routerantwort dieses Laufes als Assetcache, da Playwright-Routing
den gewöhnlichen Chromiumcache abschaltet. HTML, private APIs, Formulare und
Geschäftsdaten werden in diesem Testcache nicht gespeichert.
