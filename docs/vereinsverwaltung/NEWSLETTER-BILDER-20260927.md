# Newsletter: Bilder in der Editorvorschau

Am 27.09.2026 als begrenzte Anzeige-Korrektur veröffentlicht.
Keine Bilanzfreigabe und keine Veränderung an Kulturhus' EÜR-Einstellung.

## Ursache und Änderung

Die Veranstaltungsbilder des gespeicherten Quartalsentwurfs waren vorhanden
(HTTP 200). Der Editor unter `www.kulturhus.de` erhielt jedoch absolute
Bildadressen unter `kulturhus.de`. Die unveränderte CSP `img-src 'self'`
verhinderte ihre Darstellung. Die Veranstaltungsauswahl verwendete dagegen
bereits gleichursprüngliche Adressen und zeigte die Bilder an.

- Nur zur Anzeige werden HTTPS-Bilder unter `/site/assets/files/` der eigenen
  Domain und ihrer www-Variante auf denselben Ursprung bezogen.
- Keine Freigabe weiterer Bildhosts und keine Lockerung der CSP.
- Fremde Domains, andere Subdomains, abweichende Ports, Zugangsdaten in URLs
  und andere Pfade werden nicht umgeschrieben. Eine lokale Demo übernimmt
  damit keine produktiven Bildpfade.
- Eine WeakMap hält die Originaladresse außerhalb des serialisierten HTML.
  Speichern, Kopieren und Sortieren bewahren diese Adresse. Eine ausdrücklich
  geänderte Bildadresse wird nicht auf die alte Adresse zurückgesetzt.
- Vorschau und Editor teilen dieselbe Darstellung; neue Script-Version in
  der Ansicht verhindert die Verwendung eines alten Browser-Caches.

## Nachweise

- 21 Node-URL-Prüfungen einschließlich fremder Hosts und Pfadgrenzen bestanden.
- 26 synthetische DOM-Prüfungen mit jsdom 26.1.0 bestanden: beide Domainvarianten,
  Vorschau, Kopie, Reihenfolge, Bildänderung, Formularserialisierung und Entfernen.
  Keine Ressourcen geladen, kein Netzwerk oder Mailversand in diesen Tests.
- JavaScript-Syntax und PHP-Ansicht lokal sowie PHP-Ansicht auf dem Webserver geprüft.
- Live nach Veröffentlichung: drei Veranstaltungsbilder im Editor vollständig
  geladen (422×235, 635×422 und 1280×853 Pixel); vollständige Vorschau ebenfalls
  geladen, erstes Veranstaltungsbild visuell geprüft. Handyvorschau: alle drei
  Bilder geladen. Die drei ursprünglichen absoluten Mailadressen sind weiterhin
  im Formular enthalten. Kein tatsächlicher Testmailversand in diesem Schritt.

Nur `public/newsletter-builder.js` und `views/Website/website-newsletter-builder.php`
des aktiven Releases sowie die öffentlich ausgelieferte JS-Kopie wurden ersetzt.
Vorherige Dateien lagen gegen den Git-Ausgangsstand verifiziert vor. Atomare
Dateiumbenennung, Rückleseprüfung und private Quellcode-Rückfallkopien vorhanden.
Nachweis: `audit/newsletter-preview-images-20260927.json` (lokal, nicht versioniert).
Runtime-Verweis, Website-/Manager-Einstieg, Zugriffsregeln, Konfiguration,
Datenbank-Code und Bilanz-Ledger blieben bytegleich. Der Operator verband sich
nicht mit einer Datenbank. Keine Newsletter-Speicherung, Warteschlangenänderung,
Mail, Bankanweisung oder Steuerübermittlung ausgelöst.

Die Browserkontrolle erfolgte in einer separaten temporären Ansicht, damit
ungespeicherte Benutzereingaben im ursprünglichen Tab erhalten bleiben.

## Anschließend: zusätzliche automatische Anrede entfernt


37 neue Prüfungen mit echten Formatierungsfunktionen und ausschließlich
synthetischen Speicher-/Transport-Doubles bestanden – sowohl lokal als auch
mit den veröffentlichten Dateien unter PHP 8.3 auf dem Webserver. Dazu vier
Zielgruppen- und sechs Versandkanal-Regressionen sowie die 47 Bild-/Editorchecks.
Alle drei geänderten PHP-Dateien auf dem Server vor Ersetzung syntaxgeprüft.
Keine reale Testmail versendet; bestehende Entwürfe, Empfänger und Warteschlangen
nicht gespeichert oder geändert.

Nachweis `audit/newsletter-salutation-20260927.json`: drei ausdrückliche
Quellcode-Ziele, private Rückfallkopien, geschützte Dateien unverändert,
keine Datenbankverbindung und kein Wechsel des Runtime-Verweises. Frisch geöffnete
Live-Vorschau beginnt jetzt mit „Liebe Kulturfreundinnen und Kulturfreunde,“;
alle drei Veranstaltungsbilder weiterhin vollständig geladen. Prüftab geschlossen.
