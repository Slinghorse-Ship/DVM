# Seiten vollständig kopieren und sicher endgültig löschen

Der normale Kopierweg erstellt weiterhin bis zu 500 private Seiten atomar. In
jedem Inhaltseditor kann zusätzlich „Teilbaum schrittweise vorbereiten“ gewählt
werden. Dieser Auftrag hält bis zu 5000 Seiten und insgesamt 64 MiB gespeicherten
Seiteninhalt fest; Bilder werden aus dem bestehenden Bestand referenziert.

Die gewählte Grundlage ist entweder der gespeicherte Entwurf oder die tatsächliche
veröffentlichte Hierarchie. Später privat umgehängte Unterseiten ändern die zuvor
veröffentlichte Grundlage nicht. Titel und Wurzeladresse der Kopie sind frei
wählbar. Andere Originale, Veröffentlichungen und Dateien bleiben erhalten.

## Vorbereitung und Abschluss

Die Vorbereitung arbeitet in Schritten von höchstens 25 Seiten. Fortschritt und
erwartete Version werden gemeinsam gespeichert. Ein neuer Aufruf oder eine neue
Sitzung setzt denselben Auftrag fort. Zwei gleichzeitige Aufrufe können einen
Schritt nicht doppelt ausführen.

Während der Vorbereitung entstehen keine Seitenkopien. Die kompakte
Fortschrittsansicht zeigt Anzahl, gegebenenfalls den konkreten Konflikt und die
nächste Aktion. Abbrechen lässt keine unvollständigen Seiten zurück.

Erst „Vollständigen Teilbaum privat anlegen“ erstellt nach ausdrücklicher
Bestätigung den gesamten Baum in einer Transaktion. Die regulären
Seitenprüfungen gelten auch hier: aktuelle Rechte, genau gewählte Originalfassungen,
Feldmodell, Elternhierarchie, Medien, Dokumente und freie Adressen. Ein später
Konflikt rollt sämtliche bereits innerhalb dieser Transaktion eingefügten Kopien
zurück; der Auftrag hält die Ursache fest. Ein verändertes Original oder
Inhaltsmodell verlangt einen neu vorbereiteten Auftrag.

Interne typisierte Seitenverweise werden auf die neuen Kennungen umgebogen,
einschließlich Mehrfachwerten und verschachtelten Datensätzen. Äußere Verweise
bleiben beim Original. Die vollständige Kopie bleibt unveröffentlicht und
außerhalb des öffentlichen Menüs. Vorhandene Übersetzungen werden ebenfalls als
private, neu zu prüfende Fassungen kopiert. Veröffentlichte Sprachadressen und
Freigaben bleiben beim Original. Ein nach der Vorbereitung geänderter Sprachstand
hält den Auftrag an, auch wenn die Originalseitenversion dabei unverändert blieb.
Auftragsgrenzen schützen auch den abschließenden
atomaren Schritt; der Hoster muss dessen Laufzeit ermöglichen.

Abgeschlossene oder abgebrochene Aufträge lassen sich unter „Auftragsdaten
entfernen“ ausdrücklich bereinigen. Dafür sind Verwaltungsrecht, der aktuelle
Auftragsstand und eine eigene Bestätigung erforderlich. Entfernt werden nur
gespeicherte Vorbereitung und Auftragskopf. Alle Original- und Zielseiten bleiben
erhalten. Ein inhaltsfreier Fingerabdruck erlaubt die sichere Wiederholung nach
verlorener Antwort. Ein aktiver Auftrag oder eine zusätzliche fremde
Datenbankbeziehung verhindert die Bereinigung.

## Endgültiges Löschen

Im Papierkorb bietet eine Person mit Veröffentlichungsrecht unter „Endgültig
löschen“ eine eigene ausdrückliche Bestätigung an. Der Server verlangt die genau
angezeigte Papierkorbfassung. Aktive oder noch veröffentlichte Seiten können
nicht über diesen Weg gelöscht werden.

Die Löschung prüft vorab bestehende direkte und gespeicherte Verweise einschließlich
alter Revisionen, anderer Papierkorbinhalte, relativer URLs und Geschäftsbindungen.
Tickets, Anbieterzuordnungen, gespeicherte Pläne, Stapel und Kopieraufträge bleiben
Originale und verhindern die Löschung, solange sie die Seite verwenden. Neue
fremde Datenbankbeziehungen werden ebenfalls berücksichtigt. Unlesbare gespeicherte
Referenzdaten werden nicht stillschweigend übergangen.
Geschlossene Kopieraufträge können zuvor über den beschriebenen eigenen Weg
bereinigt werden; ihre früheren Snapshots sperren dann keine Seitenlöschung mehr.

Eine eigene serialisierbare Transaktion schützt auch gegen gleichzeitig neu
entstehende Verweise. Fehler beim späteren Löschen rollen den gesamten Vorgang
zurück. Nach verloren gegangener Antwort kann dieselbe bestätigte Fassung sicher
erneut angefordert werden.

