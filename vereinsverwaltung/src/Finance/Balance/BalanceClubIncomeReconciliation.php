<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';

use DomainException;
require_once __DIR__.'/BalanceClearingStatement.php';
require_once __DIR__.'/BalancePeriodAssetAllocation.php';

/** Book-result reconciliation for ALL taxable units. Not a tax-value review or filing. */
final class BalanceClubIncomeReconciliation
{
    private const SOURCE=['year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule'];
    private const SCOPE=['schema','kind','club_id','source','allocation','clearing','review','previous'];

    private static function require(bool $ok,string $message='Ergebnisabgleich und unveränderte Originalbelege prüfen.'):void
    {
        if(!$ok)throw new DomainException($message);
    }
    private static function add(int $a,int $b):int
    {
        $n=$a+$b;self::require(abs($n)<=BalanceCalculation::LIMIT,'Betragsgrenze des Ergebnisabgleichs überschritten.');return $n;
    }

    /** Only the persistence service supplies current, permission-checked approvals. */
    public static function fromState(array $state,?array $previous=null):array
    {
        $segments=$state['segments']??[];$history=$state['history']??[];$segmentHistory=$segments['history']??[];
        $last=$history?end($history):null;$source=$segmentHistory?end($segmentHistory):null;
        self::require(($state['current_approval']??false)===true&&($state['source_current']??false)===true&&($state['carry_current']??false)===true
            &&($segments['carry_current']??false)===true&&($state['state']??null)==='reviewed'&&is_array($last)&&is_array($source),
            'Zuerst den aktuellen Bereichs- und Verrechnungsnachweis vom Vorstand prüfen lassen.');
        foreach([$last,$source] as $row){$p=$row;unset($p['sha256']);self::require(($row['sha256']??null)===BalanceSegmentCalculation::hash($p));}
        self::require($last['state']==='reviewed'&&$last['revision']===$state['version']&&$source['revision']===$segments['version']
            &&$last['club_id']===$source['club_id']&&$last['year']===$source['year']&&$source['year']===$segments['journal']['year']
            &&$last['segment_source']===['revision'=>$source['revision'],'sha256'=>$source['sha256']]
            &&$last['proof']===$state['proof']&&$source['allocation']===$segments['allocation']);
        $scope=['schema'=>1,'kind'=>'club_income_reconciliation','club_id'=>$last['club_id'],
            'source'=>array_replace(array_fill_keys(self::SOURCE,null),array_intersect_key($segments['journal'],array_flip(self::SOURCE))),
            'allocation'=>$segments['allocation'],'clearing'=>$state['proof'],
            'review'=>['allocation'=>['revision'=>$source['revision'],'sha256'=>$source['sha256']],
                'clearing'=>['revision'=>$last['revision'],'sha256'=>$last['sha256']]],
            'previous'=>$previous['scope']??null];
        if($previous!==null)self::require(self::rebuild($previous['scope']??[])===$previous);
        return self::rebuild($scope);
    }

