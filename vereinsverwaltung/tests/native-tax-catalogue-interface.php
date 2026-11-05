<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');ob_start();
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/Web/View.php';
require __DIR__.'/support/original-database-hash.php';require __DIR__.'/support/editorial-browser-fixture.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Shared\Db};
use Kulturhus\Native\Language\TaxCatalogueMessages;
use Kulturhus\Verein\{Finance,TaxCatalog};
use Kulturhus\Verein\Web\{OriginalInput,TaxCatalogueForm};
$cfg=TestSupport::config();$db=TestSupport::db();
if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-tax-catalogue-interface'||($cfg['host']??'')!=='127.0.0.1'||($cfg['port']??0)!==13371||$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)!==[])throw new RuntimeException('Fresh owned catalogue fixture required.');
SchemaRegistry::install($db);$f=ownEditorialBrowserFixture($db);$actor=$f['accounts']['admin']['actor'];$finance=new Finance($db,$actor);$n=0;
$check=static function(bool $ok,string $why)use(&$n):void{if(!$ok)throw new RuntimeException($why);++$n;echo'PASS '.$why.PHP_EOL;};
$deny=static function(callable $run,string $why,?int $code=null)use($check):void{$failure=null;try{$run();}catch(DomainException $e){$failure=$e;}$check($failure!==null&&($code===null||$failure->getCode()===$code),$why);};
$readActor='own-catalogue-finance-reader';Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'member',0)",[$readActor,$f['club']]);$readSettings=new Kulturhus\Verein\Settings($db,$actor);$readRole=$readSettings->saveRole('',0,['name'=>'Own financial reference reader','description'=>'Own isolated read-only financial references','members_access'=>'none','finance_access'=>'read','settings_access'=>false]);$readSettings->assignRole($readActor,$readRole,0);$readFinance=new Finance($db,$readActor);
$check($readFinance->canRead()&&!$readFinance->canWrite(),'actual financial reader retains reference access without account-writing rights');
$q=['view'=>'settings','tab'=>'tax-accounts'];$b=['view'=>'settings','tab'=>'booking-keys'];
$codes=[];for($i=40001;$i<49999&&count($codes)<8;$i++){if(!isset(TaxCatalog::accounts()[(string)$i])&&!TaxCatalog::reserved((string)$i))$codes[]=(string)$i;}
$check(count($codes)===8,'owned account examples use actual available non-reserved source ranges');
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);
foreach([
 $q+['q'=>['0']],$q+['q'=>null],$q+['q'=>"invalid\xff"],$q+['q'=>"invalid\ntext"],$q+['q'=>str_repeat('ä',1001)],$q+['group'=>['revenue']],$q+['group'=>'not-an-original-group'],$q+['active'=>null],$q+['active'=>['1']],$q+['active'=>'yes'],$q+['page'=>'0'],$q+['page'=>'2x'],$q+['page'=>['2']],$q+['page'=>'1000001'],$q+['account'=>'0400'],$q+['account'=>['40401']],$q+['extra'=>'ignored'],
 $b+['active'=>'1'],$b+['account'=>'40401'],$b+['group'=>'not-an-original-group'],['view'=>'members','tab'=>'tax-accounts'],
]as$i=>$raw)$deny(static fn()=>TaxCatalogueForm::query($raw),'malformed original catalogue selection is refused without fallback '.$i,422);
$check(TaxCatalogueForm::query($q+['q'=>'0','active'=>'0'])['q']==='0'&&TaxCatalogueForm::query($q+['active'=>'0'])['active']==='0','literal zero search and inactive selection stay explicit');
$check(TaxCatalogueForm::query($b+['q'=>'%_'])['q']==='%_','catalogue search keeps literal wildcard characters');
$post=['action'=>'save-tax-account','csrf'=>'Own unreflected csrf','code'=>$codes[0],'name'=>'0 Own full original account <&> ä🙂','active'=>'1','version'=>'0','create_only'=>'1'];
$check(TaxCatalogueForm::command($q,$post)['create_only']&&TaxCatalogueForm::command($q,$post)['name']===$post['name'],'new account intention keeps original name code active flag and zero version');
$edit=$post;unset($edit['create_only']);$edit['version']='1';$selected=$q+['account'=>$codes[0]];
$check(TaxCatalogueForm::command($selected,$edit)['code']===$codes[0],'existing account change is bound to its opened original number');
foreach([
 [$q,array_replace($post,['active'=>'0'])],[$q,array_replace($post,['create_only'=>'0'])],[$q,array_replace($post,['version'=>['0']])],[$q,array_replace($post,['name'=>['Own array']])],[$q,array_replace($post,['action'=>'save-tax-account '])],[$q,$post+['ignored'=>'not ignored']],[$selected,array_replace($post,['version'=>'1'])],[$selected,array_replace($edit,['code'=>$codes[1]])],[$b,$post],[$q,$edit],
]as$i=>[$source,$raw])$deny(static fn()=>TaxCatalogueForm::command($source,$raw),'unsupported or crossed original account command is refused '.$i);
$check(ownOriginalDatabaseHash($db,$tables)===$before,'all whole original business private and schema rows stay exact through metadata checks');
$packet=json_decode(OriginalInput::json($q,['name'=>"own\xff",'active'=>'0','version'=>['own original'],'csrf'=>'Never reflect CSRF','password'=>'Never reflect password','clear_password'=>'1']),true,32,JSON_THROW_ON_ERROR);
$packetFields=[];foreach($packet['form']['entries']as$entry)$packetFields[$entry['key']['value']]=$entry['value'];$check(($packetFields['name']['base64']??null)===base64_encode("own\xff")&&($packetFields['clear_password']['value']??null)==='1'&&($packetFields['version']['type']??null)==='array'&&!array_key_exists('csrf',$packetFields)&&!array_key_exists('password',$packetFields),'typed original packet retains binary and structured intentions without authentication material');
$messages=TaxCatalogueMessages::messages('en');$check(TaxCatalogueMessages::text('Steuerkontenliste',$messages)==='Tax accounts','fixed account heading has an explicit English translation');
$check(TaxCatalogueMessages::text('{code} ist ein Steuerkonto, kein Buchungsschlüssel.',$messages,['code'=>'{category} Steuerkontenliste'])==='{category} Steuerkontenliste is a tax account, not a posting key.','fixed label interpolation never translates or expands original account text');
$render=static function(array $query,array $post=[],int $status=200,array $messages=[],?string $asActor=null)use($db,$actor):array{
 $renderActor=$asActor??$actor;$finance=new Finance($db,$renderActor);$_SESSION=['actor'=>$renderActor,'csrf'=>'Own render csrf'];$_GET=$query;$_POST=$post;$_SERVER['REQUEST_METHOD']=$post===[]?'GET':'POST';$canFinance=true;$managerLanguageMessages=$messages;http_response_code($status);ob_start();
 try{require dirname(__DIR__).($query['tab']==='booking-keys'?'/views/Finance/Accounting/booking-keys.php':'/views/Finance/Tax/tax-accounts.php');return['html'=>(string)ob_get_contents(),'status'=>http_response_code()];}finally{ob_end_clean();}
};
$readView=$render($q,[],200,$messages,$readActor);$check($readView['status']===200&&str_contains($readView['html'],'<table')&&!str_contains($readView['html'],'value="save-tax-account"')&&!str_contains($readView['html'],'catalog=1'),'actual financial reader can read the catalogue without inaccessible editing or creation forms');
$readPost=$render($selected,$edit,403,$messages,$readActor);$check($readPost['status']===403&&str_contains($readPost['html'],'data-tax-catalogue-rejected')&&!str_contains($readPost['html'],'data-tax-catalogue-correction'),'actual read-only account rejects original write intention without a fresh editable form');
$deny(static fn()=>$readFinance->saveTaxAccount($post['code'],$post['name'],true,0,true),'actual financial reader cannot mutate an account even through the service');
$bad=$render($q+['q'=>['Own original query']],[],200,$messages);$check($bad['status']===422&&str_contains($bad['html'],'Own original query')&&!str_contains($bad['html'],'<table'),'actual renderer refuses a structured query without displaying substitute account rows');
$missing=$render($q+['page'=>'1000000']);$check($missing['status']===422&&str_contains($missing['html'],'data-tax-catalogue-rejected')&&!str_contains($missing['html'],'<table'),'actual unavailable account page stays original instead of becoming the last page');
$missing=$render($b+['page'=>'1000000']);$check($missing['status']===422&&!str_contains($missing['html'],'<table'),'actual unavailable posting-key page does not substitute other keys');
$long=$post;$long['name']='0 Own overlong original ä🙂 '.str_repeat(' ä🙂 <&> ',80);$deny(static fn()=>$finance->saveTaxAccount($long['code'],$long['name'],true,0,true),'actual finance service refuses overlong original without truncating');
$correction=$render($q,$long,422,$messages);$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$correction['html']);$xpath=new DOMXPath($dom);
$check($xpath->query('//form[@data-tax-catalogue-correction]//input[@name="name"]')->item(0)?->getAttribute('value')===$long['name']&&$xpath->query('//input[@name="version"]')->item(0)?->getAttribute('value')==='0','actual correction form retains complete overlong name and original zero version');
$check($xpath->query('//input[@name="create_only"]')->item(0)?->getAttribute('value')==='1'&&!str_contains($correction['html'],'data-tax-account='),'actual rejected creation keeps its exact creation intent without current substitute rows');
$check(!str_contains($correction['html'],$post['csrf']),'original packet never reflects the submitted authentication value');
$unconfirmed=$render($q,$long,503,$messages);$check(str_contains($unconfirmed['html'],'<fieldset disabled>')&&str_contains($unconfirmed['html'],'target="_blank"')&&!str_contains($unconfirmed['html'],'data-tax-account='),'actual unconfirmed save keeps read-only original values and an explicit separate current read');
$check(ownOriginalDatabaseHash($db,$tables)===$before,'all whole original rows remain exact through actual query correction and unconfirmed render cases');

