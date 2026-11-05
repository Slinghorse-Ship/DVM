# Performance, Barrierearmut und SEO

- serverseitiges HTML; kein Frontend-Framework erforderlich
- HTML-Seitencache für anonyme Besucher, gezielte Invalidierung bei Veröffentlichung
- Eventbrite-Antworten lokal cachen; Website nie synchron von Eventbrite abhängig machen
- Bilder als WebP/AVIF mit JPG-Fallback, Größenvarianten, `width`/`height` und Lazy Loading
- eine minimierte CSS-Datei, ein kleines defer-JavaScript, lokale Fonts oder Systemfallback
- Brotli/Gzip, lange Cachezeiten für versionierte Assets, kurze Cachezeiten plus ETag für Feeds/API
- Zielwerte: LCP < 2,5 s, CLS < 0,1, INP < 200 ms auf typischem Mobilgerät
- semantische Überschriften, sichtbarer Fokus, Tastaturbedienung, Kontrast, Alt-Texte und verständliche Fehlermeldungen

Jede Eventdetailseite liefert `Event`-JSON-LD mit Name, Start/Ende, Status, Ort, Bild, Beschreibung, Veranstalter und Ticketangebot. Nur sichtbare, korrekte Daten ausgeben. Die Sitemap enthält kanonische öffentliche URLs und `lastmod`; Entwürfe, Admin, DOI und Abmeldeseiten sind ausgeschlossen. `robots.txt` verweist auf die Sitemap.