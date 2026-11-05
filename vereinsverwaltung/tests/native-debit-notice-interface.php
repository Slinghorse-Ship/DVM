<?php declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/native-document-interface.php';
require __DIR__.'/support/sepa-native-contracts.php';
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{ContributionInvoices, Documents, MemberBankVault, OutgoingInvoices, Permissions, Settings, SettingsSchema};
use Kulturhus\Verein\Finance\Banking\Notice\{DebitDraft, DebitInput, DebitRequests};

$f=ownSepaNativeContracts($db,$check);
extract($f,EXTR_SKIP);
$settings=$f['settings'];$actor=$f['actor'];$finance=$f['finance'];$account=$f['account'];$bankVault=$f['bankVault'];$key=$f['key'];$denyCode=$f['deny'];
$bankState=$settings->section('bank');$bankData=array_replace($bankState['data'],['creditor'=>'DE98ZZZ09999999999']);$settings->save('bank',$bankData,$bankState['version']);
$profile=new Settings($db,$actor);$profileState=$profile->section('account');$profile->save('account',array_replace($profileState['data'],['display_name'=>'Own verified notice clerk']),$profileState['version']);
foreach(['texts_contributions','texts_invoices']as$section){$texts=SettingsSchema::templates($section);$texts['debit']=array_replace($texts['debit'],['subject'=>$section.' {{RECHNUNGSNUMMER}}','message'=>"{{BRIEFANREDE}}\n{{ZAHLBETRAG}} on {{LASTSCHRIFT_DATUM}}, {{MANDATSREFERENZ}} / {{GLAEUBIGER_ID}}.\n{{BEARBEITER}}",'intro'=>"\n0 🙂",'closing'=>'Own final notice text']);$settings->save($section,$texts,0);}
$settings->save('tax',array_replace(SettingsSchema::defaults('tax'),['number'=>'00/000/00000']),0);
$invoices=new OutgoingInvoices($db,$f['admin']);
$outgoing=static function(string $marker)use($invoices,$account,$f,$key):array{
 $data=['invoice_format'=>'pdf','domestic_confirmed'=>'1','seller_contact'=>'Own original office','buyer_name'=>$marker,'buyer_street'=>'Own original street 7','buyer_postcode'=>'23456','buyer_city'=>'Own original town','buyer_email'=>'buyer@example.invalid','buyer_reference'=>'','description'=>'Own genuine notice service','note'=>'','vat_reason'=>'','document_date'=>$f['notice'],'delivery_date'=>$f['notice'],'due_date'=>$f['notice'],'category'=>'rooms','contact_id'=>$f['payer'],'payment_account'=>$account,'positions'=>[['description'=>'Own original service','quantity'=>'1','unit'=>'DAY','unit_gross'=>'119','tax_rate'=>'19','sphere'=>'2','account'=>'']]];
 $id=$invoices->save('',0,$data,$key());$invoices->review($id,1);$entry=$invoices->issue($id,2);$row=$invoices->get($id);return ['entry'=>$entry,'document'=>$row['document_id'],'invoice'=>$id,'type'=>'texts_invoices','number'=>$row['number']];
};
$native=new DebitRequests($db,$actor,$bankVault);$day=$f['notice'];$collection=max($f['date'],$finance->entry($f['annual'])['due_date']);$docs=new Documents($db,$f['admin']);
$annual=['entry'=>$f['annual'],'document'=>$f['issued']['document_id'],'type'=>'texts_contributions','number'=>$finance->entry($f['annual'])['display_number']];
$general=$outgoing('Own actual original notice buyer');
$firsts=[];$sourceFiles=[];
foreach([$annual,$general]as$source){
 $input=$native->initial($source['entry'],$day,$collection);$input['reason']="\n0\r\nOwn exact notice reason 🙂";
 $sourceFiles[$source['document']]=$docs->file($source['document']);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$plan=$native->prepare($input);
 $check($native->prepare($input)===$plan&&ownOriginalDatabaseHash($db,$tables)===$before,'actual '.$source['type'].' notice preview changes no business row');
 $check($plan['source']['claim']['template']===$source['type']&&str_starts_with($input['text']['subject'],$source['type']),'actual source selects its own approved debit template');
 $check(!str_contains(DebitRequests::json($plan),$f['bank']['iban'])&&!isset($plan['source']['claim']['snapshot']),'review binds a masked mandate without full member IBAN or original binary snapshots');
 $denyCode(fn()=>$native->accept($input,$key(),$plan['hash'],false),422,'unchecked original notice produces no output');
 $first=$native->accept($input,$key(),$plan['hash'],true);$firsts[]=$first;$acceptedHash=ownOriginalDatabaseHash($db,$tables);
 $check($first['input']===$input&&$native->receipt($first['key'])===$first&&$native->accept($input,$first['key'],$plan['hash'],true)===$first&&ownOriginalDatabaseHash($db,$tables)===$acceptedHash,'exact accepted notice replays its original without another output');
 $check(hash('sha256',$native->pdf($first['key']))===$first['result']['sha256']&&!str_contains($first['original_json'],$f['bank']['iban']),'canonical original notice PDF is bound and personal JSON contains no debtor IBAN');
 $file=$docs->file($first['result']['document_id']);$snapshot=json_decode($file['snapshot'],true,64,JSON_THROW_ON_ERROR);$body=$snapshot['rendered']['text']['message'];
 $originalSnapshot=json_decode($sourceFiles[$source['document']]['snapshot'],true,64,JSON_THROW_ON_ERROR);
 $check(str_contains($body,$plan['source']['values']['MANDATSREFERENZ'])&&str_contains($body,$plan['source']['values']['LASTSCHRIFT_DATUM'])&&str_contains($body,$plan['source']['values']['ZAHLBETRAG'])&&$snapshot['rendered']['recipient']===$originalSnapshot['recipient'],'notice preserves the original address and displays mandate date and exact amount');
 foreach(['own-sepa-admin'=>404,'own-sepa-writer'=>404,'own-sepa-foreign'=>404]as$other=>$code)$denyCode(fn()=>(new DebitRequests($db,$other))->receipt($first['key']),$code,'personal notice receipt is private from '.$other);
}
$input=$native->initial($general['entry'],$day,$collection);$input['reason']='Own incomplete notice';
foreach(['own-sepa-writer','own-sepa-reader','own-sepa-general']as$other)$denyCode(fn()=>(new DebitRequests($db,$other,new MemberBankVault($db,$other,$f['path'])))->prepare($input),403,'new notice requires current finance and mandate authority for '.$other);
$session=[];$draft=DebitDraft::open($session,Permissions::actor($db,$actor),$input);$check(DebitDraft::get($session,Permissions::actor($db,$actor),$general['entry'],$draft['id'])===$draft,'personal notice draft keeps complete original inputs');$denyCode(fn()=>DebitDraft::get($session,Permissions::actor($db,'own-sepa-writer'),$general['entry'],$draft['id']),404,'another writer cannot inherit a notice draft');
$raw=$input;$raw['text']['message']=str_repeat('🙂',6001);$check(DebitInput::capture($raw)===$raw,'whole overlong Unicode notice is captured without truncation');$denyCode(fn()=>$native->prepare($raw),422,'overlong notice is rejected');
$raw=$input;$raw['text']['message']='{{VORNAME}}';$denyCode(fn()=>$native->prepare($raw),422,'unknown personal value cannot be invented for a notice');
foreach(['2026-02-31',"\n0\n"]as$date){$raw=$input;$raw['collection']=$date;$denyCode(fn()=>$native->prepare($raw),422,'invalid exact collection date is rejected');}
$raw=$input;$raw['collection']=$day;$denyCode(fn()=>$native->prepare($raw),422,'today cannot claim fourteen-day advance notice');
$annualEntry=$finance->entry($annual['entry']);Db::run($db,'UPDATE finance_entries SET amount_cents=amount_cents+1 WHERE club_id=? AND id=?',[$f['club'],$annual['entry']]);$denyCode(fn()=>$native->initial($annual['entry'],$day,$collection),409,'changed contribution amount cannot be announced against a different issued original PDF');Db::run($db,'UPDATE finance_entries SET amount_cents=? WHERE club_id=? AND id=?',[$annualEntry['amount_cents'],$f['club'],$annual['entry']]);
$plan=$native->prepare($input);$state=$settings->section('bank');$settings->save('bank',array_replace($state['data'],['iban'=>'DE89370400440532013000']),$state['version']);$denyCode(fn()=>$native->accept($input,$key(),$plan['hash'],true),409,'changed bank never replaces the issued original creditor');$state=$settings->section('bank');$settings->save('bank',$bankData,$state['version']);
$plan=$native->prepare($input);$db->exec("CREATE TRIGGER own_notice_abort BEFORE INSERT ON native_settings_requests FOR EACH ROW BEGIN IF NEW.section='debit-notice' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own notice rollback';END IF;END");$before=ownOriginalDatabaseHash($db,$tables);$failed=false;try{$native->accept($input,$key(),$plan['hash'],true);}catch(PDOException $failure){if(($failure->errorInfo[0]??null)!=='45000')throw $failure;$failed=true;}finally{$db->exec('DROP TRIGGER own_notice_abort');}$check($failed&&ownOriginalDatabaseHash($db,$tables)===$before,'actual SQL abort rolls back whole document audit and personal confirmation together');
final class OwnDebitLostCommitPDO extends PDO
{
 public string $requestKey='';public bool $armed=true;
 public function commit():bool{
  $written=false;if($this->armed&&$this->requestKey!==''){$q=$this->prepare("SELECT 1 FROM native_settings_requests WHERE request_key=? AND section='debit-notice'");$q->execute([$this->requestKey]);$written=(bool)$q->fetchColumn();}
  $ok=parent::commit();if($written){$this->armed=false;throw new RuntimeException('Own actual notice COMMIT acknowledgement lost');}return $ok;
 }
}
$cfg=\Kulturhus\Native\TestSupport::config();$faultDb=new OwnDebitLostCommitPDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$faultKey=$key();$faultDb->requestKey=$faultKey;$faultRequests=new DebitRequests($faultDb,$actor,new MemberBankVault($faultDb,$actor,$f['path']));$lost=false;try{$faultRequests->accept($input,$faultKey,$plan['hash'],true);}catch(RuntimeException $failure){$lost=$failure->getMessage()==='Own actual notice COMMIT acknowledgement lost';}
$lostFirst=$native->receipt($faultKey);$wholeAfter=ownOriginalDatabaseHash($db,$tables);$check($lost&&$faultRequests->accept($input,$faultKey,$plan['hash'],true)===$lostFirst&&ownOriginalDatabaseHash($db,$tables)===$wholeAfter,'actual lost COMMIT acknowledgement preserves one canonical PDF and replays the same private first');
$history=$outgoing('Own notice history buyer');$historyFirsts=[];for($i=0;$i<26;$i++){$raw=$native->initial($history['entry'],$day,$collection);$raw['reason']='Own notice history 0 '.$i;$review=$native->prepare($raw);$historyFirsts[]=$native->accept($raw,$key(),$review['hash'],true);}
$page=$native->history($history['entry'],['query'=>'Own notice history 0','page'=>'2']);$check($page['total']===26&&count($page['rows'])===1,'twenty-six actual PDF originals expose a genuine second personal history page');$check($native->history($history['entry'],['page'=>'999'])['rows']===[]&&$native->history($history['entry'],['page'=>'999'])['error']!=='','nonexistent exact history page does not substitute another original');
$plan=$native->prepare($input);$finance->recordPayment($general['entry'],1,$account,$day,'1');$denyCode(fn()=>$native->accept($input,$key(),$plan['hash'],true),409,'actual later partial payment invalidates an earlier notice review');$check($native->receipt($firsts[1]['key'])===$firsts[1],'earlier exact notice original survives a later payment');
foreach($sourceFiles as$id=>$original)$check($docs->file($id)===$original,'entire issued source PDF metadata and snapshot remain exact');
$settings->saveRole($f['financeRole'],3,array_replace($f['financeRoleData'],['finance_access'=>'read']));$check($native->receipt($firsts[0]['key'])===$firsts[0]&&hash('sha256',$native->pdf($firsts[0]['key']))===$firsts[0]['result']['sha256'],'downgraded author retains exact own first and PDF without bank authority');$settings->saveRole($f['financeRole'],4,$f['financeRoleData']);
$cases=[];for($i=0;$i<8;$i++)$cases[]=$i%2===0?$annual:$outgoing('Own browser notice recipient '.$i);
if(getenv('DVM_DEBIT_NOTICE_BROWSER')==='1')require __DIR__.'/support/debit-notice-browser-fixture.php';
echo json_encode(['suite'=>'native-debit-notice-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
