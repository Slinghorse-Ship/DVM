<?php declare(strict_types=1);
// Central authenticated POST/CSRF protection precedes this annual-only controller.
require_once __DIR__.'/BalanceTaxReconciliationForm.php';
$service=new \Kulturhus\Verein\BalanceTaxReconciliations($db,(string)$_SESSION['actor']);
$source=\Kulturhus\Verein\BalanceTaxReconciliationForm::source($_POST,$year);$version=$source['version'];
if(input($_POST,'tax_reconciliation_form_complete')!=='1'||input($_POST,'reviewed')!=='1')throw new DomainException('Angaben vollständig prüfen und bestätigen.');
$context=$service->forAction($year,$version,$source['clearing_source']['revision'],$source['clearing_source']['sha256'],$source['prior_source']);
if(!hash_equals($context['state']['income']['sha256'],$source['income_sha256']))throw new DomainException('Die ursprüngliche Ergebnisquelle dieses Auftrags stimmt nicht überein. Aktuellen Prüfstand getrennt öffnen.',409);
$expect=['clearing_source'=>$source['clearing_source'],'prior_source'=>$source['prior_source']];$note=input($_POST,'evidence');
if($action==='balance-tax-reconciliation-confirm')$result=$service->confirm($year,$version,$source['income_sha256'],true,$note,$expect);
else{
    $review=$context['draft'];$chart=$context['state']['income']['scope']['source']['accounts'];
    $amount=static function(string $value):int{
        if(!preg_match('/\A-?[0-9]{1,12}(?:[,.][0-9]{1,2})?\z/D',trim($value)))throw new DomainException('Eurobetrag ohne Tausendertrennzeichen eingeben, zum Beispiel 12,50.');
        return \Kulturhus\Verein\Finance::cents(trim($value));
    };
    $id=static function()use($year,$version,$action):string{
        $key=input($_POST,'tax_request_key');if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Auftragskennung vollständig prüfen.',422);
        return substr(hash('sha256',$year.':'.$version.':'.$action.':'.$key),0,32);
    };
    switch($action){
    case 'balance-tax-reconciliation-prepare':break;
    case 'balance-tax-reconciliation-value':
        $d=input($_POST,'debit_account');$c=input($_POST,'credit_account');$n=$amount(input($_POST,'amount'));
        if($d===$c||!isset($chart[$d],$chart[$c])||$n<=0)throw new DomainException('Zwei unterschiedliche Konten und einen positiven Betrag angeben.');
        $lines=[];foreach([$d,$c]as$j=>$key)$lines[]=['account'=>$key,'debit_cents'=>$j===0?$n:0,'credit_cents'=>$j===1?$n:0,'sphere'=>in_array($chart[$key]['group'],['expense','revenue'],true)?'4':''];
        $review['current_adjustments'][]=['id'=>$id(),'reason'=>$note,'lines'=>$lines];break;
    case 'balance-tax-reconciliation-off-balance':
        $review['off_balance_adjustments'][]=['id'=>$id(),'kind'=>input($_POST,'correction_kind'),'sources'=>[input($_POST,'source_line')=>$amount(input($_POST,'amount'))],'reason'=>$note,'legal_basis'=>input($_POST,'legal_basis'),'confirmed'=>true];break;
    case 'balance-tax-reconciliation-asset':
        $months=input($_POST,'months');if(!preg_match('/\A[0-9]{2,4}\z/D',$months))throw new DomainException('Nutzungsdauer in ganzen Monaten angeben.');
        $review['asset_tax_plans'][input($_POST,'asset')]=['months'=>(int)$months,'depreciation_account'=>input($_POST,'depreciation_account'),'reason'=>$note,'confirmed'=>true];break;
    case 'balance-tax-reconciliation-remove':
        $period=input($_POST,'period');$target=input($_POST,'adjustment_id');$found=false;
        if($period==='asset_tax_plans'){$found=isset($review[$period][$target]);unset($review[$period][$target]);}
        elseif(in_array($period,['current_adjustments','off_balance_adjustments'],true)){$kept=[];foreach($review[$period]as$row){if($row['id']===$target)$found=true;else$kept[]=$row;}$review[$period]=$kept;}
        if(!$found)throw new DomainException('Diese Korrektur ist nicht mehr vorhanden.');break;
    default:throw new DomainException('Unbekannter Vorgang zur steuerlichen Nebenrechnung.');
    }
    $result=$service->save($year,$version,$review,$note,$expect);
}
redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'tax_reconciliation'=>'1','tax_original'=>(string)$result['revision'],'tax_original_sha256'=>$result['sha256']]);
