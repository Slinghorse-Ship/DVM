# Vereinsmanager und lokale Bildgestaltung

Verbindlich: WISO MeinVerein **Web** dient als Referenz für vertraute Vereinsabläufe. Keine KI-Anbindung, keine KI-Bilder und keine KI-Abhängigkeit. Die Oberfläche arbeitet mit tatsächlichen Daten, eigenen Fotos und lokal erzeugten Layouts.

## Gelesene Quellen und konkrete Konsequenzen

Am 8. Oktober 2026 wurden folgende Quellen tatsächlich abgerufen:

| Quelle | Beobachtung | Umsetzung / weiterer Abgleich |
| --- | --- | --- |
| [WISO MeinVerein Web](https://web.meinverein.de/) und dessen öffentlich ausgelieferte deutsche Sprachdatei `assets/i18n/de.json` | Navigation enthält Start/Dashboard, Mitglieder, Kontakte, Listen, Organisation, Finanzen, Spenden, Auswertungen und Einstellungen. Finanzen nennt Bank & Kasse, Beitragsabrechnung, Belege, Lastschriften und Finanzexport. Organisation nennt Chat, Kalender, E-Mails, Briefe und Sendungen. | Diese Begriffe und Aufgaben dienen dem Menüvergleich. Mitgliederdaten, Beitragseinzug und Zahlungszuordnung bleiben fachlich verbunden. Keine Behauptung, ein angemeldetes WISO-Konto getestet zu haben; keine Übernahme des Anbieterquellcodes. |
| [Nielsen Norman Group: Mobile Input Checklist](https://www.nngroup.com/articles/mobile-input-checklist/) | Beschriftung oberhalb des Felds, passende Tastatur, verständliche Fehler und Sichtbarkeit bei geöffneter Tastatur. | Sichtbare Feldbeschriftungen, ausreichend große Eingaben, kurze Formularabschnitte, passende Eingabetypen. |
| [W3C: Target Size Minimum](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html) | WCAG 2.2 AA fordert grundsätzlich 24 × 24 CSS-Pixel mit beschriebenen Ausnahmen. | Für regelmäßig verwendete Touchaktionen setzen wir 44 Pixel an. Tastaturfokus und reduzierte Bewegung werden berücksichtigt. Das ist keine vollständige WCAG-Zertifizierung. |
| [GitHub Primer: NavList](https://primer.style/product/components/nav-list/) | Gruppen ordnen längere Navigation; die aktive Seite bleibt erkennbar. | Aufgabenbezogene Menüs, sichtbare aktive Bereiche und Rechtefilter vor der Anzeige. |
| [GOV.UK: Task List](https://design-system.service.gov.uk/components/task-list/) | Aufgabenlisten sind für komplexe Vorgänge gedacht und sollen keine unnötige Komplexität erzeugen. | Einfache Aktionen bleiben direkt. Entwurf, Bildfassung, Freigabe und Veröffentlichung behalten nachvollziehbare Zustände. |
| [Buffer Publish](https://buffer.com/publish), [Buffer Community](https://buffer.com/community), [Hootsuite Publishing](https://www.hootsuite.com/platform/publishing) | Visuelle Planung und getrennte Kommentar-/Nachrichtenarbeit. | Inhaltsbibliothek, Planung und private Nachrichten werden als eigene Arbeitsbereiche umgesetzt. Der API-Abgleich steht im separaten Social-Bericht. |

Die WISO-Marketing- und Handbuchdomain `www.meinverein.de` lieferte hier wiederholt eine HTTP-301-Schleife auf dieselbe URL. Der erreichbare Webclient mit seiner deutschen Navigation ist die konkrete Referenz; die vom Nutzer geschätzte Verbreitung ist kein eigener Marktnachweis.

## Tatsächlich implementierte Bildgestaltung

- Drei lokale Layouts: Magazin mit Foto und Text, typografisches Plakat, Fotoposter mit kontrastreicher Textfläche.
- Drei Farbwelten und horizontal/vertikal einstellbarer Fotoausschnitt; eigene Galerie mit visueller Fotoauswahl.
- 1080 × 1350 Pixel für Posts, 1080 × 1920 Pixel für Stories. Storytexte und Markenangaben halten Abstand zu den typischen oberen und unteren Bedienelementen.
- Vereinsname, Logo, Domain und QR-Ziel stammen aus den Vereinsdaten. QR-Pixel bleiben unverändert; kein externer QR-Dienst.
- PNG und Instagram-kompatibles JPEG, private Bildhistorie mit Prüfsummen und Versionsbindung. Die genaue Gestaltung gehört zum Freigabefingerabdruck und kann in einer eigenen Vorlage gespeichert werden.
- Fotos fehlen: Fotovorlage bricht verständlich ab. Ein typografisches Plakat lässt sich vollständig lokal ohne Foto gestalten. Zu viel Text wird nicht unlesbar verkleinert oder heimlich abgeschnitten.
- Foto-/Textbausteine zeigen echte Galeriebilder. Die endgültige Vorschau und der Download stammen aus der gespeicherten Bilddatei.

Nachweise: 13 tatsächliche Pixel-/Layoutprüfungen; 27 befüllte Browserprüfungen einschließlich Fotoauswahl, dauerhaft gespeicherter Farbwelt, neuer Bildfassung und PNG-Download. Social und Newsletter bei 1440/820/390 Pixeln ohne horizontalen Überlauf. 150 bestehende Kommunikationsprüfungen bestanden. Keine echten externen Veröffentlichungen oder Nachrichten ausgelöst. Diese Stichproben sind keine Abnahme sämtlicher Vereinsmanagerabläufe; der Menü- und Funktionsvergleich wird fortgeführt.
