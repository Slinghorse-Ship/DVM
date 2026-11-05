<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalancePeriodAssets.php';
require_once __DIR__.'/BalanceSegmentCalculation.php';

/** Original book plan plus a separate, evidenced tax-basis clock. No original is rewritten. */
final class BalancePeriodSphereChange
{
    public const CASES=['exempt_to_exempt','exempt_book','exempt_value','taxable_value','taxable_return'];
    private static function require(bool $ok,string $why):void{if(!$ok)throw new DomainException($why);}
    private static function months(string $from,string $to):int
    {return ((int)substr($to,0,4)-(int)substr($from,0,4))*12+(int)substr($to,5,2)-(int)substr($from,5,2);}
    private static function value(array $clock,string $boundary,bool $daily=false):int
    {
        if($daily)return $clock['basis_cents']-BalancePeriodDayAllocation::cumulative($clock['basis_cents'],$clock['residual_cents'],$clock['months'],$clock['active_on']??$clock['on'],BalancePeriodDayAllocation::before($boundary));
        $elapsed=max(0,min($clock['months'],self::months($clock['on'],$boundary)));
        return $clock['basis_cents']-intdiv(($clock['basis_cents']-$clock['residual_cents'])*$elapsed+intdiv($clock['months'],2),$clock['months']);
    }
    private static function date(string $value):bool
    {return (bool)TaxCatalog::documentYear($value)&&substr($value,8)==='01';}
    private static function change(array $c,array $plan,string $last):array
    {
        self::require(array_keys($c)===['on','to_sphere','case','value_cents','remaining_months','residual_cents','document_id','revision','sha256','reason','charitable_use','funds_reviewed','vat_reviewed','tax_reviewed','history_reviewed'], 'Bereichswechsel mit vollständigem Bewertungsnachweis angeben.');
        self::require(is_string($c['on'])&&self::date($c['on'])&&$c['on']>$last&&$c['on']>$plan['acquired_on']
            &&in_array($c['to_sphere'],['1','2','3','4'],true)&&in_array($c['case'],self::CASES,true), 'Monatsbeginn nach der Anschaffung und einen anderen Vereinsbereich auswählen.');
        foreach(['value_cents','remaining_months','residual_cents'] as $key)self::require(is_int($c[$key])&&$c[$key]>=0&&$c[$key]<=BalanceCalculation::LIMIT,'Bewertung in ganzen Cent und Restnutzungsdauer in Monaten prüfen.');
        self::require(is_string($c['document_id'])&&preg_match('/^[a-f0-9]{32}$/D',$c['document_id'])&&is_int($c['revision'])&&$c['revision']>0
            &&is_string($c['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$c['sha256']), 'Unveränderten Originalnachweis für den Bereichswechsel auswählen.');
        self::require(is_string($c['reason'])&&trim($c['reason'])!==''&&mb_strlen($c['reason'])<=1000&&!preg_match('/[\x00-\x1f]/',$c['reason']), 'Grund und Bewertungsnachweis des Bereichswechsels angeben.');
        self::require(is_bool($c['charitable_use']),'Steuerbegünstigte Verwendung ausdrücklich klären.');
        foreach(['funds_reviewed','vat_reviewed','tax_reviewed','history_reviewed'] as $key)self::require($c[$key]===true,'Mittelbindung, Umsatzsteuer, bisherige Nutzung und steuerliche Bewertung fachlich prüfen.');
        $c['reason']=trim($c['reason']);return $c;
    }
    /** Independently replay a stored proof. The caller still binds its predecessor to the journal. */
    public static function rebuild(array $proof):array
    {
        self::require(array_keys($proof)===['schema','period','plan','changes','previous_sha256','history','book','tax','closing_sphere','sha256']
            &&$proof['schema']===1&&is_array($proof['plan'])&&is_array($proof['period'])&&is_array($proof['history'])&&is_array($proof['changes'])
            &&is_string($proof['sha256'])&&($proof['previous_sha256']===null||(is_string($proof['previous_sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$proof['previous_sha256']))),
            'Vollständigen ursprünglichen Bereichswechsel nachweisen.');
        $period=BalancePeriod::check($proof['period']);$dayPolicy=$proof['book']['allocation_policy']??null;
        if($dayPolicy===null)BalancePeriod::fullMonths($period);else self::require($dayPolicy===BalancePeriodDayAllocation::POLICY,'Bestätigten internen Bereichs-Tagesnachweis verwenden.');
        // The ordinary builder also validates original method, account and valuation limits.
        self::build($proof['plan'],$period,[],null,$dayPolicy);
        $v=self::calculate($proof['plan'],$period,$proof['history'],$dayPolicy);
        $changes=array_values(array_filter($v['history'],static fn(array $c):bool=>$c['on']>=$period['start']));
        self::require(count($changes)<=12,'Höchstens zwölf Bereichswechsel je Abschlusszeitraum nachweisen.');
        $expected=['schema'=>1,'period'=>$period,'plan'=>$proof['plan'],'changes'=>$changes,'previous_sha256'=>$proof['previous_sha256'],
            'history'=>$v['history'],'book'=>$v['book'],'tax'=>$v['tax'],'closing_sphere'=>$v['closing_sphere']];
        self::require($proof===$expected+['sha256'=>BalanceSegmentCalculation::hash($expected)],'Bereichswerte stimmen nicht mit den ursprünglichen Bewertungsnachweisen überein.');
        return $proof;
    }
    /** Replay ALL evidenced transitions, including inactive tax periods, to avoid resetting AfA on return. */
    public static function build(array $plan,array $period,array $changes,?array $previous=null,?string $dayPolicy=null):array
    {
        $period=BalancePeriod::check($period);if($dayPolicy===null)BalancePeriod::fullMonths($period);else self::require($dayPolicy===BalancePeriodDayAllocation::POLICY,'Bestätigten internen Bereichs-Tagesnachweis verwenden.');
        self::require(($plan['method']??'linear_monthly')==='linear_monthly'&&!isset($plan['gwg'])&&!isset($plan['degressive'])
            &&in_array($plan['account']??null,AssetTypes::codes('linear'),true)&&in_array($plan['sphere']??null,['1','2','3','4'],true), 'Bereichswechsel für diese Abschreibungsmethode gesondert abstimmen.');
        // Validate every original valuation field with the ordinary book calculator.
        $book=BalancePeriodAssets::calculate($plan,$period,null,$dayPolicy);
        self::require($plan['acquired_on']<=$period['end']&&is_array($changes)&&array_is_list($changes)&&count($changes)<=12,'Anlage und höchstens zwölf Wechsel in diesem Zeitraum auswählen.');
        $history=[];
        if($previous!==null){
            self::require(array_keys($previous)===['schema','period','plan','changes','previous_sha256','history','book','tax','closing_sphere','sha256']&&$previous['schema']===1
                &&$previous['plan']===$plan&&is_array($previous['period'])&&is_array($previous['history'])&&is_array($previous['changes'])
                &&is_array($previous['book'])&&is_array($previous['tax'])&&is_string($previous['sha256'])
                &&BalancePeriod::follows($previous['period'],$period),'Unveränderten unmittelbaren Bereichs-Vorzeitraum übernehmen.');
            $old=$previous;unset($old['sha256']);self::require(hash_equals(BalanceSegmentCalculation::hash($old),$previous['sha256']),'Bereichs-Vortrag wurde verändert.');
            $previousPolicy=$previous['book']['allocation_policy']??null;
            self::require($previousPolicy===null||$previousPolicy===$dayPolicy,'Bestätigten ursprünglichen Tagesvortrag nicht als anderen Monatsplan fortführen.');
            $replay=self::calculate($plan,$previous['period'],$previous['history'],$previousPolicy);
            foreach(['book','tax','closing_sphere'] as $key)self::require($previous[$key]===$replay[$key],'Bisherige Bereichswerte sind nicht nachrechenbar.');
            $periodChanges=array_values(array_filter($replay['history'],static fn(array $c):bool=>$c['on']>=$previous['period']['start']));
            self::require($previous['history']===$replay['history']&&$previous['changes']===$periodChanges,'Bereichswechsel und ursprünglicher Abschlusszeitraum widersprechen sich.');
            $history=$previous['history'];
        }
        foreach($changes as $c){self::require(is_array($c)&&is_string($c['on']??null)&&BalancePeriod::contains($period,$c['on']),'Neuen Bereichswechsel im gewählten Abschlusszeitraum angeben.');$history[]=$c;}
        self::require(count($history)<=120,'Zu viele Bereichswechsel an dieser Anlage.');
        $calculated=self::calculate($plan,$period,$history,$dayPolicy);
        self::require($calculated['book']['schedule']===$book,'Bereichswechsel verändert den ursprünglichen Buchplan.');
        if($previous!==null){
            self::require($previous['closing_sphere']===$calculated['book']['opening_sphere']&&$previous['tax']['closing_cents']===$calculated['tax']['opening_cents'],'Bereichs- und Steuerwerte stimmen nicht mit dem Vorzeitraum überein.');
        }
        $result=['schema'=>1,'period'=>$period,'plan'=>$plan,'changes'=>$changes?array_slice($calculated['history'],-count($changes)):[],
            'previous_sha256'=>$previous['sha256']??null,'history'=>$calculated['history'],'book'=>$calculated['book'],'tax'=>$calculated['tax'],'closing_sphere'=>$calculated['closing_sphere']];
        return $result+['sha256'=>BalanceSegmentCalculation::hash($result)];
    }
    private static function calculate(array $plan,array $period,array $history,?string $dayPolicy=null):array
    {
        self::require($dayPolicy===null||$dayPolicy===BalancePeriodDayAllocation::POLICY,'Ursprüngliche Aufteilungsregel des Bereichsnachweises prüfen.');$daily=$dayPolicy!==null;
        self::require(array_is_list($history)&&count($history)<=120,'Chronologischen Wechselnachweis prüfen.');
        $clock=['on'=>substr($plan['acquired_on'],0,7).'-01','basis_cents'=>$plan['basis_cents'],'months'=>$plan['months'],'residual_cents'=>$plan['residual_cents']];
        if($daily)$clock['active_on']=$plan['acquired_on'];
        $bookClock=$clock;$sphere=$plan['sphere'];$everTaxable=$sphere==='4';$exit=null;$last='';$clean=[];$byMonth=[];
        foreach($history as $c){self::require(is_array($c),'Bereichsnachweis prüfen.');$c=self::change($c,$plan,$last);self::require($c['on']<=$period['end'],'Künftigen Wechsel nicht in den bisherigen Nachweis aufnehmen.');$clean[]=$c;$byMonth[$c['on']]=$c;$last=$c['on'];}
        $tax=['opening_cents'=>0,'additions_cents'=>0,'contributions_cents'=>0,'withdrawals_cents'=>0,'valuation_result_cents'=>0,'depreciation_cents'=>0,'closing_cents'=>0,'movements'=>[]];
        $parts=array_fill_keys(['1','2','3','4'],0);$bookMoves=[];$openingSphere=null;
        // Include the acquisition month even if the first reported period begins later.
        for($on=$bookClock['on'];$on<=$period['end'];$on=(new \DateTimeImmutable($on))->modify('+1 month')->format('Y-m-d')){
            $inside=$on>=$period['start'];$next=(new \DateTimeImmutable($on))->modify('+1 month')->format('Y-m-d');
            if($on===$period['start']||($openingSphere===null&&$inside)){$openingSphere=$sphere;$tax['opening_cents']=$plan['acquired_on']<$period['start']&&$sphere==='4'?self::value($clock,$daily?$period['start']:$on,$daily):0;}
            if(($daily?BalancePeriod::contains($period,$plan['acquired_on']):$inside)&&$on===$bookClock['on']&&$sphere==='4')$tax['additions_cents']+=$plan['basis_cents'];
            if(isset($byMonth[$on])){
                $c=$byMonth[$on];$to=$c['to_sphere'];self::require($to!==$sphere,'Der neue Vereinsbereich ist unverändert.');
                $oldValue=self::value($clock,$on,$daily);$incoming=0;$outgoing=0;$gain=0;
                if($sphere!=='4'&&$to!=='4'){
                    self::require($c['case']==='exempt_to_exempt'&&$c['value_cents']===0&&$c['remaining_months']===0&&$c['residual_cents']===0,'Zwischen steuerfreien Bereichen keinen neuen Steuerwert erfinden.');
                }elseif($sphere==='4'){
                    self::require(in_array($c['case'],['exempt_book','exempt_value'],true)&&$c['remaining_months']===0&&$c['residual_cents']===0,'Steuerlichen Schlusswert beim Verlassen des Geschäftsbetriebs prüfen.');
                    if($c['case']==='exempt_book')self::require($c['charitable_use']===true&&$c['value_cents']===$oldValue,'Buchwertfortführung benötigt bestätigte steuerbegünstigte Verwendung und unveränderten Steuerbuchwert.');
                    else self::require($c['charitable_use']===false,'Bei steuerbegünstigter Verwendung die zutreffende Buchwertregel prüfen.');
                    $outgoing=$c['value_cents'];$gain=$outgoing-$oldValue;$exit=$c['case'];
                }else{
                    self::require($c['case']===($everTaxable&&$exit==='exempt_book'?'taxable_return':'taxable_value'),'Erstmalige steuerliche Nutzung und Rückkehr nach Buchwertübertragung unterscheiden.');
                    if($c['case']==='taxable_return'){
                        self::require($c['value_cents']===$oldValue&&$c['remaining_months']===0&&$c['residual_cents']===0,'Bei Rückkehr den ununterbrochen fortgerechneten Steuerwert übernehmen; Abschreibung nicht neu beginnen.');
                    }else{
                        self::require($c['value_cents']>0&&$c['remaining_months']>=1&&$c['remaining_months']<=1200&&$c['residual_cents']<$c['value_cents'],'Nachgewiesenen Teilwert und verbleibende Nutzungsdauer prüfen.');
                        $clock=['on'=>$on,'basis_cents'=>$c['value_cents'],'months'=>$c['remaining_months'],'residual_cents'=>$c['residual_cents']];
                    }
                    $incoming=$c['value_cents'];$everTaxable=true;
                }
                if($inside){
                    $net=self::value($bookClock,$on,$daily);$bookMoves[]=['on'=>$on,'from_sphere'=>$sphere,'to_sphere'=>$to,'cost_cents'=>$plan['basis_cents'],'accumulated_cents'=>$plan['basis_cents']-$net,'book_cents'=>$net];
                    $tax['contributions_cents']+=$incoming;$tax['withdrawals_cents']+=$outgoing;$tax['valuation_result_cents']+=$gain;
                    $tax['movements'][]=['on'=>$on,'case'=>$c['case'],'from_sphere'=>$sphere,'to_sphere'=>$to,'before_cents'=>$sphere==='4'?$oldValue:0,'after_cents'=>$to==='4'?self::value($clock,$on,$daily):0,'contribution_cents'=>$incoming,'withdrawal_cents'=>$outgoing,'result_cents'=>$gain];
                }
                $sphere=$to;
            }
            if($daily){
                if($openingSphere===null&&$on<$period['start']&&$next>$period['start']){$openingSphere=$sphere;$tax['opening_cents']=$plan['acquired_on']<$period['start']&&$sphere==='4'?self::value($clock,$period['start'],true):0;}
                $begin=max($on,$period['start'],$plan['acquired_on']);$until=min($next,(new \DateTimeImmutable($period['end']))->modify('+1 day')->format('Y-m-d'));
                if($begin<$until){$parts[$sphere]+=self::value($bookClock,$begin,true)-self::value($bookClock,$until,true);if($sphere==='4')$tax['depreciation_cents']+=self::value($clock,$begin,true)-self::value($clock,$until,true);}
            }elseif($inside){$parts[$sphere]+=self::value($bookClock,$on)-self::value($bookClock,$next);if($sphere==='4')$tax['depreciation_cents']+=self::value($clock,$on)-self::value($clock,$next);}
        }
        $tax['closing_cents']=$sphere==='4'?self::value($clock,(new \DateTimeImmutable($period['end']))->modify('+1 day')->format('Y-m-d'),$daily):0;
        foreach($tax as $key=>$amount)if($key!=='movements')self::require(abs($amount)<=BalanceCalculation::LIMIT,'Betragsgrenze der Bereichsrechnung überschritten.');
        self::require($tax['opening_cents']+$tax['additions_cents']+$tax['contributions_cents']-$tax['withdrawals_cents']+$tax['valuation_result_cents']-$tax['depreciation_cents']===$tax['closing_cents'],'Steuerlicher Bereichsvortrag ist nicht ausgeglichen.');
        $schedule=BalancePeriodAssets::calculate($plan,$period,null,$dayPolicy);self::require(array_sum($parts)===$schedule['depreciation_cents'],'Bereichsabschreibungen verändern die ursprüngliche Abschreibung.');
        $book=['schedule'=>$schedule,'opening_sphere'=>$openingSphere??$plan['sphere'],'depreciation_by_sphere'=>$parts,'movements'=>$bookMoves];if($daily)$book['allocation_policy']=$dayPolicy;
        return ['history'=>$clean,'book'=>$book,'tax'=>$tax,'closing_sphere'=>$sphere];
    }
}
