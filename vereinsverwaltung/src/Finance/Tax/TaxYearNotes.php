<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Manual working-paper inputs only: no database writes, tax assessment or filing. */
final class TaxYearNotes
{
    public const MAX_RESERVES=10;
    public const MAX_CORRECTIONS=20;
    private const MAX_CENTS=100000000000;
    private const FIELDS=['record_completeness','record_completeness_note','receivables','liabilities','fixed_assets_book_value','other_assets','valuation_note','reserves_status','reserves','funds_use_status','funds_use_note','corrections_status','corrections'];

    /** Input is tax_year_notes[YYYY]; output is keyed by the selected calendar year. */
    public static function normalize(array $input,int $from,int $to):array
    {
        if($from<2000||$to>(int)date('Y')||$from>$to||$to-$from>2)throw new DomainException('Für die Jahresangaben ein bis drei Kalenderjahre zwischen 2000 und dem laufenden Jahr auswählen.');
        // At most three selected years plus three preserved drafts after a range change.
        if(count($input)>6)throw new DomainException('Zu viele Jahresangaben. Bitte den Zeitraum prüfen.');
        $normalized=[];
        foreach($input as $key=>$row){
            if(!preg_match('/^[0-9]{4}$/D',(string)$key)||(int)$key<2000||(int)$key>(int)date('Y')||!is_array($row))throw new DomainException('Jahresangaben dem richtigen Kalenderjahr zuordnen.');
            foreach(array_keys($row) as $field)if(!in_array($field,self::FIELDS,true))throw new DomainException('Unbekanntes Feld in den Jahresangaben. Bitte die Seite neu öffnen.');
            $year=(int)$key;$value=self::year($row,$year);
            if($year<$from||$year>$to){if($value!==self::year([],$year))throw new DomainException('Für '.$year.' wurden bereits Jahresangaben eingegeben. Den Zeitraum passend auswählen oder diese Angaben ausdrücklich entfernen.');continue;}
            $normalized[$year]=$value;
        }
        $out=[];for($year=$from;$year<=$to;$year++)$out[$year]=$normalized[$year]??self::year([],$year);
        return $out;
    }

    private static function year(array $row,int $year):array
    {
        $label='Jahr '.$year.': ';
        $reservesStatus=self::choice($row,'reserves_status',['unknown','none','listed'],'unknown',$label.'Rücklagen');
        $correctionsStatus=self::choice($row,'corrections_status',['unknown','none','listed'],'unknown',$label.'Korrekturen');
        $fundsStatus=self::choice($row,'funds_use_status',['unknown','entered'],'unknown',$label.'Mittelverwendung');
        $reserves=[];
        foreach(self::rows($row,'reserves',self::MAX_RESERVES,$label) as $i=>$reserve){
            self::keys($reserve,['purpose','reason','opening','addition','use','closing'],$label.'Rücklage');
            $prefix=$label.'Rücklage '.($i+1).': ';
            $r=['purpose'=>self::text($reserve,'purpose',200,$prefix.'Zweck'),'reason'=>self::text($reserve,'reason',1500,$prefix.'Begründung')];
            foreach(['opening'=>'Anfangsbestand','addition'=>'Zugang','use'=>'Verwendung','closing'=>'Endbestand'] as $key=>$name)$r[$key.'_cents']=self::amount($reserve[$key]??'',$prefix.$name);
            if($r['purpose']===''&&$r['reason']===''&&count(array_filter(array_slice($r,2),static fn($value)=>$value!==null))===0)continue;
            if($reservesStatus!=='listed')throw new DomainException($prefix.'Vorhandene Angaben mit „Einzeln erfassen“ übernehmen oder ausdrücklich entfernen.');
            if($r['purpose']===''||$r['reason']==='')throw new DomainException($prefix.'Bitte Zweck und Begründung ergänzen.');
            $complete=!in_array(null,[$r['opening_cents'],$r['addition_cents'],$r['use_cents'],$r['closing_cents']],true);
            if($complete&&$r['opening_cents']+$r['addition_cents']-$r['use_cents']!==$r['closing_cents'])throw new DomainException($prefix.'Der Endbestand muss Anfangsbestand plus Zugang minus Verwendung entsprechen. Unbekannte Beträge leer lassen.');
            $r['calculation_status']=$complete?'balanced':'unknown';$reserves[]=$r;
        }
        $corrections=[];
        foreach(self::rows($row,'corrections',self::MAX_CORRECTIONS,$label) as $i=>$correction){
            self::keys($correction,['sphere','direction','amount','reason'],$label.'Korrektur');
            $prefix=$label.'Korrektur '.($i+1).': ';
            $reason=self::text($correction,'reason',1500,$prefix.'Begründung');$amount=self::amount($correction['amount']??'',$prefix.'Betrag');
            $sphere=self::choice($correction,'sphere',['','1','2','3','4'],'',$prefix.'Vereinsbereich');
            $direction=self::choice($correction,'direction',['','add','subtract'],'',$prefix.'Art');
            if($reason===''&&$amount===null)continue;
            if($correctionsStatus!=='listed')throw new DomainException($prefix.'Vorhandene Angaben mit „Einzeln erfassen“ übernehmen oder ausdrücklich entfernen.');
            if($reason===''||$amount===null||$sphere===''||$direction==='')throw new DomainException($prefix.'Bitte Vereinsbereich, Hinzurechnung oder Abzug, Betrag und Begründung vollständig angeben.');
            $corrections[]=['sphere'=>$sphere,'direction'=>$direction,'amount_cents'=>$amount,'reason'=>$reason];
        }
        $fundsNote=self::text($row,'funds_use_note',6000,$label.'Mittelverwendung');
        if($reservesStatus==='listed'&&!$reserves)throw new DomainException($label.'Mindestens eine Rücklage erfassen oder den Rücklagenstand auf „Noch offen“ beziehungsweise „Keine Rücklagen“ setzen.');
        if($correctionsStatus==='listed'&&!$corrections)throw new DomainException($label.'Mindestens eine Korrektur erfassen oder den Korrekturenstand auf „Noch offen“ beziehungsweise „Keine Korrekturen“ setzen.');
        if($fundsStatus==='entered'&&$fundsNote==='')throw new DomainException($label.'Bitte die Mittelverwendung beschreiben oder „Noch offen“ wählen.');
        if($fundsStatus==='unknown'&&$fundsNote!=='')throw new DomainException($label.'Den vorhandenen Text zur Mittelverwendung mit „Beschreiben“ übernehmen oder ausdrücklich entfernen.');
        return [
            'year'=>$year,
            'record_completeness'=>self::choice($row,'record_completeness',['unknown','complete','incomplete'],'unknown',$label.'Buchführungsstand'),
            'record_completeness_note'=>self::text($row,'record_completeness_note',2000,$label.'Erläuterung zum Buchführungsstand'),
            'receivables_cents'=>self::amount($row['receivables']??'',$label.'Forderungen'),
            'liabilities_cents'=>self::amount($row['liabilities']??'',$label.'Verbindlichkeiten'),
            'fixed_assets_book_value_cents'=>self::amount($row['fixed_assets_book_value']??'',$label.'Buchwert des Sachvermögens'),
            'other_assets_cents'=>self::amount($row['other_assets']??'',$label.'Sonstiges Vermögen'),
            'valuation_note'=>self::text($row,'valuation_note',2000,$label.'Grundlage der Vermögenswerte'),
            'reserves_status'=>$reservesStatus,'reserves'=>$reserves,
            'funds_use_status'=>$fundsStatus,'funds_use_note'=>$fundsNote,
            'corrections_status'=>$correctionsStatus,'corrections'=>$corrections,
        ];
    }