    /** Combined dated review; both allocations and advances have the same board signature. */
    public static function fromPeriodState(array $state,?array $previous=null):array
    {
        $history=$state['history']??[];$last=$history?end($history):null;
        self::require(($state['current_approval']??false)===true&&($state['source_current']??false)===true&&($state['carry_current']??false)===true
            &&($state['state']??null)==='reviewed'&&is_array($last),'Zuerst die Aufteilung dieses Zeitraums vom Vorstand bestätigen lassen.');
        $payload=$last;unset($payload['sha256']);
        self::require($last['sha256']===BalanceSegmentCalculation::hash($payload)&&$last['state']==='reviewed'&&$last['revision']===$state['version']
            &&$last['period_id']===$state['period_id']&&$last['source']===$state['source']&&$last['proof']===$state['proof']);
        $source=array_replace(array_fill_keys(self::SOURCE,null),array_intersect_key($state['source'],array_flip(self::SOURCE)));
        $source['period']=BalancePeriod::check($state['source']['period'],$source['year']);
        $hasAssets=isset($state['proof']['assets']);if($hasAssets)$source['assets']=$state['proof']['assets'];
        $ref=['revision'=>$last['revision'],'sha256'=>$last['sha256']];
        if($previous!==null)self::require(self::rebuild($previous['scope']??[])===$previous);
        return self::rebuild(['schema'=>$hasAssets?3:2,'kind'=>'club_income_reconciliation','club_id'=>$last['club_id'],'source'=>$source,
            'allocation'=>$state['proof']['allocation'],'clearing'=>$state['proof']['clearing'],'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>$previous['scope']??null]);
    }

    /** Legacy signed allocation, including the explicit no-advance case, without new records. */
    public static function fromCalendarAreas(array $state,?array $previous=null):array
    {
        self::require(($state['current_approval']??false)===true&&is_array($state['proof']??null)&&!isset($state['source']['period']));
        if($previous!==null)self::require(self::rebuild($previous['scope']??[])===$previous);
        $source=array_replace(array_fill_keys(self::SOURCE,null),array_intersect_key($state['source'],array_flip(self::SOURCE)));
        return self::rebuild(['schema'=>1,'kind'=>'club_income_reconciliation','club_id'=>$state['proof']['clearing']['club_id'],'source'=>$source,
            'allocation'=>$state['proof']['allocation'],'clearing'=>$state['proof']['clearing'],'review'=>$state['review'],'previous'=>$previous['scope']??null]);
    }

    /** Recompute from original journal lines; a rehashed caller-supplied total is not evidence. */
    public static function rebuild(array $scope,int $depth=0):array
    {
        return BalanceSourceRead::run(static fn()=>self::rebuildFromSources($scope,$depth));
    }
    private static function rebuildFromSources(array $scope,int $depth=0):array
    {
        self::require($depth<=100&&array_keys($scope)===self::SCOPE&&in_array($scope['schema'],[1,2,3],true)&&$scope['kind']==='club_income_reconciliation');
        $r=$scope['source'];$a=$scope['allocation'];$w=$scope['clearing'];$review=$scope['review'];
        self::require(is_string($scope['club_id'])&&is_array($r)&&array_keys($r)===match($scope['schema']){3=>[...self::SOURCE,'period','assets'],2=>[...self::SOURCE,'period'],default=>self::SOURCE}&&is_array($a)&&is_array($w)&&is_array($review)
            &&array_keys($review)===['allocation','clearing']);
        foreach($review as $kind=>$binding)self::require(is_array($binding)&&array_keys($binding)===['revision','sha256']
            &&is_int($binding['revision'])&&$binding['revision']>=($kind==='clearing'?2:1)
            &&is_string($binding['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$binding['sha256'])===1);
        self::require(($w['club_id']??null)===$scope['club_id']);
        $statement=BalanceClearingStatement::build($r,$a,$w,$r['assets']['movements']??[]);
        self::require($statement['complete'],'Alle Bereiche, Anlagen und Verrechnungen vor dem Ergebnisabgleich abstimmen.');
        if($r['previous_sha256']!==null){
            self::require(is_array($scope['previous']),'Geprüften Ergebnisabgleich des Vorjahres nachweisen.');
            $prior=self::rebuild($scope['previous'],$depth+1);$p=$prior['scope'];
            self::require($p['club_id']===$scope['club_id']&&$w['previous']===$p['clearing'],'Vereins- und Verrechnungsbindung des Vorjahres prüfen.');
            $expected=BalanceClearingStatement::carry($p['source'],$p['allocation'],$p['clearing'],$r,$p['source']['assets']['movements']??[]);
            foreach($expected['units'] as $id=>$unit)self::require(($a['units'][$id]['sphere']??null)===$unit['sphere']);
            foreach($expected['allocations'] as $key=>$row)self::require(($a['allocations'][$key]['parts']??null)===$row['parts'],'Bereichs-Anfangswerte stimmen nicht mit dem Vorjahr überein.');
            self::require(($a['capital_carry']??[])===($expected['capital_carry']??[]));
            foreach($expected['asset_shares']??[] as $id=>$share)foreach(['weights','measure'] as $f)self::require(($a['asset_shares'][$id][$f]??null)===$share[$f]);
        }else self::require($scope['previous']===null&&$w['previous']===null,'Unerwarteten Vorjahresnachweis prüfen.');
        if($scope['schema']===3){
            $old=isset($p)?($p['source']['assets']??BalancePeriodAssetAllocation::calendar($p['source'],$p['allocation'])):null;
            $assets=BalancePeriodAssetAllocation::build($r,$a['units'],$statement['units'],$r['assets']['shares']??[],$old);
            self::require($assets===$r['assets']&&!$assets['issues'],'Anlagennutzung und Ergebnisabgleich stimmen nicht überein.');
        }elseif(isset($r['period']))self::require(empty($r['asset_schedule']['items']),'Zeitraumanlagen benötigen einen gebundenen Nutzungsnachweis.');

        $selected=array_keys(array_filter($a['units'],static fn($u)=>$u['sphere']==='4'));
        $rows=[];$lines=[];$unitResults=[];$wholeIncome=0;$wholeExpense=0;$income=0;$expense=0;
        foreach($r['accounts'] as $id=>$account){
            if(!in_array($account['group'],['revenue','expense'],true))continue;
            $rows[$id]=$account+['whole_debit_cents'=>0,'whole_credit_cents'=>0,'taxable_debit_cents'=>0,'taxable_credit_cents'=>0];
        }
        foreach(BalanceSegmentCalculation::sources($r) as $key=>$source){
            $line=$source['line'];$id=$line['account'];if(!isset($rows[$id]))continue;
            self::require($source['voucher']!=='opening');$parts=$a['allocations'][$key]['parts'];$chosen=0;
            foreach($selected as $unit)$chosen=self::add($chosen,$parts[$unit]??0);
            // Original GuV spheres are fixed by allocation, never inferred from payment source.
            self::require($chosen===($line['sphere']==='4'?$source['cents']:0));
            $side=$line['debit_cents']>0?'debit':'credit';
            $rows[$id]['whole_'.$side.'_cents']=self::add($rows[$id]['whole_'.$side.'_cents'],$source['cents']);
            $rows[$id]['taxable_'.$side.'_cents']=self::add($rows[$id]['taxable_'.$side.'_cents'],$chosen);
            $lines[$key]=['voucher'=>$source['voucher'],'date'=>$source['date'],'account'=>$id,'sphere'=>$line['sphere'],
                'debit_cents'=>$line['debit_cents'],'credit_cents'=>$line['credit_cents'],'parts'=>$parts,
                'taxable_debit_cents'=>$side==='debit'?$chosen:0,'taxable_credit_cents'=>$side==='credit'?$chosen:0];
        }
        foreach($rows as $id=>&$row){
            $sign=$row['group']==='expense'?1:-1;
            $row['whole_cents']=$sign*($row['whole_debit_cents']-$row['whole_credit_cents']);
            $row['taxable_book_cents']=$sign*($row['taxable_debit_cents']-$row['taxable_credit_cents']);
            $row['other_areas_cents']=self::add($row['whole_cents'],-$row['taxable_book_cents']);
            self::require($r['calculation']['accounts'][$id]['amount_cents']===$row['whole_cents']);
            $sum=0;foreach($selected as $unit)$sum=self::add($sum,$statement['units'][$unit]['accounts'][$id]['amount_cents']);
            self::require($sum===$row['taxable_book_cents']);
            if($row['group']==='expense'){$wholeExpense=self::add($wholeExpense,$row['whole_cents']);$expense=self::add($expense,$sum);}
            else{$wholeIncome=self::add($wholeIncome,$row['whole_cents']);$income=self::add($income,$sum);}
        }unset($row);
        foreach($statement['units'] as $id=>$unit)$unitResults[$id]=['name'=>$unit['name'],'sphere'=>$unit['sphere'],
            'income_cents'=>$unit['totals']['income_cents'],'expense_cents'=>$unit['totals']['expense_cents'],'result_cents'=>$unit['totals']['annual_result_cents']];
        $result=self::add($income,-$expense);$whole=self::add($wholeIncome,-$wholeExpense);
        self::require($result===$r['calculation']['spheres']['4']['income_cents']-$r['calculation']['spheres']['4']['expense_cents']
            &&$whole===$r['calculation']['annual_result_cents']);
        // Boundary balances are disclosed separately, never converted to GuV, external debt or final grants.
        $boundary=['opening_advanced_cents'=>0,'opening_received_cents'=>0,'closing_advanced_cents'=>0,'closing_received_cents'=>0,'within_taxable_closing_cents'=>0,'outside_taxable_closing_cents'=>0];
        foreach($w['cases'] as $case){
            $from=in_array($case['from'],$selected,true);$to=in_array($case['to'],$selected,true);
            if($from===$to){$f=$from?'within_taxable_closing_cents':'outside_taxable_closing_cents';$boundary[$f]=self::add($boundary[$f],$case['closing_cents']);continue;}
            foreach(['opening','closing'] as $period){$f=$period.($from?'_advanced_cents':'_received_cents');$boundary[$f]=self::add($boundary[$f],$case[$period.'_cents']);}
        }
        $payload=['schema'=>1,'kind'=>'club_income_reconciliation','year'=>$r['year'],'scope'=>$scope,
            'statement_sha256'=>$statement['sha256'],'selected_units'=>$selected,'units'=>$unitResults,'accounts'=>$rows,'lines'=>$lines,
            'result'=>['whole_cents'=>$whole,'taxable_book_cents'=>$result,'other_areas_cents'=>self::add($whole,-$result)],
            'internal_balances'=>$boundary,'tax_values_reviewed'=>false,'tax_export_ready'=>false];
        if($scope['schema']>=2){$payload['schema']=$scope['schema'];$payload['period']=$r['period'];}
        return $payload+['sha256'=>BalanceSegmentCalculation::hash($payload)];
    }

    /** Human-readable companion to the machine-checkable source proof. */
    public static function summary(array $value):string
    {
        self::require(self::rebuild($value['scope']??[])===$value);
        $money=static fn(int $n):string=>number_format($n/100,2,',','.').' EUR';
        $r=$value['result'];
        $label=isset($value['period'])?$value['period']['start'].' bis '.$value['period']['end']:(string)$value['year'];
        $lines=['Ergebnisabgleich '.$label,'',
            'Ergebnis des Gesamtvereins: '.$money($r['whole_cents']),
            'Davon steuerpflichtige Betriebe: '.$money($r['taxable_book_cents']),
            'Davon andere Vereinsbereiche: '.$money($r['other_areas_cents']),'',
            'Ergebnis je Vereinsbereich'];
        foreach($value['units'] as $unit)$lines[]=$unit['name'].': '.$money($unit['result_cents']);
        $lines[]='';$lines[]='Interne Vorschüsse am Jahresende';
        $lines[]='Vom steuerpflichtigen Bereich vorgestreckt: '.$money($value['internal_balances']['closing_advanced_cents']);
        $lines[]='Vom steuerpflichtigen Bereich zurückzuzahlen: '.$money($value['internal_balances']['closing_received_cents']);
        $lines[]='';$lines[]='Grundlage: festgeschriebene Originalbuchungen und geprüfte Bereichsaufteilung. Interne Vorschüsse und Rückzahlungen verändern das Ergebnis nicht.';
        $lines[]='Dies sind Buchführungsergebnisse vor gesonderten steuerlichen Anpassungen, keine Steuererklärung.';
        $lines[]='Nachweis: '.$value['sha256'];
        return implode("\n",$lines)."\n";
    }
}
