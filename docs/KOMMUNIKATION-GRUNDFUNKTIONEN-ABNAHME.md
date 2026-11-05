# Abnahme der Newsletter- und Pressegrundfunktionen

Die benannten ursprünglichen Funktionen von K-28 und K-30 werden anhand ihrer
wirklichen nativen Bedienwege geprüft. Die bisherigen pauschalen Restnotizen
werden durch diese konkreten Verträge ersetzt. Altimporte gehören nicht dazu.

| Benötigte Funktion | Vorhandener Fachweg und aktuelle Prüfung |
| --- | --- |
| Newsletter-/Presseausgaben anlegen, finden und bearbeiten | `Communication/Workspace`, eigene sechsteilige Ausgaben-/Revisionslisten; `native-communications`, `native-edition-catalogue`. |
| Vorlagen und typisierte Bausteine | `Workspace`, `TemplateCatalogue`, `Web/Communication/TemplateActions`, eigene Verwaltung/Originalhistorie/Kopie; `native-communication-templates`, beide Composer. |
| Menschliche Texte und ursprüngliche Fehlerformulare | `NewsletterComposerState`, `PressComposerState`, unveränderte angezeigte Fassungen bei Konflikten; `native-newsletter-compose`, `native-press-compose`. |
| Angenommenes Speichern nach Antwortverlust wiederfinden | `EditionSaveRequests`, genauer Originalnachweis, COMMIT-Verlust und echte gleichzeitige Prozesse; beide Save-Requests-Gruppen. |
| Empfängerprofile, Themen und Einwilligungshistorie | `Subscribers`, `SubscriberCatalogue`, eigene sechszeilige Verwaltung; `native-newsletter-contacts`, aktuelle integrierte und native Browser. |
| DOI, bewusste Abmeldung und alte Linkpfade | `NewsletterGateway`, `NewsletterEndpoints`, GET/HEAD ohne Zustandsänderung; `native-newsletter-forms`, `native-newsletter-recovery`. |
| Testausgabe und Freigabe der tatsächlichen Versandfassung | `Campaigns`, `Workspace`, Editor-/Publisherrechte getrennt; `native-communications`, `native-newsletter-deliveries`. |
| Warteschlange, Postfachvorprüfung und ursprünglicher Zustellverlauf | `Delivery/Outbox`, `Delivery/MailAccount`, originalgebundene Zustellungen; `native-mail-preflight`, `native-newsletter-deliveries`, `native-press-delivery`. |
| Bestätigungserinnerungen, Erneuerung, Perioden und Pausen | `ConfirmationReminders`, `Confirmations/Renewals`, `Digests`; `native-digests`, `native-confirmation-renewals`, `native-communications`. |
| Private Newsletter-PDFs | `DocumentAttachments`, originale verschlüsselte MIME-Anlagen und genaue Wiederholung; `native-newsletter-pdf-attachments`. |
| Pressekontakte und Empfängerauswahl | `PressContacts`, gezählte Profile und ausgewählte Originalversionen; `native-press-recipients`, `native-press-contacts-management`. |
| Presseentwurf aus Website oder eigenem Kalender | `EventDrafts`, getrennte Herkunfts-/Rechteoriginale; `native-press-event-sources`. |
| Einfache Presse-Fotoanlagen und Kameravorbereitung | `PressPhotoAttachments`, `PressUploadAccess`, lokale eigene HEIC-/RAW-Verarbeitung; `native-press-photo-attachments`, `native-press-upload-rights`. |
| Geplanter Presseversand und wahrer Status | `PressCampaigns`, privater vorhandener Worker, kein automatischer Retry bei unklarem SMTP-Ergebnis; `native-press-delivery`, `native-mail-preflight`. |

Der integrierte Controller begrenzt die allgemeine Tarif-/Spenden-Kontaktauswahl
auf ihre eigenen Aktionen. Newsletter-/Pressefelder werden nicht mehr vorher
abgefangen. Die tatsächlichen Kontaktbrowser prüfen bewusstes Öffnen, Suche,
Seitenwechsel, ursprüngliche Auswahl, Bearbeitung, Fehlerkorrektur und Abmeldung.

Der vollständige aktuelle Lauf auf unverändertem `f1d3088` endet mit
`{"suites":21,"failed":[],"live_transport":false}`. SHA-256 des vollständigen
Protokolls: `59d3fcd87a65998191f29fa9769a849361ebd1bf7df4282a5f1cfddf3844da82`.
Enthalten sind unter anderem 430 Kontakt-, 224 Presse-Kontakt-, 504 Vorlagen-,
377 Ausgaben-, 222 Terminquellen-, 159 Newsletterhistorien-, 116 Newsletter-
Fehlerformular-, 120 Presse-Fehlerformular- und 86 PDF-Browserprüfungen.
Die gemischten Fach-/HTTP-/Browserzahlen werden nicht als Gesamtprozentzahl addiert.
Frühere fehlgeschlagene Läufe gelten nicht als bestanden. Die Kameraprüfung ist
zusätzlich gegen parallele Verzeichnisse isoliert; ihre eigenen aktuellen
21 Fach- und getrennten 22 Fach-/44 Browserprüfungen bleiben separat dokumentiert:
[Presse-Kameraprüfung](PRESSE-KAMERA-PRUEFISOLATION.md).


Die Softwareabnahme umfasst beide Zugänge, befüllte kurze Listen, aktuelle
Lese-/Bearbeitungs-/Verwaltungs-/Veröffentlichungsrechte, Touch/Tastatur,
320/390/820 Pixel, doppelte Schrift und JavaScript-freie ursprüngliche Formulare.
Lokale Prüfempfänger ersetzen ausschließlich den realen Transport. Eine tatsächliche
Postfach-/SMTP-Anbieterzustellung bleibt eine gesonderte Betriebseinrichtung;
sie wird hier nicht erfunden. Die Prüfungen senden keine reale Mail.

K-28 und K-30 sind damit im benannten Softwareumfang implementiert. Die übrigen
Fach-/UI-Aufgaben, Apps und abschließende Statusseite behalten ihren eigenen Stand.
