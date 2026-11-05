# Frühere private Nachrichtenversionen und Kontoverläufe

Der private Facebook-/Instagram-Chat bietet „Frühere Fassungen“, sobald native
Nachrichtenversionen oder frühere Kontoverbindungen erhalten sind. Die Historie
zeigt getrennt Nachrichtenversionen und Kontoverläufe. Inhalt, Richtung,
Absendername und ursprüngliche Zeit bleiben erhalten; die Zeit ist die
gespeicherte Nachrichtenzeit, kein erfundenes Bearbeitungsdatum.

25 Originalauswahlen pro Seite öffnen eine genaue frühere Nachricht oder einen
Kontoverlauf. Dessen 40er-Seiten enthalten alle gespeicherten nativen Nachrichten
und nur die menschlichen Mailantworten derselben ursprünglichen Verbindung und
desselben Gesprächs. Bereits als Anbieternachricht gespeicherte Antworten
erscheinen einmal. Bearbeitungsfassungen früherer Konten bleiben innerhalb dieses
Kontoverlaufs wählbar. Stabile private Auswahlkennungen funktionieren unabhängig
von der aktuellen Verzeichnisseite und der Reihenfolge anderer Gespräche.

Die Historie zeigt ausdrücklich „Nachricht“, Facebook/Instagram und „Lesen“.
Sie enthält keine Entwurfs-, Antwort-, Mail-, Abgleich- oder Versandaktionen.
„Zum aktuellen Chat“ führt bewusst zurück. Listenposition, Plattformfilter und
Suchbegriff bleiben erhalten. Die Fassungswahl klappt kompakt auf und scrollt
innerhalb ihres begrenzten Bereichs. Alle Links funktionieren auch ohne
JavaScript mit Touch und Tastatur.

Die Leseansicht verwendet die bereits authentifiziert verschlüsselten nativen
Inboxoriginale. Aktuelle Vereins-/Social-Rechte schützen den gesamten Vorgang;
fremde Gespräche, andere Plattformen und unpassende Verbindungen/Fassungen
werden abgelehnt. Der frühere Kontext erlaubt ausschließlich gebundene
Mailverlauf-Leseabfragen. Er ist weder eine aktive Antwortquelle noch ein
Anbieterzugang. Eine aktuelle Meta-Verbindung, Postfachabfrage oder
Mailkonfiguration ist für diese Ansicht nicht erforderlich.

Es gibt keine neue Datenbankversion, keine nachträgliche Originaländerung und
keinen Altimport. Einzelne gespeicherte Gespräche werden entschlüsselt; ein
verschlüsselter Suchindex, allgemeine Aufbewahrungsregeln und Beiträge als
eigene Originalinspektion bleiben getrennte offene Arbeitspakete.

67 MySQL-/Dienst-/UI-Verträge prüfen 65 frühere Nachrichtenversionen, 35 frühere
Kontoverbindungen, 85 genaue native Nachrichten und 70 ursprünglich gebundene
Mailantworten. Dazu gehören falsche Plattform-/Konto-/Vereinsverweise, aktuelle
Rechte, Originalzeilen/-Unicode und ein tatsächlicher 16-MB-Prozess mit mehr als
14 MB verschlüsselter ursprünglicher MIME. Die sieben betroffenen nativen
Suiten bestehen ohne Live-Transport.

```sh
php vereinsverwaltung/tests/ci-native.php native-inbox-history native-inbox-catalogue native-conversation-search native-social-originals native-social-message-replies native-mail-direct native-social-hub
KH_NATIVE_TEST_CONFIG=.local/native-cms-integration/integrated-manager.json php vereinsverwaltung/tests/support/inbox-history-browser-fixture.php prepare
node vereinsverwaltung/tests/native-inbox-history-browser.mjs
```

171 tatsächliche Browserprüfungen bestehen mit beiden Plattformen, 65
Fassungen, 35 Kontoverbindungen und 70 alten Mails bei vier Breiten bis
320 Pixeln, Touch, Tastatur, 200 % Zoom und ohne JavaScript. Der vollständige
Originalbytevergleich bleibt unverändert; es gibt keine Fachschreibvorgänge
oder externen Aktionen. Auch nach tatsächlich getrenntem Meta-Zugang bleibt
der vorherige native Konto-/Mailverlauf unverändert lesbar.

Die Browserhilfe akzeptiert nur die eigene synthetische Integrationseinrichtung.
Ihre Originalbyte-Prüfsumme bleibt privat und wird nach dem tatsächlichen
Browserdurchlauf abgeglichen.
