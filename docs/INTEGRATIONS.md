# Integrationen

## Eventbrite

Der Cron-Worker ruft regelmäßig Organisationsveranstaltungen ab. `external_id` ist der eindeutige Schlüssel; Imports sind idempotent. API-Antworten werden lokal mit Zeitstempel gespeichert, sodass die Website auch bei API-Störungen funktioniert. Webhooks können später nur den nächsten Sync vorziehen.

Fehlerstrategie: exponentielle Wiederholung, letzte gültige Daten weiter anzeigen, Fehler protokollieren und im Admin sichtbar machen. Abgesagte Events bleiben mit Statushinweis erreichbar. Personen- oder Bestelldaten werden nicht importiert.

## Facebook Pages

Nach Freigabe kann der Worker Text, Link und freigegebenes Bild über die Meta Graph API als Page-Post veröffentlichen. Tokens liegen ausschließlich in der Serverumgebung. Externe Post-ID und Permalink werden gespeichert. Echte Facebook-Events werden bewusst nicht hier erzeugt; dafür in Eventbrite **Publish to Facebook** manuell auslösen.

## Instagram

Voraussetzungen sind ein professionelles Instagram-Konto, Verknüpfung mit einer Facebook-Seite und die passenden Meta-Berechtigungen. Der zweistufige Ablauf erzeugt zuerst einen Mediencontainer und veröffentlicht ihn nach erfolgreicher Verarbeitung. Vorher werden Format, Alt-Text/Caption, Rechte, Freigabe und Veröffentlichungszeit geprüft.

## WISO MeinVerein

Der Admin zeigt einen konfigurierbaren Sprunglink. Exporte werden nur bei Bedarf als CSV erzeugt. Es gibt keine unkontrollierte Synchronisierung personenbezogener Mitgliederdaten in das öffentliche CMS.

## Presse und Touristik

Segmente: NWZ, Friebo, Jade Touristik, Nordsee Jade Weser/TANO/Niedersachsen Hub, Wesermarsch Aktuell, Radio Jade, Radio Nordseewelle, KulturNetz Jadebusen, FUKS, MoX, Neuer Lotse sowie regionale Verteiler Varel, Dangast und Rastede. E-Mail-Adressen bleiben im Beispiel leer und müssen vor Nutzung aus offiziellen Quellen bestätigt werden.