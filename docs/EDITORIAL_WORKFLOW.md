# Redaktion, Rollen und Veröffentlichungsregeln

## Rollen

- `superuser`: technische Administration; nicht für tägliche Redaktion
- `administrator`: Nutzer, Konfiguration, Integrationen, Freigaben
- `chief_editor`: alle Inhalte und Kanäle freigeben, Presseentwürfe erzeugen
- `editor`: News, Seiten und lokale Event-Ergänzungen bearbeiten
- `event_manager`: Eventbrite-Sync prüfen, Event-Ergänzungen und Kanäle pflegen
- `newsletter_manager`: Empfänger, Kampagnen und Exporte; kein Systemzugriff
- `press_manager`: Verteiler und Presseentwürfe; Versand bleibt bewusster Schritt

Privilegierte Rollen erhalten 2FA. Redakteure sehen große Schaltflächen, klare Sprache, Autosave-Hinweis und eine Vorschau. Inline-Editing ist auf Text, Teaser, Bild/Alt-Text und Kanalwahl begrenzt; komplexe Einstellungen bleiben im Admin.

## Kanalregeln

| Kanal | Voraussetzung | Aktion |
|---|---|---|
| Website | Status `published` | sofort/cache-invalidate |
| RSS/ICS/API | Website veröffentlicht | automatisch |
| Newsletter | DOI-Verteiler + Freigabe | Warteschlange, Testmail, Versand |
| Facebook Post | Meta-Text/Bild + Freigabe | API-Worker |
| Facebook Event | Eventbrite-Event vollständig | **manuell in Eventbrite „Publish to Facebook“** |
| Instagram | quadratisches/4:5-Bild, Rechte, Freigabe | API-Worker |
| Presse | Verteilersegment + Freigabe | E-Mail-Entwurf; standardmäßig kein Auto-Senden |

## Vier-Augen-Prinzip

Der Autor kann einen Inhalt zur Prüfung einreichen. Eine Person mit Freigaberecht kontrolliert Fakten, Datum, Links, Bildrechte, Alt-Text, Datenschutz und Kanäle. Jede Freigabe wird mit Nutzer, Zeitpunkt und Inhaltsversion protokolliert.