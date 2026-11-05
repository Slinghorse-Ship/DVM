<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/vendor/autoload.php';require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';require __DIR__.'/support/credit-refund-fixture.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Shared\Db};
use Kulturhus\Verein\{Finance,TransferOrders,MemberBankVault,InvoiceApprovals};
$db=TestSupport::db();SchemaRegistry::install($db);$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";};$key=static fn()=>bin2hex(random_bytes(16));
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/own-credit-refund';mkdir($root,0700);$f=ownCreditRefundFixture($db,'own-list-club',$root);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$firsts=[];
foreach(['person','organisation']as$kind){
    $case=($f['make'])($kind,true);$input=($f['inputFor'])($case['creditEntry']);$before=ownOriginalDatabaseHash($db,$tables);$review=$f['native']->prepare($input);$source=$review['source']['preview']['source'];
    $check($source['source_version']===3&&$source['recipient_kind']==='credit-refund'&&$source['total_cents']===2000&&$source['credit_refund']['parent_id']===$case['invoice']&&$source['credit_refund']['id']===$case['credit']&&$source['credit_refund']['paid_cents']===10000&&$source['credit_refund']['credited_cents']===4500,'actual issued '.$kind.' credit binds original100 paid100 both credits45 and only remaining20');
    $check(ownOriginalDatabaseHash($db,$tables)===$before,'whole credit preview changes no earlier table cell or read audit');
    $approval=(new InvoiceApprovals($db,$f['reader']))->state($case['creditEntry']);$check(!str_contains($approval['last']['snapshot'],'OWN-PRIVATE-NOT-FINANCE-'),'real finance-only original approval includes payment identity without unrelated private contact profile fields');
    $first=$f['native']->accept($input,$key(),$review['hash'],true);$order=$first['result']['order']['id'];$after=ownOriginalDatabaseHash($db,$tables);$check($f['native']->accept($input,$first['key'],$review['hash'],true)===$first&&ownOriginalDatabaseHash($db,$tables)===$after,'identical accepted credit preparation returns the exact first without another order');
    $f['orders']->approve($order,1);$sealed=$f['orders']->get($order)['sealed_snapshot'];$payload=$f['orders']->fintsPayload($order,2);$xml=$f['orders']->export($order,2);$doc=new DOMDocument();$doc->loadXML($xml,LIBXML_NONET);$xp=new DOMXPath($doc);
    $check($xml===$payload['xml']&&$doc->schemaValidate(dirname(__DIR__,2).'/banking/schemas/pain.001.001.09_GBIC_5.xsd')&&$xp->evaluate('string(//*[local-name()="InstdAmt"])')==='20.00'&&$xp->evaluate('string(//*[local-name()="CdtrAcct"]//*[local-name()="IBAN"])')===$f['bank']['iban'],'real '.$kind.' credit XML pays exact remaining20 to the saved original recipient and meets actual SEPA schema');
    $check($f['orders']->get($order)['sealed_snapshot']===$sealed&&$f['finance']->entry($case['creditEntry'])['remaining_cents']===2000&&!str_contains($first['original_json'],$f['bank']['iban']),'export preserves its encrypted first and leaves the actual credit unpaid and public original bank account masked');$firsts[]=compact('case','input','first','order','xml','sealed');
}
require __DIR__.'/support/credit-refund-contracts.php';$contracts=ownCreditRefundContracts($db,$check,$f,$firsts);
require __DIR__.'/support/credit-refund-legacy.php';ownCreditRefundLegacy($db,$check,$f);
if(getenv('DVM_CREDIT_REFUND_BROWSER')==='1')require __DIR__.'/support/credit-refund-browser-fixture.php';
echo json_encode(['suite'=>'native-credit-refund-transfers','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
