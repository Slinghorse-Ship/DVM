<?php declare(strict_types=1);
// Included only after the main POST/CSRF check; service repeats permissions and version checks.
require_once __DIR__.'/BalanceSegments.php';
foreach(['balance_year','version','segment_journal_sha256']as$key)if(!is_string($_POST[$key]??null))throw new DomainException('Ursprüngliche Angaben zur Bereichsaufteilung vollständig prüfen.',422);
if(!preg_match('/\A[0-9]{4}\z/D',$_POST['balance_year'])||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',$_POST['version'])||!preg_match('/\A[a-f0-9]{64}\z/D',$_POST['segment_journal_sha256'])||(int)$_POST['balance_year']!==$year||(int)$_POST['version']!==$version)throw new DomainException('Ursprüngliche Angaben zur Bereichsaufteilung vollständig prüfen.',422);
$segmentResult=null;
$segments=new \Kulturhus\Verein\BalanceSegments($db,(string)$_SESSION['actor']);
$segmentState=$segments->forAction($year,$version);if(!hash_equals($segmentState['journal']['closed_sha256'],$_POST['segment_journal_sha256']))throw new DomainException('Die ursprüngliche Vereinsbilanz dieses Auftrags stimmt nicht mehr überein. Aktuellen Stand getrennt prüfen.',409);$unitData=$segmentState['allocation']['units'];$allocationData=$segmentState['allocation']['allocations'];
$sourceRows=\Kulturhus\Verein\BalanceSegmentCalculation::sources($segmentState['journal']);
switch($action){
case 'balance-segment-asset':
    $weights=$_POST['weights']??null;if(!is_array($weights)||array_diff_key($weights,$unitData))throw new DomainException('Betriebe und Nutzungsanteile prüfen.');
    $normalized=[];foreach($weights as $unit=>$value){if(!is_string($value))throw new DomainException('Nutzungsanteile prüfen.');$value=trim($value);if($value===''||$value==='0')continue;
        if(!preg_match('/^[1-9][0-9]{0,6}$/D',$value)||(int)$value>1000000)throw new DomainException('Nutzungsanteile als ganze Einheiten bis 1.000.000 angeben.');$normalized[$unit]=(int)$value;
    }
    $segmentResult=$segments->asset($year,$version,input($_POST,'asset_item'),$normalized,input($_POST,'measure'),input($_POST,'evidence'),input($_POST,'reviewed')==='1');break;
case 'balance-segment-carry':$segmentResult=$segments->carry($year,$version,input($_POST,'reviewed')==='1');break;
case 'balance-segment-rebase':$segmentResult=$segments->rebase($year,$version,input($_POST,'reviewed')==='1',input($_POST,'evidence'));break;
case 'balance-segment-transfer':$segmentResult=$segments->transfer($year,$version,input($_POST,'segment_voucher'),input($_POST,'transfer_account'),input($_POST,'reviewed')==='1',input($_POST,'evidence'));break;
case 'balance-segment-transfer-remove':$segmentResult=$segments->removeTransfer($year,$version,input($_POST,'segment_voucher'),input($_POST,'evidence'));break;
case 'balance-segment-unit':
    $id=input($_POST,'unit_id');if($id!==''&&!isset($unitData[$id]))throw new DomainException('Bereich nicht gefunden.');
    if($id===''){
        $requestKey=input($_POST,'unit_request_key');if(!preg_match('/\A[a-f0-9]{32}\z/D',$requestKey))throw new DomainException('Ursprünglichen Auftrag zum neuen Vereinsbereich vollständig prüfen.',422);
        $id='unit-'.substr(hash('sha256',(string)$year.':'.(string)$version.':'.$requestKey),0,16);
    }
    $unitData[$id]=['name'=>input($_POST,'unit_name'),'sphere'=>input($_POST,'unit_sphere')];
    $segmentResult=$segments->save($year,$version,$unitData,$allocationData);break;
case 'balance-segment-remove':
    $id=input($_POST,'unit_id');if(!isset($unitData[$id]))throw new DomainException('Bereich nicht gefunden.');
    foreach($allocationData as $row)if(isset($row['parts'][$id]))throw new DomainException('Zuerst die zugehörigen Beträge neu zuordnen.');
    unset($unitData[$id]);$segmentResult=$segments->save($year,$version,$unitData,$allocationData);break;
case 'balance-segment-voucher':
    $voucher=input($_POST,'segment_voucher');$unit=input($_POST,'unit_id');$reason=input($_POST,'evidence');$found=false;
    if($voucher==='opening'&&$segmentState['carry_forward']!==null)throw new DomainException('Anfangsbestände wurden aus dem Vorjahr übernommen. Änderungen zuerst dort prüfen.');
    if(!isset($unitData[$unit]))throw new DomainException('Bereich oder Betrieb auswählen.');
    foreach($sourceRows as $key=>$row)if($row['voucher']===$voucher){$found=true;$allocationData[$key]=['parts'=>[$unit=>$row['cents']],'reason'=>$reason];}
    if(!$found)throw new DomainException('Originalbuchung nicht gefunden.');
    $segmentResult=$segments->save($year,$version,$unitData,$allocationData);break;
case 'balance-segment-line':
    $key=input($_POST,'segment_line');$parts=$_POST['parts']??null;
    if(!isset($sourceRows[$key])||!is_array($parts)||array_diff(array_keys($parts),array_keys($unitData)))throw new DomainException('Originalzeile und Aufteilung prüfen.');
    if($sourceRows[$key]['voucher']==='opening'&&$segmentState['carry_forward']!==null)throw new DomainException('Anfangsbestände wurden aus dem Vorjahr übernommen. Änderungen zuerst dort prüfen.');
    $normalized=[];foreach($parts as $unit=>$amount){if(!is_string($amount))throw new DomainException('Beträge prüfen.');if(trim($amount)==='')continue;$cents=\Kulturhus\Verein\Finance::cents($amount);if($cents!==0)$normalized[$unit]=$cents;}
    $allocationData[$key]=['parts'=>$normalized,'reason'=>input($_POST,'evidence')];
    $segmentResult=$segments->save($year,$version,$unitData,$allocationData);break;
case 'balance-segment-suggest':
    if(input($_POST,'reviewed')!=='1')throw new DomainException('Eindeutige Vorschläge zuerst prüfen.');
    $suggested=\Kulturhus\Verein\BalanceSegmentCalculation::suggest($segmentState['journal'],$unitData);
    foreach($suggested as $key=>$row)if(!isset($allocationData[$key]))$allocationData[$key]=['parts'=>$row['parts'],'reason'=>input($_POST,'evidence')];
    $segmentResult=$segments->save($year,$version,$unitData,$allocationData);break;
case 'balance-segment-confirm':$segmentResult=$segments->confirm($year,$version,input($_POST,'reviewed')==='1',input($_POST,'evidence'));break;
default:throw new DomainException('Unbekannter Aufteilungsvorgang.');
}
if($segmentResult===null)throw new DomainException('Ursprünglicher Ausgang der Bereichsaufteilung fehlt.');
redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'segments'=>'1','segment_original'=>(string)$segmentResult['revision'],'segment_original_sha256'=>$segmentResult['sha256'],'segment_voucher'=>input($_POST,'segment_voucher'),'segment_line'=>input($_POST,'segment_line'),'asset_item'=>input($_POST,'asset_item')]);
