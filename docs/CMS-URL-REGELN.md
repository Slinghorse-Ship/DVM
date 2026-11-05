# URL-Regeln für Website-Vorlagen

Die Vorlagenverwaltung unter **Website → Technik → Vorlagen bearbeiten →
Adressen und Seitenwechsel** bietet eigene URL-Regeln. Die Eingaben funktionieren
mit Tastatur und Touch sowie ohne JavaScript. Die bis zu acht geordneten
Segmentregeln stehen in einzeln geschlossenen Bereichen.

## Einstellungen

Jedes Segment hat einen kurzen eindeutigen Namen und genau einen festen Typ:
Pfadwort, begrenzte ganze Zahl, echtes ISO-Datum oder eine Auswahl aus höchstens
40 Pfadwörtern. Optionale Segmente stehen am Ende. Bei ergänzten Parameterpfaden
müssen die vorhergehenden erforderlichen Segmente vorhanden sein. Die genaue
Originaladresse bleibt als Basis erreichbar. Es werden weder reguläre Ausdrücke
noch PHP oder hochgeladene Vorlagendateien ausgeführt.

Ein Segment kann als typisierter URL-Kontext dienen oder auf der Programmseite
konkret Jahr, Suchwort, Archiv/Kommendes und Sortierung auswählen. Beispielsweise
kann ein Zahlensegment mit Verwendung **Programm nach Jahr filtern** die Auswahl
`/veranstaltungen/2050/` steuern. Das Formular zeigt die tatsächlich verwendete
Auswahl. Widersprüchliche Pfad- und Formularfilter werden abgewiesen.

Der Seitenwechsel verwendet wahlweise Abfrageparameter, ein festes Pfadwort mit
Seitennummer oder keine weiteren Seiten. Die Programmseite verwendet `seite`,
Nachrichten und Galerie `page`. Beide erhalten ihre vorhandenen Fachfunktionen.
Wenn Programm oder Galerie eine zu hohe gültige Seite auf ihre letzte Seite
begrenzen, zeigen Canonical und Frontend-Kontext diese tatsächlich angezeigte
Seite.
Bei Pfadwort `page` wird beispielsweise die zweite Nachrichtenseite unter
`/aktuelles/theater/page/2` erreichbar. Die erste Seite hat keinen unnötigen
Seitenzahlanhang. Seitennummern sind positiv, haben keine führenden Nullen und
bleiben innerhalb der ausdrücklich gewählten Obergrenze (höchstens 83.334).

Der abschließende Schrägstrich kann die Website-Vorgabe übernehmen, verlangt oder
weggelassen werden. Abweichungen führen direkt mit HTTP 308 zur gewählten
Adresse; HEAD liefert keinen Inhalt. HTTPS kann die Servervorgabe übernehmen
oder zusätzlich verlangt werden. Produktionswebsites behalten unabhängig von
der Vorlageneinstellung ihre feste HTTPS-Origin und die vorhandenen Host- und
Transportprüfungen. Eine HTTP-Abschwächung ist nicht auswählbar.

## Eigentümer und Originalschutz

Die Auflösung prüft zuerst die längste vorhandene Seitenbasis. Exakte öffentlich
verfügbare Originale und Sprachfassungen haben Vorrang. Private Seiten,
abweichende private Entwurfspfade, alte Original- und Sprachadressen,
Absagehinweise und gelöschte reservierte Pfade werden nicht als Parameter einer
Elternseite umgedeutet. Eine längere vorhandene Basis, die den Restpfad nicht
akzeptiert, fällt nicht auf eine kürzere Elternbasis zurück. Die Startseite ist
kein allgemeiner Auffangpfad. Systemendpunkte behalten ihren festen Dispatcher.

Würde ein erzeugter Paginierungspfad auf ein echtes Kind oder eine reservierte
Adresse zeigen, bleibt der bestehende Abfrage-Seitenwechsel nutzbar. Dadurch
bleibt beispielsweise `?page=2` erreichbar, während ein vorhandenes Original
unter `page/2` sein Eigentum behält. Es entsteht keine Weiterleitungsschleife.
Historische Adressen werden nur auf erneut als öffentlich geprüfte Originale
weitergeleitet. Ergänzte Parameter müssen auch zum aktuellen Ziel passen.

