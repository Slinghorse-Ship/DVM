<?php declare(strict_types=1);
use Kulturhus\Verein\{Settings,SettingsSchema,Finance,Documents,DonationForms,DonationReceipts};

// The issuance path must use the same current original guard as the settings review.
$icClub='own-current-tax-issuer';$icActor=$icClub.'-admin';
$db->exec("INSERT INTO clubs VALUES('own-current-tax-issuer','Own current issuer'),('own-current-tax-issuer-untouched','Own untouched issuer');INSERT INTO actors(id,club_id,role,is_superuser)VALUES('own-current-tax-issuer-admin','own-current-tax-issuer','members',1)");
$icSettings=new Settings($db,$icActor);$icFinance=new Finance($db,$icActor);$icDocuments=new Documents($db,$icActor);
$icSettings->save('club',array_replace(SettingsSchema::defaults('club'),['name'=>'Own current issuer','street'=>'Own original street 1','postcode'=>'00000','city'=>'Own city']),0);
$icNotice=$icDocuments->upload('own-current-authority.pdf',$pdf('actual-current-authority-first'),['title'=>'Own original current issuing authority','kind'=>'other','scope'=>'finance','document_date'=>'2025-02-01','links'=>[]],$key());
$icTax=array_replace(SettingsSchema::defaults('tax'),['office'=>'Own tax office','number'=>'00/000/00000','purpose'=>'Own original charitable purpose','notice_type'=>'exemption','exemption_date'=>'2025-02-01','assessment'=>'2022 bis 2024','membership_deductible'=>'no','notice_document_id'=>$icNotice,'receipt_signatory'=>'Own actual signatory','receipt_authority_reviewed'=>true]);$icSettings->save('tax',$icTax,0);
$icEntry=$icFinance->createEntry(['kind'=>'donation','category'=>'donations','description'=>'Own earlier complete current-issuer donation','counterparty'=>"Own synthetic donor\nOwn donor street 1\n00000 Own city",'amount'=>'17.00','document_date'=>'2026-10-21','due_date'=>'2026-10-21'],$key());
$icAccount=$icFinance->createAccount('Own current issuer recorded account','offline','0','2026-01-01');$icFinance->recordPayment($icEntry,1,$icAccount,'2026-10-21');
$icEntryBefore=$icFinance->entry($icEntry);$icPositionsBefore=$icFinance->positions('entry',$icEntry);$icOriginalPdf=$icDocuments->file($icNotice,1);
$icInput=(new DonationForms($db,$icActor))->defaults('money',$icEntry)+['receipt_reviewed'=>'1'];$icInput['waiver']='no';
$icCaller=new PDO($txDsn,$txConfig['username'],$txConfig['password'],$txOptions);$icCaller->beginTransaction();$icCaller->query('SELECT * FROM finance_entries')->fetchAll();
$icCaller->exec("UPDATE clubs SET name='Own earlier actual issuer caller work' WHERE id='own-current-tax-issuer-untouched'");
$icDocuments->newVersion($icNotice,1,'own-current-authority-is-text.txt','Own currently replaced authority file','Own consciously current authority replacement');
try{
    $icOld=(new Documents($icCaller,$icActor))->document($icNotice);$icCurrent=$icDocuments->document($icNotice);$icBefore=ownOriginalDatabaseHash($icCaller,$tables);$icClosed=false;
    try{(new DonationReceipts($icCaller,$icActor))->build($icInput);}catch(DomainException $failure){$icClosed=$failure->getCode()===422;}
    $check($icOld['mime']==='application/pdf'&&$icCurrent['mime']==='text/plain'&&$icClosed&&$icCaller->inTransaction()&&ownOriginalDatabaseHash($icCaller,$tables)===$icBefore,'actual certificate issuance review rejects a currently replaced non-PDF authority behind an unrelated older caller snapshot while retaining every entire earlier caller row and transaction');
}finally{$icCaller->rollBack();}
$icDocuments->newVersion($icNotice,2,'own-current-authority-restored.pdf',$pdf('actual-current-authority-third'),'Own deliberately restored valid current authority');
$icCurrent=$icDocuments->document($icNotice);$icBefore=ownOriginalDatabaseHash($db,$tables);$icBuilt=(new DonationReceipts($db,$icActor))->build($icInput);
$check($icBuilt['authority']['revision']===3&&$icBuilt['authority']['sha256']===$icCurrent['sha256']&&$icBuilt['authority']['tax_settings_version']===1&&$icDocuments->file($icNotice,1)===$icOriginalPdf&&$icFinance->entry($icEntry)===$icEntryBefore&&$icFinance->positions('entry',$icEntry)===$icPositionsBefore&&ownOriginalDatabaseHash($db,$tables)===$icBefore,'restored current PDF authorization selects its actual third revision and hash without changing the earlier exact PDF financial entry positions or any whole business row');
