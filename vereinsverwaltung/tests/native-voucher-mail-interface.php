<?php declare(strict_types=1);
require __DIR__.'/native-document-interface.php';
use Kulturhus\Verein\{Registry,EventVouchers,EventVoucherMail};
use Kulturhus\Native\{TestSupport,Shared\Db};

$nmRegistry=new Registry($db,'own-list-admin');$nmVouchers=new EventVouchers($db,'own-list-admin');$nmMail=new EventVoucherMail($db,'own-list-admin');$nmKey=static fn():string=>bin2hex(random_bytes(16));$nmPeople=[];
foreach(['own','other']as$nmIndex){$nmId=$nmRegistry->saveContact(null,0,'person','Own native mail recipient '.$nmIndex,'own-native-mail-'.$nmIndex.'@example.invalid','Own native address');$nmRegistry->addMembership($nmId,$nmIndex,'aktiv','2020-01-01');$nmPeople[$nmIndex]=$nmId;}
$nmSource=$nmRegistry->saveAppointment(null,0,['title'=>'Own full original mail event ä🙂','start'=>'2046-08-01T18:00','end'=>'2046-08-01T20:00','location'=>'Own original mail venue 0','description'=>str_repeat('Own private source body ',200),'category'=>'verein'],[]);
$nmEvent=$nmVouchers->open($nmSource,$nmKey());$nmVouchers->grant($nmEvent,[$nmPeople['own']],[['label'=>'0','kind'=>'other','unit'=>'piece','amount'=>'2']],$nmKey());$nmVouchers->grant($nmEvent,[$nmPeople['other']],[['label'=>'Own other recipient original','kind'=>'other','unit'=>'piece','amount'=>'1000']],$nmKey());
$nmConfig=TestSupport::config();$nmDsn='mysql:host='.$nmConfig['host'].';port='.$nmConfig['port'].';dbname='.$nmConfig['database'].';charset=utf8mb4';$nmOptions=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$nmCaller=new PDO($nmDsn,$nmConfig['username'],$nmConfig['password'],$nmOptions);$nmCaller->beginTransaction();$nmCaller->query('SELECT * FROM contacts')->fetchAll();$nmRegistry->saveContact($nmPeople['own'],1,'person','Own deliberately current mail recipient','own-current-native-mail@example.invalid','Own current address');$nmBefore=ownOriginalDatabaseHash($db,$tables);$nmPreview=(new EventVoucherMail($nmCaller,'own-list-admin'))->preview($nmEvent,$nmPeople['own']);
$check($nmPreview['snapshot']['person']['name']==='Own deliberately current mail recipient'&&$nmPreview['snapshot']['person']['email']==='own-current-native-mail@example.invalid'&&$nmCaller->inTransaction(),'actual native mail preview reads the committed current recipient through an earlier caller contact snapshot and preserves its transaction');
$check(count($nmPreview['snapshot']['vouchers'])===2&&$nmPreview['snapshot']['vouchers'][0]['label']==='0'&&ownOriginalDatabaseHash($db,$tables)===$nmBefore,'current native personal preview retains only the requested original pieces and zero label without changing any complete business row');$nmCaller->rollBack();
$nmCaller->beginTransaction();$nmCaller->query('SELECT * FROM club_settings')->fetchAll();$nmSmallTemplate=['subject'=>'Own currently saved {{NAME}}','message'=>"Own current original introduction 0\r\n{{VERANSTALTUNG}}"];$nmMail->saveTemplate($nmEvent,0,$nmSmallTemplate,$nmKey());$nmBefore=ownOriginalDatabaseHash($db,$tables);$nmCurrentTemplate=(new EventVoucherMail($nmCaller,'own-list-admin'))->template($nmEvent);
$check($nmCurrentTemplate['version']===1&&$nmCurrentTemplate['data']===EventVoucherMail::normalizeTemplate($nmSmallTemplate)&&$nmCaller->inTransaction()&&ownOriginalDatabaseHash($db,$tables)===$nmBefore,'actual current saved mail template survives an earlier caller settings snapshot and leaves every accepted row unchanged');$nmCaller->rollBack();

