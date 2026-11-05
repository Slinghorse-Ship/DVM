# Abnahme der Beitrags- und Redaktionsgrundfunktionen

Der benannte Softwareumfang K-04 und K-25 ist vollständig implementiert.
Pauschale weitere Metadaten-/Kanal-/Rollenvarianten werden durch folgende
konkreten ursprünglichen Verträge ersetzt. Neue Inhalte werden im eigenen CMS
angelegt; eim früheren CMS-Altbestandimport gehört nicht zum Auftrag.

| Benötigte Funktion | Vorhandener Fachweg und aktuelle Prüfung |
| --- | --- |
| Eigene Beiträge vollständig finden und bearbeiten | `Cms/Articles`, sechs gezählte private Treffer, originale Fassungen und erhaltene Such-/Status-/Seitenwahl; `native-article-catalogue`, [Katalog](BEITRAEGE-KATALOG.md). |
| Veröffentlichte und fällige Originalbeiträge | `Pages`, `Web/Articles`, originalgebundene Veröffentlichung und echter zeitgesteuerter Worker; `native-content-workflow`, `native-publication`. |
| Website/RSS getrennt wählen und bewusst zurückziehen | `NewsChannels`, öffentliches `PublicSite`, eigene alte Routen und bedingtes RSS/HEAD; `native-news-channels`, neue tatsächliche `native-editorial-channels`-Browser. |
| Redaktioneller Zustand und ursprüngliche Prüfung | `NewsReview`, `Articles`, echte aktuelle Versionsprüfung und Rücksetzung nach Bearbeitung; `native-news-review`, `native-production-editorial`, neue tatsächliche Kanal-/Freigabebrowser. |
| Optionale Vier-Augen-Regel | `NewsPolicy`, aktueller Regelstand, Autorentrennung, unabhängige Freigabe und ausdrücklich erneuerte Prüfung; `native-news-policy`, neue tatsächliche `native-editorial-policy`-Browser. |
| Freigabe ohne allgemeines Bearbeitungsrecht | Reiner ursprünglicher Publisher und getrenntes persönliches Lesekonto; `native-news-reading`, neue tatsächliche `native-editorial-reading`-Browser beider Oberflächen. |
| Vollständige gespeicherte Lesefassung | Originale Texte, Formatierung, Datum, Kanäle, private Bilder und ursprüngliche SEO-/Menüangaben; unveränderte alte Felddefinitionen im Diensttest, vollständige Browserlesefassung mit 80 Absätzen und zwölf Bildern. |
| Kurzer direkter Prüfweg | Fokus-/Touchsprung zur Prüfung ohne erneutes Laden der Lesefassung, eingeklappte weitere Bilder, erreichbares Tabletmenü und Navigation ohne JavaScript; tatsächliche Lesebrowser. |
| Revisionen, Rückholung, neue Kopien und Pläne | Ursprüngliche Versions-/Freigabe-/Planfingerprints und aktuelle Rollen werden in `native-content-workflow`, `native-news-policy`, `native-publication` geprüft; Änderungen benötigen die dafür vorgesehene erneute Prüfung. |
| Geschützte Originale und andere Fachbereiche | Die neuen Browser prüfen unveränderte frühere Seiten-/Bild-/Rollendaten, persönliche Akteure, Kontakte, eigene Vereinsvorgaben, Sozialunterhaltungen, Mailboxen, Zustellaufträge, Dokumente und private Schlüssel. |

Der saubere gemeinsame Lauf aller drei neuen Prüfgruppen endet mit
`{"suites":3,"failed":[],"live_transport":false}`. SHA-256 des vollständigen Logs:
`4134e6561837307b816902eec1042d506a0dfc10a3efb0363ece198f4c2a77d0`.
Enthalten sind 324 tatsächliche Lesebrowserprüfungen, 122 tatsächliche
Vier-Augen-Prüfungen mit 27 bewusst ausgelösten Speichervorgängen und vollständige
Kanal-/RSS-/Rückzugsabläufe bei drei Breiten. 128, 18 und 11 zusätzliche
Dienst-/Originalverträge schützen die zugehörigen früheren Daten und Dateien.
Die Browser umfassen 1440/820/390/320 Pixel, Tastatur, tatsächlichen 200-Prozent-
Zoom und JavaScript-freie Lesung/Freigabe. Mobile Ansichten wurden auch visuell
geprüft. Die originalen Authentifizierungs- und privaten Datenschlüssel bleiben gleich.
Acht unmittelbar betroffene Fachgruppen bestehen separat; die drei neuen Gruppen
bestehen auch ohne Browserflag. Frühere fehlgeschlagene Entwicklungsstände der
Prüf-Fixture gelten nicht als erfolgreicher gemeinsamer Lauf.


Die zusätzlichen Browser-Fixtures laufen in frischen eigenen MySQL-Beständen,
mit echten persönlichen Anmeldesitzungen, eigenen Schlüsseln und freien lokalen
Ports. Sie lesen oder befüllen keine bestehende Entwicklungsdatenbank. Die
bisherigen eigenständigen Browseraufrufe behalten ihren ursprünglichen Weg.
Ohne Browserflag bleiben die neuen Kerngruppen ohne Playwright ausführbar.
Veröffentlichungen betreffen ausschließlich eigene synthetische lokale Seiten;
es wird kein externer Anbieter oder realer Mailtransport ausgelöst.

K-04 und K-25 werden damit im benannten Softwareumfang auf implementiert gesetzt.
Die übrige Fach-/UI-Arbeit, Apps und gemeinsame Statusseite behalten ihren eigenen
Stand. Keine neue Laufzeitgeneration oder Tabelle; 243 registrierte native Suiten.