Vorlagenregeln verändern keine gespeicherten Seiten- oder Sprachrevisionen.
Die aktuell gespeicherte Regel wirkt unmittelbar und erhöht atomar die
Publikationsgeneration. Ein unveränderlicher Laufzeitkontext enthält getrennt
von den Originalfeldern nur die Originalkennung, genaue Publikationsrevision,
Sprach-/Basisadresse, geprüfte Parameter, Seitennummer und Generation. Er ist
als `Routing\Context` serialisierbar und als `data-url-context` im tatsächlichen
Frontend verfügbar. Canonical, Sprachalternativen, app-eigene Links, Pager und
Sitemap verwenden geprüfte Adressen. Gespeicherter Richtext wird dabei nicht
umgeschrieben.

Der öffentliche Cache trennt Parameter, Seitennummer, Sprache, Regel,
Server-Origin und erhaltene Abfragebytes. Vor und nach seiner Nutzung werden
aktuelle Rechte, Publikation und Pfadreservierungen erneut geprüft. Auch eine
neu angelegte private Entwurfsadresse kann deshalb eine vorher zwischengespeicherte
Parameterroute sperren, ohne dass dafür eine Veröffentlichung erforderlich ist.

## Grenzen und Nachweise

Gespeicherte Originalpfade bleiben auf 180 Byte begrenzt; Sprachbasen auf 197
Byte. Ausschließlich der flüchtige Inhaltspfad des Requests darf bis zu 1.024
Byte umfassen, mit höchstens acht jeweils 80 Byte langen Parameterwerten und
einem gesonderten Seitenwechsel. Abfragen sind auf 8.192 Byte begrenzt.
Doppelte/arrayförmige Seitennummern, Steuerzeichen, kodierte Pfadtrenner und
ungültige Typen werden vor einer Ausgabe abgewiesen. Der lokale Demo-Router
verwendet zusätzlich seinen vorhandenen `/site/`-Mount; Produktionsadressen
verwenden die öffentliche Basis ohne diesen Mount.

Reproduzierbare lokale Prüfung ohne externe Provideraktionen:

```sh
bash tools/ci/local-native.sh --core native-routing
DVM_ROUTING_BROWSER=1 bash tools/ci/local-native.sh --core native-routing
```

Die Suite prüft echte MySQL-Originale, acht maximale Segmente einschließlich einer
197-Byte-Sprachbasis, private und gelöschte Reservierungen, historische Ziele,
Konflikte mit echten Pagerkindern, tatsächliche Nachrichten-/Programm-/Galerie-
Ausgabe, Cachetrennung und widerrufene Regeln. Die Browserprüfung benutzt beide
echten Webrouter, geschützte Vorlagen-POSTs, normale GET-Formulare und
Seitenwechsel mit und ohne JavaScript sowie Smartphone-, Tablet- und
Desktopbreiten mit normaler und vergrößerter Schrift. Originaltabellen,
Schlüssel und unveränderte Fachdatensätze werden bytegenau verglichen.

## Getrennte Auslieferungsabnahme

Die kuratierte Auslieferungsfassung besteht sieben getrennte Gruppen: URL-Regeln einschließlich 69 Fach- und 32.274 Browserbedingungen in beiden echten Apps, 128 Ansichten und 20 tatsächliche POSTs, öffentlicher Router, Aliasrouten, Pfadhistorie, Cache, Sprachausgabe und sprachbezogene Suche. Alle 401 vorhandenen Originaltabellen und beide privaten Schlüssel bleiben exakt. Es finden keine externen Veröffentlichungen statt. Beleg: `kulturhus-routing-isolated-feature.log`, SHA-256 `22caf761aca1e3ae854191fa8e3c0b19b6a79027a69ccf4d65deee7febe1f119`.

Nach der ergänzten API-Speichergrenze und der Korrektur leerer Feldgruppen bestehen in einer neuen unveränderlichen Exportfassung zusätzlich vier gezielte Gruppen für URL-Regeln, Budget, API und Feldgruppen. Beleg: `kulturhus-routing-after-api-budget.log`, SHA-256 `02075cfd37a36e05e330fd37a421540c3e39ad57401679c5c0f68c79ce8f8091`. Die umfangreiche ursprüngliche Browserabnahme und die ergänzende Fachabnahme sind getrennte Nachweise.
