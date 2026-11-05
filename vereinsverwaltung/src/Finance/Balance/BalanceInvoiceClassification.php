<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceTaxMapping.php';

/** Opt-in, source-bound purchase detail. Never edits original positions or old journals. */
final class BalanceInvoiceClassification
{
    /** Browser round-trip; the marker distinguishes an unchecked box from first display. */
    public static function fields(?array $request):array
    {
        return ['purchase_form'=>'1','classify_purchases'=>$request===null?'':'1','purchase_uses'=>$request['uses']??[]];
    }

    public static function view(int $year,array $snapshot,array $input,?array $saved,bool $bound):array
    {
        $out=['questions'=>[],'request'=>$saved,'error'=>''];
        try{
            if(($snapshot['entry']['kind']??'')!=='expense')return $out;
            $out['questions']=self::questions($year,$snapshot['positions']??[]);
            if(array_key_exists('purchase_form',$input)){
                if($input['purchase_form']!=='1')throw new DomainException('Rechnungszuordnung erneut prüfen.');
                $out['request']=self::form($input);
            }elseif(!$bound&&$out['questions'])$out['request']=['version'=>1,'uses'=>[]];
        }catch(DomainException $e){$out['error']=$e->getMessage();}
        return $out;
    }

    public static function form(array $form):?array
    {
        $enabled=$form['classify_purchases']??'';
        if(!is_string($enabled)||!in_array($enabled,['','1'],true))throw new DomainException('Rechnungszuordnung erneut prüfen.');
        $uses=$form['purchase_uses']??[];
        if(!is_array($uses)||count($uses)>50)throw new DomainException('Verwendung der Rechnungspositionen prüfen.');
        $out=[];
        foreach($uses as $ordinal=>$use){
            if(!preg_match('/^[1-9][0-9]?$/D',(string)$ordinal)||!is_string($use)||!in_array($use,['','material','goods','services'],true))throw new DomainException('Verwendung der Rechnungspositionen prüfen.');
            if($use!=='')$out[(int)$ordinal]=$use;
        }
        if($enabled==='')return null; // Unchecked UI may still contain the previous visible answers.
        ksort($out,SORT_NUMERIC);return ['version'=>1,'uses'=>$out];
    }

    public static function questions(int $year,array $positions):array
    {
        BalanceTaxMapping::preview($year,[]); // Exact original-year source hashes, no current-year fallback.
        $catalog=TaxCatalog::sourceData('skr42-'.$year.'.json');$byCode=[];
        foreach($catalog['accounts'] as $row)$byCode[$row['code']]=$row;
        $out=[];
        foreach($positions as $row){
            $code=(string)($row['account_code']??'');$original=$byCode[$code]??null;
            if(!$original||($original['function']??'')!==''||isset($original['alternatives'])||isset($original['range_issue'])||TaxCatalog::reserved($code,$year))continue;
            $use=match($code){'51000'=>'material','52000'=>'goods','59000'=>'services',default=>null};
            if($use===null&&($original['range_start']??'')!=='50000')continue;
            $ordinal=(int)($row['ordinal']??0);
            if($ordinal<1||$ordinal>99||isset($out[$ordinal]))throw new DomainException('Positionsnummern der Originalrechnung prüfen.');
            $out[$ordinal]=['description'=>(string)$row['description'],'source_code'=>$code,'use'=>$use??'',
                'choices'=>$use===null?['material'=>'Material zum Verbrauchen oder Verarbeiten','goods'=>'Ware zum unveränderten Weiterverkauf']:[]];
        }
        return $out;
    }

    public static function resolve(int $year,array $positions,mixed $request):array
    {
        if(!is_array($request)||array_keys($request)!==['version','uses']||$request['version']!==1||!is_array($request['uses']))throw new DomainException('Rechnungszuordnung erneut prüfen.');
        $checked=self::form(['classify_purchases'=>'1','purchase_uses'=>$request['uses']]);
        if($checked!==$request)throw new DomainException('Rechnungszuordnung erneut prüfen.');
        $questions=self::questions($year,$positions);$out=[];$rows=[];$derived=[];
        foreach($positions as $row)$rows[(int)$row['ordinal']]=$row;
        if(array_diff_key($request['uses'],$questions))throw new DomainException('Verwendung gehört nicht zu einer offenen Rechnungsposition.');
        foreach($questions as $ordinal=>$question){
            $row=$rows[$ordinal];$use=$request['uses'][$ordinal]??$question['use'];
            if($use===''||($question['use']!==''&&$use!==$question['use'])||($question['choices']&&!isset($question['choices'][$use])))throw new DomainException('Position '.$ordinal.': Material zum Verbrauchen/Verarbeiten oder Ware zum Weiterverkaufen auswählen.');
            if((int)$row['catalog_year']!==$year||$row['account_category']!=='expense')throw new DomainException('Originaljahr und Kostenart der Position prüfen.');
            $mode=$row['input_tax'];$rate=(string)$row['tax_rate'];$key=$row['booking_key'];$vat=(int)$row['tax_cents'];
            if(!in_array($rate,['0','7','19'],true)||$vat<0||($rate==='0'&&$vat!==0))throw new DomainException('Steuerbetrag der Originalrechnung prüfen.');
            if($mode==='none'||($rate==='0'&&$vat===0&&in_array($mode,['unknown','deductible'],true))){
                if($key!=='')throw new DomainException('Die gespeicherten Steuerangaben widersprechen sich. Rechnung prüfen lassen.');
                $code=match($use){'material'=>'51290','goods'=>'53490','services'=>'59090'};
            }elseif($mode==='deductible'&&in_array($rate,['7','19'],true)&&in_array($key,$rate==='7'?['8','402']:['9','401'],true)&&$vat>0){
                $keyRule=TaxCatalog::supportedKeys($year)[$key]??null;
                if(!$keyRule||$keyRule['direction']!=='expense'||$keyRule['local_rate']!==(int)$rate)throw new DomainException('Jahresbezogenen Steuerschlüssel prüfen.');
                $code=match($use){'material'=>$rate==='7'?'51100':'51300','goods'=>$rate==='7'?'53000':'54000','services'=>$rate==='7'?'59080':'59060'};
            }else throw new DomainException('Die steuerliche Behandlung dieser Position ist noch nicht geklärt. Rechnung prüfen lassen.');
            $a=TaxCatalog::accounts($year)[$code]??null;
            if(!$a||!$a['active']||$a['category']!=='expense')throw new DomainException('Geprüfte Jahreszuordnung fehlt.');
            $derived[$code]=['skr_code'=>$code,'name'=>$a['name'],'group'=>'expense','amount_cents'=>0];
            $out[$ordinal]=['source_code'=>$question['source_code'],'account_code'=>$code,'use'=>$use,'tax_rate'=>$rate,
                'input_tax'=>$mode,'booking_key'=>$key,'catalog_year'=>$year];
        }
        if($derived){
            $preview=BalanceTaxMapping::preview($year,$derived);
            foreach($out as &$resolved){
                $resolved['target']=$preview['mapping'][$resolved['account_code']]??throw new DomainException('Automatische Jahreszuordnung fehlt.');
            }unset($resolved);
        }
        return $out;
    }
}
