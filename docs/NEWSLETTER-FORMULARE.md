# Öffentliche Newsletter-Formulare

Anmeldung, Bestätigung und Abmeldung verwenden die gewählte Website-Vorlage,
den Vereinsnamen und die lokale Bootstrap-Version 6.0.0-alpha.1. Vorhandene
öffentliche Menüs und Footerlinks führen direkt zum Newsletterformular. Ohne
öffentliche Menüeinträge erscheint kein leerer Menüknopf.

Die Anmeldung bietet Veranstaltungen und Neuigkeiten sowie einen freiwilligen
Namen. E-Mail-Adresse, Themen und bewusste Zustimmung bleiben bei korrigierbaren
Fehlern erhalten. Der native Formularanker führt direkt zur Meldung und gibt
einem Fehler den Tastaturfokus. Dies funktioniert auch ohne JavaScript.
Formularfelder und vollständige Checkboxbeschriftungen haben Touchziele von
mindestens 44 Pixeln; Text bleibt bei 200 % Zoom lesbar und umbricht am Handy.

Die Anmeldung erzeugt zunächst eine persönliche Bestätigungsanfrage. Bestätigen
und Abmelden erfordern weiterhin die originalgebundenen eigenen Tokens. GET und
HEAD führen keine Einwilligungsaktion aus. Die vorhandenen älteren Linkpfade
und One-Click-Abmeldung bleiben bedienbar; Wiederholung verändert den bereits
bearbeiteten Stand nicht. Kanonische URLs enthalten keinen privaten Token.
Seiten bleiben ohne Cache und ohne Weitergabe der Herkunftsadresse.

## Nachweise

`native-newsletter-forms` prüft die tatsächliche `PublicSite`-HTTP-Route mit
einem neuen eigenen Verein in einer frischen isolierten MySQL-Datenbank:
ungültige verschachtelte Angaben, Formularschutz, genaue Originalwerte,
Anmeldung, GET/HEAD, fremde Tokens, Bestätigung, Abmeldung und Wiederholung.
329 Fachverträge; mit `DVM_NEWSLETTER_BROWSER=1` zusätzlich 59 Browserverträge
und abschließend 330 HTTP-/Datenbankverträge über genau einen neuen Empfänger.
Jede frühere Zeile, Tabelle, Veröffentlichung und Originaldatei bleibt erhalten.
Der private Zustellauftrag hat keinen Transportversuch; keine reale Mail.

`newsletter-forms-app-browser.mjs` prüft 115 tatsächliche Verträge beider
eingerichteten Anwendungen mit und ohne JavaScript bei 320/390/820 Pixeln und
200 % Zoom. Nur ungültige neue Anmeldeversuche und lesende Tokenaufrufe; danach
bestätigen die beiden öffentlichen Galerie-Fixtures unveränderte vollständige
Tabellen, Zeilenzahlen, Fassungen, Veröffentlichungen und Dateien.

Zehn betroffene native Suiten, 174 bestehende lesende Framework-Verträge sowie
67 Familien-/275 Antragsverträge bestehen erneut. 118 native Suiten registriert,
CMS-Version 14 ohne neue DDL. Weitere benötigte Zustell-, Wiederaufnahme-,
Kontakt-, Fach- und Gerätevarianten bleiben im Umsetzungsregister offen.
