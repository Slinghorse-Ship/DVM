<?php declare(strict_types=1);
// Central authenticated POST/CSRF protection precedes this dated-only controller.
require_once __DIR__.'/BalancePeriodTaxReconciliationForm.php';
require_once __DIR__.'/BalancePeriodTaxReconciliations.php';
$service=new \Kulturhus\Verein\BalancePeriodTaxReconciliations($db,(string)$_SESSION['actor']);
$source=\Kulturhus\Verein\BalancePeriodTaxReconciliationForm::source($_POST);$taxPeriodId=$source['period_id'];$version=$source['version'];
if(input($_POST,'tax_reconciliation_form_complete')!=='1'||input($_POST,'reviewed')!=='1')throw new DomainException('Angaben vollständig prüfen und bestätigen.');
$context=$service->forAction($taxPeriodId,$version,$source['area_source']['revision'],$source['area_source']['sha256'],$source['prior_source']);
if(!hash_equals($context['state']['income']['sha256'],$source['income_sha256']))throw new DomainException('Die ursprüngliche Ergebnisquelle dieses Auftrags stimmt nicht überein. Aktuellen Prüfstand getrennt öffnen.',409);
$expect=['area_source'=>$source['area_source'],'prior_source'=>$source['prior_source']];$note=input($_POST,'evidence');
if($action==='balance-period-tax-confirm')$result=$service->confirm($taxPeriodId,$version,$source['income_sha256'],true,$note,$expect);
else{
    $review=$context['draft'];$chart=$context['state']['income']['scope']['source']['accounts'];
    $amount=static function(string $value):int{
        if(!preg_match('/\A-?[0-9]{1,12}(?:[,.][0-9]{1,2})?\z/D',trim($value)))throw new DomainException('Eurobetrag ohne Tausendertrennzeichen eingeben, zum Beispiel 12,50.');
        return \Kulturhus\Verein\Finance::cents(trim($value));
    };
    $id=static function()use($taxPeriodId,$version,$action):string{
        $key=input($_POST,'tax_request_key');if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Auftragskennung vollständig prüfen.',422);
        return substr(hash('sha256',$taxPeriodId.':'.$version.':'.$action.':'.$key),0,32);
    };
    switch($action){
    case 'balance-period-tax-prepare':break;
    case 'balance-period-tax-value':
        $d=input($_POST,'debit_account');$c=input($_POST,'credit_account');$n=$amount(input($_POST,'amount'));
        if($d===$c||!isset($chart[$d],$chart[$c])||$n<=0)throw new DomainException('Zwei unterschiedliche Konten und einen positiven Betrag angeben.');
        $lines=[];foreach([$d,$c]as$j=>$key)$lines[]=['account'=>$key,'debit_cents'=>$j===0?$n:0,'credit_cents'=>$j===1?$n:0,'sphere'=>in_array($chart[$key]['group'],['expense','revenue'],true)?'4':''];
        $review['current_adjustments'][]=['id'=>$id(),'reason'=>$note,'lines'=>$lines];break;
    case 'balance-period-tax-off-balance':
        $review['off_balance_adjustments'][]=['id'=>$id(),'kind'=>input($_POST,'correction_kind'),'sources'=>[input($_POST,'source_line')=>$amount(input($_POST,'amount'))],'reason'=>$note,'legal_basis'=>input($_POST,'legal_basis'),'confirmed'=>true];break;
    case 'balance-period-tax-asset':
        $months=input($_POST,'months');if(!preg_match('/\A[0-9]{2,4}\z/D',$months))throw new DomainException('Nutzungsdauer in ganzen Monaten angeben.');
        $review['asset_tax_plans'][input($_POST,'asset')]=['months'=>(int)$months,'depreciation_account'=>input($_POST,'depreciation_account'),'reason'=>$note,'confirmed'=>true];break;
    case 'balance-period-tax-remove':
        $period=input($_POST,'period');$target=input($_POST,'adjustment_id');$found=false;
        if($period==='asset_tax_plans'){$found=isset($review[$period][$target]);unset($review[$period][$target]);}
        elseif(in_array($period,['current_adjustments','off_balance_adjustments'],true)){$kept=[];foreach($review[$period]as$row){if($row['id']===$target)$found=true;else$kept[]=$row;}$review[$period]=$kept;}
        if(!$found)throw new DomainException('Diese Korrektur ist nicht mehr vorhanden.');break;
    default:throw new DomainException('Unbekannter Vorgang zur steuerlichen Nebenrechnung.');
    }
    $result=$service->save($taxPeriodId,$version,$review,$note,$expect);
}
redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$taxPeriodId,'period_tax'=>'1','period_tax_original'=>(string)$result['revision'],'period_tax_original_sha256'=>$result['sha256']]);
