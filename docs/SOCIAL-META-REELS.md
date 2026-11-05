# Gespeicherte Reels über Facebook und Instagram

Ein eigenes Reel speichern, die gewünschte originale Videofassung ansehen und
ausdrücklich freigeben. Anschließend einen Zeitpunkt wählen. Für jeden gewählten
Kanal wird ein eigener dauerhafter Auftrag mit genau dieser Datei und den
eigenen Texten gespeichert. Der native Meta-Worker verarbeitet diese Aufträge
unabhängig von Mailversand und anderen Veröffentlichungen.

Facebook und Instagram erhalten das bytegleiche gemeinsame AVC-/AAC-Profil.
Instagram bekommt einen `REELS`-Container mit der freigegebenen Datei. Der
gespeicherte Container wird vor Veröffentlichung abgefragt und wiederverwendet.
Facebook bekommt einen eigenen Reel-Upload; Start, Hosted-Upload, Verarbeitung,
Finish und unabhängige Veröffentlichungsbestätigung sind getrennte dauerhafte
Schritte. Nach akzeptiertem Finish folgen ausschließlich Bestätigungsabrufe.
Ein fertig verarbeitetes Video gilt erst mit bestätigtem veröffentlichtem
Zustand als veröffentlicht. Unklare Upload-/Veröffentlichungsantworten bleiben
gesperrt; eine unabhängige Statusabfrage kann die bestehende Veröffentlichung
bestätigen, ohne sie nochmals anzulegen.

Die Anbieterabläufe folgen den öffentlich gelesenen Meta-Referenzen:

- [Facebook Reels Publishing](https://developers.facebook.com/docs/video-api/guides/reels-publishing/)
- [Instagram Content Publishing](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/content-publishing/)

Der Dateizugang enthält ausschließlich einen zufälligen befristeten Token.
Gespeichert wird dessen SHA-256, gebunden an eigenen Verein, Originalvideo,
Auftrag und aktuelle Kontoversion. Der Zugang benötigt keinen Login-Cookie des
Anbieters, erlaubt GET/HEAD und einzelne Bytebereiche und verwendet `no-store`
sowie `nosniff`. Abbruch, Ablauf, geänderte Inhalte/Vereinsdaten, entzogene
Personenrechte oder ein geänderter Kontozugang sperren ihn. Native und genuine
Produktionscontroller prüfen dieselben tatsächlichen ursprünglichen Bytes.
Die allgemeine Suchmaschinenwahl bleibt erhalten; bei abgeschalteter Indexierung
erlaubt eine eigene Regel Meta nur die befristeten Veröffentlichungsdateien.

Die normale private Videoadresse bleibt angemeldeten berechtigten Personen
vorbehalten. Der Hosted-Upload verwendet ausschließlich den fest eingetragenen
HTTPS-Ursprung `rupload.facebook.com` und die bekannte numerische Video-ID.
Zurückgelieferte fremde Upload-Ziele erhalten keine Zugangsdaten. Verschlüsselte
Auftragsframes und doppelte Auftragskennungen bleiben über sämtliche Schritte
unverändert. Derselbe Plan erzeugt keine zweite Datei-Berechtigung oder Queue.

Kommunikationsschema 6 ergänzt eine leere Berechtigungstabelle über den Installer.
Gefüllte frühere Videoquellen, Fassungen, Freigaben und Queues bleiben unverändert.
Bei Kommunikationsschema 5 lesen Dateiabfragen die fehlende Fähigkeit ohne DDL.
CMS bleibt 17, 140 native Suiten sind registriert.

149 Fach-/MySQL-/Originalverträge prüfen beide Kanäle, sämtliche dauerhaften
Anbieterschritte, verlorene Antworten, unabhängige Bestätigung, aktuelle Rechte,
geänderte Konten, Ablauf, fremde Vereine und unveränderte vollständige frühere
Zeilen, Primärkennungen, Gesamtzahlen, Dateien, Schlüssel und verschlüsselte
Nachweise. Zwölf echte native HTTP-Verträge prüfen anonyme Original-/Bereichs-
abrufe, HEAD, fehlerhafte Bereiche, private Loginpflicht und verbotene Änderungen.
78 genuine Produktionscontrollerverträge enthalten zusätzlich originale anonyme
Dateiabfragen und Meta-Robotsregeln. 19 betroffene native Suiten bestehen. 31 native Worker-Verträge prüfen
zusätzlich den ausdrücklichen Reel-Auftrag und seinen konfigurierten privaten
Medienordner sowie sämtliche unveränderten übrigen Originalzeilen. Der gemeinsame HTTP-Einstieg behandelt
die produktive Installationsangabe `0` korrekt und hält Meta unabhängig von
deaktivierter Mail. 52 tatsächliche Grenzverträge erreichen ausschließlich
einen isolierten Curl-Sentinel; es wird kein Socket oder Netzwerkaufruf erzeugt.
27 tatsächliche Freigabebrowserprüfungen und 36 tatsächliche Videobrowserprüfungen
bei drei Breiten/200 % Zoom bleiben bestanden; letztere speichern jetzt eine
echte AVC-/AAC-Datei über den bisherigen 2-MiB-Datei-/8-MiB-Formulargrenzen.

**Die Providerprüfungen sind lokale Testtransporte.** Es wurde kein Reel über ein
reales Meta-Konto veröffentlicht. Erforderliche Konten-/Herstellerrechte und
Geräteabnahmen sind gesonderte Voraussetzungen.

Das später ergänzte [Videoangabenmodul](SOCIAL-VIDEOANGABEN.md) verbindet die
aktiven optionalen Rechte-/Angabenregeln und genaue Quellenattribution mit
diesem Veröffentlichungsweg. Uploads bleiben davon unabhängig. Videoquoten,
universelle Videobausteine, weitere Geräte und Carousels bleiben offen.

Neue PHP-FPM-/CGI-Installationen erhalten in `.user.ini` 128 MiB für Datei und
Formular sowie 50 Dateien pro Anfrage. Eine bereits ausdrücklich vorhandene
eigene Datei wird nicht überschrieben. Die Entwicklungsumgebung verwendet
dieselben Werte. Das fachliche MP4-Limit bleibt 100 MiB. Hostingvorgaben können
PHP-Werte begrenzen; die tatsächliche Webspace-Konfiguration bleibt zu prüfen.

Die tatsächlichen Installerwege bestehen mit 125 CLI- und 52 HTTP-/Browser-
verträgen. 76 echte verschlüsselte Wiederherstellungsverträge enthalten
originale Videoquellen/-fassungen, den unveränderten verschlüsselten Reel-Auftrag
und dessen befristete Berechtigung am neuen privaten Speicherort.

Die vorhandenen gemeinsamen Apps wurden für die geänderten PHP-Uploadwerte
ohne Befüllung, Schemaänderung oder neue Schlüssel wieder aufgenommen. Beide
vollständigen früheren App-Bestände sind rein lesend erneut geprüft.

Die nachfolgende vollständige lokale Prüfung führte alle 140 Suiten aus:
139 bestanden im Gesamtlauf; die einzige Abweichung war die historische
robots.txt-Erwartung ohne neue Meta-Dateiausnahme. Diese Prüfung ist auf die
tatsächliche gesperrte allgemeine Indexierung und ausschließlich zwei erlaubte
befristete Meta-Dateipfade aktualisiert. Alle 77 Indexierungsverträge und vier
betroffene Suiten bestehen danach. Damit sind sämtliche 140 Suiten geprüft;
der ursprüngliche Gesamtlauf wird weiterhin als Lauf mit einer korrigierten
Abweichung dokumentiert. Keine GitHub-Actions gestartet.

