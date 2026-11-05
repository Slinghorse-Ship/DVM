# Native API: geprüfte Typen und Frontendvorlagen

Die versionierte API verwendet dieselben aktuellen Modellrechte und
Kontrollversionen wie die native Modellverwaltung. Neben den bisherigen
Eigenschaften erlauben `POST /api/v1/fields` und `PUT /api/v1/fields/{key}` die
optionalen Bindungen `fieldtype` und `inputfield`. Für
`POST /api/v1/templates` und `PUT /api/v1/templates/{key}` kommen
`frontend_renderer` und `url_policy` hinzu.

Eine Bindung enthält ausschließlich `owner`, `name`, die genaue `version` und
`source_hash`. Die tatsächlichen Werte stammen aus einer bereits lokal geprüften,
installierten und aktivierten Erweiterung. Serverseitige Erweiterungsentwickler
erhalten sie über `fieldtypes()->binding(owner,name)`, für Eingabefelder mit dem
dritten Argument `inputfield`, beziehungsweise
`renderers()->binding(owner,name)`. Bereits gespeicherte freigegebene Bindungen
stehen auch in den leseberechtigten Modellantworten. Code, Klassen, Dateipfade,
`latest` und selbst erfundene Prüfsummen sind keine API-Eingaben.

Beim Schreiben prüft das ursprüngliche Datenmodell zusätzlich die aktuelle
Erweiterungsfreigabe, passende native Feldgrundlage und exakte Quellfassung.
Die API führt weder PHP-Uploads noch eine eigene unsichere Typumwandlung ein.
Seitenspeicherung, Veröffentlichung und tatsächliche Websiteausgabe verwenden
anschließend dieselben nativen Dienste wie die Oberfläche.

Eine ausgelassene Bindung bleibt bei einer gewöhnlichen Metadatenänderung
erhalten, auch wenn ihr Anbieter inzwischen pausiert wurde. Eine solche
historische Bindung erlaubt keine neue Zuweisung des pausierten Anbieters.
Explizites `null` entfernt die optionale aktuelle Bindung; bei
`frontend_renderer: null` gilt wieder die native Standardausgabe. Bereits
veröffentlichte ursprüngliche Seitenfassungen werden dadurch nicht umgeschrieben.

Der native CMS-Paketexport überträgt ausschließlich portable native Modelle.
Eine nicht leere lokale Typ-, Eingabefeld- oder Frontendbindung wird vor der
Projektion ausdrücklich abgewiesen, auch in verschachtelten Datensatzfeldern
und gespeicherten Seitenvorlagen. Es erfolgt keine automatische Umwandlung oder
erneute Ausführung des Anbieters. Der abgelehnte Export erzeugt kein Teilpaket
und verändert kein vorhandenes Ziel. Ein ausdrücklich von diesen Bindungen
befreites aktuelles natives Modell kann weiterhin exportiert werden; ursprüngliche
Seitenwerte und veröffentlichte Fassungen bleiben dabei erhalten. Eingelieferte
Pakete dürfen weiterhin keine solchen Codeidentitäten enthalten.

`url_policy` ist die geschlossene native URL-Grammatik für endliche typisierte
Segmente, Seitennummerierung, Schrägstriche und HTTPS-Regeln. Beispielsweise:

```json
{
  "segments": [
    {"name": "category", "type": "choice", "values": ["culture", "sport"], "required": false}
  ],
  "pagination": {"mode": "path", "marker": "page", "maximum": 9},
  "slash": "never",
  "https": "inherit"
}
```

Unbekannte oder ausführbare Eigenschaften und unbeschränkte URL-Werte werden
vom ursprünglichen Routingvertrag abgewiesen. Diese Modellfunktionen benötigen
weiterhin `schema.write` und die tatsächlich vorhandenen Verwaltungsrechte;
Session-/Bearer-, CSRF-, Idempotenz- und Fehlereigenschaften der allgemeinen
[API](NATIVE-API.md) gelten unverändert.

`native-api-composition` weist die vollständige Kette mit eigener lokal
registrierter Erweiterung nach: echte API-Modelländerung, Typausführung,
Veröffentlichung, gewählter Website-Renderer, exakte URL-Übernahme,
Anbieterentzug, explizite Entfernung und Abweisung gewöhnlicher Leser. Bestehende
Geschäftsoriginale und beide privaten Schlüssel werden dabei exakt verglichen.
