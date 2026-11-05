# Prüfung der aktuellen StadtLand.Funk-Webversion

Am 13. Oktober war `https://www.stadtlandfunk.app/login` wieder erreichbar
(HTTP 200). Der aktuelle öffentliche Expo-Webcode ist
`/_expo/static/js/web/index-232d50067c14391d9653bde072070885.js`, 7.565.796 Bytes,
SHA-256 `1444d4f96029e372c9e952b067cb343f7fde10968adbbe349c79e18775a894f7`.

Die bestehende native Anbindung wurde anschließend mit dem bereits autorisierten
persönlichen Zugang direkt geprüft. PKCE-Anmeldung, Tokenaustausch, Kontoprüfung,
eigene Veranstaltungen und Kommentarabruf funktionierten. Die Veranstaltungsliste
lieferte das bereits unterstützte `PagedModel` mit einem eigenen Original, einer
Seite und einem Gesamteintrag. Heimatgebiet und eigene Zuordnung wurden bestätigt.
Die ursprünglichen `likeCount`- und `commentCount`-Felder waren tatsächlich als
numerische API-Werte vorhanden. Der Kommentarabruf lieferte HTTP 200 und eine leere
Liste; ein nichtleeres Kommentaroriginal oder eine echte Antwort wurde dadurch
nicht zusätzlich abgenommen.

Der aktuelle öffentliche Client verwendet weiterhin die unterstützten Lesewege
`/app/onboardingStatus`, `/grapevine/post/own` mit `type`, `page` und `count`
sowie `/grapevine/comment` mit `postId`. Die bisherigen festen HTTPS-Hosts,
Realm `DD`, Appkennung und Callback-Adresse bleiben passend. Eine neue
Schnittstellenänderung war für die geprüften Wege nicht nötig.

Sechs Authentifizierungs-/Leseaufrufe wurden ausgeführt. Es wurden keine
Veröffentlichung, Nachricht, Kommentarantwort oder sonstige fachliche Änderung
an StadtLand.Funk gesendet. Zugangsdaten, Tokens und private Antwortinhalte wurden
nicht in Datenbank-, Quell-, Log- oder Prüfdokumentdateien gespeichert. Die Prüfung
belegt Erreichbarkeit und diese konkreten Abläufe zum Prüfzeitpunkt; sie ersetzt
keine spätere laufende Verfügbarkeitsprüfung oder reale Zustellabnahme.