Gelöscht werden der ursprüngliche Seiteninhalt und seine eigenen Versionen.
Hat die Seite eigene Sprachfassungen, verlangt der gemeinsame Löschvorgang
zusätzlich Verwaltungsrecht und entfernt ihre eigenen Sprachentwürfe,
Sprachrevisionen und Sprachrouten in derselben Transaktion. Erhaltene Verweise
anderer Originale sperren den Vorgang weiterhin. Frühere und vorgeschlagene
Sprachadressen werden zusammen mit den Originaladressen dauerhaft reserviert.
Originalbilder und fremde Geschäfts- oder Inhaltsdaten bleiben unverändert.
Ein Inhalts- und Revisionsfingerabdruck hält die Löschung nachvollziehbar; alte
Adressen bleiben gesperrt, damit frühere Links künftig keine andere Seite zeigen.

Die MySQL-Verträge stehen in `native-tree-copy-jobs.php` und
`native-page-purge.php` unter `vereinsverwaltung/tests/`. Sie prüfen auch echte
parallele Sitzungen, vollständige Rollbacks, die tatsächliche 5000-/5001-Seiten-
Grenze und unveränderte Originaldaten. Die Browserabnahme ist gezielt mit
`DVM_PAGE_LIFECYCLE_BROWSER=1 bash tools/ci/local-native.sh --core native-tree-copy-jobs`
aktivierbar.

## Geprüfter Stand vom 15. Oktober 2026

Sieben gemeinsame Fachgruppen sind grün: 33 Kopierauftragsbedingungen, 47
Einzelkopierbedingungen, 74 Teilbaumkopierbedingungen, 56 Papierkorbbedingungen,
46 Anordnungsbedingungen, 56 Adresshistorienbedingungen und 48 Bedingungen für
die endgültige Löschung. Die letzte Quell- und Stagingoptimierung besteht die
33 Kopierauftragsbedingungen nochmals separat, einschließlich der tatsächlichen
5000-/5001-Seiten-Grenze.

Der reguläre Browserlauf mit dem oben genannten Schalter besteht 358 Bedingungen:
beide tatsächlichen Anwendungen, jeweils mit und ohne JavaScript, vier vollständige
Kopier-/Löschabläufe, 48 Ansichten bei 320/390/1024 Pixeln und 16/32-Pixel-Schrift,
20 geschützte HTTP-POSTs. 391 ursprüngliche Geschäftstabellen, 220 Originalzeilen,
beide Schlüssel und die Originalmedien bleiben erhalten. Externe Anfragen und
JavaScriptfehler: jeweils null.

Prüfprotokoll: `kulturhus-page-lifecycle-browser-final.log`, SHA-256
`796c30146af1c462615a1481941ea311b51fc9ade33a61e3631aaf32889520c9`.
Diese Abnahme gilt für den beschriebenen Seitenlebenszyklus. Später ergänzte
Vorlagen- oder Sprachenrechte brauchen ihre eigenen zusätzlichen Fachprüfungen.

Der anschließende vollständige Fachlauf unter den Vorlagenanschlüssen besteht
erneut Jobs 33, Anordnung 46, Adresshistorie 56 und Papierkorb 56 Bedingungen;
vier Suiten, keine Fehler. Protokoll:
`/tmp/kulturhus-lifecycle-policy-jobs-final.log`, SHA256
`1b3f6a0e89963180d30aeb44b604654680ff47a13f0499e8d84c5dffb34c87f2`.

Die ergänzte Auftragsbereinigung besteht 23 eigene MySQL-Bedingungen,
einschließlich echter gleichzeitiger Sitzungen, späterem Transaktionsfehler,
fremder CASCADE-Beziehung, unveränderten Originalen und tatsächlich anschließend
erfolgreicher endgültiger Quelllöschung. Protokoll:
`/tmp/kulturhus-tree-copy-removal-complete.log`, SHA256
`d9afd47606f31bb62b5b9cb427262a8d38a140dfecbfe4455e8371abc7fe30f5`.

Der abschließende erweiterte Browserlauf besteht 374 Bedingungen, vier vollständige
JS-/ohne-JS-Abläufe in beiden tatsächlichen Anwendungen, 48 Ansichten und 24
geschützte HTTP-POSTs einschließlich bestätigter Auftragsbereinigung. 392 andere
Tabellen, 220 bestehende Originalzeilen, Schlüssel und Medien bleiben exakt;
externe Anfragen und JavaScriptfehler: null.
Protokoll: `/tmp/kulturhus-lifecycle-forget-browser-complete.log`, SHA256
`f0fcd7b38df88a941556113ef82b8c5f54d5d6e1127515f89325cde27b489d30`.

Der erweiterte Sprachpurge besteht 60 Fachbedingungen einschließlich getrennter
Publisher-/Administrationsrechte, passender Formularanzeige, vollständigem
Sprachrollback und Wiederholung. Protokoll:
`/tmp/kulturhus-language-purge-rights-final.log`, SHA256
`baa9191ec66f53c34bf69a7f079b889b8146c350b1f0b19a6ab00ecf14cc38d9`.
