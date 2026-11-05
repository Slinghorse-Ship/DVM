<?php declare(strict_types=1);
// Central controller has already checked POST, session and CSRF.
require_once __DIR__.'/BalanceClosingCorrections.php';
foreach(['balance_year','correction_revision','base_hash']as$key)if(!is_string($_POST[$key]??null))throw new DomainException('Ursprüngliche Angaben zur Abschlusskorrektur vollständig prüfen.',422);
if(!preg_match('/\A[0-9]{4}\z/D',$_POST['balance_year'])||!preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D',$_POST['correction_revision'])||!preg_match('/\A[a-f0-9]{64}\z/D',$_POST['base_hash']))throw new DomainException('Ursprüngliche Angaben zur Abschlusskorrektur vollständig prüfen.',422);
$corrections=new \Kulturhus\Verein\BalanceClosingCorrections($db,(string)$_SESSION['actor']);
$revision=(int)input($_POST,'correction_revision');$baseHash=input($_POST,'base_hash');
switch($action){
case 'balance-correction-save':
    $lines=[];$rows=$_POST['lines']??[];
    if(!is_array($rows)||!array_is_list($rows)||count($rows)>50)throw new DomainException('Korrekturzeilen prüfen.');
    foreach($rows as $row){
        if(!is_array($row)||array_diff(array_keys($row),['account','debit','credit','sphere'])||count(array_filter($row,'is_string'))!==count($row))throw new DomainException('Korrekturzeile prüfen.');
        if(input($row,'account')===''&&input($row,'debit')===''&&input($row,'credit')===''&&input($row,'sphere')==='')continue;
        $lines[]=['account'=>input($row,'account'),'debit_cents'=>\Kulturhus\Verein\Finance::cents(input($row,'debit','0')?:'0'),'credit_cents'=>\Kulturhus\Verein\Finance::cents(input($row,'credit','0')?:'0'),'sphere'=>input($row,'sphere')];
    }
    $entry=$lines?[['id'=>input($_POST,'request_key'),'date'=>input($_POST,'booked_on'),'description'=>input($_POST,'description'),'evidence'=>input($_POST,'evidence'),'lines'=>$lines]]:[];
    $correctionResult=$corrections->save($year,$revision,$baseHash,['kind'=>input($_POST,'correction_kind'),'reason'=>input($_POST,'reason'),'legal_basis'=>input($_POST,'legal_basis'),'confirmed'=>input($_POST,'confirmed')==='1','entries'=>$entry]);break;
case 'balance-correction-confirm':
    $correctionResult=$corrections->confirm($year,$revision,$baseHash,input($_POST,'proof_hash'),input($_POST,'reviewed')==='1',input($_POST,'note'));break;
case 'balance-correction-discard':$correctionResult=$corrections->discard($year,$revision,$baseHash,input($_POST,'note'));break;
case 'balance-correction-reaffirm':$correctionResult=$corrections->reaffirm($year,$revision,$baseHash,input($_POST,'reviewed')==='1',input($_POST,'note'));break;
default:throw new DomainException('Unbekannte Abschlusskorrektur.');
}
redirectTo(['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>(string)$year,'corrections'=>'1',
    'correction_original'=>(string)$correctionResult['revision'],'correction_sha256'=>$correctionResult['sha256']]);
