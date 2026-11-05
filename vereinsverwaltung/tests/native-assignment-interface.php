<?php declare(strict_types=1);
require __DIR__.'/native-document-interface.php';
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Settings,Registry,Database,Finance,CatalogBilling,CatalogPricing};
use Kulturhus\Verein\Settings\{AssignmentInput,AssignmentRepository,AssignmentRequests,CatalogRequests,GeneralRequests};

Database::ensureContributionRates($db);
$as=new Settings($db,'own-list-admin');$ar=new Registry($db,'own-list-admin');$ap=new AssignmentRepository($db,'own-list-admin');$aq=new AssignmentRequests($db,'own-list-admin');$at=new CatalogRequests($db,'own-list-admin');$ag=new GeneralRequests($db,'own-list-admin');$af=new Finance($db,'own-list-admin');
$agState=$as->section('club');$agInput=['version'=>(string)$agState['version'],'data'=>array_replace($agState['data'],['name'=>'Own unchanged assignment club'])];$agReview=$ag->prepare('club',$agInput);$agOriginal=$ag->accept('club',$agInput,bin2hex(random_bytes(16)),$agReview['hash'],true);
$oldPerson=$ar->saveContact(null,0,'person','Own earlier assigned person','old-assignment@example.invalid','Own original address',['birthday'=>'2000-01-01']);$newPerson=$ar->saveContact(null,0,'person','Own new assignment contact 0','new-assignment@example.invalid','Own original address',['birthday'=>'2000-01-01']);
$catalogData=array_replace(Settings::catalogDefaults('service'),['name'=>str_repeat('ä',160),'amount'=>'29.00','interval'=>'month','description'=>str_repeat('🙂',2000)]);$catalogInput=['id'=>'','kind'=>'service','version'=>'0','data'=>$catalogData];$catalogReview=$at->prepare($catalogInput);$catalogOriginal=$at->accept($catalogInput,bin2hex(random_bytes(16)),$catalogReview['hash'],true);$catalogId=$catalogOriginal['result']['id'];
$oldAssignment=$as->assign($catalogId,$oldPerson,'2020-01-01','');$oldRevision=$ap->revision($oldAssignment,'services','1');$oldQuote=(new CatalogBilling($db,'own-list-admin'))->review([$oldAssignment],'2026-10-20')[0];$oldEntries=$af->createCatalogCharges([['assignment_id'=>$oldAssignment,'fingerprint'=>$oldQuote['fingerprint']]],'2026-10-20','2026-11-20','full',null,bin2hex(random_bytes(16)));$oldEntry=$af->entry($oldEntries[0]);$oldPositions=$af->positions('entry',$oldEntries[0]);
$assignmentOriginalRows=[];
foreach($tables as$table){
 $columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);
 if(!$columns)throw new RuntimeException('Original row proof requires the actual declared primary key.');
 $values=$db->query('SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM);
 $assignmentOriginalRows[$table]=compact('columns','values');
}
$assignmentOldFilter=static function(string $table)use($assignmentOriginalRows,$catalogId):array{
 $key=$assignmentOriginalRows[$table];if(!$key['values'])return[' WHERE 0=1',[]];
 $args=[];foreach($key['values']as$row)array_push($args,...$row);
 $where=' WHERE (`'.implode('`,`',$key['columns']).'`) IN ('.implode(',',array_fill(0,count($key['values']),'('.implode(',',array_fill(0,count($key['columns']),'?')).')')).')';
 if($table==='settings_catalog'){$where.=' AND NOT(club_id=? AND id=?)';array_push($args,'own-list-club',$catalogId);}
 return[$where,$args];
};
$assignmentOldHash=ownOriginalDatabaseHash($db,$tables,$assignmentOldFilter);
$check($aq->history('services')['total']===0&&$ap->revision($oldAssignment,'services','1')===$oldRevision,'actual earlier assignment revision remains readable without inventing an earlier preview or personal acceptance');
$input=['operation'=>'create','tab'=>'services','id'=>'','version'=>'0','data'=>['catalog_id'=>$catalogId,'contact_id'=>$newPerson,'start'=>'2026-10-20','end'=>'']];$before=ownOriginalDatabaseHash($db,$tables);$review=$aq->prepare($input);
$check($review['input']===$input&&$review['normalized']===$input['data']&&$review['source']['catalog']['data']['name']===str_repeat('ä',160)&&$review['source']['contact']['name']==='Own new assignment contact 0'&&ownOriginalDatabaseHash($db,$tables)===$before,'assignment review retains the exact chosen current tariff recipient and complete typed dates without a row write or financial charge');
$firstKey=bin2hex(random_bytes(16));$oldAudit=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM audit_events')->fetchColumn();$first=$aq->accept($input,$firstKey,$review['hash'],true);$id=$first['result']['id'];$firstRaw=$ap->revision($id,'services','1');
$filter=static function(string $table)use($id,$firstKey,$oldAudit):array{return match($table){'catalog_assignments'=>[' WHERE NOT(club_id=? AND id=?)',['own-list-club',$id]],'settings_revisions'=>[' WHERE NOT(club_id=? AND section=?)',['own-list-club','assignment:'.$id]],'native_settings_requests'=>[' WHERE NOT(club_id=? AND request_key=?)',['own-list-club',$firstKey]],'audit_events'=>[' WHERE sequence<=?',[$oldAudit]],default=>['',[]]};};
$audits=Db::run($db,'SELECT * FROM audit_events WHERE sequence>?',[$oldAudit])->fetchAll();
$check(preg_match('/\A[a-f0-9]{32}\z/D',$id)===1&&$first['result']['version']===1&&$first['result']['data']===$input['data']&&$first['result']['source']===$review['source']&&$aq->receipt($firstKey)===$first&&$first['sha256']===hash('sha256',$first['original_json']),'first accepted assignment binds its actual generated identity whole original input reviewed sources first private JSON bytes and timestamp');
$check(ownOriginalDatabaseHash($db,$tables,$filter)===$before&&count($audits)===1&&$audits[0]['object_id']==='assignment:'.$id,'first accepted assignment preserves every earlier whole business row and appends only its own assignment actual revision audit and first original');
$before=ownOriginalDatabaseHash($db,$tables);$check($aq->accept($input,$firstKey,$review['hash'],true)===$first&&ownOriginalDatabaseHash($db,$tables)===$before,'same consciously accepted assignment key returns its first exact original without a second assignment revision audit or financial write');
$reject=static function(callable $operation,int $status,string $label)use($db,$tables,$check):void{$before=ownOriginalDatabaseHash($db,$tables);$denied=false;try{$operation();}catch(DomainException $failure){$denied=$failure->getCode()===$status;}$check($denied&&ownOriginalDatabaseHash($db,$tables)===$before,$label);};
foreach([['operation'=>'unknown'],['version'=>'01'],['id'=>$id],['tab'=>'unknown']]as$bad)$reject(fn()=>$aq->prepare(array_replace($input,$bad)),422,'whole invalid assignment operations identities versions and areas cannot be replaced by another intention');
foreach([['start'=>'2026-02-30'],['start'=>'2026-10-20x'],['end'=>'2020-01-01'],['catalog_id'=>$catalogId.'x'],['contact_id'=>str_repeat('x',65)]]as$bad){$invalid=$input;$invalid['data']=array_replace($invalid['data'],$bad);$reject(fn()=>$aq->prepare($invalid),422,'complete invalid assignment dates and source identifiers fail without shortening casting or a substitute source');}
$reject(fn()=>$aq->accept($input,bin2hex(random_bytes(16)),$review['hash'],false),422,'assignment creation requires its separate deliberate approval');
$overlap=$aq->prepare($input);$check(AssignmentRequests::issues($overlap)[0]['code']===409,'current already assigned period is explicitly visible in the new review');
$reject(fn()=>$aq->accept($input,bin2hex(random_bytes(16)),$overlap['hash'],true),409,'an explicitly reviewed overlapping assignment still cannot be accepted twice');
$reject(fn()=>$aq->receipt($catalogOriginal['key']),404,'the assignment original decoder cannot substitute an earlier tariff original');
$reject(fn()=>$at->receipt($firstKey),404,'the existing tariff decoder cannot substitute an assignment original');
$reject(fn()=>$ag->receipt('club',$firstKey),404,'the existing general settings decoder cannot substitute an assignment original');
$reject(fn()=>(new AssignmentRequests($db,'own-list-foreign'))->receipt($firstKey),404,'another club cannot read a known private assignment original');
$end=['operation'=>'end','tab'=>'services','id'=>$id,'version'=>'1','data'=>['end'=>'2026-12-31']];$endReview=$aq->prepare($end);$endKey=bin2hex(random_bytes(16));$ended=$aq->accept($end,$endKey,$endReview['hash'],true);
$check($ended['result']['version']===2&&$ended['result']['data']===array_replace($input['data'],['end'=>'2026-12-31'])&&$aq->receipt($firstKey)===$first&&$ap->revision($id,'services','1')===$firstRaw&&$firstRaw['data']===['catalog'=>$catalogId,'contact'=>$newPerson,'start'=>'2026-10-20','end'=>''],'explicit end has its own first original and later actual version while the first created assignment original and real raw first revision remain exact');
$before=ownOriginalDatabaseHash($db,$tables);$check($aq->accept($end,$endKey,$endReview['hash'],true)===$ended&&ownOriginalDatabaseHash($db,$tables)===$before,'an accepted ending retry reads its first original even after the target is already ended without another write');
$reject(fn()=>$aq->accept($end,bin2hex(random_bytes(16)),$endReview['hash'],true),409,'an older unaccepted ending key cannot overwrite the later consciously accepted end');
$check($af->entry($oldEntries[0])===$oldEntry&&$af->positions('entry',$oldEntries[0])===$oldPositions&&$ap->revision($oldAssignment,'services','1')===$oldRevision&&$at->receipt($catalogOriginal['key'])===$catalogOriginal&&$ag->receipt('club',$agOriginal['key'])===$agOriginal,'all assignment creates endings retries and invalid reviews preserve the earlier whole twenty-nine euro financial original its positions old assignment tariff original and general original');
require __DIR__.'/support/assignment-original-failures.php';
require __DIR__.'/support/assignment-original-processes.php';
if(getenv('DVM_ASSIGNMENT_BROWSER')==='1')require __DIR__.'/support/assignment-browser-fixture.php';
echo json_encode(['suite'=>'native-assignment-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
