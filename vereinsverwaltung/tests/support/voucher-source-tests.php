<?php declare(strict_types=1);
use Kulturhus\Verein\{EventVouchers,WebsiteCalendar};
use Kulturhus\Native\Shared\Db;

$vsPages=new \Kulturhus\Native\Cms\Pages($db,$access);
$vsWebsite=WebsiteCalendar::fromNative($db,'own-list-club',$access->club(),'https://own-voucher-source.example.invalid');
$vsInput=['slug'=>'own-voucher-native-source','title'=>str_repeat('ä',160).' <em data-own-source>0</em> ä🙂','template'=>'event','event_start'=>'2040-06-01T18:00','event_end_local'=>'2040-06-01T20:00','event_location'=>'Own current place 0','event_address'=>'Own current address 0','body'=>str_repeat('Own private original source body ',300)];
$vsPublic=$vsPages->save($vsInput);$vsPublic=$vsPages->publish($vsPublic['id'],1);$vsService=new EventVouchers($db,'own-list-admin',$vsWebsite);$vsEvent=$vsService->open('website:'.$vsPublic['id'],$key());$vsService->grant($vsEvent,[$viPerson],[['label'=>'Own current public source piece','kind'=>'other','unit'=>'piece','amount'=>'1']],$key());$vsReader=new EventVouchers($db,'own-list-reader',$vsWebsite);
$vsBefore=ownOriginalDatabaseHash($db,$tables);$vsDisplay=$vsReader->cardsPage($vsEvent,[],true);
$check($vsDisplay['available']&&$vsDisplay['event']['title']===$vsInput['title']&&$vsDisplay['event']['location']==='Own current place 0 Own current address 0','native public source preserves the complete canonical Unicode title and exact projected venue');
$vsDraft=$vsPages->get($vsPublic['id']);$vsDraft['title']='Own hidden draft source';$vsDraft['event_location']='Own hidden draft place';$vsPages->save($vsDraft,$vsDraft['version']);
$vsDisplay=$vsReader->cardsPage($vsEvent,[],true);
$check($vsDisplay['event']['title']===$vsInput['title']&&$vsDisplay['event']['location']==='Own current place 0 Own current address 0','unpublished public source draft never replaces the currently published voucher metadata');
$viCaller->beginTransaction();$viCaller->query('SELECT * FROM khcms_pages')->fetchAll();$vsCurrent=$vsPages->get($vsPublic['id']);$vsPages->unpublish($vsPublic['id'],$vsCurrent['version']);
$vsBefore=ownOriginalDatabaseHash($db,$tables);$vsOldWebsite=WebsiteCalendar::fromNative($viCaller,'own-list-club',$access->club(),'https://own-voucher-source.example.invalid');$vsOldReader=new EventVouchers($viCaller,'own-list-reader',$vsOldWebsite);$vsWithdrawn=$vsOldReader->cardsPage($vsEvent,[],true);
$check(!$vsWithdrawn['available']&&$vsWithdrawn['notice']!==''&&$vsWithdrawn['total']===1&&$viCaller->inTransaction(),'actual earlier caller source snapshot cannot expose a withdrawn public source as an active original QR');
$deny(fn()=>$vsOldReader->printSnapshot($vsEvent,$viPerson,true),'actual withdrawn current public source refuses an original print inside the old caller transaction');$viCaller->rollBack();
$check(ownOriginalDatabaseHash($db,$tables)===$vsBefore,'public source withdrawal refusal preserves every accepted source revision code balance and business row');

$viCaller->beginTransaction();$viCaller->query('SELECT * FROM event_vouchers')->fetchAll();$vsTicket=$viReader->cardsPage($viEvents[0],['state'=>'active'],true)['rows'][0];$vsVersion=(int)Db::run($db,'SELECT version FROM event_vouchers WHERE id=?',[$vsTicket['id']])->fetchColumn();$vsMove=$viAdmin->change($viEvents[0],$vsTicket['id'],$vsVersion,'redeem','1','',$key(),$vsTicket['ticket_id']);$vsBefore=ownOriginalDatabaseHash($db,$tables);$vsOldCards=(new EventVouchers($viCaller,'own-list-reader'))->cardsPage($viEvents[0],['query'=>\Kulturhus\Verein\EventVoucherQr::payload($viEvents[0],$vsTicket['code'])],true);
$check($vsOldCards['total']===1&&$vsOldCards['rows'][0]['display_state']==='redeemed'&&$vsOldCards['rows'][0]['remaining']===0&&$viCaller->inTransaction(),'actual original redeemed after an earlier caller balance snapshot is current and never offered as active');$viCaller->rollBack();$check(ownOriginalDatabaseHash($db,$tables)===$vsBefore,'current earlier-snapshot balance read preserves all accepted originals');
$viAdmin->reverse($viEvents[0],$vsMove,'Own deliberate current original return',$key());
// No additional browser mutation: these two accepted movements are part of its protected originals.

