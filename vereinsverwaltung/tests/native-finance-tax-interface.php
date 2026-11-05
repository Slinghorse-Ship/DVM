<?php declare(strict_types=1);
require __DIR__.'/native-document-interface.php';
use Kulturhus\Verein\{Settings,SettingsSchema,Documents};
use Kulturhus\Verein\Settings\{GeneralRequests,FinanceTaxInput,FinanceTaxContext};
use Kulturhus\Native\Shared\Db;

$txSection='tax';$txSettings=new Settings($db,'own-list-admin');$txRequests=new GeneralRequests($db,'own-list-admin');
$txFinance=new \Kulturhus\Verein\Finance($db,'own-list-admin');$txDocuments=new Documents($db,'own-list-admin');$txNotices=[];
$txFolder=$txDocuments->saveFolder('',0,'Own tax notice originals','','finance');
for($i=0;$i<8;$i++)$txNotices[]=$txDocuments->upload('own-tax-notice-'.$i.'.pdf',$pdf('tax-'.$i),['title'=>$i===0?str_repeat('ä',75):'Own tax notice '.$i,'kind'=>'other','folder'=>$txFolder,'scope'=>'finance','document_date'=>'2026-10-21','notes'=>'Own complete original notice note','links'=>[]],$key());
$txSelected=$txNotices[0];$txDocumentBefore=Db::run($db,"SELECT * FROM documents WHERE club_id='own-list-club' AND id=?",[$txSelected])->fetch();$txPdfBefore=$txDocuments->file($txSelected,1);
$txEntry=$txFinance->createEntry(['kind'=>'income','category'=>'general','description'=>'Own earlier whole tax financial original','amount'=>'17.00','document_date'=>'2026-10-21','due_date'=>'2026-11-21','positions'=>[['description'=>'Own earlier whole tax position','amount'=>'17.00','tax_rate'=>'0','sphere'=>'3','account'=>'40000']]],$key());
$txEntryBefore=$txFinance->entry($txEntry);$txPositionsBefore=$txFinance->positions('entry',$txEntry);
$txSettings->save($txSection,SettingsSchema::defaults($txSection),0);$txRawRevision=$txRequests->revision($txSection,'1');
$check($txRequests->history($txSection)['total']===0&&$txRawRevision['version']===1,'genuine earlier raw tax settings have no invented complete notice review or approval');
$txPrimary=[];foreach($db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_NAME='PRIMARY' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll()as$row)$txPrimary[$row['TABLE_NAME']][]=$row['COLUMN_NAME'];
$txOldKeys=[];foreach($tables as$table){if(!($txPrimary[$table]??[]))throw new RuntimeException('Actual tax original primary keys required.');$txOldKeys[$table]=$db->query('SELECT '.implode(',',$txPrimary[$table]).' FROM '.$table)->fetchAll(PDO::FETCH_NUM);}
$txOldFilter=static function(string $table)use($txPrimary,$txOldKeys,$txSelected):array{
 if(!$txOldKeys[$table])return [' WHERE 0=1',[]];$columns=$txPrimary[$table];$values=[];foreach($txOldKeys[$table]as$row)array_push($values,...$row);
 $where=' WHERE ('.implode(',',$columns).') IN ('.implode(',',array_fill(0,count($txOldKeys[$table]),'('.implode(',',array_fill(0,count($columns),'?')).')')).')';
 if($table==='club_settings'){$where.=' AND NOT(club_id=? AND section=?)';array_push($values,'own-list-club','tax');}
 if($table==='documents'){$where.=' AND NOT(club_id=? AND id=?)';array_push($values,'own-list-club',$txSelected);}
 return [$where,$values];
};
$txOldHash=ownOriginalDatabaseHash($db,$tables,$txOldFilter);
$txDeny=static function(callable $operation,int $code,string $label)use($db,$tables,$check):void{
 $before=ownOriginalDatabaseHash($db,$tables);$closed=false;try{$operation();}catch(DomainException $failure){$closed=$failure->getCode()===$code;}
 $check($closed&&ownOriginalDatabaseHash($db,$tables)===$before,$label);
};
$txInput=FinanceTaxInput::initial($txSettings->section($txSection));
$txInput['data']=array_replace($txInput['data'],['notice_document_id'=>$txSelected,'office'=>str_repeat('ö',180),'purpose'=>str_repeat('🙂',2000),'vat_id'=>'0','small_business'=>'0','input_tax_eligibility'=>'no','input_tax_default'=>'unknown']);
$before=ownOriginalDatabaseHash($db,$tables);$txPlan=$txRequests->prepare($txSection,$txInput);
$check(count($txPlan['input']['data'])===23&&$txPlan['input']===$txInput&&$txPlan['normalized']['office']===$txInput['data']['office']&&$txPlan['normalized']['purpose']===$txInput['data']['purpose']&&$txPlan['normalized']['vat_id']==='0'&&$txPlan['normalized']['small_business']===false&&$txPlan['normalized']['input_tax_default']==='none'&&$txPlan['tax_context']['document']['id']===$txSelected&&$txPlan['tax_context']['file']['sha256']===$txPdfBefore['sha256']&&ownOriginalDatabaseHash($db,$tables)===$before,'whole twenty-three-field tax preview preserves Unicode raw zero and separate statutory-default normalization with current original PDF metadata and no write');
$txInvalid=$txInput;unset($txInvalid['data']['advisor']);$txDeny(fn()=>$txRequests->prepare($txSection,$txInvalid),422,'missing native tax fields cannot become optional canonical defaults');
foreach([['office',str_repeat('🙂',181)],['purpose',str_repeat('🙂',2001)],['fiscal_start','0'],['assessment_date','2026-02-30'],['small_business','2'],['accounting_basis','0'],['input_tax_default','0']]as[$field,$value]){
 $bad=$txInput;$bad['data'][$field]=$value;$check(FinanceTaxInput::capture($bad)===$bad,'whole invalid requested tax field stays literal before validation: '.$field);
 $txDeny(fn()=>$txRequests->prepare($txSection,$bad),422,'invalid whole requested tax field is not clipped rounded or substituted: '.$field);
}
$txPrivate=$txInput;$txPrivate['data']['office']=str_repeat('🙂',181);$txPrivate['data']['fiscal_start']='0';$before=ownOriginalDatabaseHash($db,$tables);
$txChosen=FinanceTaxContext::select($db,'own-list-admin',$txPrivate,'choose:'.$txNotices[1]);
$check($txChosen['data']['notice_document_id']===$txNotices[1]&&$txChosen['data']['office']===$txPrivate['data']['office']&&$txChosen['data']['fiscal_start']==='0'&&ownOriginalDatabaseHash($db,$tables)===$before,'private notice choice retains every complete invalid other tax field and changes no business row');
$txCleared=FinanceTaxContext::select($db,'own-list-admin',$txChosen,'clear');
$check($txCleared['data']['notice_document_id']===''&&$txCleared['data']['office']===$txPrivate['data']['office']&&$txCleared['data']['fiscal_start']==='0','private notice clearing changes only the consciously requested link');
$db->beginTransaction();try{
 $page=FinanceTaxContext::page($db,'own-list-admin','Own tax','1');$check(count($page['rows'])===6&&$page['total']===8&&$page['pages']===2,'actual readable PDF tax notices use six-row pages without loading original PDF bytes');
 $txDeny(fn()=>FinanceTaxContext::page($db,'own-list-admin','','999999'),422,'whole unavailable notice page chooses no replacement');
 $txDeny(fn()=>FinanceTaxContext::page($db,'own-list-admin',str_repeat('🙂',151),'1'),422,'whole overlong Unicode notice query cannot become a shortened search');
}finally{$db->rollBack();}
$txFirstKey=$key();$txDeny(fn()=>$txRequests->accept($txSection,$txInput,$txFirstKey,$txPlan['hash'],false),422,'tax settings review requires its separate deliberate approval');
$txDeny(fn()=>$txRequests->accept($txSection,$txInput,$txFirstKey,str_repeat('a',64),true),409,'incorrect original notice review cannot save or invent a first tax original');
$txFirst=$txRequests->accept($txSection,$txInput,$txFirstKey,$txPlan['hash'],true);$body=json_decode($txFirst['original_json'],true,64,JSON_THROW_ON_ERROR);
$check($txFirst['result']['version']===2&&$body['input']===$txInput&&$body['review']===$txPlan&&$body['result']===$txSettings->section($txSection)&&$txFirst['sha256']===hash('sha256',$txFirst['original_json'])&&$body['actor_id']==='own-list-admin'&&preg_match('/\.\d{6}Z$/D',$body['created_at'])===1,'first tax confirmation retains whole raw choices actual PDF revision and hash complete reviewed metadata canonical result actor microsecond time and exact JSON bytes');
$before=ownOriginalDatabaseHash($db,$tables);$check($txRequests->accept($txSection,$txInput,$txFirstKey,$txPlan['hash'],true)===$txFirst&&ownOriginalDatabaseHash($db,$tables)===$before,'accepted tax key reads its precise first original before current review without another save');
$txLaterInput=FinanceTaxInput::initial($txSettings->section($txSection));$txLaterInput['data']['office']='Own deliberately later tax office';$txLaterPlan=$txRequests->prepare($txSection,$txLaterInput);
$txDocuments->update($txSelected,1,['title'=>str_repeat('ü',75),'kind'=>'other','document_date'=>'2026-10-21','state'=>'active','notes'=>'Own complete original notice note','folder'=>$txFolder,'links'=>[],'reason'=>'Own deliberate notice metadata rename']);
$txAfterRename=Db::run($db,"SELECT * FROM documents WHERE club_id='own-list-club' AND id=?",[$txSelected])->fetch();
$check($txAfterRename===array_replace($txDocumentBefore,['title'=>str_repeat('ü',75),'version'=>2]),'real canonical notice rename changes only its current title and version while every other whole document field stays exact');
$txDeny(fn()=>$txRequests->accept($txSection,$txLaterInput,$key(),$txLaterPlan['hash'],true),409,'actual notice rename invalidates an unaccepted tax review without a tax version change');
$txLaterPlan=$txRequests->prepare($txSection,$txLaterInput);$txDocuments->newVersion($txSelected,2,'own-later-notice.pdf',$pdf('tax-deliberately-later'),'Own deliberate later PDF original');
$txDeny(fn()=>$txRequests->accept($txSection,$txLaterInput,$key(),$txLaterPlan['hash'],true),409,'actual later PDF revision and hash invalidate an earlier tax review without a tax version change');
$txLaterPlan=$txRequests->prepare($txSection,$txLaterInput);
$txConfig=\Kulturhus\Native\TestSupport::config();$txDsn='mysql:host=127.0.0.1;port=13371;dbname='.$txConfig['database'].';charset=utf8mb4';$txOptions=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$txCaller=new PDO($txDsn,$txConfig['username'],$txConfig['password'],$txOptions);$txCaller->beginTransaction();$txCaller->query('SELECT * FROM finance_entries')->fetchAll();
$txLater=$txRequests->accept($txSection,$txLaterInput,$key(),$txLaterPlan['hash'],true);
try{
 $callerSettings=new Settings($txCaller,'own-list-admin');$check($callerSettings->section($txSection)===$txLater['result']&&$txCaller->inTransaction(),'tax settings read actual latest saved state behind an unrelated old caller snapshot');
 $before=ownOriginalDatabaseHash($txCaller,$tables);$closed=false;try{$callerSettings->save($txSection,$txLaterInput['data'],(int)$txLaterInput['version']);}catch(DomainException $failure){$closed=$failure->getCode()===409;}
 $check($closed&&$txCaller->inTransaction()&&ownOriginalDatabaseHash($txCaller,$tables)===$before,'stale tax setting save returns a version conflict preserving every entire earlier caller row and transaction');
}finally{$txCaller->rollBack();}
$check($txRequests->receipt($txSection,$txFirstKey)===$txFirst&&$txRequests->revision($txSection,'1')===$txRawRevision&&$txDocuments->file($txSelected,1)===$txPdfBefore&&$txFinance->entry($txEntry)===$txEntryBefore&&$txFinance->positions('entry',$txEntry)===$txPositionsBefore,'later current PDF and tax defaults retain first raw tax revision first original exact earlier PDF bytes and complete seventeen-euro financial positions');
require __DIR__.'/support/finance-tax-source-current.php';
require __DIR__.'/support/finance-tax-original-failures.php';
$check(ownOriginalDatabaseHash($db,$tables,$txOldFilter)===$txOldHash,'all later tax approvals source changes rights and retries retain every earlier whole row except the separately verified consciously current tax setting and notice metadata');
require __DIR__.'/support/finance-tax-original-processes.php';
require __DIR__.'/support/finance-tax-issuer-current.php';
if(getenv('DVM_FINANCE_TAX_INTERFACE_BROWSER')==='1')require __DIR__.'/support/finance-tax-browser-fixture.php';
echo json_encode(['suite'=>'native-finance-tax-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
