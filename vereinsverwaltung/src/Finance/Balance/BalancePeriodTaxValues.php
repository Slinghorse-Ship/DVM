<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalancePeriodTaxAssetValues.php';

/** Working tax ledger with explicit dates; never an official-filing permission. */
final class BalancePeriodTaxValues
{
    private static function require(bool $ok,string $why):void{if(!$ok)throw new DomainException($why);}
    private static function entry(mixed $e,array &$seen,array $chart,bool $opening):array
    {
        self::require(is_array($e)&&array_keys($e)===['id','reason','lines']&&is_string($e['id'])&&preg_match('/^[a-z0-9_-]{1,40}$/D',$e['id'])&&!isset($seen[$e['id']])
            &&is_string($e['reason'])&&trim($e['reason'])!==''&&mb_strlen($e['reason'])<=1500&&!preg_match('/[\x00-\x1f]/',$e['reason']),'Eindeutige steuerliche Wertänderung mit Nachweis angeben.');
        $seen[$e['id']]=true;return BalanceCalculation::lines($e['lines'],$chart,$opening);
    }
    public static function carryForward(array $prior,array $priorValues,array $report):array
    {
        $period=BalancePeriod::check($report['period'],$report['year']);$previous=$prior['period']??BalancePeriod::calendar($prior['year']);
        $previousHash=$report['previous_sha256']??$report['previous']['sha256']??null;
        self::require(BalancePeriod::follows($previous,$period)&&$previousHash===$prior['closed_sha256']&&$report['result_account']===$prior['result_account'],'Unmittelbaren Steuer-Vorzeitraum mit unveränderten Originalwerten übernehmen.');
        self::require($priorValues['journal_sha256']===$prior['closed_sha256']&&$priorValues['book']===$prior['calculation'],'Steuerlicher Vortrag gehört nicht zum bestätigten Vorabschluss.');
        foreach($prior['accounts'] as $key=>$a)self::require(isset($report['accounts'][$key])&&$a['group']===$report['accounts'][$key]['group']&&$a['skr_code']===$report['accounts'][$key]['skr_code'],'Konten des Vorzeitraums unverändert vortragen.');
        $net=static function(array $lines)use($report):array{$out=array_fill_keys(array_keys($report['accounts']),0);foreach($lines as $v)$out[$v['account']]+=$v['debit_cents']-$v['credit_cents'];ksort($out);return $out;};
        $book=$net(BalanceCalculation::carryForward($priorValues['book'],$report['result_account']));self::require($book===$net($report['opening']),'Buch-Anfangswerte und bestätigter Vorabschluss stimmen nicht überein.');
        $tax=$net(BalanceCalculation::carryForward($priorValues['tax'],$report['result_account']));$lines=[];
        foreach($tax as $id=>$v){$difference=$v-$book[$id];if($difference)$lines[]=['account'=>$id,'debit_cents'=>max(0,$difference),'credit_cents'=>max(0,-$difference),'sphere'=>''];}
        return ['source_values_sha256'=>$priorValues['sha256'],'source_journal_sha256'=>$prior['closed_sha256'],
            'entry'=>$lines?['id'=>'carry-'.substr($priorValues['sha256'],0,32),'reason'=>'Unveränderter steuerlicher Wertvortrag aus '.$previous['start'].' bis '.$previous['end'].'.','lines'=>BalanceCalculation::lines($lines,$report['accounts'],true)]:null];
    }
    public static function build(array $report,array $review,?array $previous=null,bool $wholeTaxable=false,string $section=''):array
    {
        $period=BalancePeriod::check($report['period'],$report['year']);$year=$report['year'];$chart=BalanceCalculation::chart($report['accounts']);
        self::require($report['state']==='closed'&&$report['issues']===[]&&preg_match('/^[a-f0-9]{64}$/D',$report['closed_sha256'])&&($review['complete']??null)===true
            &&($review['period']??null)===$period&&($review['year']??null)===$year&&($review['journal_sha256']??null)===$report['closed_sha256'],'Steuerwerte für genau diesen bestätigten Abschlusszeitraum prüfen.');
        $originalEntries=array_values($report['entries']);
        $book=BalanceCalculation::build($year,$chart,$report['opening'],$originalEntries,$period);
        self::require($book===$report['calculation']&&!$book['issues'],'Steuerliche Ausgangswerte stimmen nicht mit dem Originaljournal überein.');
        foreach(['opening_adjustments','current_adjustments','asset_tax_plans'] as $field)self::require(is_array($review[$field]??null),'Wertänderungen und Steuer-Anlagenpläne vollständig angeben.');
        self::require(array_is_list($review['opening_adjustments'])&&array_is_list($review['current_adjustments'])&&count($review['opening_adjustments'])+count($review['current_adjustments'])<=200,'Steuerliche Wertänderungen prüfen.');
        $assets=BalancePeriodTaxAssetValues::prepare($report,$review['asset_tax_plans'],$previous['asset_tax']??null,$wholeTaxable,$section);
        $chart=BalanceCalculation::chart($chart+$assets['additional_accounts']);
        $managed=array_fill_keys($assets['managed_accounts'],true);
        foreach($chart as $key=>$a)if($a['group']==='fixed_assets'||in_array($a['skr_code'],['62000','62200','62600','48450','48490','48550','68850','68890','68950','68960'],true))$managed[$key]=true;
        $openingNet=array_fill_keys(array_keys($chart),0);$adjustmentNet=$openingNet;$seen=[];
        foreach($report['opening'] as $line)$openingNet[$line['account']]+=$line['debit_cents']-$line['credit_cents'];
        foreach($review['opening_adjustments'] as $e)foreach(self::entry($e,$seen,$chart,true) as $v){$d=$v['debit_cents']-$v['credit_cents'];$openingNet[$v['account']]+=$d;$adjustmentNet[$v['account']]+=$d;}
        foreach($chart as $key=>$a)if($a['group']==='fixed_assets')self::require($adjustmentNet[$key]===($assets['desired_opening'][$key]??0),'Anlagen-Anfangsunterschiede unverändert aus dem bestätigten Steuer-Vorzeitraum übernehmen.');
        $taxOpening=[];foreach($openingNet as $key=>$v)if($v)$taxOpening[]=['account'=>$key,'debit_cents'=>max(0,$v),'credit_cents'=>max(0,-$v),'sphere'=>''];
        $current=$review['current_adjustments'];
        foreach($current as $e)foreach($e['lines']??[] as $v)self::require(!isset($managed[$v['account']??'']),'Anlagen-Steuerwerte durch ihre Pläne berechnen, nicht zusätzlich manuell buchen.');
        $current=array_merge($current,$assets['generated_current']);$vouchers=$originalEntries;
        foreach($current as $e)$vouchers[]=['id'=>'tax-adjustment-'.$e['id'],'date'=>$period['end'],'lines'=>self::entry($e,$seen,$chart,false)];
        $tax=BalanceCalculation::build($year,$chart,$taxOpening,$vouchers,$period);self::require(!$tax['issues'],'Steuerliche Bilanzwerte sind nicht abgestimmt: '.implode(' ',$tax['issues']));
        require_once __DIR__.'/BalanceAccountClosing.php';BalanceAccountClosing::calculative($year,$chart,$originalEntries);BalanceAccountClosing::calculative($year,$chart,$vouchers);
        $accountTotals=[];foreach($assets['tax_schedule']['items'] as $id=>$item){$key=$report['asset_schedule']['items'][$id]['fixed_account'];foreach(['opening','closing'] as $f)$accountTotals[$key][$f]=($accountTotals[$key][$f]??0)+$item['schedule']['book_'.$f.'_cents'];}
        foreach($chart as $key=>$a)if($a['group']==='fixed_assets')foreach(['opening','closing'] as $f)self::require(($accountTotals[$key][$f]??0)===$tax['accounts'][$key][$f.'_cents'],'Steuerliches Anlagenverzeichnis und Steuerbilanz stimmen nicht überein.');
        $rows=[];foreach($chart as $id=>$row){$original=$book['accounts'][$id]??['opening_cents'=>0,'amount_cents'=>0];$rows[$id]=['name'=>$row['name'],'group'=>$row['group'],'skr_code'=>$row['skr_code'],
            'book_opening_cents'=>$original['opening_cents'],'tax_opening_cents'=>$tax['accounts'][$id]['opening_cents'],'book_cents'=>$original['amount_cents'],
            'tax_cents'=>$tax['accounts'][$id]['amount_cents'],'difference_cents'=>$tax['accounts'][$id]['amount_cents']-$original['amount_cents']];}
        $spheres=[];foreach($vouchers as $v)foreach($v['lines'] as $l)if($l['sphere']!=='')$spheres[$l['sphere']]=true;$spheres=array_map('strval',array_keys($spheres));sort($spheres,SORT_STRING);
        $payload=['period'=>$period,'year'=>$year,'journal_sha256'=>$report['closed_sha256'],'accounts'=>$rows,'book'=>$book,'tax'=>$tax,'opening_adjustments'=>$review['opening_adjustments'],
            'current_adjustments'=>$current,'result_difference_cents'=>$tax['annual_result_cents']-$book['annual_result_cents'],'source_spheres'=>$spheres,'asset_schedule'=>$report['asset_schedule'],'asset_tax'=>$assets];
        return $payload+['sha256'=>BalanceSegmentCalculation::hash($payload),'transmission'=>'unavailable'];
    }
}