// Real SQL rollback and an accepted COMMIT with lost acknowledgement exercise the existing finance transaction.
$finance->saveTaxAccount($codes[0],'Own original before failed update',true,0,true);
$before=ownOriginalDatabaseHash($db,$tables);
$db->exec("CREATE TRIGGER own_catalogue_abort BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='finance.tax-account.saved' AND NEW.object_id='".$codes[0]."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own original account audit SQL abort'; END IF; END");
$failed=false;try{$finance->saveTaxAccount($codes[0],'Own refused update',false,1);}catch(PDOException $failure){$failed=str_contains($failure->getMessage(),'Own original account audit SQL abort');}finally{$db->exec('DROP TRIGGER own_catalogue_abort');}
$check($failed&&ownOriginalDatabaseHash($db,$tables)===$before,'actual audit SQL abort rolls back the complete account change and retains the original error');
final class OwnCatalogueCommit extends PDO{public bool $lose=false;public function commit():bool{$ok=parent::commit();if($this->lose){$this->lose=false;throw new PDOException('Own account COMMIT accepted before response loss');}return$ok;}}
$fault=new OwnCatalogueCommit('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$faultFinance=new Finance($fault,$actor);
$untouched=array_values(array_diff($tables,['tax_account_settings','audit_events']));$untouchedBefore=ownOriginalDatabaseHash($db,$untouched);$earlierAudit=$db->query('SELECT * FROM audit_events ORDER BY sequence')->fetchAll();
$fault->lose=true;$lost=false;try{$faultFinance->saveTaxAccount($codes[0],'Own accepted account after lost response',false,1);}catch(PDOException $failure){$lost=$failure->getMessage()==='Own account COMMIT accepted before response loss';}
$saved=$finance->taxAccounts()[$codes[0]];$check($lost&&$saved['name']==='Own accepted account after lost response'&&(int)$saved['version']===2&&(int)$saved['active']===0,'actual accepted COMMIT keeps one exact account change despite lost response');
$afterCommit=ownOriginalDatabaseHash($db,$tables);$deny(static fn()=>$finance->saveTaxAccount($codes[0],'Own refused stale intention',true,1),'stale original after accepted COMMIT cannot overwrite its saved successor');
$check(ownOriginalDatabaseHash($db,$tables)===$afterCommit,'stale repetition cannot add another account or audit record');
$audit=$db->query('SELECT * FROM audit_events ORDER BY sequence')->fetchAll();$check(array_slice($audit,0,count($earlierAudit))===$earlierAudit&&count($audit)===count($earlierAudit)+1&&ownOriginalDatabaseHash($db,$untouched)===$untouchedBefore,'all complete earlier audit and unrelated whole original tables stay exact through accepted account change');
$_POST=[];$_GET=[];$_SERVER['REQUEST_METHOD']='GET';http_response_code(200);if(ob_get_level()>0)ob_end_flush();
echo json_encode(['suite'=>'tax-catalogue-interface-models','checks'=>$n,'whole_tables'=>count($tables),'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
if(getenv('DVM_TAX_CATALOGUES_BROWSER')==='1')require __DIR__.'/support/tax-catalogues-browser-fixture.php';
echo json_encode(['suite'=>'native-tax-catalogue-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
