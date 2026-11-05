# ERiC: Integration und Weitergabe mit DVM

Maßgeblich ist der im Programmstand enthaltene
[Softwarehersteller-Lizenzvertrag](../distribution/packages/linux-runtime/LICENSES/ERiC-44.3.6.0/lizenz.pdf).
Diese Beschreibung bezieht sich auf diesen Vertrag; sie erteilt keine eigenen
Rechte an ERiC und bestätigt keine pauschale Konformität einer Installation.

## Was der Vertrag erlaubt

§ 4 Absatz 1 gestattet innerhalb des steuerlichen Verwendungszwecks die Integration
von ERiC und des Beispielcodes in eigene Softwareprodukte und die Verbreitung
sowie öffentliche Zugänglichmachung **zusammen mit der eigenen Software als
einheitlichem Produkt**. Das erfasst eine entsprechende DVM-Auslieferung, auch
wenn das Repository öffentlich einsehbar ist. ERiC bleibt dabei unter seinen
eigenen Bedingungen, nicht unter der DVM-Lizenz.

§ 4 Absatz 3 erlaubt weder die Übertragung dieses Hersteller-Nutzungsrechts an
Dritte noch eine über Absatz 1 hinausgehende Unterlizenzierung. Ein Fork oder
anderes Produkt erhält dadurch keine fremde Herstellerregistrierung. Ein
allgemeiner ERiC-SDK-Spiegel ist nicht der hier beschriebene Auslieferungsweg.
Das ELSTER-Logo ist nach § 4 Absatz 2 und § 12 ebenfalls gesondert zu behandeln.

Die vollständige Entwicklerdokumentation und Schemadokumentation sind in dieser
Rechteeinräumung nicht ausdrücklich genannt. Ihre ZIP-Archive werden deshalb
nicht mit DVM weitergegeben. Wer sie zum Entwickeln oder Erzeugen eigener
Formularkataloge benötigt, bezieht sie selbst über den
[offiziellen Entwicklerzugang](https://www.elster.de/elsterweb/entwickler/infoseite/eric).
Lizenztexte und die vorgeschriebenen Endnutzerinformationen bleiben enthalten.

## Was vor Nutzung erfüllt sein muss

- Der verantwortliche Softwarehersteller hat den Vertrag wirksam angenommen und
  verwendet eine für seinen Produktbetrieb gültige Herstellerregistrierung.
- Nach § 5 Absatz 1 erhalten Endnutzer die allgemeinen Datenschutzinformationen
  aus Anhang 2 mit der Möglichkeit der Kenntnisnahme und deren Bestätigung vor
  Nutzung. Der zusätzliche Datenschutzhinweis aus § 5 Absatz 2 ist ebenfalls
  bereitzustellen. Ein bloß im Repository vorhandenes PDF bestätigt keine
  Kenntnisnahme. Der Betreiber muss den Ablauf und seinen Nachweis einrichten;
  DVM hat dafür noch keine automatisch erzwungene ERiC-Erstnutzungsbestätigung.
- § 5 verlangt die vorgesehene Verwendung, regelmäßige Prüfung neuer Versionen
  und die jeweils vorgeschriebene Mindestversion. Die mitgelieferte Version ist
  kein Nachweis, dass sie dauerhaft die aktuelle Mindestversion erfüllt.
- Nach § 7 übernimmt der Softwarehersteller den Endnutzersupport. Interne
  Supportkontakte des LfSt werden nicht an Endnutzer weitergereicht.
- Die Hinweise zu Protokolldateien und ihrer Weitergabe aus § 15 sind zu beachten.
  Eine Weitergabe personenbezogener Supportdaten benötigt die dort beschriebene
  ausdrückliche Erlaubnis. Bei Nutzung von RABE gelten zusätzlich § 6 und dessen
  Anforderungen; das ist keine allgemeine Pflicht zur Aktivierung von RABE.

Die aktuelle Entwicklerseite führte beim anonymen Abruf am 5. November 2026 zur
Anmeldung. Eine aktuelle Mindestversionsliste konnte darüber nicht unabhängig
bestätigt werden. Der Softwareverantwortliche prüft dies im eigenen berechtigten
Entwicklerzugang. Eine Hersteller-ID allein ersetzt diese Prüfung nicht.

## Auslieferung und Einrichtung

Das Quellpaket enthält ein DVM-Laufzeitpaket mit unveränderten ERiC-Bibliotheken,
Plugins und Lizenzinformationen. Das vollständige Entwickler-SDK ist nicht enthalten.
Der [gemeinsame Vorbereitungsbefehl](ZUSATZLAUFZEITEN.md) entpackt dieses Produktpaket. Er verteilt weder
SDK-Handbücher noch Testzertifikate, Betreiberkennungen oder Steuerdaten und
aktiviert keine Übermittlung.

Die anschließende private Einrichtung von Hersteller-ID, Formularkatalog,
Laufzeit und gegebenenfalls eigenem Zertifikat bleibt Aufgabe des Betreibers.
[Programmpaket und Prüfsummen](ZUSATZLAUFZEITEN.md#eric-einrichten),
[private Einrichtung](ZUSATZLAUFZEITEN.md#eric-einrichten).
