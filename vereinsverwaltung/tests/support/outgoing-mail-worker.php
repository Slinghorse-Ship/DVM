<?php declare(strict_types=1);
use Kulturhus\Verein\{OutgoingInvoices,Finance};
use Kulturhus\Verein\Finance\Invoices\{OutgoingMailInput,OutgoingMailRequests};
use Kulturhus\Native\Delivery\{DeliveryNotStarted,MailMessage};
use Kulturhus\Native\Shared\Db;

final class OwnOutgoingMessageClaim extends PDO
{
    public $beforeClaim=null;
    public function scheduleBeforeClaim():void{if($this->beforeClaim!==null){$operation=$this->beforeClaim;$this->beforeClaim=null;$operation();}}
    public function beginTransaction():bool{$this->scheduleBeforeClaim();return parent::beginTransaction();}
}
final class OwnOutgoingMessageClaimStatement extends PDOStatement
{
    protected function __construct(private OwnOutgoingMessageClaim $owner){}
    public function execute(?array $params=null):bool
    {
        if(str_contains($this->queryString,"UPDATE outgoing_invoice_deliveries SET state='sending',updated_at=?"))$this->owner->scheduleBeforeClaim();
        return parent::execute($params);
    }
}
/** Schedule actual independent services between observation and the actual worker claim. */
function ownOutgoingMailWorker(PDO $db,callable $check,array $fixture,array $config):array
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/\Akh_(?:native|test)_[a-f0-9]{24}\z/D',$name)||$config['database']!==$name||$config['host']!=='127.0.0.1'||!in_array($config['port'],[13371,13372],true))throw new RuntimeException('Own isolated outgoing worker required.');
    $dsn='mysql:host=127.0.0.1;port='.$config['port'].';dbname='.$name.';charset=utf8mb4';$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
    $worker=new OwnOutgoingMessageClaim($dsn,$config['username'],$config['password'],$options);$worker->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnOutgoingMessageClaimStatement::class,[$worker]]);$other=new PDO($dsn,$config['username'],$config['password'],$options);
    $service=$fixture['service'];$requests=$fixture['requests'];$actor=$fixture['actor'];$origin=$fixture['origin'];$key=static fn()=>bin2hex(random_bytes(16));$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    OutgoingInvoices::dispatch($db,$origin,static fn()=>throw new DeliveryNotStarted('mail_message_invalid'),'own-list-club');
    $issue=static function(string $description)use($service,$fixture,$key):string{$id=$service->save('',0,array_replace($fixture['data'],['description'=>$description]),$key());$service->review($id,1);$service->issue($id,2);return $id;};
    $id=$issue('Own actual separately replaced pending invoice message');$input=OutgoingMailInput::initial($requests->context($id));$plan=$requests->prepare($input);$first=$requests->accept($input,$key(),$plan['hash'],true);$replaced=null;
    $originals=['outgoing_invoices','outgoing_invoice_history','finance_entries','finance_transactions','finance_allocations','accounting_positions','documents','document_files'];$before=ownOriginalDatabaseHash($db,$originals);
    $worker->beforeClaim=static function()use($other,$origin,$actor,$id,$key,&$replaced):void{
        OutgoingInvoices::dispatch($other,$origin,static fn()=>throw new DeliveryNotStarted('mail_message_invalid'),'own-list-club');$service=new OutgoingMailRequests($other,$actor);$next=OutgoingMailInput::initial($service->context($id),'replace');$next['data']['subject']='Own deliberately newer independently approved subject 0';$next['data']['message']='Own deliberately newer whole independently approved message **0**';$review=$service->prepare($next);$replaced=$service->accept($next,$key(),$review['hash'],true);
    };
    $calls=0;$sent=OutgoingInvoices::dispatch($worker,$origin,static function()use(&$calls):void{$calls++;},'own-list-club');$current=Db::run($db,'SELECT * FROM outgoing_invoice_deliveries WHERE club_id=? AND invoice_id=?',['own-list-club',$id])->fetch();
    $check($replaced!==null&&$sent===0&&$calls===0&&$current===$replaced['result']&&$requests->receipt($first['key'])===$first&&$requests->receipt($replaced['key'])===$replaced&&ownOriginalDatabaseHash($db,$originals)===$before,'actual older worker skips a genuinely newer independently approved whole message without sending old text or changing the new pending authorization or either first original');
    $messages=[];$sent=OutgoingInvoices::dispatch($db,$origin,static function($to,$subject,$text,$html,$attachments)use(&$messages):void{MailMessage::compose(['from'=>'own-worker@example.invalid','reply_to'=>'own-worker@example.invalid'],['to'=>$to,'subject'=>$subject,'text'=>$text,'html'=>$html,'attachments'=>$attachments])->toString();$messages[]=compact('subject','text','html')+['sha256'=>hash('sha256',$attachments[0]['bytes'])];},'own-list-club');
    $check($sent===1&&count($messages)===1&&$messages[0]['subject']===$replaced['result']['subject']&&$messages[0]['sha256']===$replaced['result']['document_sha256']&&str_contains($messages[0]['html'],'<strong>0</strong>')&&ownOriginalDatabaseHash($db,$originals)===$before,'later actual worker sends only the newer approved complete message with its exact original PDF through real MIME and a capturing callback');
    // The same independent scheduling proves that current payment facts belong to the claim.
    $paymentId=$issue('Own actual payment change before invoice message claim');$context=$requests->context($paymentId);$paymentInput=OutgoingMailInput::initial($context);$paymentPlan=$requests->prepare($paymentInput);$paymentFirst=$requests->accept($paymentInput,$key(),$paymentPlan['hash'],true);$paymentChanged=false;
    $immutable=['outgoing_invoices','outgoing_invoice_history','accounting_positions','documents','document_files'];$immutableBefore=ownOriginalDatabaseHash($db,$immutable);
    $worker->beforeClaim=static function()use($other,$actor,$fixture,$context,$key,&$paymentChanged):void{
        $finance=new Finance($other,$actor);$transaction=$finance->createTransaction(['account'=>$fixture['account'],'booked_on'=>'2025-12-04','direction'=>'income','amount'=>'100.00','description'=>'Own actual payment while earlier mail worker was observing','counterparty'=>'Own actual buyer'],$key());$tx=$finance->transactionDetail($transaction);$entry=$finance->entry($context['invoice']['entry_id']);$finance->allocate($transaction,(int)$tx['version'],$entry['id'],(int)$entry['version'],'100.00',$key());$paymentChanged=true;
    };
    $calls=0;$sent=OutgoingInvoices::dispatch($worker,$origin,static function()use(&$calls):void{$calls++;},'own-list-club');$changed=$requests->context($paymentId);
    $check($paymentChanged&&$sent===0&&$calls===0&&$changed['delivery']['state']==='blocked'&&$changed['payment']['open_cents']===25700&&$requests->receipt($paymentFirst['key'])===$paymentFirst&&ownOriginalDatabaseHash($db,$immutable)===$immutableBefore,'actual payment inserted and allocated before the claim stops the old amount without transport and retains the exact earlier invoice PDF positions and first approved payment facts');
    $next=OutgoingMailInput::initial($changed,'replace');$review=$requests->prepare($next);$currentFirst=$requests->accept($next,$key(),$review['hash'],true);$pending=$currentFirst['result'];$before=ownOriginalDatabaseHash($db,$tables);
    $held=new PDO($dsn,$config['username'],$config['password'],$options);$held->beginTransaction();Db::run($held,'SELECT * FROM outgoing_invoices WHERE club_id=? AND id=? FOR UPDATE',['own-list-club',$paymentId])->fetch();
    try{$calls=0;$started=microtime(true);$sent=OutgoingInvoices::dispatch($db,$origin,static function()use(&$calls):void{$calls++;},'own-list-club');$check($sent===0&&$calls===0&&microtime(true)-$started<3&&ownOriginalDatabaseHash($db,$tables)===$before,'actual busy invoice source leaves the complete pending message retryable without a callback or another business write');}finally{$held->rollBack();}
    $sent=OutgoingInvoices::dispatch($db,$origin,static fn()=>throw new DeliveryNotStarted('mail_configuration_missing'),'own-list-club');$retry=$requests->context($paymentId);$check($sent===0&&$retry['delivery']['state']==='pending'&&$retry['delivery']['body']===$pending['body']&&$retry['delivery']['recipient']===$pending['recipient']&&$retry['delivery']['document_sha256']===$pending['document_sha256'],'actual known-unsent temporary mail configuration failure retains the complete approved original pending');
    $unknownCalls=0;OutgoingInvoices::dispatch($db,$origin,static function()use(&$unknownCalls):void{$unknownCalls++;throw new RuntimeException('Own actual unconfirmed transport result.');},'own-list-club');$unknown=$requests->context($paymentId);$refused=false;try{$requests->prepare(OutgoingMailInput::initial($unknown,'replace'));}catch(DomainException $failure){$refused=$failure->getCode()===409;}
    OutgoingInvoices::dispatch($db,$origin,static function()use(&$unknownCalls):void{$unknownCalls++;},'own-list-club');$check($unknownCalls===1&&$unknown['delivery']['state']==='uncertain'&&$refused&&$requests->receipt($currentFirst['key'])===$currentFirst&&$requests->receipt($paymentFirst['key'])===$paymentFirst&&ownOriginalDatabaseHash($db,$immutable)===$immutableBefore,'actual unknown begun delivery stays uncertain and cannot be replaced or automatically repeated while all first approvals and earlier issued originals remain exact');
    $before=ownOriginalDatabaseHash($db,$tables);$db->beginTransaction();$db->exec("UPDATE clubs SET name='Own earlier uncommitted worker caller work' WHERE id='foreign-list-club'");$callerBefore=ownOriginalDatabaseHash($db,$tables);
    try{$calls=0;$denied=false;try{OutgoingInvoices::dispatch($db,$origin,static function()use(&$calls):void{$calls++;},'own-list-club');}catch(DomainException $failure){$denied=$failure->getCode()===503;}$check($denied&&$calls===0&&$db->inTransaction()&&ownOriginalDatabaseHash($db,$tables)===$callerBefore,'actual worker refuses transport inside an earlier uncommitted caller transaction without touching its whole work');}finally{$db->rollBack();}
    $check(ownOriginalDatabaseHash($db,$tables)===$before,'whole business state after caller rollback is exact when dispatch requires its own committed claim');
    return compact('id','first','replaced','paymentId','paymentFirst','currentFirst','unknown');
}