$nmFullTemplate=['subject'=>str_repeat('ä🙂',100),'message'=>str_repeat('🙂',6000)];$nmFullKey=$nmKey();$nmMail->saveTemplate($nmEvent,1,$nmFullTemplate,$nmFullKey);$nmReceipt=$nmVouchers->confirmation($nmFullKey);
$check($nmMail->template($nmEvent)['data']===$nmFullTemplate&&$nmReceipt['payload']===['mail-template',$nmEvent,1,$nmFullTemplate]&&strlen($nmReceipt['original_json'])>65535,'actual full two-hundred and six-thousand Unicode template fields retain every original character and acceptance beyond the former TEXT capacity');
$nmBefore=ownOriginalDatabaseHash($db,$tables);$nmMail->saveTemplate($nmEvent,1,$nmFullTemplate,$nmFullKey);$check($nmVouchers->confirmation($nmFullKey)===$nmReceipt&&ownOriginalDatabaseHash($db,$tables)===$nmBefore,'identical full Unicode template retry preserves the first original input and every earlier complete row');
foreach([['subject'=>str_repeat('🙂',201),'message'=>'0'],['subject'=>'0','message'=>str_repeat('🙂',6001)],['subject'=>"Own invalid\nheader",'message'=>'0'],['subject'=>'0','message'=>'Own {{UNKNOWN}} original'],['subject'=>'0','message'=>"Own invalid\x00original"]]as$nmInvalid){
 $nmBefore=ownOriginalDatabaseHash($db,$tables);$deny(fn()=>$nmMail->saveTemplate($nmEvent,2,$nmInvalid,$nmKey()),'invalid complete native mail template is refused before accepting an original');$check(ownOriginalDatabaseHash($db,$tables)===$nmBefore,'refused complete template never clips repairs or rewrites any earlier original business row');
}
$nmMail->saveTemplate($nmEvent,2,['subject'=>'0','message'=>'0'],$nmKey());$check($nmMail->template($nmEvent)['data']===['subject'=>'0','message'=>'0'],'native mail subject and introduction preserve literal zero rather than replacing it with a default');

final class OwnNativeMailTicketRead extends PDOStatement{
 public static int $individualRows=0;public static bool $forbid=false;
 protected function __construct(){}
 public function execute(?array $params=null):bool{
  if(self::$forbid&&preg_match('/\bFROM\s+(\w+)/i',$this->queryString,$match)&&$match[1]==='event_voucher_tickets')throw new RuntimeException('Individual native mail ticket loading occurred before the budget refusal');
  return parent::execute($params);
 }
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{
  $rows=parent::fetchAll($mode,...$args);if(preg_match('/\bFROM\s+(\w+)/i',$this->queryString,$match)&&$match[1]==='event_voucher_tickets')self::$individualRows+=count($rows);return$rows;
 }
}
$nmGuarded=new PDO($nmDsn,$nmConfig['username'],$nmConfig['password'],$nmOptions);$nmGuarded->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnNativeMailTicketRead::class,[]]);$nmGuardedMail=new EventVoucherMail($nmGuarded,'own-list-admin');$nmBefore=ownOriginalDatabaseHash($db,$tables);$nmSmall=$nmGuardedMail->preview($nmEvent,$nmPeople['own']);
$check(count($nmSmall['snapshot']['vouchers'])===2&&OwnNativeMailTicketRead::$individualRows===2&&ownOriginalDatabaseHash($db,$tables)===$nmBefore,'actual two-piece private mail preview loads exactly its own pieces while another recipient has one thousand originals');
$nmPlan=$nmMail->batchReview($nmEvent);$nmLegacy=$nmMail->batchPreview($nmEvent);
$check($nmPlan['hash']===$nmLegacy['hash']&&$nmPlan['skipped']===$nmLegacy['skipped']&&count($nmPlan['ready'])===1&&!isset($nmPlan['ready'][0]['snapshot'])&&$nmPlan['ready'][0]['count']===2,'native small batch review retains the exact legacy global confirmation fingerprint with metadata instead of every recipient code list');
$nmBadPages=$nmMail->batchPages($nmEvent,['ready_query'=>str_repeat('🙂',241)]);$check($nmBadPages['http_status']===422&&$nmBadPages['ready']===null&&$nmBadPages['skipped']!==null,'whole invalid batch search never supplies substitute recipients and preserves the independent skipped-person page');
require __DIR__.'/support/voucher-mail-originals.php';
require __DIR__.'/support/voucher-mail-native-actions.php';
$nmVouchers->grant($nmEvent,[$nmPeople['own']],[['label'=>'Own deliberate oversized native mail','kind'=>'other','unit'=>'piece','amount'=>'201']],$nmKey());OwnNativeMailTicketRead::$forbid=true;OwnNativeMailTicketRead::$individualRows=0;$nmBefore=ownOriginalDatabaseHash($db,$tables);$deny(fn()=>$nmGuardedMail->preview($nmEvent,$nmPeople['own']),'oversized current native recipient refuses its mail before any physical ticket selection');
$check(OwnNativeMailTicketRead::$individualRows===0&&ownOriginalDatabaseHash($db,$tables)===$nmBefore,'actual oversized mail budget refusal preserves every complete earlier source code balance movement request receipt and business row');OwnNativeMailTicketRead::$forbid=false;

if(getenv('DVM_VOUCHER_MAIL_INTERFACE_BROWSER')==='1')require __DIR__.'/support/voucher-mail-browser-fixture.php';
echo json_encode(['suite'=>'native-voucher-mail-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
