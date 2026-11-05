<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed application summaries only; original applicant names and answers stay literal. */
final class ApplicationCatalogueMessages
{
 public const ENGLISH=[
 'Antragssuche prüfen'=>'Review application search',
 '← Mitgliedsanträge'=>'← Membership applications',
 'Mitgliedsanträge'=>'Membership applications',
 'Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.'=>'This section is available only to current board members and superusers.',
 'Antrag manuell erfassen'=>'Enter application manually',
 'Antragsvorschau'=>'Preview application',
 'Antrag einrichten'=>'Configure application',
 'Eingegangene Anträge · {count}'=>'Received applications · {count}',
 'Name oder E-Mail'=>'Name or email',
 'Status'=>'Status',
 'Zur Prüfung'=>'Pending review',
 'Angenommen'=>'Accepted',
 'Abgelehnt'=>'Rejected',
 'Alle'=>'All',
 'Suchen'=>'Search',
 'Antragssuche zurücksetzen'=>'Reset application search',
 'Suchangaben und Antragsseite prüfen.'=>'Check the search details and application page.',
 'Antrag nicht verfügbar.'=>'Application unavailable.',
 'Suchangaben prüfen'=>'Review original search details',
 'Ursprüngliche Statusangabe prüfen'=>'Review the original status value',
 'Antrag öffnen'=>'Open application',
 'Keine passenden Anträge.'=>'No matching applications.',
 'Seite {page} von {pages} · {count} Anträge'=>'Page {page} of {pages} · {count} applications',
 'Mitgliedsantragsseiten'=>'Application pages',
 'Vorherige Seite'=>'Previous page',
 'Nächste Seite'=>'Next page',
 'Antragsseite'=>'Application page',
 'Seite öffnen'=>'Open page',
 'Aufnahmemail'=>'Welcome email',
 'Beitragsrechnung'=>'Membership invoice',
 'Manuelle Kontoanlage – Status im Mitgliederprofil'=>'Manual account creation – status in member profile',
 'Bereit zum Versand'=>'Ready to send',
 'Zugang wird vorbereitet'=>'Account is being prepared',
 'Versand läuft'=>'Sending',
 'Versendet'=>'Sent',
 'Versandstatus prüfen'=>'Review delivery status',
 'E-Mail-Adresse ergänzen'=>'Add email address',
 'Mitgliedszugang zuordnen'=>'Link member account',
 'Nachricht prüfen'=>'Review message',
 'Wartet auf Aufnahmemail'=>'Waiting for welcome email',
 'Keine Rechnung erforderlich'=>'No invoice required',
 'Status prüfen'=>'Review status',
 'Entwurf · Angaben fehlen'=>'Draft · details missing',
 ];
 public static function key(string$source):string{return'application_catalogue_'.substr(hash('sha256',$source),0,24);}
 public static function messages(string$locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
 public static function text(string$source,array$messages,array$values=[]):string{$text=$messages[self::key($source)]??$source;foreach($values as$key=>$value)$text=str_replace('{'.$key.'}',(string)$value,$text);return$text;}
}
