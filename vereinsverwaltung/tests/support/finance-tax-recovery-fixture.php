<?php declare(strict_types=1);
/** A real earlier issued donation certificate and distinct first tax-settings originals. */
function recoveryFinanceTaxOriginal(PDO $db):array
{
    $club='own-taxsettings-recovery';$actor=$club.'-admin';$key=static fn()=>bin2hex(random_bytes(16));
    $db->exec("INSERT INTO clubs VALUES('own-taxsettings-recovery','Own tax settings recovery');INSERT INTO actors(id,club_id,role,is_superuser)VALUES('own-taxsettings-recovery-admin','own-taxsettings-recovery','members',1)");
    $settings=new \Kulturhus\Verein\Settings($db,$actor);$finance=new \Kulturhus\Verein\Finance($db,$actor);$documents=new \Kulturhus\Verein\Documents($db,$actor);$requests=new \Kulturhus\Verein\Settings\GeneralRequests($db,$actor);
    $settings->save('club',array_replace(\Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Own tax settings recovery','street'=>'Own street 1','postcode'=>'00000','city'=>'Own city']),0);
    $pdf=static function(string $marker):string{
        $stream='BT /F1 18 Tf 72 720 Td (Own tax original '.$marker.') Tj ET';
        $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>','<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $bytes="%PDF-1.4\n";$offsets=[0];foreach($objects as$i=>$object){$offsets[]=strlen($bytes);$bytes.=($i+1)." 0 obj\n".$object."\nendobj\n";}$xref=strlen($bytes);$bytes.="xref\n0 6\n0000000000 65535 f \n";foreach(array_slice($offsets,1)as$offset)$bytes.=sprintf("%010d 00000 n \n",$offset);
        return $bytes."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    };
    $notice=$documents->upload('own-earlier-tax-notice.pdf',$pdf('first'),['title'=>str_repeat('ä',75),'kind'=>'other','scope'=>'finance','document_date'=>'2025-02-01','links'=>[]],$key());
    $other=$documents->upload('own-later-tax-notice.pdf',$pdf('separate'),['title'=>'Own separate later tax notice','kind'=>'other','scope'=>'finance','document_date'=>'2026-02-01','links'=>[]],$key());
    $oldTax=array_replace(\Kulturhus\Verein\SettingsSchema::defaults('tax'),['office'=>'Own original tax office ä','number'=>'00/000/00000','purpose'=>'Own actual original charitable purpose','notice_type'=>'exemption','exemption_date'=>'2025-02-01','assessment'=>'2022 bis 2024','membership_deductible'=>'no','notice_document_id'=>$notice,'receipt_signatory'=>'Own original signatory','receipt_authority_reviewed'=>true]);
    $settings->save('tax',$oldTax,0);$raw=$requests->revision('tax','1');$noticeFile=$documents->file($notice,1);
    $entryId=$finance->createEntry(['kind'=>'donation','category'=>'donations','description'=>'Own earlier seventeen-euro tax certificate original','counterparty'=>"Own synthetic donor\nOwn donor street 1\n00000 Own city",'amount'=>'17.00','document_date'=>'2026-10-21','due_date'=>'2026-10-21'],$key());
    $account=$finance->createAccount('Own recorded synthetic donation account','offline','0','2026-01-01');$finance->recordPayment($entryId,1,$account,'2026-10-21');
    $receiptInput=(new \Kulturhus\Verein\DonationForms($db,$actor))->defaults('money',$entryId)+['receipt_reviewed'=>'1'];$receiptInput['waiver']='no';$receiptKey=$key();$receipt=(new \Kulturhus\Verein\DonationReceipts($db,$actor))->issue($receiptInput,$receiptKey);
    $file=$documents->file($receipt);$issued=['name'=>$file['name'],'mime'=>$file['mime'],'sha256'=>hash('sha256',$file['bytes']),'snapshot'=>$file['snapshot']];$entry=$finance->entry($entryId);$positions=$finance->positions('entry',$entryId);$payment=ownRecoveryTaxPayment($db,$club,$entryId);
    $input=\Kulturhus\Verein\Settings\FinanceTaxInput::initial($settings->section('tax'));$input['data']=array_replace($input['data'],['office'=>str_repeat('ö',180),'purpose'=>str_repeat('🙂',2000),'vat_id'=>'0','small_business'=>'0','input_tax_eligibility'=>'no','input_tax_default'=>'unknown']);
    $plan=$requests->prepare('tax',$input);$first=$requests->accept('tax',$input,$key(),$plan['hash'],true);
    $input=\Kulturhus\Verein\Settings\FinanceTaxInput::initial($settings->section('tax'));$input['data']=array_replace($input['data'],['office'=>'0','notice_document_id'=>$other,'receipt_signatory'=>'Own deliberately later signatory']);$plan=$requests->prepare('tax',$input);$later=$requests->accept('tax',$input,$key(),$plan['hash'],true);
    $documents->newVersion($notice,1,'own-later-original-text.txt','Own deliberately later text notice source','Own deliberately replaced current file');
    $row=$documents->document($notice);$documents->update($notice,2,['title'=>'Own later archived notice','kind'=>'other','document_date'=>'2025-02-01','folder'=>$row['folder_id'],'state'=>'archived','notes'=>$row['notes'],'links'=>[],'reason'=>'Own deliberately later archival']);
    $db->beginTransaction();try{$currentSource=\Kulturhus\Verein\Settings\FinanceTaxContext::read($db,$actor,$settings->section('tax')['data']);$earlierCurrent=$documents->taxNotice($notice,false);}finally{$db->rollBack();}
    return compact('first','later','raw','noticeFile','currentSource','earlierCurrent','entry','positions','payment','receipt','receiptInput','receiptKey','issued','oldTax')+['current'=>$settings->section('tax')];
}
function ownRecoveryTaxPayment(PDO $db,string $club,string $entry):array
{
    return ['transactions'=>\Kulturhus\Native\Shared\Db::run($db,'SELECT t.* FROM finance_transactions t WHERE t.club_id=? AND EXISTS(SELECT 1 FROM finance_allocations l WHERE l.club_id=t.club_id AND l.transaction_id=t.id AND l.entry_id=?) ORDER BY t.id',[$club,$entry])->fetchAll(),'allocations'=>\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM finance_allocations WHERE club_id=? AND entry_id=? ORDER BY id',[$club,$entry])->fetchAll()];
}
function recoveryTaxCertificateOriginal(PDO $db,string $root,string $work):array
{
    require_once dirname(__DIR__).'/fixtures/php-runtime.php';
    $scratch=$work.'/kh-eric-fixture-owned-tax-certificate';mkdir($scratch,0700);
    $directory=$root.'/private/elster-certificates';if(!is_dir($directory))mkdir($directory,0700);
    $catalog=$scratch.'/catalog.json';file_put_contents($catalog,'{"version":1,"profiles":[]}');chmod($catalog,0600);
    $runtime=$scratch.'/runtime.json';
    file_put_contents($runtime,json_encode(['version'=>1,'manufacturer_id'=>'12345','library'=>__FILE__,'scratch'=>$scratch,'catalog'=>$catalog,'command'=>taxFixturePhpCommand(),'environment'=>taxFixturePhpEnvironment(),'profiles'=>[],'production_enabled'=>false,'transport_disabled'=>true,'test_fixture_only'=>true],JSON_THROW_ON_ERROR));chmod($runtime,0600);
    $oldRuntime=getenv('VEREIN_ERIC_RUNTIME');$oldVault=getenv('VEREIN_ELSTER_CERTIFICATE_ROOT');
    try{
        putenv('VEREIN_ERIC_RUNTIME='.$runtime);putenv('VEREIN_ELSTER_CERTIFICATE_ROOT='.$directory);
        $actor='own-taxsettings-recovery-admin';$vault=new \Kulturhus\Verein\TaxCertificateVault($db,$actor);$vault->save('synthetic-org','fixture secret','',true);
        $relative='private/elster-certificates/'.hash('sha256','own-taxsettings-recovery').'.enc';
        return ['relative'=>$relative,'bytes'=>file_get_contents($root.'/'.$relative),'status'=>$vault->status(),'actor'=>$actor];
    }finally{
        putenv($oldRuntime===false?'VEREIN_ERIC_RUNTIME':'VEREIN_ERIC_RUNTIME='.$oldRuntime);
        putenv($oldVault===false?'VEREIN_ELSTER_CERTIFICATE_ROOT':'VEREIN_ELSTER_CERTIFICATE_ROOT='.$oldVault);
    }
}
function recoveryTaxCertificateVerify(PDO $db,string $target,array $original):bool
{
    $old=getenv('VEREIN_ELSTER_CERTIFICATE_ROOT');
    try{
        putenv('VEREIN_ELSTER_CERTIFICATE_ROOT='.$target.'/private/elster-certificates');
        $vault=new \Kulturhus\Verein\TaxCertificateVault($db,$original['actor']);
        return file_get_contents($target.'/'.$original['relative'])===$original['bytes']
            &&(fileperms($target.'/'.$original['relative'])&0077)===0
            &&$vault->status()===$original['status']
            &&$vault->load($original['status']['revision'],'fixture secret')==='synthetic-org';
    }finally{putenv($old===false?'VEREIN_ELSTER_CERTIFICATE_ROOT':'VEREIN_ELSTER_CERTIFICATE_ROOT='.$old);}
}
function recoveryFinanceTaxVerify(string $app,string $dsn,array $fixture,array $original):void
{
    $code=<<<'PHP'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);require $f['app'].'/vereinsverwaltung/native/bootstrap.php';require $f['app'].'/vereinsverwaltung/src/bootstrap.php';require $f['app'].'/vereinsverwaltung/tests/support/original-database-hash.php';
$db=new PDO($f['dsn'],$f['username'],$f['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$actor='own-taxsettings-recovery-admin';$o=$f['original'];$requests=new \Kulturhus\Verein\Settings\GeneralRequests($db,$actor);$settings=new \Kulturhus\Verein\Settings($db,$actor);$finance=new \Kulturhus\Verein\Finance($db,$actor);$documents=new \Kulturhus\Verein\Documents($db,$actor);
$first=$requests->receipt('tax',$o['first']['key']);$later=$requests->receipt('tax',$o['later']['key']);$replays=[];foreach([$first,$later]as$read)$replays[]=$requests->accept('tax',$read['input'],$read['key'],$read['review']['hash'],true);$raw=$requests->revision('tax','1');$current=$settings->section('tax');$db->beginTransaction();try{$currentSource=\Kulturhus\Verein\Settings\FinanceTaxContext::read($db,$actor,$current['data']);$earlierCurrent=$documents->taxNotice($o['oldTax']['notice_document_id'],false);}finally{$db->rollBack();}
$entry=$finance->entry($o['entry']['id']);$positions=$finance->positions('entry',$entry['id']);$payment=['transactions'=>\Kulturhus\Native\Shared\Db::run($db,'SELECT t.* FROM finance_transactions t WHERE t.club_id=? AND EXISTS(SELECT 1 FROM finance_allocations l WHERE l.club_id=t.club_id AND l.transaction_id=t.id AND l.entry_id=?) ORDER BY t.id',[$entry['club_id'],$entry['id']])->fetchAll(),'allocations'=>\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM finance_allocations WHERE club_id=? AND entry_id=? ORDER BY id',[$entry['club_id'],$entry['id']])->fetchAll()];$noticeFile=$documents->file($o['oldTax']['notice_document_id'],1);$receipt=(new \Kulturhus\Verein\DonationReceipts($db,$actor))->issue($o['receiptInput'],$o['receiptKey']);$file=$documents->file($receipt);$issued=['name'=>$file['name'],'mime'=>$file['mime'],'sha256'=>hash('sha256',$file['bytes']),'snapshot'=>$file['snapshot']];$paths=[];foreach([\Kulturhus\Verein\Settings\FinanceTaxInput::class,\Kulturhus\Verein\Settings\FinanceTaxContext::class,\Kulturhus\Verein\Settings\GeneralRequests::class,\Kulturhus\Verein\Settings::class,\Kulturhus\Verein\Documents::class,\Kulturhus\Verein\DonationReceipts::class]as$class)$paths[]=(new ReflectionClass($class))->getFileName();echo json_encode(compact('first','later','replays','raw','current','currentSource','earlierCurrent','entry','positions','payment','noticeFile','receipt','issued','paths')+['unchanged'=>ownOriginalDatabaseHash($db,$tables)===$before],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
PHP;
    $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$app);if(!is_resource($process))throw new RuntimeException('Independent tax recovery unavailable.');
    fwrite($pipes[0],json_encode(['app'=>$app,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password'],'original'=>$original],JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Recovered tax originals failed: '.substr($error,0,900));$proof=json_decode($output,true,64,JSON_THROW_ON_ERROR);
    recoveryCheck($proof['first']===$original['first']&&$proof['later']===$original['later']&&$proof['replays']===[$original['first'],$original['later']],'independent actual restored tax originals retain whole Unicode raw switches separate normalization first source PDF metadata actor time and exact first JSON bytes');
    recoveryCheck($proof['raw']===$original['raw']&&$proof['current']===$original['current']&&$proof['first']['result']['data']!==$proof['current']['data'],'actual restored first tax approval earlier raw version one and consciously later current version three remain separate without invented historical approval');
    recoveryCheck($proof['currentSource']===$original['currentSource']&&$proof['earlierCurrent']===$original['earlierCurrent']&&$proof['earlierCurrent']['document']['state']==='archived'&&$proof['earlierCurrent']['file']['mime']==='text/plain'&&$proof['noticeFile']===$original['noticeFile'],'restored actual later archived non-PDF notice state remains separate from the originally approved exact earlier PDF bytes and current separate PDF notice');
    recoveryCheck($proof['entry']===$original['entry']&&$proof['positions']===$original['positions']&&$proof['payment']===$original['payment'],'actual earlier seventeen-euro donation keeps its complete original financial entry positions and recorded synthetic payment unchanged after later tax defaults');
    $snapshot=json_decode($proof['issued']['snapshot'],true,64,JSON_THROW_ON_ERROR);
    recoveryCheck($proof['receipt']===$original['receipt']&&$proof['issued']===$original['issued']&&$snapshot['authority']['document_id']===$original['oldTax']['notice_document_id']&&$snapshot['authority']['revision']===1&&$snapshot['authority']['sha256']===$original['noticeFile']['sha256']&&$snapshot['authority']['signatory']===$original['oldTax']['receipt_signatory']&&$snapshot['authority']['tax_settings_version']===1,'actual earlier issued donation certificate PDF and entire snapshot retain their original notice revision SHA signatory and tax-settings version after later tax and PDF changes');
    recoveryCheck($proof['unchanged']&&count(array_filter($proof['paths'],static fn(string $path):bool=>str_starts_with($path,$app.'/vereinsverwaltung/src/')))===6,'all six tax original and repeated certificate helpers execute from the actual restored package without changing any entire restored business row');
}
