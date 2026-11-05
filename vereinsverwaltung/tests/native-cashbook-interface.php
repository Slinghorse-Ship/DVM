<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';require dirname(__DIR__).'/src/Web/View.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry};
use Kulturhus\Verein\Finance;
$db=TestSupport::db();SchemaRegistry::install($db);$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";};
$key=static fn()=>bin2hex(random_bytes(16));
$db->exec("INSERT INTO clubs VALUES('cashbook-own','Own actual cashbook'),('cashbook-foreign','Other cashbook');INSERT INTO actors(id,club_id,role,is_superuser)VALUES('cashbook-admin','cashbook-own','member',1),('cashbook-reader','cashbook-own','member',0),('cashbook-foreign','cashbook-foreign','member',1);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version)VALUES('cashbook-own','cashbook-read','Cashbook reader','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('cashbook-reader','cashbook-own','cashbook-read',1)");
$f=new Finance($db,'cashbook-admin');$cash=$f->createAccount('Cashbook <&> ä🙂','cash','1000','2020-01-01');$bank=$f->createAccount('Original own bank','offline','1000','2020-01-01');
$actual=static fn(string $account,string $day,string $direction,string $amount,string $reference)=>$f->createTransaction(['account'=>$account,'booked_on'=>$day,'direction'=>$direction,'amount'=>$amount,'counterparty'=>'0','description'=>$reference],$key());
$actual($cash,'2021-05-31','income','0.10','Whole prior positive');$actual($cash,'2021-05-31','expense','0.05','Whole prior negative');
for($i=0;$i<83;$i++)$actual($cash,$i<41?'2021-06-01':'2021-06-02',$i%4===0?'expense':'income',$i%4===0?'0.25':'1.00','Whole period original '.$i.' <&> ä🙂');
$source=$actual($cash,'2021-06-01','income','2.50','0 whole mixed cash original');$out=$actual($bank,'2021-06-01','expense','1.50','Own real bank counter');
$receiptA=$f->createEntry(['kind'=>'income','category'=>'general','description'=>'Whole original receipt zero','amount'=>'0.50','document_date'=>'2021-06-01','due_date'=>'2021-06-01'],$key());
$receiptB=$f->createEntry(['kind'=>'income','category'=>'general','description'=>'Whole original receipt wording','amount'=>'0.50','document_date'=>'2021-06-01','due_date'=>'2021-06-01'],$key());
// Synthetic historical originals with literal labels; never rewrite them after their actual financial decisions.
$q=$db->prepare('UPDATE finance_entries SET display_number=? WHERE id=?');$q->execute(['0',$receiptA]);$literal='Eigener Geldtransfer, <&> ä🙂';$q->execute([$literal,$receiptB]);
$f->allocate($source,1,$receiptA,1,'0.50',$key());$f->allocate($source,2,$receiptB,1,'0.50',$key());$f->linkInternalMovement($source,3,$out,1,'1.50','Whole own cash-bank original',$key());
$actual($cash,'2021-06-03','income','7','Outside selected period');
$whole=$f->cashbook($cash,'2021-06-01','2021-06-02');$csv=$f->exportCashbook($cash,'2021-06-01','2021-06-02');$repeatCsv=$f->exportCashbook($cash,'2021-06-01','2021-06-02');
$check($csv===$repeatCsv&&substr_count($csv,"\n")===count($whole['rows'])+2,'whole-period CSV keeps every original row independently of a display page');
$nativeAccess=(new \Kulturhus\Native\Integration\ManagerBridge($db,'cashbook-admin','native',static fn()=>null))->access();$catalog=new \Kulturhus\Native\Language\Catalog($db,$nativeAccess);$catalog->select('en');
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$seen=[];$previous=$whole['opening'];$typed=null;
for($page=1;$page<=3;$page++){
 $read=$f->cashbookPage($cash,'2021-06-01','2021-06-02',$page);
 $check($read['page']===$page&&$read['pages']===3&&$read['total']===84&&count($read['rows'])<=40,'actual cashbook page '.$page.' loads only its bounded original rows');
 foreach(['opening','closing','income','expense','account','closures']as$field)$check($read[$field]===$whole[$field],'cashbook page '.$page.' retains the complete-period '.$field);
 $check($read['page_opening']===$previous,'cashbook page '.$page.' carries the exact preceding page balance');
 foreach($read['rows']as$row){$expected=$whole['rows'][count($seen)];$check($row['transaction_id']===$expected['transaction_id']&&$row['balance']===$expected['balance']&&$row['description']===$expected['description']&&$row['counterparty']===$expected['counterparty'],'cashbook source order exact cents running balance and whole originals agree with the original full book');$seen[]=$row['transaction_id'];if($row['id']===$source)$typed=$row;}
 $previous=$read['page_closing'];
}
$check(count(array_unique($seen))===84&&$previous===$whole['closing'],'all original cash rows are reachable exactly once and final carry equals the whole-period closing balance');
$check($typed!==null&&$typed['receipts']===[['id'=>$receiptA,'number'=>'0'],['id'=>$receiptB,'number'=>$literal]]&&(int)$typed['internal_cents']===150&&$typed['remaining_cents']===0,'typed cash associations retain literal zero comma Unicode and label-like receipt originals beside the actual own transfer');
$last=$f->cashbookPage($cash,'2021-06-01','2021-06-02','999999');$check($last['page']===3&&count($last['rows'])===4,'valid large cash page clamps to the final actual page');
$empty=$f->cashbookPage($cash,'2021-05-30','2021-05-30');$check($empty['rows']===[]&&$empty['pages']===1&&$empty['opening']===100000&&$empty['closing']===100000&&$empty['page_opening']===$empty['page_closing'],'empty cash period retains actual initial funds without invented rows');
foreach([0,-1,'1.5','1000001',null,[]]as$invalid){$denied=false;try{$f->cashbookPage($cash,'2021-06-01','2021-06-02',$invalid);}catch(DomainException){$denied=true;}$check($denied,'invalid whole cash page is rejected without replacing its input');}
foreach([[$cash,'2021-06-02','2021-06-01'],[$cash,'2019-12-31','2021-06-02'],[$bank,'2021-06-01','2021-06-02']]as$args){$denied=false;try{$f->cashbookPage(...$args);}catch(DomainException){$denied=true;}$check($denied,'cash projection respects the actual account kind and complete valid cash period');}
$check((new Finance($db,'cashbook-reader'))->cashbookPage($cash,'2021-06-01','2021-06-02',2)===$f->cashbookPage($cash,'2021-06-01','2021-06-02',2),'current finance reader sees the same original bounded cash page');
$denied=false;try{(new Finance($db,'cashbook-foreign'))->cashbookPage($cash,'2021-06-01','2021-06-02');}catch(DomainException){$denied=true;}$check($denied,'foreign association cannot read another cash account');
$db->beginTransaction();try{$db->exec("INSERT INTO contacts(id,club_id,kind,name)VALUES('cashbook-caller-work','cashbook-own','person','Whole caller-owned pending work')");$f->cashbookPage($cash,'2021-06-01','2021-06-02',2);$check($db->inTransaction()&&(int)$db->query("SELECT COUNT(*) FROM contacts WHERE id='cashbook-caller-work'")->fetchColumn()===1,'cash projection preserves its caller transaction and all earlier uncommitted work');}finally{if($db->inTransaction())$db->rollBack();}
$check($f->cashbook($cash,'2021-06-01','2021-06-02')===$whole,'bounded display leaves the complete original cashbook DTO exact');
$render=static function(array $get)use($db,$f,$nativeAccess):DOMXPath{
 $saved=[$_GET,$_POST,$_SESSION??[]];$_GET=['view'=>'finance','tab'=>'bank']+$get;$_POST=[];$_SESSION=['actor'=>'cashbook-admin','csrf'=>str_repeat('a',64)];$finance=$f;$accountRows=$f->accounts();$documents=new \Kulturhus\Verein\Documents($db,'cashbook-admin');$inventory=new \Kulturhus\Verein\Inventory($db,'cashbook-admin');$canFinance=true;$error='';
 ob_start();try{require dirname(__DIR__).'/views/Finance/Banking/bank.php';$html=ob_get_clean();}catch(Throwable $failure){ob_end_clean();throw $failure;}finally{[$_GET,$_POST,$_SESSION]=$saved;}
 $dom=new DOMDocument();$dom->loadHTML('<meta charset="utf-8">'.$html,LIBXML_NOERROR|LIBXML_NOWARNING);return new DOMXPath($dom);
};
$view=$render(['account'=>$cash,'from'=>'2021-06-01','to'=>'2021-06-02','cash_page'=>'2','bank_q'=>'0 <&>']);$middle=$f->cashbookPage($cash,'2021-06-01','2021-06-02',2);
$check($view->query('//*[@data-cashbook-transaction]')->length===40&&$view->query('//*[@data-cashbook-interface]//table')->length===0,'actual cashbook view renders only forty complete responsive originals without a wide table');
$check($view->evaluate('string(//*[@data-cashbook-page-opening])')===euro($middle['page_opening'])&&$view->evaluate('string(//*[@data-cashbook-page-closing])')===euro($middle['page_closing']),'actual cash page displays its own correct beginning and final carry');
$selected=[];foreach($view->query('//form[input[@name="action" and @value="auto-match"]]/input[@name="transactions[]"]')as$node)$selected[]=$node->getAttribute('value');
$check($selected===array_column($middle['rows'],'transaction_id'),'actual automatic matching selects exactly the visible original cash page');
$sourceLink=$view->evaluate('string((//*[@data-cashbook-source]/@href)[1])');parse_str((string)parse_url($sourceLink,PHP_URL_QUERY),$context);
$check(($context['cash_page']??'')==='2'&&($context['from']??'')==='2021-06-01'&&($context['to']??'')==='2021-06-02'&&($context['bank_q']??'')==='0 <&>','actual cash source links retain the whole period exact page and literal original bank search');
$check(str_contains($view->evaluate('string(//*[@data-cashbook-interface])'),'not a tax income and expense report')&&str_contains($view->evaluate('string(//*[@data-cashbook-interface])'),'Complete cash period'),'actual English cash display distinguishes full-period physical funds from a tax classification');
$sourceIndex=array_search($source,$seen,true);$sourcePage=(int)floor($sourceIndex/40)+1;$view=$render(['account'=>$cash,'from'=>'2021-06-01','to'=>'2021-06-02','cash_page'=>(string)$sourcePage]);$article='//*[@data-cashbook-transaction="'.$source.'"]';
$check($view->evaluate('string('.$article.'//div[@class="cashbook-receipts"]/a[1])')==='0'&&$view->evaluate('string('.$article.'//div[@class="cashbook-receipts"]/a[2])')===$literal,'actual English cash receipt links retain literal zero comma Unicode and German label-like original numbers');
$check($view->evaluate('string('.$article.'//p[@class="cashbook-association"])')==='Own-account transfer','only the actual typed own-transfer label follows the current language');
$view=$render(['account'=>$cash,'from'=>'2021-06-01','to'=>'2021-06-02','cash_page'=>'1.5']);
$check($view->evaluate('string(//form[@data-cashbook-filter]//input[@name="cash_page"]/@value)')==='1.5'&&str_contains($view->evaluate('string(//*[@data-cashbook-error])'),'whole page number'),'invalid cash page keeps the whole input and actual translated correction');
$check($view->query('//*[@data-cashbook-period-totals]')->length===0&&$view->query('//form[input[@name="action" and @value="auto-match"]]')->length===0,'rejected cash page does not invent totals or offer an unrelated automatic match');
$check(ownOriginalDatabaseHash($db,$tables)===$before,'all bounded cash reads retain every whole original table definition row financial decision and key');
if(getenv('DVM_CASHBOOK_BROWSER')==='1')require __DIR__.'/support/cashbook-browser-fixture.php';
echo json_encode(['suite'=>'native-cashbook-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
