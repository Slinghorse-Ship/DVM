<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Registry,Finance,Settings,SettingsSchema,ContributionInvoices,ContributionMail,Documents};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1)");
$s=new Settings($db,'admin');$f=new Finance($db,'admin');$r=new Registry($db,'admin');$key=fn()=>bin2hex(random_bytes(16));
$s->save('club',array_replace(SettingsSchema::defaults('club'),['name'=>'Synthetic Verein','street'=>'Testweg 1','postcode'=>'12345','city'=>'Musterstadt']),0);
$bank=$f->createAccount('Synthetic Bank','offline','0','2026-01-01');$s->save('bank',array_replace(SettingsSchema::defaults('bank'),['account_id'=>$bank,'holder'=>'Synthetic Verein','iban'=>'DE89370400440532013000']),0);
$person=$r->saveContact(null,0,'person','Alex Referenz','','Testweg 4, 12345 Musterstadt');$r->addMembership($person,'MEMBER-77','aktiv','2025-01-01');$entry=$f->createAnnualContribution($person,2026,'');$reference=$f->entry($entry)['display_number'];
$templates=SettingsSchema::defaults('texts_contributions');$templates['invoice']['intro']='Eigene Einleitung {{VERWENDUNGSZWECK}}';$templates['invoice']['message']='Bitte Zweck {{VERWENDUNGSZWECK}} verwenden.';$templates['invoice']['closing']='Individuelle Schlusszeile ohne Nummer';$s->save('texts_contributions',$templates,0);
$issued=(new ContributionInvoices($db,'admin'))->issue($entry);$docs=new Documents($db,'admin');$file=$docs->file($issued['document_id']);$snapshot=json_decode($file['snapshot'],true,512,JSON_THROW_ON_ERROR);
function check($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
check(str_contains($snapshot['text']['intro'],'Eigene Einleitung '.$reference)&&str_contains($snapshot['text']['intro'],'gemäß Satzung'),'custom invoice introduction retained alongside contribution explanation');
check($snapshot['payment_reference']===$reference&&$snapshot['values']['VERWENDUNGSZWECK']===$reference,'issued PDF snapshot uses exact invoice reference');
check($reference!=='MEMBER-77'&&$snapshot['values']['MITGLIEDSNUMMER']==='MEMBER-77','member number is separate from payment reference');
$mail=ContributionMail::build($db,'a',$entry,'invoice');check(str_contains($mail['text'],'Bitte Zweck '.$reference)&&str_contains($mail['text'],'Verwendungszweck bei Überweisung (Rechnungsnummer): '.$reference),'custom text and mandatory reference agree');
$templates['invoice']['message']='Eigener Text ohne Variablen';$s->save('texts_contributions',$templates,1);check(str_contains(ContributionMail::build($db,'a',$entry,'invoice')['text'],$reference),'custom templates cannot hide the payment reference');
$t=$f->createTransaction(['account'=>$bank,'booked_on'=>'2026-08-01','direction'=>'income','amount'=>'24','description'=>$reference],$key());check($f->transactionDetail($t)['remaining_cents']===2400,'partial payment stays manual');$f->allocate($t,1,$entry,1,'24',$key());$remaining=ContributionMail::build($db,'a',$entry,'reminder');check(str_contains($remaining['text'],'Offener Betrag: 24,00 €')&&str_contains($remaining['text'],$reference),'reminder retains reference and actual unpaid balance');
check($file===$docs->file($issued['document_id']),'issued PDF unchanged after template edit and payment');
if($out=getenv('KULTURHUS_REFERENCE_PDF')){file_put_contents($out,$file['bytes']);file_put_contents($out.'.json',json_encode(['reference'=>$reference,'member'=>'MEMBER-77']));}
check((int)$db->query('SELECT count(*) FROM club_outbox')->fetchColumn()===0,'no outgoing mail queued');
