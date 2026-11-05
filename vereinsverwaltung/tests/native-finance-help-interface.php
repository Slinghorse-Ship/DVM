<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/Web/View.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Integration\ManagerBridge,Language\Catalog,Language\FinanceHelpMessages};
use Kulturhus\Verein\Finance;
$db=TestSupport::db();SchemaRegistry::install($db);$n=0;ob_start();
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";};
$db->exec("INSERT INTO clubs VALUES('own-list-club','Own finance help club'),('help-foreign','Other help club');INSERT INTO actors(id,club_id,role,is_superuser)VALUES('own-list-admin','own-list-club','member',1),('own-list-reader','own-list-club','member',0),('own-list-foreign','help-foreign','member',1),('help-no-finance','own-list-club','member',0);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version)VALUES('own-list-club','help-read','Own help finance reader','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('own-list-reader','own-list-club','help-read',1)");
$render=static function(string $actor,string $locale)use($db):array{
 $nativeAccess=(new ManagerBridge($db,$actor,'native',static fn()=>null))->access();$catalog=new Catalog($db,$nativeAccess);$catalog->select($locale);$finance=new Finance($db,$actor);http_response_code(200);ob_start();require dirname(__DIR__).'/views/Finance/Accounting/finance-help.php';$html=ob_get_clean();$document=new DOMDocument();@$document->loadHTML('<?xml encoding="UTF-8">'.$html);return[$html,new DOMXPath($document),http_response_code()];
};
$f=new Finance($db,'own-list-admin');$bank=$f->createAccount('0 Own original help bank <&> ä🙂','offline','100','2020-01-01');$f->createTransaction(['account'=>$bank,'booked_on'=>'2021-06-01','direction'=>'income','amount'=>'25','counterparty'=>'0','description'=>'Whole original help movement ä🙂 <&>'],bin2hex(random_bytes(16)));
$anchors=['zahlungsrueckfuehrung','teilrueckzahlung','bankausfuehrung','native-ruecklastschrift','gemeinsame-gegenbuchung','banknachweis-gebuehr','banknachweis-teilbetraege','banknachweis-sammel','banknachweis-vorhanden','gutschrift-rueckzahlung','ueberschuss-rueckzahlung','bank-abrufe','bank-zuordnung','rueckzahlung','eigene-geldtransfers'];
foreach(['de','en']as$locale)foreach(['own-list-admin','own-list-reader']as$actor){
 [$html,$xp,$status]=$render($actor,$locale);$check($status===200&&$xp->evaluate('string(//h1)')===($locale==='en'?'Finance step by step':'Finanzen Schritt für Schritt'),'actual own finance help heading follows the personal interface language');
 $check($xp->query('//section[@data-finance-help-topic]')->length===21,'all twenty original topics and the real manual receipt recovery topic remain available');
 foreach($anchors as$id)$check($xp->query('//*[@id="'.$id.'"]')->length===1,'whole original help anchor remains exact: '.$id);
 $links=$xp->query('//nav[@id="finanzthemen"]//a');$check($links->length===25,'quick paths and the complete topic index use native read-only links');
 foreach($links as$link){$id=substr($link->getAttribute('href'),1);if($xp->query('//section[@id="'.$id.'"]')->length!==1)throw new RuntimeException('Actual help link must reach its unique existing topic.');}
 $check($xp->query('//form|//script')->length===0,'help topic navigation contains no mutations or script dependency');
 $check(str_contains($html,'tab=bank&amp;bank_import=1')===($actor==='own-list-admin'),'current finance reader cannot inherit the writer-only private bank-review shortcut');
 foreach(['inbox','bank&amp;assignment=pending','export']as$target)$check(str_contains($html,'tab='.$target),'original real finance action route remains reachable: '.$target);
 $cash=$xp->evaluate('string(//*[@id="eigene-geldtransfers"])');$check(str_contains($cash,$locale==='en'?'starts no transfer':'löst keine Überweisung aus')&&str_contains($cash,'100')&&str_contains($cash,'3'),'cash guide retains neutral own-account movement and separate exact fee example');
 $bank=$xp->evaluate('string(//*[@id="bank-zuordnung"])');$check(str_contains($bank,$locale==='en'?'open expense invoice':'offenen Ausgabenrechnung')&&str_contains($bank,$locale==='en'?'prepared or submitted order':'vorbereiteter oder eingereichter Auftrag'),'bank guide retains invoice-bound outgoing payment and separate actual bank evidence');
 $manual=$xp->evaluate('string(//*[@id="beleg-erfassung"])');$check(str_contains($manual,$locale==='en'?'same original key':'derselben ursprünglichen Kennung')&&str_contains($manual,$locale==='en'?'Without JavaScript':'Ohne JavaScript'),'new help describes actual no-script controls and accepted-outcome recovery');
}
[$denied,,$status]=$render('help-no-finance','de');$check($denied===''&&$status===403,'current absence of finance permission refuses the complete help view');
$malicious='<img src=x onerror=alert(1)><strong>Safe & original</strong><script>alert(1)</script>';$key=FinanceHelpMessages::key('Own hostile translation');$safe=FinanceHelpMessages::html('Own hostile translation',[$key=>$malicious]);$doc=new DOMDocument();@$doc->loadHTML($safe);$xp=new DOMXPath($doc);$check($xp->query('//img|//script|//*[@onerror]')->length===0&&$xp->query('//strong')->length===1&&str_contains($doc->textContent,'<script>'),'translated help escapes executable markup and renders only the fixed strong tag');
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$protected=array_values(array_diff($tables,['native_language_preferences']));$before=ownOriginalDatabaseHash($db,$protected);
foreach(['de','en']as$locale)$render('own-list-admin',$locale);
$check(ownOriginalDatabaseHash($db,$protected)===$before,'actual complete guidance rendering preserves every whole original business row schema financial first and private record');
if(getenv('DVM_FINANCE_HELP_BROWSER')==='1'){
 require __DIR__.'/support/free-list-interface-browser-fixture.php';$fixture=ownFreeListInterfaceBrowser($db,[]);$protected=array_values(array_diff($tables,['native_auth_sessions','native_auth_limits','native_language_preferences']));$before=ownOriginalDatabaseHash($db,$protected);$private=[];foreach(['auth','private']as$name)$private[$name]=hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key');
 try{$process=proc_open(['node',__DIR__.'/finance-help-interface-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fwrite($pipes[0],json_encode($fixture['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Actual finance help browser failed.');}finally{foreach($fixture['servers']as[$server,$log]){proc_terminate($server);proc_close($server);fclose($log);}}
 $check(ownOriginalDatabaseHash($db,$protected)===$before,'actual help navigation across front direct scripts languages and roles changes no whole original business row or financial first');
 foreach($private as$name=>$hash)$check(hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key')===$hash,'actual finance help navigation preserves the exact private '.$name.' key');
}
echo json_encode(['suite'=>'native-finance-help-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";

ob_end_flush();
