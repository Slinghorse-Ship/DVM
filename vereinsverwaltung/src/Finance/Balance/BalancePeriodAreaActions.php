<?php declare(strict_types=1);
// Reached only after the main POST/CSRF verification. Services repeat all permissions.
require_once __DIR__.'/BalancePeriodAreas.php';
require_once __DIR__.'/BalancePeriodAreaForm.php';
$areas=new \Kulturhus\Verein\BalancePeriodAreas($db,(string)$_SESSION['actor']);
$selection=\Kulturhus\Verein\BalancePeriodAreaForm::source($_POST);$id=$selection['period_id'];$version=$selection['version'];$hash=$selection['closed_hash'];$note=input($_POST,'evidence');$expect=['prior_source'=>$selection['prior_source']];
$s=$areas->forAction($id,$version,$hash,$selection['prior_source']);$units=$s['input']['units']??$s['prior']['input']['units']??\Kulturhus\Verein\BalanceSegments::defaults();
switch($action){
case 'balance-period-area-prepare':$result=$areas->prepare($id,$version,$hash,$units,$note,$expect);break;
case 'balance-period-area-unit':
    $key=input($_POST,'unit_id');if($key!==''&&!isset($units[$key]))throw new DomainException('Bereich nicht gefunden.');
    if($key==='')$key='unit-'.substr(hash('sha256',$id.':'.$version.':'.$selection['request_key']),0,16);$units[$key]=['name'=>input($_POST,'unit_name'),'sphere'=>input($_POST,'unit_sphere')];
    $result=$areas->prepare($id,$version,$hash,$units,$note,$expect);break;
case 'balance-period-area-voucher':$result=$areas->edit($id,$version,$hash,'voucher',['voucher'=>input($_POST,'area_voucher'),'unit'=>input($_POST,'unit_id')],$note,$expect);break;
case 'balance-period-area-line':
    $parts=$_POST['parts']??null;$out=[];if(!is_array($parts)||array_diff_key($parts,$units))throw new DomainException('Bereichsbeträge prüfen.');
    foreach($parts as $key=>$value){if(!is_string($value))throw new DomainException('Betrag prüfen.');if(trim($value)==='')continue;$amount=\Kulturhus\Verein\BalancePeriodAreaForm::cents($value);if($amount!==0)$out[$key]=$amount;}
    $result=$areas->edit($id,$version,$hash,'line',['key'=>input($_POST,'area_line'),'parts'=>$out],$note,$expect);break;
case 'balance-period-area-transfer':$result=$areas->edit($id,$version,$hash,'transfer',['voucher'=>input($_POST,'area_voucher')],$note,$expect);break;
case 'balance-period-area-asset':
case 'balance-period-area-asset-use-change':
    $weights=$_POST['weights']??null;$out=[];if(!is_array($weights)||array_diff_key($weights,$units))throw new DomainException('Nutzungsanteile prüfen.');
    foreach($weights as $key=>$value){if(!is_string($value))throw new DomainException('Nutzung als ganze Einheiten angeben.');if(trim($value)===''||$value==='0')continue;if(!preg_match('/^[1-9][0-9]{0,6}$/D',$value)||(int)$value>1000000)throw new DomainException('Nutzung als ganze Einheiten bis 1.000.000 angeben.');$out[$key]=(int)$value;}
    if($action==='balance-period-area-asset')$result=$areas->edit($id,$version,$hash,'asset',['item'=>input($_POST,'area_item'),'weights'=>$out,'measure'=>input($_POST,'measure')],$note,$expect);
    else $result=$areas->edit($id,$version,$hash,'asset-use-change',['item'=>input($_POST,'area_item'),'on'=>input($_POST,'use_on'),'weights'=>$out],$note,$expect);break;
case 'balance-period-area-asset-use-remove':$result=$areas->edit($id,$version,$hash,'asset-use-remove',['item'=>input($_POST,'area_item'),'on'=>input($_POST,'use_on')],$note,$expect);break;
case 'balance-period-area-advance':
    $result=$areas->edit($id,$version,$hash,'advance',['id'=>input($_POST,'request_key'),'voucher'=>input($_POST,'area_voucher'),'from'=>input($_POST,'from_unit'),'to'=>input($_POST,'to_unit'),'cents'=>\Kulturhus\Verein\BalancePeriodAreaForm::cents(input($_POST,'amount')),'repay_by'=>input($_POST,'repay_by'),'reason'=>$note],$note,$expect);break;
case 'balance-period-area-repayment':
    $result=$areas->edit($id,$version,$hash,'repayment',['id'=>input($_POST,'request_key'),'voucher'=>input($_POST,'area_voucher'),'advance'=>input($_POST,'advance_id'),'cents'=>\Kulturhus\Verein\BalancePeriodAreaForm::cents(input($_POST,'amount')),'reason'=>$note],$note,$expect);break;
case 'balance-period-area-remove':$result=$areas->edit($id,$version,$hash,'remove',['type'=>input($_POST,'remove_type'),'key'=>input($_POST,'remove_key')],$note,$expect);break;
case 'balance-period-area-confirm':$result=$areas->confirm($id,$version,$hash,input($_POST,'area_hash'),input($_POST,'reviewed')==='1',$note,$expect);break;
default:throw new DomainException('Unbekannter Bereichsvorgang.');
}
redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$id,'areas'=>'1','area_voucher'=>input($_POST,'area_voucher'),'area_original'=>(string)$result['revision'],'area_original_sha256'=>$result['sha256']]);
