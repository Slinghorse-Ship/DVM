<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Filing checklist, not a tax assessment. Unknown answers never mean exemption. */
final class TaxFilingScope
{
    public static function families():array
    {
        return [
            'kst'=>['label'=>'Körperschaftsteuer · KSt 1 und Anlagen','forms'=>['KSt 1','Anlage Gem','Anlagen GK/ZVE und weitere Anlagen'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare/kst'],
            'euer'=>['label'=>'Einnahmenüberschussrechnung','forms'=>['Anlage EÜR','AVEÜR'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare/euer'],
            'gewst'=>['label'=>'Gewerbesteuer','forms'=>['GewSt 1 A','Zerlegung, soweit erforderlich'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare'],
            'ust'=>['label'=>'Umsatzsteuer · Jahreserklärung','forms'=>['USt 2 A'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare/ust'],
            'ustva'=>['label'=>'Umsatzsteuer · Voranmeldung','forms'=>['USt 1 A','Berichtigte Voranmeldung'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare/ustva'],
            'ebilanz'=>['label'=>'E-Bilanz','forms'=>['Bilanz','Gewinn- und Verlustrechnung','Steuerliche Überleitung / Taxonomie'],'url'=>'https://www.elster.de/elsterweb/infoseite/e_bilanz'],
            'lsta'=>['label'=>'Lohnsteuer-Anmeldung','forms'=>['Lohnsteuer-Anmeldung','Berichtigte Anmeldung'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare/lsta'],
            'lstb'=>['label'=>'Lohnsteuerbescheinigungen','forms'=>['Lohnsteuerbescheinigung','Korrektur / Stornierung'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare'],
            'elstam'=>['label'=>'Lohnsteuermerkmale · ELStAM','forms'=>['Anmeldung','Abmeldung','Änderungslisten / Abruf'],'url'=>'https://www.elster.de/elsterweb/infoseite/elstam_(arbeitgeber)'],
            'receipts'=>['label'=>'Nachweise beim Finanzamt einreichen','forms'=>['Tätigkeitsbericht','Satzung','Jahresaufstellungen','Weitere Belege'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare/belegnachreichung'],
            'message'=>['label'=>'Nachrichten und Anträge','forms'=>['Sonstige Nachricht','Fristverlängerung'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare'],
            'foreign_artist'=>['label'=>'Steuerabzug · ausländische Künstler / Lizenzgeber','forms'=>['Anmeldung nach § 50a EStG','Freistellung / Entlastung gesondert prüfen'],'url'=>'https://online.portal.bzst.de/SharedDocs/Leistungsbeschreibung/DE/steuerabzugsverfahren_nach_50_a.html'],
            'zm'=>['label'=>'EU-Geschäfte · Zusammenfassende Meldung','forms'=>['Zusammenfassende Meldung'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare'],
            'registration'=>['label'=>'Steuerliche Erfassung','forms'=>['Gründung eines Vereins / Aufnahme einer wirtschaftlichen Tätigkeit'],'url'=>'https://www.elster.de/eportal/formulare-leistungen/alleformulare'],
        ];
    }
    public static function questions():array
    {
        $yn=['unknown'=>'Noch zu klären','no'=>'Nein','yes'=>'Ja'];
        return [
            'nonprofit'=>['label'=>'Ist die Gemeinnützigkeit steuerlich festgestellt?','options'=>$yn],
            'kst_due'=>['label'=>'Ist für diesen Zeitraum eine Körperschaftsteuer- / Gemeinnützigkeitserklärung abzugeben?','options'=>$yn],
            'accounting'=>['label'=>'Wie wird der Jahresabschluss erstellt?','options'=>['unknown'=>'Noch zu klären','euer'=>'Einnahmenüberschussrechnung','balance'=>'Bilanz und Gewinn- und Verlustrechnung']],
            'economic'=>['label'=>'Gab es Einnahmen aus Verkauf, Bewirtung oder anderen wirtschaftlichen Tätigkeiten?','options'=>$yn],
            'profit_return'=>['label'=>'Ist eine gesonderte steuerliche Gewinnermittlung erforderlich oder angefordert?','options'=>$yn],
            'vat'=>['label'=>'Welche Umsatzsteuerregelung gilt?','options'=>['unknown'=>'Noch zu klären','regular'=>'Regelbesteuerung','small'=>'Kleinunternehmer','exempt'=>'Nur umsatzsteuerfreie Tätigkeiten']],
            'vat_requested'=>['label'=>'Hat das Finanzamt Umsatzsteuererklärungen oder Voranmeldungen angefordert?','options'=>$yn],
            'vat_advance'=>['label'=>'Sind für diesen Zeitraum Umsatzsteuer-Voranmeldungen abzugeben?','options'=>$yn],
            'foreign_services'=>['label'=>'Wurden Leistungen aus dem Ausland bezogen, etwa Werbung, Software oder Honorare?','options'=>$yn],
            'eu_sales'=>['label'=>'Gab es Lieferungen oder sonstige Leistungen an Unternehmen in anderen EU-Staaten?','options'=>$yn],
            'employees'=>['label'=>'Gab es Beschäftigte mit individuellem Lohnsteuerabzug?','options'=>$yn],
            'mini_jobs'=>['label'=>'Gab es pauschal versteuerte Minijobs?','options'=>$yn],
            'foreign_artists'=>['label'=>'Wurden im Ausland ansässige Künstler, Sportler oder Lizenzgeber bezahlt?','options'=>$yn],
            'new_activity'=>['label'=>'Wurde der Verein gegründet oder eine neue wirtschaftliche Tätigkeit aufgenommen?','options'=>$yn],
            'notices_checked'=>['label'=>'Sind die Aufforderungen und Bescheide des Finanzamts für diesen Zeitraum geprüft?','options'=>$yn],
        ];
    }
    public static function normalize(array $input):array
    {
        $questions=self::questions();
        if(array_diff(array_keys($input),array_keys($questions)))throw new DomainException('Unbekannte Angabe im Steuerprofil.');
        $out=[];
        foreach($questions as $key=>$q){$value=$input[$key]??'unknown';if(!is_string($value)||!array_key_exists($value,$q['options']))throw new DomainException('Bitte prüfen: '.$q['label']);$out[$key]=$value;}
        return $out;
    }
    public static function build(int $year,array $input):array
    {
        if($year<2000||$year>(int)date('Y'))throw new DomainException('Erklärungsjahr prüfen.');
        $a=self::normalize($input);$rows=[];$issues=[];
        foreach(self::families() as $key=>$family)$rows[$key]=$family+['state'=>'review','reason'=>'Abgabepflicht noch zu prüfen.'];
        $set=static function(string $key,string $state,string $reason)use(&$rows):void{$rows[$key]['state']=$state;$rows[$key]['reason']=$reason;};
        $set('kst','review',$a['nonprofit']==='yes'?'KSt 1 und Anlage Gem; Prüfungszeitraum laut Finanzamt.':'Körperschaftsteuerpflicht und erforderliche Anlagen prüfen.');
        if($a['kst_due']==='yes')$set('kst','needed',$a['nonprofit']==='yes'?'KSt 1 und Anlage Gem für den angeforderten Zeitraum.':'KSt 1 mit den für den Verein erforderlichen Anlagen.');
        elseif($a['kst_due']==='no')$set('kst','not_needed','Für diesen Zeitraum laut bestätigter Angabe nicht fällig.');
        foreach(['euer','ebilanz'] as $key){
            if($a['profit_return']==='no')$set($key,'not_needed','Laut bestätigter Angabe keine gesonderte Gewinnermittlung angefordert; Jahresnachweise bleiben erforderlich.');
            elseif($a['accounting']!=='unknown'&&$a['profit_return']==='yes')$set($key,($a['accounting']==='euer')===($key==='euer')?'needed':'not_needed','Gewählte Abschlussart: '.($a['accounting']==='euer'?'EÜR':'Bilanzierung').'.');
        }
        if($a['economic']==='no')$set('gewst','not_needed','Keine wirtschaftlichen Tätigkeiten angegeben.');
        else $set('gewst','review','Steuerpflichtige Geschäftsbetriebe, Gewinn und jahresabhängige Grenzen prüfen; auch GK/ZVE in KSt 1.');
        foreach(['ust','ustva'] as $key){
            if($a['vat']==='regular'||$a['vat_requested']==='yes')$set($key,'needed','Umsatzsteuerpflicht / Aufforderung; Anmeldezeitraum gesondert festlegen.');
            elseif(in_array($a['vat'],['small','exempt'],true)&&$a['foreign_services']==='no'&&$a['eu_sales']==='no'&&$a['vat_requested']==='no')$set($key,'not_needed','Keine allgemeinen oder aus den Antworten erkennbaren besonderen Anlässe; Bescheide sind maßgeblich.');
            else $set($key,'review','Sonderfälle wie § 13b UStG / EU-Erwerbe prüfen. Kein Vorsteuerabzug bedeutet nicht keine Erklärungspflicht.');
        }
        foreach(['lsta','lstb','elstam'] as $key)if($a['employees']!=='unknown')$set($key,$a['employees']==='yes'?'needed':'not_needed','Individueller Lohnsteuerabzug: '.($a['employees']==='yes'?'ja':'nein').'.');
        if($a['vat_advance']==='yes')$set('ustva','needed','Voranmeldungen sind für den Zeitraum bestätigt.');
        elseif($a['vat_advance']==='no'&&$a['foreign_services']==='no'&&$a['eu_sales']==='no')$set('ustva','not_needed','Keine Voranmeldungspflicht angegeben; Sonderfälle und Aufforderungen beachten.');
        else $set('ustva','review','Voranmeldungspflicht und Zeitraum klären.');
        if($a['mini_jobs']==='yes')$issues[]='Pauschale Minijob-Abgaben und Sozialversicherung laufen nicht über diese ELSTER-Anbindung; Minijob-Zentrale / SV-Verfahren gesondert erledigen.';
        foreach(['foreign_artist'=>'foreign_artists','zm'=>'eu_sales','registration'=>'new_activity'] as $key=>$question)if($a[$question]==='no')$set($key,'not_needed','Kein entsprechender Anlass angegeben.');
        $set('receipts','needed','Tätigkeitsbericht und erforderliche Jahres-/Satzungsnachweise zur Erklärung zusammenstellen; tatsächliche Nachreichung dokumentieren.');
        $set('message','optional','Nur bei Bedarf, beispielsweise Rückfrage oder Fristverlängerung.');
        foreach(self::questions() as $key=>$q)if($a[$key]==='unknown')$issues[]=$q['label'].' – Noch zu klären.';
        if($a['notices_checked']!=='yes')$issues[]='Bescheide, Aufforderungen und Abgabezeiträume zuerst prüfen.';
        return ['year'=>$year,'answers'=>$a,'families'=>$rows,'issues'=>$issues,'complete'=>!$issues,
            'notice'=>'Diese Auswahl unterstützt die Prüfung. Sie ersetzt keine Bescheide und entscheidet keine unbekannten steuerlichen Sonderfälle.'];
    }
}
