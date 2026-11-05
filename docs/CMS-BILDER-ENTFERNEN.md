# Bilder entfernen und ursprünglich zurückholen

`Cms/MediaRemoval` verbindet die frische globale Verwendungsprüfung mit der
privaten Dateiquarantäne und einem getrennten versionierten Zustand. Entfernen,
Zurückholen und ausdrückliche Speicherwiederaufnahme benötigen aktuelle
Medienverwaltungsrechte und einen eigenen gesicherten MySQL-Vorgang.
Ein früheres Anzeigeergebnis oder eine alte Datenbanksicht ist keine Freigabe.

Der genaue Bilddatensatz, seine ursprüngliche Kennung und Zeitstempel sowie
Rechtefassungen und verschlüsselte Nachweise bleiben unverändert. Die Dateien
liegen nach dem Entfernen in der [privaten Quarantäne](CMS-BILDQUARANTAENE.md).
Die normalen Bildlisten, die Seitenauswahl und Vorschauen bieten sie nicht mehr
an. Neue Seiten-/Textlink-/typisierte Medien- sowie Social-/Vorlagenbezüge werden
gegen den aktuellen Zustand geprüft. Auch die ursprüngliche Uploadkennung
bleibt reserviert; Nachbearbeitung erzeugt kein Derivat eines entfernten Bildes.
Bekannte Bildvarianten bleiben auf die Speicherquote angerechnet.

Ein unveränderlicher Vorgang enthält Originaldatensatz, Datei-/SHA-Inventar,
geprüfte Dateigrößen, Akteur, ursprünglichen Entfernungszeitpunkt und eine Frist
von 30 Tagen. Zustandswechsel erhalten getrennte unveränderliche Historienzeilen.
Die entfernte Liste liest höchstens sechs kleine Metadaten pro Seite.
Die [mobile Bedienoberfläche](CMS-ENTFERNTE-BILDER-BEDIENUNG.md) ist inzwischen
ergänzt. Die Frist löst keine automatische endgültige Löschung aus.

Beim Zurückholen bleibt die ursprüngliche Kennung erhalten. Alle Originalbytes
werden geprüft. Ein belegter Zielordner wird nicht überschrieben. Dieselbe
Bestätigung kann ohne neue Zeitstempel oder doppelte Historie wiederholt werden;
eine veraltete andere Bestätigung kann keinen späteren Vorgang zurückdrehen.
Nach Fehlern beziehungsweise Prozessabbrüchen bestimmt der bestätigte
Datenbankzustand, ob die Dateien aktiv oder in Quarantäne liegen müssen.
Unklare oder nicht prüfbare Fälle bleiben für die ausdrückliche Speicherprüfung
erhalten, ohne Originalbytes zu ersetzen.

178 Fach-/MySQL-/Dateiverträge prüfen 13 eigene Bilder, aktuelle Rechteentzüge,
Vereintrennung, frühere vollständige Datenbanksichten, begrenzte Listen und
Originalerhalt. Zwei tatsächliche unabhängige PHP-Prozesse werden in echten
Dienstaufrufen nach der Dateibewegung vor Datenbankabschluss beendet. Die
Wiederaufnahme prüft beide Richtungen. Alle vollständigen ursprünglichen
Datenbankzeilen einschließlich der ausgewählten Bilddatensätze, Nachweise und
Pixel bleiben erhalten; nur genau zugeordnete neue Zustands-/Historienzeilen
werden ergänzt. 20 betroffene native Suiten und die erneute Fachprüfung bestehen.
Beide bestehenden App-Bestände bestehen ihre vollständige Originalprüfung ohne
Datenbankänderung.

CMS 15 ergänzt drei Tabellen ausschließlich durch den Installer. Bestehende
CMS-14-Webanfragen erhalten weiterhin ihre bisherigen Bildwege und führen
keine DDL aus. Der tatsächliche Upgradeweg prüft jede vollständige frühere
Zeile und alle ursprünglichen Bildvarianten. Die verschlüsselte Recovery-Prüfung
enthält auch Quarantäneplan, Bildoriginale, genaue Zustände und private Nachweise
sowie das Zurückholen aus einer an einen neuen Speicherort verlegten Installation.
127 native Suiten sind registriert.

Offen bleiben konfigurierbare Aufbewahrung, der separat bestätigte endgültige Löschvorgang
und weitere Medien-/Video-/Fach-/UI-Aufgaben. Kein Live-Transport.