    private static function keys(array $row,array $allowed,string $label):void
    {
        foreach(array_keys($row) as $key)if(!in_array($key,$allowed,true))throw new DomainException($label.': Unbekanntes Eingabefeld. Bitte die Seite neu öffnen.');
    }
    private static function rows(array $input,string $key,int $max,string $label):array
    {
        $rows=$input[$key]??[];
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>$max)throw new DomainException($label.'Höchstens '.$max.' geordnete Einträge für '.($key==='reserves'?'Rücklagen':'Korrekturen').' erfassen.');
        foreach($rows as $row)if(!is_array($row))throw new DomainException($label.'Einzeleinträge prüfen.');
        return $rows;
    }
    private static function text(array $input,string $key,int $max,string $label):string
    {
        $value=$input[$key]??'';
        if(!is_string($value)||strlen($value)>$max||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException($label.': Bitte den Text prüfen und gegebenenfalls kürzen.');
        return trim(str_replace(["\r\n","\r"],"\n",$value));
    }
    private static function choice(array $input,string $key,array $allowed,string $default,string $label):string
    {
        $value=$input[$key]??$default;
        if(!is_string($value)||!in_array($value,$allowed,true))throw new DomainException($label.': Bitte eine der angebotenen Antworten wählen.');
        return $value;
    }
    private static function amount(mixed $value,string $label):?int
    {
        if(!is_string($value)||strlen($value)>30)throw new DomainException($label.': Betrag als Eurozahl eingeben.');
        $value=trim($value);if($value==='')return null;
        if(preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?$/D',$value))$value=str_replace(',','.',str_replace('.','',$value));
        elseif(!preg_match('/^[0-9]+\.[0-9]{1,2}$/D',$value))throw new DomainException($label.': Zum Beispiel 1.234,56 eingeben; unbekannte Beträge leer lassen.');
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');$whole=ltrim($whole,'0');
        if(strlen($whole)>10)throw new DomainException($label.': Betrag ist zu groß.');
        $cents=(int)$whole*100+(int)str_pad($fraction,2,'0');
        if($cents>self::MAX_CENTS)throw new DomainException($label.': Betrag ist zu groß.');
        return $cents;
    }
}
