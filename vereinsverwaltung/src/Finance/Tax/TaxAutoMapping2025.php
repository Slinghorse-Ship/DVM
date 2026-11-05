<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Narrow, version-bound KSt/Gem preparation for a confirmed tax-exempt cultural club. */
final class TaxAutoMapping2025
{
    public const VERSION='KSt_30_2025';
    public const SCHEMA='d42ac2fef64d1afc3ba8c1ecd4b9bed4058d050ee46b52e239e63ce721684ac0';
    public static function questions():array
    {
        return [
            'tax_number'=>['ELSTER-Steuernummer · 13 Ziffern','text'],
            'seat'=>['Vereinssitz','text'], 'management'=>['Ort der Geschäftsleitung','text'],
            'statute_date'=>['Datum der gültigen Satzung','date'],
            'statute_filed'=>['Liegt diese Satzung dem Finanzamt vor?','choice'],
            'dues_date'=>['Datum des gültigen Beitragsbeschlusses','date'],
            'dues_filed'=>['Liegt dieser Beitragsbeschluss dem Finanzamt vor?','choice'],
            'purpose'=>['Kunst und Kultur · Mitgliedsbeiträge steuerlich abziehbar?','purpose'],
            'fully_exempt'=>['Ist der Verein im Abgabejahr vollumfänglich körperschaftsteuerbefreit?','choice'],
            'domestic'=>['Vereinssitz und Geschäftsleitung lagen im gesamten Zeitraum in Deutschland?','choice'],
            'source_reviewed'=>['Stammdaten, Jahreszahlen und Nachweise für den gesamten Zeitraum geprüft?','choice'],
            'rounding'=>['Gerundete Gesamteinnahmen für die amtlichen Ganz-Euro-Felder geprüft?','choice'],
        ];
    }
    public static function normalize(array $answers):array
    {
        if(array_diff_key($answers,self::questions()))throw new DomainException('Unbekannte Frage im Steuerassistenten.');$out=[];
        foreach(self::questions() as $key=>[$label,$type]){
            $v=$answers[$key]??'';
            if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>180||preg_match('/[\x00-\x1f\x7f]/',$v))throw new DomainException($label.': Eingabe prüfen.');
            $v=trim($v);
            if($type==='choice'&&!in_array($v,['','yes','no'],true))throw new DomainException($label.': Auswahl prüfen.');
            if($type==='purpose'&&!in_array($v,['','51','52','other'],true))throw new DomainException('Steuerbegünstigten Zweck prüfen.');
            if($type==='date'&&$v!==''&&!TaxCatalog::documentYear($v))throw new DomainException($label.': Datum prüfen.');
            $out[$key]=$v;
        }return $out;
    }
    public static function suggest(array $club,array $tax):array
    {
        $out=self::normalize([]);$out['seat']=$out['management']=(string)($club['city']??'');
        $number=preg_replace('/[\s\/.-]+/u','',(string)($tax['number']??''));if(preg_match('/^[0-9]{13}$/D',$number))$out['tax_number']=$number;
        // Only an exact stored purpose, never a guessed classification from the club's name.
        $purpose=mb_strtolower(trim((string)($tax['purpose']??'')));
        if(in_array($purpose,['förderung von kunst und kultur','förderung der kunst und kultur','kunst und kultur'],true))$out['purpose']=match($tax['membership_deductible']??''){'yes'=>'51','no'=>'52',default=>''};
        return $out;
    }
    public static function build(array $snapshot,array $answers,array $scope,array $sourceIssues=[]):array
    {
        $a=self::normalize($answers);$fields=[];$origins=[];$issues=$sourceIssues;$year=(int)$snapshot['to'];$club=$snapshot['club_data'];$tax=$snapshot['tax']['data'];
        $set=static function(string $path,string $value,string $source)use(&$fields,&$origins):void{if($value==='')return;$key=TaxFormSchema::key($path);$fields[$key]=$value;$origins[$key]=['path'=>$path,'source'=>$source];};
        if($year!==2025)$issues[]='Für '.$year.' bitte die Erklärung in Mein ELSTER vorbereiten.';
        if(($snapshot['accounting_basis']??'')!=='euer')$issues[]='Dieser Assistent ist für Einnahmenüberschussrechnung, nicht Bilanzierung.';
        if(($tax['fiscal_start']??'')!==''&&substr($tax['fiscal_start'],5)!=='01-01')$issues[]='Abweichendes Wirtschaftsjahr zuerst gesondert abgrenzen.';
        foreach(['nonprofit'=>'yes','accounting'=>'euer','employees'=>'no','mini_jobs'=>'no','notices_checked'=>'yes','kst_due'=>'yes'] as $key=>$value)if(($scope['answers'][$key]??'')!==$value)$issues[]='Steuerprofil prüfen: '.TaxFilingScope::questions()[$key]['label'];
        if(!$scope['complete'])$issues=[...$issues,...$scope['issues']];
        foreach(['fully_exempt','domestic','source_reviewed'] as $key)if($a[$key]!=='yes')$issues[]=self::questions()[$key][0];
        if(($club['registered']??null)!==true)$issues[]='Rechtsform prüfen. Für andere Rechtsformen die Erklärung direkt im Formular vorbereiten.';
        foreach(['name','street','postcode','city'] as $key)if(trim((string)($club[$key]??''))==='')$issues[]='Vereinsdaten ergänzen: '.SettingsSchema::fields('club')[$key][0];
        foreach(['tax_number','seat','management','statute_date','statute_filed','dues_date','dues_filed','purpose'] as $key)if($a[$key]==='')$issues[]=self::questions()[$key][0];
        if(!preg_match('/^[0-9]{13}$/D',$a['tax_number']))$issues[]='Die 13-stellige ELSTER-Steuernummer ergänzen; eine regionale Steuernummer reicht hier nicht aus.';
        if(!in_array($a['purpose'],['51','52'],true))$issues[]='Weitere oder andere gemeinnützige Zwecke benötigen eine gesonderte Formularzuordnung.';
        foreach(['statute_date','dues_date'] as $key)if($a[$key]!==''&&$a[$key]>$year.'-12-31')$issues[]='Das Datum für '.self::questions()[$key][0].' liegt nach dem Abgabejahr.';
        $guide=$snapshot['declaration'];
        foreach(['member_benefits','asset_donations','funds_forwarded'] as $key)if(($guide[$key]['answer']??'unknown')!=='no')$issues[]=TaxDeclarationGuide::questions()[$key]['question'].' – diesen Sonderfall vor automatischer Übernahme klären.';
        if(($guide['purpose_fulfilled']['answer']??'')!=='yes')$issues[]='Tatsächliche Verwirklichung des Vereinszwecks bestätigen.';
        $issues=[...$issues,...TaxDeclarationGuide::issues($guide)];
        if(($guide['reserves']['answer']??'')!=='no')$issues[]='Vorhandene Rücklagen müssen vor der Formularübernahme nach ihrer gesetzlichen Rücklagenart zugeordnet werden.';
        $rounded=[];$hasCents=false;
        foreach($snapshot['reports'] as $y=>$report){
            $note=$snapshot['year_notes'][$y];$annual=$report['annual'];
            if($note['record_completeness']!=='complete')$issues[]=$y.': Vollständigkeit der Buchführung noch bestätigen.';
            foreach($annual['issues'] as $issue)$issues[]=$y.': '.$issue['message'];
            if($note['corrections_status']==='unknown')$issues[]=$y.': Steuerliche Korrekturen noch klären.';
            if($note['reserves_status']!=='none')$issues[]=$y.': Rücklagen sind noch offen oder benötigen eine Einzelzuordnung.';
            if($note['funds_use_status']!=='entered')$issues[]=$y.': Mittelverwendung ergänzen.';
            foreach(['receivables_cents','liabilities_cents','fixed_assets_book_value_cents','other_assets_cents'] as $key)if(($note[$key]??null)===null)$issues[]=$y.': Vermögensübersicht ist noch unvollständig.';
            $income=(int)$annual['totals']['income_cents'];if($income<0){$issues[]=$y.': Negative Gesamteinnahmen gesondert prüfen.';continue;}
            // Explicitly reviewed proposal, not a hidden field-by-field rounding of each transaction.
            $whole=intdiv($income+50,100);$hasCents=$hasCents||$income%100!==0;
            $rounded[$y]=['cents'=>$income,'euros'=>$whole,'method'=>'commercial_whole_euro_proposal'];
            $offset=$year-(int)$y;if($offset>=0&&$offset<=2)$set('Gem/Gesamteinnahmen/E840090'.(4+$offset),(string)$whole,'Gesamteinnahmen '.$y.' aus dem abgestimmten Zahlungsjournal; volle Euro geprüft');
        }
        if($hasCents&&$a['rounding']!=='yes')$issues[]='Ganz-Euro-Vorschläge neben den unveränderten Centbeträgen prüfen.';
        $last=$snapshot['reports'][$year]['annual'];$spheres=array_column($last['spheres'],null,'key');
        $economic=(int)$spheres['3']['income_cents']+(int)$spheres['4']['income_cents'];
        if($year===2025&&$economic>4500000)$issues[]='2025: Zweckbetriebe und wirtschaftliche Geschäftsbetriebe zusammen über 45.000 €. Zusätzliche Betriebsangaben erforderlich; nicht als einfacher Befreiungsfall übernehmen.';
        if(($scope['answers']['economic']??'unknown')==='no'&&$economic!==0)$issues[]='Das Steuerprofil verneint wirtschaftliche Tätigkeiten, aber dafür sind Einnahmen gebucht.';
        if(($guide['economic_activities']['answer']??'unknown')==='no'&&$economic!==0)$issues[]='Die Angaben zur Vereinsarbeit widersprechen den Einnahmen in Zweckbetrieb/Geschäftsbetrieb.';
        if(($tax['input_tax_eligibility']??'')!=='no')$issues[]='Vorsteuerabzug in den Steuereinstellungen klären. Bei Vorsteuerabzug die Erklärung direkt im Formular vorbereiten.';
        $set('KSt1/Allgemein/E8000101',(string)($club['name']??''),'Vereinsdaten');
        $set('KSt1/Steuerpflicht/E8000501',$a['management'],'Bestätigter Ort der Geschäftsleitung');$set('KSt1/Steuerpflicht/E8000601',$a['seat'],'Bestätigter Vereinssitz');
        if(($club['registered']??false)===true)$set('KSt1/Steuerpflicht/Rechtsform/E8001105','520','Vereinsdaten: eingetragener Verein');
        if(($scope['answers']['nonprofit']??'')==='yes')$set('KSt1/Steuerbefreiung/Bezeichnung/E8001505','9','Bestätigtes Steuerprofil');
        if($a['fully_exempt']==='yes')$set('KSt1/Steuerbefreiung/Umfang/E8001506','1','Ausdrückliche Bestätigung der Steuerbefreiung im Abgabejahr');
        $set('Gem/Allgemein/E8400902',(string)$snapshot['from'],'Gewählter Prüfungszeitraum');$set('Gem/Allgemein/E8400903',(string)$year,'Gewählter Prüfungszeitraum');
        if(in_array($a['purpose'],['51','52'],true))$set('Gem/Allgemein/Zweck[0]/E8401004',$a['purpose'],'Geprüfter Bescheid und Abziehbarkeit der Mitgliedsbeiträge');
        foreach(['statute_date'=>'E8401601','dues_date'=>'E8401703'] as $key=>$field)if($a[$key]!=='')$set('Gem/Allgemein/Unterlagen/'.$field,(new \DateTimeImmutable($a[$key]))->format('d.m.Y'),'Bestätigter Satzungs-/Beitragsnachweis');
        foreach(['statute_filed'=>'E8401603','dues_filed'=>'E8401702'] as $key=>$field)if($a[$key]!=='')$set('Gem/Allgemein/Unterlagen/'.$field,$a[$key]==='yes'?'1':'2','Ausdrücklicher Nachweisstatus');
        if($a['statute_filed']==='no'||$a['dues_filed']==='no')$set('KSt1/E8000012','X','Satzungs-/Beitragsnachweise müssen nachgereicht werden');
        if($year===2025)$set('Gem/Betatigung/E8401901',$economic>4500000?'1':'2','Unrundete Einnahmen aus Sphäre 3 und 4, Grenze für 2025');
        if(($guide['member_benefits']['answer']??'')==='no')$set('Gem/Satzungsfremd/E8405001','2','Ausdrückliche Vereinsantwort');
        if(($guide['asset_donations']['answer']??'')==='no')$set('Gem/Zufuhrung/E8404902','0','Ausdrücklich keine Vermögenszuführungen bestätigt');
        if(($guide['funds_forwarded']['answer']??'')==='no')$set('Gem/Zufuhrung/MittelP58NR3/Gegeben/E8404903','2','Ausdrückliche Vereinsantwort');
        foreach(['Unterfallart'=>'30','Vorgang'=>'01','StNr'=>$a['tax_number'],'Zeitraum'=>(string)$year,'OrdNrArt'=>'S','AbsName'=>(string)($club['name']??''),'AbsStr'=>(string)($club['street']??''),'AbsPlz'=>(string)($club['postcode']??''),'AbsOrt'=>(string)($club['city']??''),'Copyright'=>'Vereinsmanager','Rueckuebermittlung/Bescheid'=>'2'] as $key=>$v)$set('Vorsatz/'.$key,$v,'Auftragskopf aus geprüften Vereinsdaten; keine Bescheiddatenabholung eingerichtet');
        ksort($fields,SORT_STRING);ksort($origins,SORT_STRING);
        return ['data_version'=>self::VERSION,'fields'=>$fields,'counts'=>[],'origins'=>$origins,'rounding'=>$rounded,
            'header'=>['office'=>preg_match('/^[0-9]{13}$/D',$a['tax_number'])?substr($a['tax_number'],0,4):'','supplier'=>implode(', ',array_filter([(string)($club['name']??''),(string)($club['street']??''),trim(($club['postcode']??'').' '.($club['city']??''))]))],
            'issues'=>array_values(array_unique($issues)),'ready'=>!$issues];
    }
}
