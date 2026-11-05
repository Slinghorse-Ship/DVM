# Mein Account und persönliche Chat-Mitteilungen

## Änderung

Mein Account verwendet vier große aufklappbare Bereiche: Persönliche Anzeige, Anmeldung & Sicherheit, Benachrichtigungen und die nur bei entsprechender Berechtigung angezeigte Vorstands-Unterschrift. Im geschlossenen Zustand bleiben kurze Angaben zur aktuellen Einstellung sichtbar. Jede Form behält ihren eigenen Speicherweg. Links mit Abschnittsanker und fehlgeschlagene Eingaben öffnen den passenden Bereich. Das Profil hat eine eindeutige Speicheraktion.

Der Kontobereich unten in der Seitenleiste verwendet jetzt dieselbe Menübedienung wie die übrigen Bereiche. Account-Einstellungen und Abmelden sind auch bei eingeklappter Navigation über das Kontosymbol erreichbar. Die Abmeldeadresse und Berechtigungen wurden nicht verändert.

„Neue Chat-Nachrichten“ ist in der persönlichen Themenauswahl verfügbar. Die bisherigen Chat-Geräte bleiben zunächst aktiv. Ein ausdrückliches Ausschalten speichert den privaten Marker `_chat_disabled` in der vorhandenen Präferenztabelle; keine Migration. Der bestehende Chatsender prüft diese Auswahl sowohl beim Einreihen als auch vor dem Versand. Noch wartende Hinweise des eigenen Kontos werden beim Ausschalten beendet. Erneutes Einschalten erzeugt keine nachträglichen Hinweise. Andere Themen, Geräte, Konten und E-Mails bleiben unabhängig.

Vor der Veröffentlichung bereits geöffnete Formulare ohne das neue Chat-Feld erhalten die aktuelle Chat-Auswahl. Manipulierte Themen, ungültiges CSRF und veraltete Versionsstände bleiben abgewiesen. Chat-Inhalte werden weder in den Sperrbildschirmtext übernommen noch durch die Themenauswahl freigegeben.

## Nachweise

- 38 synthetische PHP-Prüfungen unter PHP 8.3.6 und 8.5.10: Altpräferenzen, Ausschalten, wartende Hinweise, tatsächliche Senderprüfung, Wiederaktivierung, andere Konten/Vereine, Transaktion und Rechte.
- 12 bestehende Push-Prüfungen unter PHP 8.3; vollständige Manager-Prüfung mit 47 Checks unter PHP 8.5 und verfügbarer PDF-Laufzeit. Unter dem separaten PHP-8.3-Testsystem fehlte für den späteren PDF-Teil zunächst `pypdf`; die Chat- und Push-Suiten liefen dort vollständig.
- 30 echte lokale Browserprüfungen für Themenauswahl, Speichern/Neuladen, Altformulare, Gerätebestand, Berechtigungen und 390-px-Ansicht. Ein Fehler beim Aufklappen verschachtelter Bereiche wurde gefunden, korrigiert und erneut geprüft.
- 29 lokale Account-/Menüprüfungen: Profilwerte wirklich gespeichert, fremdes Konto unverändert, veraltete Formulare, Feldmarkierung, Abschnittsanker, Signaturberechtigung, Tastatur, Symbolmenü und Mobilansicht. Screenshots anschließend visuell geprüft.
- Handbuchtext fortgeschrieben und gebaut; 59 Routen- und 13 Medienprüfungen. Keine neuen Aufnahmen sämtlicher älterer Handbuchbilder behauptet.

Alle schreibenden Tests verwenden isolierte Beispieldaten und lokale Versand-Callbacks. Keine Testmail oder Pushmitteilung an echte Mitglieder oder Vorstände. Ein Browser-Viewport-Test ersetzt keine Geräteabnahme auf Android oder eine Verständlichkeitsprüfung durch unerfahrene Erstnutzer.

## Live-Veröffentlichung

Commit `b48eae45b4ce4107af4ca94af0be9b92c40bc762` ist veröffentlicht: 14 Laufzeitquelldateien an 17 Serverzielen, alle gegen den vorherigen Stand geprüft, privat gesichert, vor Veröffentlichung geprüft und anschließend per Hash abgeglichen. Keine Datenbankmigration, Präferenzänderung oder Änderung des Sitzungsspeichers.

Mit der bestehenden zentralen Anmeldung live geprüft: Accountübersicht mit vier geschlossenen Bereichen, sichtbare Chat-Auswahl nach Öffnen des Benachrichtigungsankers, passende geladene Gestaltung, Kontomenü im Symbolmodus, sichtbare Abmeldung und funktionierender Account-Link. Die Anmeldung blieb beim Update bestehen; weiterhin 30 Tage ausgewählt. Keine produktiven Einstellungen gespeichert und keine Abmeldung ausgelöst. Private Nachweise: `.local/account-notifications-deploy-result.json` und `.local/account-notifications-ui-acceptance.json`.

## Weiterer UI-Stand

Diese Änderung vereinheitlicht Account und Kontomenü. Sie ist keine vollständige
Neugestaltung sämtlicher Vereinsmanagerseiten.
