# Gerätehinweise für den neuen Vereinschat

Im Vereinschat **Benachrichtigungen → Über neue Chatnachrichten benachrichtigen**
einschalten und speichern. **Gerätebenachrichtigungen einrichten** führt direkt
zur vorhandenen persönlichen Geräteverwaltung im Konto. Die dort registrierten
Geräte werden auch für den neuen Vereinschat verwendet. Die Ansicht zeigt ihre
tatsächlich gespeicherte Anzahl. Die globale Auswahl **Neue Chat-Nachrichten**
im Konto gilt weiterhin; ihre Abwahl stoppt auch lokale Browserhinweise.

Die vorhandene Geräteverwaltung verwendet PushManager und die bestehende private
VAPID-Konfiguration. Eine fehlende Konfiguration wird nicht als aktive Zustellung
ausgegeben. Die Anmeldung benötigt die ausdrückliche Browsererlaubnis. Auf iPhone
und iPad gelten die bereits dort beschriebenen Voraussetzungen der installierten
Web-App. Diese Geräteverwaltung wird nicht ein zweites Mal im Chat nachgebaut.

Eine neue native Nachricht erzeugt pro bereits registriertem berechtigtem Gerät
einen ursprünglichen Auftrag. Er enthält persönliche Vereinszuordnung,
ursprüngliche Gerätekennung, deren genauen Originalhash und einen allgemeinen
Hinweis mit dem richtigen privaten Gesprächslink. Nachrichtentext und Namen
verlassen die Anwendung nicht. Später angemeldete Geräte erhalten keine alten
Nachrichten rückwirkend.

Der vorhandene private `--push`-Worker berücksichtigt jetzt auch diese native
Warteschlange. Begrenzte Anteile verhindern, dass eine bestehende Warteschlange
die anderen verdrängt; bei einem einzelnen Auftrag entscheidet der älteste
ursprüngliche Zeitpunkt. Die bisherige Chat- und Fachzustellung bleibt erhalten.
Vor der Übergabe werden native bestätigte Konten und aktuelle Sperren,
Vereins-/Personenbindung, Teilnahme, Lesestand, Stummschaltung, native und globale
Themenwahl sowie das genau ursprüngliche registrierte Gerät erneut geprüft.

Bestätigte Hinweise werden nicht wiederholt. Entfernte oder ersetzte Originalgeräte
werden nicht durch eine neue Adresse ersetzt. Unklare Antworten oder ein Abbruch
nach begonnener Übergabe bleiben **uncertain**; der Worker sendet sie nicht erneut.
Abgelaufene Geräte werden nur entfernt, wenn ihre ursprüngliche Bindung weiterhin
genau übereinstimmt. Der ursprüngliche Nachrichten-/Lesestand bleibt vom Versand
unverändert. Das tatsächliche Anzeigen auf einem physischen Gerät folgt nicht aus
einem angenommenen Anbieterauftrag.

Chat-Schema 2 ergänzt eine leere `native_chat_push_deliveries`-Tabelle.
Vorhandene Gespräche, Nachrichten, Lesestände und Geräte werden nicht umgeschrieben;
alte Nachrichten werden nicht als neue Aufträge vorbereitet. Der verschlüsselte
Umzug hält Aufträge, Eigentümer, Gerätehash, ursprünglichen Hinweis und Zeitstempel
fest. Nur bereits begonnene Übergaben wechseln zu **uncertain**. Die private
Betriebsfreigabe nach Wiederherstellung bleibt eine ausdrückliche eigene Aktion.

27 Fach-/MySQL-Prüfungen bestehen. Zwei echte parallele PHP-Worker übergeben jedes
Gerät genau einmal. Ein echter SIGKILL nach angenommener Fake-Übergabe bestätigt
die dauerhafte unklare Quittung und die getrennte Weiterführung des anderen
unberührten Geräts. 38 tatsächliche Browserprüfungen mit zwei Formularaktionen
prüfen den nativen Chat und seine wirkliche Kontoansicht mit/ohne JavaScript,
320/390/820 Pixel und doppelte Schrift. Vier weitere Datenbankprüfungen bestätigen
die tatsächlich gespeicherte Auswahl und die daraus folgenden Originalaufträge.
14 isolierte Chromium-Clientprüfungen bestätigen auch die globale Abwahl.
Alle Transporte benutzen ausschließlich eigene lokale Callbacks oder kontrollierte
Browser-Fixtures; keine reale APNs-/FCM-Anfrage.

Kommunikation bleibt 14, Chat 2, Paketgeneration 2026101312 und 183 registrierte
native Suiten. Kein neuer vollständiger Lauf aller 183 Suiten und kein Gesamtabschluss
behauptet. Echte Anbieterzustellung, physische Geräte und spätere native App-SDKs
sowie übrige Fach-/UI-Abnahme bleiben offen.

```sh
DVM_CHAT_PUSH_BROWSER=1 bash tools/ci/local-native.sh --core native-chat-push
bash tools/ci/local-native.sh --core native-chat native-chat-workflows native-original-jobs native-reminders native-chat-catalogue
bash tools/ci/local-native.sh --core native-installer native-webspace-installer native-recovery native-package-upgrade native-restore-resume
```
