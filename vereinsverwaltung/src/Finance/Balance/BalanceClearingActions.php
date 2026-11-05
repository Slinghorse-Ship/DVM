<?php declare(strict_types=1);
// Reached only after the central authenticated POST/CSRF check.
require_once __DIR__.'/BalanceClearings.php';
$service=new \Kulturhus\Verein\BalanceClearings($db,(string)$_SESSION['actor']);
foreach(['balance_year','version','segment_version','clearing_segment_sha256']as$key)if(!is_string($_POST[$key]??null))throw new DomainException('Ursprüngliche Angaben zum Verrechnungsnachweis vollständig prüfen.',422);
if(!preg_match('/\A[0-9]{4}\z/D',$_POST['balance_year'])||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',$_POST['version'])||!preg_match('/\A[1-9][0-9]{0,3}\z/D',$_POST['segment_version'])||(int)$_POST['segment_version']>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$_POST['clearing_segment_sha256'])||(int)$_POST['balance_year']!==$year||(int)$_POST['version']!==$version)throw new DomainException('Ursprüngliche Angaben zum Verrechnungsnachweis vollständig prüfen.',422);
$segmentVersion=(int)$_POST['segment_version'];$clearingBasis=$service->forAction($year,$version,$segmentVersion);$clearingSource=end($clearingBasis['segments']['history']);
if(!$clearingSource||!hash_equals($clearingSource['sha256'],$_POST['clearing_segment_sha256']))throw new DomainException('Die ursprüngliche Buchungsaufteilung dieses Auftrags stimmt nicht mehr überein. Aktuellen Nachweis getrennt prüfen.',409);
$clearingResult=null;
switch($action){
case 'balance-clearing-save':
    if(input($_POST,'clearing_form_complete')!=='1')throw new DomainException('Formular unvollständig übertragen. Bitte neu laden; nichts wurde gespeichert.');
    $existing=$clearingBasis['proof']['instructions']??['advances'=>[],'repayments'=>[]];$seen=['advances'=>[],'repayments'=>[]];
    $instructions=['advances'=>[],'repayments'=>[]];
    foreach($instructions as $type=>$_){
        $rows=$_POST[$type]??null;if(!is_array($rows)||count($rows)>200)throw new DomainException('Verrechnungszeilen prüfen.');
        foreach($rows as $row){
            $keys=$type==='advances'?['id','voucher','from','to','amount','repay_by','reason','remove']:['id','voucher','advance','amount','reason','remove'];
            if(!is_array($row)||array_diff(array_keys($row),$keys)||count(array_filter($row,'is_string'))!==count($row))throw new DomainException('Verrechnungszeile prüfen.');
            $values=array_diff_key($row,['id'=>true,'remove'=>true]);if(!array_filter($values,static fn($v)=>trim($v)!==''))continue;
            $id=input($row,'id');if(!preg_match('/^[a-f0-9]{32}$/D',$id)||isset($seen[$type][$id]))throw new DomainException('Verrechnungskennung prüfen.');$seen[$type][$id]=true;
            if(input($row,'remove')==='1'){if(!isset($existing[$type][$id]))throw new DomainException('Vorhandene Zuordnung für die Aufhebung auswählen.');continue;}
            $amount=input($row,'amount');if(!preg_match('/^[0-9]{1,10}(?:[,.][0-9]{1,2})?$/D',$amount))throw new DomainException('Betrag wie 12,50 ohne Tausenderpunkt angeben.');
            $v=['voucher'=>input($row,'voucher')];
            if($type==='advances')$v+=['from'=>input($row,'from'),'to'=>input($row,'to')];else $v['advance']=input($row,'advance');
            $v['cents']=\Kulturhus\Verein\Finance::cents($amount);
            if($type==='advances')$v['repay_by']=input($row,'repay_by');$v['reason']=input($row,'reason');$instructions[$type][$id]=$v;
        }
        if(array_diff_key($existing[$type],$seen[$type]))throw new DomainException('Gespeicherte Zuordnung fehlt im Formular. Zum Aufheben die entsprechende Auswahl markieren.');
    }
    $clearingResult=$service->save($year,$version,(int)$segmentVersion,$instructions,input($_POST,'reviewed')==='1',input($_POST,'evidence'));break;
case 'balance-clearing-confirm':$clearingResult=$service->confirm($year,$version,(int)$segmentVersion,input($_POST,'reviewed')==='1',input($_POST,'evidence'));break;
default:throw new DomainException('Unbekannter Verrechnungsvorgang.');
}
if($clearingResult===null)throw new DomainException('Ursprünglicher Verrechnungsnachweis fehlt.');
redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'clearings'=>'1','clearing_original'=>(string)$clearingResult['revision'],'clearing_original_sha256'=>$clearingResult['sha256']]);
