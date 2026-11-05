<?php declare(strict_types=1);
use Kulturhus\Verein\{EventVouchers,EventVoucherViewState};
use Kulturhus\Native\Shared\Db;

$vrOldest=$db->query("SELECT id FROM event_voucher_moves WHERE voucher_id IN (SELECT id FROM event_vouchers WHERE event_id=".$db->quote($viEvents[0])." AND contact_id=".$db->quote($viPerson).") ORDER BY created_at,id LIMIT 1")->fetchColumn();
$vrEuro=array_values(array_filter($viReader->vouchers($viEvents[0]),static fn(array $row):bool=>$row['unit']==='eur'))[0];
for($vrIndex=0;$vrIndex<110;$vrIndex++){
 $vrVersion=(int)Db::run($db,'SELECT version FROM event_vouchers WHERE id=?',[$vrEuro['id']])->fetchColumn();
 $vrMove=$viAdmin->change($viEvents[0],$vrEuro['id'],$vrVersion,'redeem','0.01','',$key());
 $viAdmin->reverse($viEvents[0],$vrMove,'Own original reversal reason 0',$key());
}
$vrHistory=$viReader->historyPage($viEvents[0],[],true);$vrLast=$viReader->historyPage($viEvents[0],['page'=>$vrHistory['pages']],true);
$check($vrHistory['total']===222&&count($vrHistory['rows'])===25&&in_array($vrOldest,array_column($vrLast['rows'],'id'),true),'native original voucher history reaches the first actual grant beyond the former two-hundred-row cut');
$vrVersion=(int)Db::run($db,'SELECT version FROM event_vouchers WHERE id=?',[$vrEuro['id']])->fetchColumn();
$viAdmin->change($viEvents[0],$vrEuro['id'],$vrVersion,'redeem','3.50','',$key());
$vrRedeemed=$viReader->cardsPage($viEvents[0],['state'=>'redeemed'],true);
$check($vrRedeemed['total']===1&&$vrRedeemed['rows'][0]['unit']==='eur'&&$vrRedeemed['rows'][0]['state']==='active'&&$vrRedeemed['rows'][0]['remaining']===0,'spent euro credit is honestly included as redeemed without rewriting its original active ledger state or code');
$vrPiece=array_values(array_filter($viReader->vouchers($viEvents[0]),static fn(array $row):bool=>$row['unit']==='piece'))[0];
$viAdmin->change($viEvents[0],$vrPiece['id'],1,'redeem','1','',$key(),$vrPiece['tickets'][0]['id']);
$viAdmin->change($viEvents[0],$vrPiece['id'],2,'revoke','','Own original revocation reason 0',$key(),$vrPiece['tickets'][1]['id']);
$vrActual=$viReader->cardsPage($viEvents[0],[],true);
$check($vrActual['total']===31&&$viReader->cardsPage($viEvents[0],['state'=>'active'],true)['total']===28&&$viReader->cardsPage($viEvents[0],['state'=>'redeemed'],true)['total']===2&&$viReader->cardsPage($viEvents[0],['state'=>'revoked'],true)['total']===1,'current native filters distinguish actual active pieces redeemed pieces spent euro credit and revoked originals');
$vrPrint=$viReader->printSnapshot($viEvents[0],$viPerson,true);
$check(count($vrPrint['vouchers'])===28&&count(array_filter($vrPrint['vouchers'],static fn(array $row):bool=>$row['state']!=='active'||$row['remaining']!==1))===0,'actual print after redemption and revocation includes only the twenty-eight remaining original physical pieces');

$vrSource=$viRegistry->saveAppointment(null,0,['title'=>'Own original oversized native voucher event','start'=>'2040-05-01T18:00','end'=>'2040-05-01T20:00','location'=>'Own original large venue','description'=>'Own private large source','category'=>'verein'],[]);
$vrLargeEvent=$viAdmin->open($vrSource,$key());$viAdmin->grant($vrLargeEvent,[$viPerson],[['label'=>'Own exact original 201','kind'=>'other','unit'=>'piece','amount'=>'201']],$key());
final class OwnVoucherBudgetGuard extends PDOStatement
{
 protected function __construct(){}
 public function execute(?array $params=null):bool{
  if(str_contains($this->queryString,'FROM event_voucher_tickets'))throw new RuntimeException('Individual ticket loading occurred before the print budget refusal');
  return parent::execute($params);
 }
}
$vrBudgetDb=new PDO($viDsn,$viConfig['username'],$viConfig['password'],$viOptions);$vrBudgetDb->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnVoucherBudgetGuard::class,[]]);
$vrBefore=ownOriginalDatabaseHash($db,$tables);$vrTooLarge=false;
try{(new EventVouchers($vrBudgetDb,'own-list-reader'))->printSnapshot($vrLargeEvent,$viPerson,true);}catch(DomainException $failure){$vrTooLarge=$failure->getCode()===422;}
$check($vrTooLarge&&ownOriginalDatabaseHash($db,$tables)===$vrBefore,'actual two-hundred-one-card print is refused before selecting any individual ticket and changes no original row');
$vrLargeState=EventVoucherViewState::prepare($viReader,['voucher_scope'=>'own','voucher_event'=>$vrLargeEvent]);
$check($vrLargeState['cards']['total']===201&&!$vrLargeState['print']['available']&&$vrLargeState['print']['notice']!=='','native oversized personal collection stays fully paginated with an honest unavailable print explanation');

$viAdmin->configure($viEvents[1],1,false,[],$key());$vrClosed=$viReader->cardsPage($viEvents[1],[],true);
$check(!$vrClosed['available']&&$vrClosed['notice']!==''&&$vrClosed['event']['title']==='Own private voucher event 01 <em data-own-event>0</em> ä🙂','closed issue retains the complete canonical current source title and original cards with a distinct availability notice');
$deny(fn()=>$viReader->printSnapshot($viEvents[1],$viPerson,true),'closed issue cannot silently produce an active original print');

$vrBlocker=new PDO($viDsn,$viConfig['username'],$viConfig['password'],$viOptions);$vrBlocker->beginTransaction();Db::run($vrBlocker,'SELECT id FROM event_vouchers WHERE id=? FOR UPDATE',[$vrPiece['id']])->fetch();
$viCaller->beginTransaction();$vrStarted=microtime(true);$vrBusy=false;
try{(new EventVouchers($viCaller,'own-list-reader'))->cardsPage($viEvents[0],[],true);}catch(DomainException $failure){$vrBusy=$failure->getCode()===503;}
$check($vrBusy&&microtime(true)-$vrStarted<2&&$viCaller->inTransaction()&&(int)$viCaller->query('SELECT 1')->fetchColumn()===1,'actual competing voucher row lock refuses the private card read promptly and preserves a usable caller transaction');
$viCaller->rollBack();$vrBlocker->rollBack();

$vrBefore=ownOriginalDatabaseHash($db,$tables);$vrPrepared=EventVoucherViewState::prepare($viReader,['voucher_scope'=>'own','voucher_event'=>$viEvents[0],'voucher_cards_query'=>str_repeat('🙂',241)]);
$check($vrPrepared['http_status']===422&&$vrPrepared['cards']===null&&$vrPrepared['query']['voucher_cards_query']===str_repeat('🙂',241)&&$vrPrepared['history']['total']===225,'whole invalid native card search stays correctable while the independently authorized complete original history remains readable');
$check(ownOriginalDatabaseHash($db,$tables)===$vrBefore,'all original history oversized source state busy refusal and prepared metadata reads preserve every complete accepted business row');