final class OwnVoucherMetadataGuard extends PDOStatement
{
 protected function __construct(){}
 public function execute(?array $params=null):bool{
  if(preg_match('/\bSELECT\s+(?:[a-z]+\.)?\*\s+FROM\s+appointments\b/i',$this->queryString)||preg_match('/\bSELECT\s+(?:i\.\*,r\.payload_json|[^;]*\b(?:bytes|file_bytes)\b)/i',$this->queryString))throw new RuntimeException('Voucher metadata attempted to hydrate an original body.');
  return parent::execute($params);
 }
}
$vsBefore=ownOriginalDatabaseHash($db,$tables);$vsOldStatements=$db->getAttribute(PDO::ATTR_STATEMENT_CLASS);$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnVoucherMetadataGuard::class,[]]);
try{$vsMetadata=$vsReader->eventsPage([],true);$check($vsMetadata['total']===30&&count($vsMetadata['rows'])===25,'actual voucher query guard permits current native metadata while forbidding complete appointment bodies original files and full public revision reads');}
finally{$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,$vsOldStatements);}
$check(ownOriginalDatabaseHash($db,$tables)===$vsBefore,'guarded native voucher metadata keeps all complete originals exact');

$viRegistry->saveAppointment($viSources[2],1,['title'=>'Own deliberately cancelled original source 0','start'=>'2040-01-03T18:00','end'=>'2040-01-03T20:00','location'=>'Own cancelled venue 0','description'=>'Own original cancellation','category'=>'verein','activity_state'=>'cancelled'],[]);
$vsBefore=ownOriginalDatabaseHash($db,$tables);$vsCancelled=$viReader->cardsPage($viEvents[2],[],true);$check(!$vsCancelled['available']&&$vsCancelled['total']===2&&$vsCancelled['event']['title']==='Own deliberately cancelled original source 0','actual cancelled canonical source remains accurately readable without an active QR promise');$deny(fn()=>$viReader->printSnapshot($viEvents[2],$viPerson,true),'cancelled current source cannot print active original pieces');$check(ownOriginalDatabaseHash($db,$tables)===$vsBefore,'cancelled canonical source reads preserve all complete accepted originals');

$vmBefore=ownOriginalDatabaseHash($db,$tables);$vmReader=\Kulturhus\Verein\Database::connect($viDsn,$viConfig['username'],$viConfig['password']);$vmBlocker=\Kulturhus\Verein\Database::connect($viDsn,$viConfig['username'],$viConfig['password']);$vmBlocker->beginTransaction();
try{
 $vmStarted=microtime(true);$vmPrepared=\Kulturhus\Verein\EventVoucherViewState::prepare(new EventVouchers($vmReader,'own-list-reader'),['voucher_scope'=>'own','voucher_event'=>$viEvents[0]]);$vmDuration=microtime(true)-$vmStarted;
 $vmLock='verein-write-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,48);$vmOwner=(int)Db::run($vmReader,'SELECT IS_USED_LOCK(?)',[$vmLock])->fetchColumn();
 $check($vmPrepared['http_status']===0&&$vmPrepared['cards']['total']===31&&$vmDuration<2&&!$vmReader->inTransaction(),'actual managed-connection personal read remains promptly available behind an unrelated writer advisory gate');
 $check($vmBlocker->inTransaction()&&$vmOwner===(int)$vmBlocker->query('SELECT CONNECTION_ID()')->fetchColumn(),'actual managed personal read neither releases another connection writer gate nor owns its transaction');
}finally{$vmBlocker->rollBack();}
$check(ownOriginalDatabaseHash($db,$tables)===$vmBefore,'actual managed-connection current read preserves every complete earlier original and business row');
