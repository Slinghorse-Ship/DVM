<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Translates only declared navigation positions; names, content and route values are never rewritten. */
final class ManagerLabels
{
    private const GROUPS=[
        'start'=>['Start','Home'],'people'=>['Mitglieder & Kontakte','Members & contacts'],'finance'=>['Finanzen','Finance'],'organisation'=>['Organisation','Organisation'],'portal'=>['Vereinsportal','Member portal'],'website'=>['Website','Website'],'communication'=>['Kommunikation','Communication'],'settings'=>['Einstellungen','Settings'],
    ];
    private const FINANCE=[
        'excess_title'=>['Überschussrückzahlung zuordnen','Match an excess payment return'],
        'excess_intro'=>['Eine bereits erfolgte Rückzahlung dem offenen Überschuss einer bezahlten Forderung zuordnen. Beide Kontoumsätze und die ursprüngliche Forderung bleiben erhalten.','Match an already recorded return to the excess of a paid document. Both transactions and the original demand are retained.'],
        'excess_remaining'=>['Noch nicht zugeordneter Betrag','Amount still unassigned'],'excess_paid_document'=>['Bezahlter Beleg','Paid document'],
        'excess_choose'=>['Vorhandene Rückzahlung auswählen','Choose a recorded return'],'excess_search_label'=>['Rückzahlung suchen','Find a return'],'excess_search'=>['Suchen','Search'],
        'excess_refresh'=>['Aktuellen Stand neu prüfen','Review the current state'],'excess_available'=>['Davon noch verfügbar','Amount still available'],'excess_select'=>['Diese Rückzahlung prüfen','Review this return'],
        'excess_matches'=>['Treffer','Matches'],'excess_page'=>['Seite','Page'],'excess_previous'=>['Vorherige Seite','Previous page'],'excess_next'=>['Nächste Seite','Next page'],
        'excess_selected'=>['Ausgewählte tatsächliche Rückzahlung','Selected recorded return'],'excess_open_original'=>['Kontoumsatz separat öffnen','Open transaction separately'],'excess_clear'=>['Auswahl entfernen','Clear selection'],
        'excess_amount'=>['Rückzahlungsbetrag (EUR)','Returned amount (EUR)'],'excess_reason'=>['Grund und tatsächlicher Belegbezug','Reason and actual document reference'],'excess_reason_limit'=>['Höchstens 500 Zeichen.','Up to 500 characters.'],
        'excess_confirm'=>['Ich habe geprüft, dass diese bereits erfasste Bewegung genau diesen Zahlungsüberschuss zurückführt.','I have checked that this recorded transaction returns this excess payment.'],
        'excess_commit'=>['Vorhandene Überschussrückzahlung zuordnen','Match this recorded excess return'],
        'excess_refresh_hint'=>['Die ursprünglichen Angaben bleiben erhalten. Aktuellen Bezug erneut prüfen und anschließend gesondert bestätigen.','Your original input is retained. Review the current reference and then confirm it separately.'],
        'excess_history'=>['Überschussrückzahlungen','Excess payment returns'],'excess_history_hint'=>['Gespeicherter Bezug vorhandener Kontoumsätze. Die Forderung wurde dadurch nicht geändert; es wurde keine Zahlung ausgelöst.','Recorded links between existing transactions. The demand was retained and no payment was sent.'],
        'excess_active'=>['Zugeordnet','Matched'],'excess_released'=>['Zuordnung aufgehoben','Match released'],'excess_source'=>['Ursprüngliche Zahlung','Original payment'],'excess_return'=>['Tatsächliche Rückzahlung','Recorded return'],
        'excess_reviewed_document'=>['Damals geprüfter Originalbeleg','Document originally reviewed'],'excess_release_reason'=>['Korrekturgrund','Reason for correction'],'excess_release'=>['Rückzahlungsbezug aufheben','Release return match'],
        'excess_history_pages'=>['Seiten der Rückzahlungsbezüge','Return history pages'],
        'process_title'=>['Belegablauf','Accounting document workflow'],
        'process_document'=>['Beleg','Document'],'process_intake'=>['Eingang','Intake'],'process_review'=>['Prüfen','Review'],'process_payment'=>['Zahlung','Payment'],'process_history'=>['Verlauf','History'],
        'process_document_hint'=>['Originalbeleg und Angaben ansehen.','View the original document and its details.'],
        'process_intake_hint'=>['Rechnung im Rechnungseingang aufnehmen.','Add an invoice through incoming invoices.'],
        'process_review_hint'=>['Originalrechnung, Empfänger und Freigabe prüfen.','Review the original invoice, recipient and approval.'],
        'process_payment_hint'=>['Zahlung auf Grundlage dieses Belegs zuordnen oder vorbereiten.','Match a payment or prepare it from this document.'],
        'process_match_hint'=>['Vorhandenen Kontoumsatz dem Beleg zuordnen.','Match a recorded transaction to this document.'],
        'process_status_hint'=>['Gespeicherten Zahlungsstand ansehen.','View the recorded payment status.'],
        'process_history_hint'=>['Gespeicherte Zahlungen, Zuordnungen und Korrekturen ansehen.','View recorded payments, allocations and corrections.'],
        'choose_page'=>['Bereich wechseln','Change section'],'areas'=>['Finanzbereiche','Finance areas'],'pages'=>['Finanzbereich – Unterseiten','Finance area pages'],'help'=>['Finanzen Schritt für Schritt','Finance step by step'],
        'bank'=>['Bankverbindung','Bank details'],
        'tax'=>['Steuerdaten & Freistellung','Tax details & tax exemption'],'finance_preferences'=>['Finanz-Vorauswahl & Kontenanzeige','Finance defaults & account visibility'],
    ];
    private const ITEMS=[
        'home'=>['Übersicht','Dashboard'],'people:members'=>['Mitglieder','Members'],'people:contacts'=>['Kontakte','Contacts'],'people:applications'=>['Mitgliedsanträge','Membership applications'],'people:occasions'=>['Geburtstage & Jubiläen','Birthdays & anniversaries'],
        'finance:bank'=>['Bank & Kasse','Bank & cash'],'finance:inbox'=>['Rechnungseingang','Incoming invoices'],'finance:contributions'=>['Beiträge','Membership fees'],'finance:receipts'=>['Belege & Spenden','Documents & donations'],'finance:reports'=>['Auswertungen & Steuer','Reports & tax'],
        'organisation:documents'=>['Dokumente & Nachweise','Documents & records'],'organisation:workflows'=>['Gefährdungsbeurteilung','Risk assessments'],'organisation:inspections'=>['Gefährdungsprüfung','Safety implementation checks'],'organisation:activity-reports'=>['Tätigkeitsberichte','Activity reports'],'organisation:inventory'=>['Inventar & Ausleihe','Inventory & loans'],'organisation:gema'=>['GEMA','GEMA'],'organisation:lists'=>['Freie Listen','Custom lists'],
        'portal:vouchers'=>['Tickets & Gutscheine','Tickets & vouchers'],'portal:signatures'=>['Meine Unterschriften','My signatures'],'portal:calendar'=>['Kalender','Calendar'],'portal:minutes'=>['Protokolle','Minutes'],'portal:safety'=>['Sicher helfen','Volunteer safety'],'portal:own'=>['Mein Profil & Belege','My profile & documents'],'portal:chat'=>['Chat','Chat'],'portal:messages'=>['E-Mails & Briefe','Emails & letters'],
        'website:contents'=>['Seiten & Programm','Pages & programme'],'website:design'=>['Design & Vorlagen','Design & templates'],'website:automation'=>['Automatisierung','Automation'],'website:module-settings'=>['Moduleinstellungen','Module settings'],'website:technical'=>['Verbindungen','Connections'],'website:model'=>['Felder & Vorlagen','Fields & templates'],
        'newsletter:contents'=>['Newsletter','Newsletter'],'social:contents'=>['Social Media','Social media'],'press:contents'=>['Presse','Press'],
        'settings:club'=>['Verein & Zugänge','Club & access'],'settings:rates'=>['Beiträge & Nummern','Fees & numbering'],'settings:templates'=>['Vorlagen & Briefpapier','Templates & stationery'],'settings:status'=>['Systemstatus','System status'],'settings:languages'=>['Sprachen','Languages'],
    ];
    /** Route-specific labels used by the actual menu search, rather than text matching arbitrary output. */
    private const ROUTES=[
        ['dashboard','','','Übersicht','Dashboard'],['members','','current','Aktuelle Mitglieder','Current members'],['members','','members','Alle Mitglieder','All members'],['members','','contacts','Kontakte','Contacts'],['members','','birthdays','Geburtstage','Birthdays'],['members','archive','','Geschütztes Archiv','Protected archive'],['members','changes','','Datenänderungen prüfen','Review profile changes'],['members-extra','anniversaries','','Jubiläumsliste','Membership anniversaries'],['contacts-extra','favourites','','Favoriten','Favourites'],
        ['portal','application','','Mitgliedsanträge','Membership applications'],['portal','vouchers','','Tickets & Gutscheine','Tickets & vouchers'],['portal','signatures','','Meine Unterschriften','My signatures'],['portal','own','','Mein Profil','My profile'],['portal','documents','','Meine Belege','My documents'],['portal','bank','','Bankverbindung & SEPA','Bank details & SEPA'],['portal','changes','','Meine Angaben ändern','Update my details'],['portal','safety','','Sicher helfen','Volunteer safety'],['pos','','','Kasse','Point of sale'],
        ['finance','bank','','Bank & Kasse','Bank & cash'],['finance','inbox','','Rechnungseingang','Incoming invoices'],['finance','contributions','','Beitragsabrechnung','Membership fee billing'],['finance','contribution-mails','','Beitragsmails & Erinnerungen','Fee emails & reminders'],['finance','debit','','Lastschriften','Direct debits'],['finance','receipts','','Belege','Accounting documents'],['finance','outgoing','','Ausgangsrechnungen','Outgoing invoices'],['finance','donation-collections','','Sammelbestätigungen & Aufwandsverzicht','Combined donation receipts & expense waivers'],['finance','reports','','Auswertungen','Reports'],['finance','book-values','','Anschaffungen & Abschreibungen','Acquisitions & depreciation'],['finance','export','','Export & DATEV','Export & DATEV'],['finance','tax','','Jahresabschluss & Steuern','Year-end accounts & tax'],['donations','money','','Geldspenden','Cash donations'],['donations','goods','','Sachspenden','Donations in kind'],
        ['documents','','','Dokumente & Nachweise','Documents & records'],['documents','checks','','Dokumentprüfung','Document checks'],['documents','workflows','','Gefährdungsbeurteilung','Risk assessments'],['documents','inspections','','Gefährdungsprüfung','Safety implementation checks'],['documents','activity-reports','','Tätigkeitsberichte','Activity reports'],['inventory','where','','Inventar','Inventory'],['inventory','loans','','Ausleihe','Loans'],['inventory','assets','','Vereinsvermögen','Club assets'],['calendar','','','Kalender','Calendar'],['minutes','','','Protokolle','Minutes'],['free','','','Freie Listen','Custom lists'],['gema','','','GEMA','GEMA'],
        ['website','overview','','Website-Übersicht','Website overview'],['website','','','Website-Übersicht','Website overview'],['website','pages','','Seiten','Pages'],['website','navigation','','Menü','Menu'],['website','news','','Aktuelles','News'],['website','gallery','','Fotogalerie','Photo gallery'],['website','events','','Veranstaltungen','Events'],['website','design','','Designvorschläge','Design templates'],['website','automation','','Automatische Vorschläge','Automatic suggestions'],['website','module-settings','','Moduleinstellungen','Module settings'],['website','technical','','Verbindungen & Veröffentlichung','Connections & publication'],['newsletter','','','Newsletter','Newsletter'],['social','','','Social Media','Social media'],['press','','','Presse','Press'],['chat','','','Chat','Chat'],['messages','','','E-Mails & Briefe','Emails & letters'],
        ['settings','','','Vereinsdaten','Club details'],['settings','account','','Mein Account','My account'],['settings','roles','','Rollen & Rechte','Roles & permissions'],['settings','rates','','Beitragssätze','Fee rates'],['settings','numbers','','Nummernkreis','Numbering'],['settings','services','','Leistungen','Services'],['settings','sepa','','SEPA für Mitglieder','Member SEPA mandates'],['settings','texts','','Textbausteine','Message templates'],['settings','paper','','Briefpapier','Stationery'],['settings','status','','Status & Verfügbarkeit','Status & availability'],['settings','cache','','Cache','Cache'],['settings','extensions','','Erweiterungen','Extensions'],['settings','languages','','Website & Sprache','Website & languages'],['settings','tax-accounts','','Steuerkonten','Tax accounts'],['settings','booking-keys','','Buchungsschlüssel','Posting codes'],['settings','donation-forms','','Zuwendungsbescheinigungen','Donation receipts'],
    ];
    private static function key(string $kind,string $id):string{return 'nav_'.$kind.'_'.str_replace([':', '-'], '_',$id);}
    public static function messages(string $locale):array
    {
        $index=explode('-',$locale,2)[0]==='en'?1:0;$messages=['nav_website_tickets'=>['Tickets verwalten','Manage tickets'][$index]];
        foreach(self::GROUPS as $id=>$pair)$messages[self::key('group',$id)]=$pair[$index];
        foreach(self::ITEMS as $id=>$pair)$messages[self::key('item',$id)]=$pair[$index];
        foreach(self::FINANCE as $id=>$pair)$messages[self::key('finance',$id)]=$pair[$index];
        foreach(self::ROUTES as $row)$messages[self::routeKey(['view'=>$row[0],'tab'=>$row[1],'list'=>$row[2]])]=$row[$index+3];
        return $messages;
    }
    public static function groups(array $groups,array $messages):array
    {
        $defaults=self::messages('de');foreach($groups as &$group){$key=self::key('group',$group['id']);if(isset($messages[$key])&&$messages[$key]!==$defaults[$key])$group['label']=$messages[$key];foreach($group['items'] as &$item){$id=$item['id']==='website:technical'&&$item['label']==='Felder & Vorlagen'?'website:model':$item['id'];$key=self::key('item',$id);if(isset($messages[$key])&&$messages[$key]!==$defaults[$key])$item['label']=$messages[$key];}unset($item);}unset($group);return $groups;
    }
    /** Localize the existing role-filtered financial bars by their declared route identities. */
    public static function financeGroups(array $groups,array $messages):array
    {
        $defaults=self::messages('de');foreach($groups as&$group){$key=self::key('item','finance:'.$group['id']);if(isset($messages[$key],$defaults[$key])&&$messages[$key]!==$defaults[$key])$group['label']=$messages[$key];foreach($group['children']as&$child){$route=$child['route'];$section=$route['section']??'';$key=$section!==''?self::key('finance',$section):self::routeKey($route);if(isset($messages[$key],$defaults[$key])&&$messages[$key]!==$defaults[$key])$child['label']=$messages[$key];}unset($child);}unset($group);return$groups;
    }
    public static function financeText(string $kind,array $messages):string
    {
        if(!isset(self::FINANCE[$kind]))return$kind;return$messages[self::key('finance',$kind)]??self::FINANCE[$kind][0];
    }
    public static function search(array $entries,array $messages):array
    {
        $defaults=self::messages('de');foreach($entries as &$entry){if(($entry['route']['view']??'')==='website'&&($entry['route']['tab']??'')==='events'&&($entry['route']['tickets']??'')==='1'){$entry['label']=$messages['nav_website_tickets']??'Tickets verwalten';continue;}if(isset($entry['route']['section'])&&$entry['route']['section']!==''){if(($entry['route']['view']??'')==='settings'&&($entry['route']['tab']??'')===''&&in_array($entry['route']['section'],['tax','finance_preferences','bank'],true)){$key=self::key('finance',$entry['route']['section']);if(isset($messages[$key])&&$messages[$key]!==$defaults[$key])$entry['label']=$messages[$key];}continue;}$key=($entry['route']['view']??'')==='website'&&($entry['route']['tab']??'')==='technical'&&$entry['label']==='Website · Felder & Vorlagen'?self::key('item','website:model'):self::routeKey($entry['route']);if(isset($messages[$key])&&$messages[$key]!==$defaults[$key])$entry['label']=$messages[$key];}unset($entry);return $entries;
    }
    private static function routeKey(array $route):string
    {
        // Labels of user-authored queries, saved lists, names and IDs are never translation inputs.
        $parts=[];foreach(['view','tab','list'] as $key)$parts[]=is_string($route[$key]??'')?$route[$key]??'':'';return 'nav_route_'.substr(hash('sha256',implode('|',$parts)),0,24);
    }
}
