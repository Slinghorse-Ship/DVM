<?php declare(strict_types=1);
use Kulturhus\Verein\{BalanceFilingCommands,BalanceFilingActions};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Web\{BalanceFilingForm,BalanceFilingMarkup};
$policy=new OwnBalanceEnvironmentRuntime();
$command=new BalanceFilingCommands($db,'env-board',$policy);
$allTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$prove=static function(bool $ok,string $label)use($check):void{$check($ok,'BALANCE LOCAL '.$label);};
$refuse=static function(callable $fn,string $label,?int $status=null)use($db,$allTables,$prove):void{
 $before=ownOriginalDatabaseHash($db,$allTables);$caught=false;
 try{$fn();}catch(DomainException $e){$caught=$status===null||$e->getCode()===$status;}
 $prove($caught&&ownOriginalDatabaseHash($db,$allTables)===$before,$label.' refuses with all whole originals exact');
};
$create=static fn():array=>['action'=>'tax-filing-balance-create','context'=>$command->workspace(2025)['context'],'values'=>['balance_scope'=>'separate_business_ledger','environment'=>'production']];
$input=$create();$key=bin2hex(random_bytes(16));
foreach(['balance_filing_commands','audit_events']as $failTable){
 $db->exec("CREATE TRIGGER own_balance_command_abort BEFORE INSERT ON ".$failTable." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own actual balance command SQL abort'");
 $before=ownOriginalDatabaseHash($db,$allTables);$caught=false;
 try{$command->run($input,$key);}catch(PDOException $e){$caught=(int)($e->errorInfo[1]??0)===1644;}
 finally{$db->exec('DROP TRIGGER own_balance_command_abort');}
 $prove($caught&&ownOriginalDatabaseHash($db,$allTables)===$before,'actual '.$failTable.' SQL abort rolls back local creation, command and audit together');
}
class OwnBalanceCommandLostCommit extends PDO{
 public bool $lose=true;
 public function commit():bool{$ok=parent::commit();if($this->lose){$this->lose=false;throw new PDOException('Own actual local balance COMMIT acknowledgement lost');}return $ok;}
}
$dsn='mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4';
$lostDb=new OwnBalanceCommandLostCommit($dsn,$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$lost=false;try{(new BalanceFilingCommands($lostDb,'env-board',$policy))->run($input,$key);}catch(PDOException $e){$lost=$e->getMessage()==='Own actual local balance COMMIT acknowledgement lost';if(!$lost)throw $e;}
$first=$command->receipt($key);$id=$first['result']['id'];
$prove($lost&&$first['result']['version']===1&&(int)$db->query('SELECT COUNT(*) FROM tax_filings')->fetchColumn()===1,'actual lost outer COMMIT leaves exactly one first local E-balance draft');
$prove($command->run($input,$key)===$first,'same original accepted create reads exact first without a second draft');
$wrong=$input;$wrong['values']['balance_scope']='complete_club_ledger';$refuse(fn()=>$command->run($wrong,$key),'same key with another original creation',409);
$rawProfile=['business_name'=>'0','supplier'=>'Own Unicode Übernahme <&> 🙂','tax_privileged'=>'yes','scope_confirmed'=>'1','asset_values_unchanged'=>'1','company'=>['tax_number'=>'5192050001276','name'=>'Own invented company','street'=>'Own invented road','house_number'=>'1','postal_code'=>'11111','city'=>'Own invented town','country'=>'DE','legal_status'=>'Other.KOER']];
$rawMapping=['cash'=>'bs.ass.currAss.cashEquiv.bank','expense'=>'is.netIncome.regular.operatingTC.otherCost.administration','equity'=>'bs.eqLiab.equity.retainedEarnings','revenue'=>'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1'];
$save=static fn(array $values):array=>['action'=>'tax-filing-balance-save','context'=>$command->workspace(2025,$id)['context'],'values'=>$values];
$saveInput=$save(['profile'=>$rawProfile,'mapping'=>$rawMapping,'values_reviewed'=>'1']);$saveKey=bin2hex(random_bytes(16));$saved=$command->run($saveInput,$saveKey);
$prove($saved['result']['version']===2&&$saved['result']['form_data']['balance_preparation']['profile']['business_name']==='0'&&$saved['input']===$saveInput,'whole original zero, Unicode, company, four real mappings and review save together');
$prove($command->run($input,$key)===$first&&$command->receipt($key)===$first,'first creation remains exact after a genuinely later save');
$refuse(fn()=>$command->run($saveInput,bin2hex(random_bytes(16))),'new stale save',409);
foreach(['certificate_pin','unrelated','confirmation']as $field){$bad=$save([]);$bad['values'][$field]='Own value';$refuse(fn()=>$command->run($bad,bin2hex(random_bytes(16))),'unrelated or secret local field '.$field,422);}
$generate=['action'=>'tax-filing-balance-generate','context'=>$command->workspace(2025,$id)['context'],'values'=>[]];$generated=$command->run($generate,bin2hex(random_bytes(16)));
$xml=$generated['result']['xml_data'];$xmlDom=new DOMDocument();
$prove(is_string($xml)&&$xmlDom->loadXML($xml,LIBXML_NONET)&&$generated['result']['xml_sha256']===hash('sha256',$xml)&&$generated['result']['state']==='draft'&&$generated['result']['validation']===[],'actual local Python creates parseable first XML without native validation or transport');
$oldGenerated=$generated;
$changed=$rawProfile;$changed['supplier']='Own genuinely later supplier';
$later=$command->run($save(['profile'=>$changed,'mapping'=>$rawMapping,'values_reviewed'=>'1']),bin2hex(random_bytes(16)));
$prove($later['result']['xml_data']===null&&$command->receipt($generated['key'])===$oldGenerated&&$command->receipt($generated['key'])['result']['xml_data']===$xml,'later save clears current XML but preserves every original earlier XML byte');
foreach(['UPDATE','DELETE']as $verb){
 $before=ownOriginalDatabaseHash($db,$allTables);$caught=false;
 try{$db->exec($verb==='UPDATE'?"UPDATE balance_filing_commands SET original_json='{}' WHERE request_key='".$key."'":"DELETE FROM balance_filing_commands WHERE request_key='".$key."'");}catch(PDOException){$caught=true;}
 $prove($caught&&ownOriginalDatabaseHash($db,$allTables)===$before,'actual immutable '.$verb.' trigger protects first results');
}
$callerInput=$create();$db->beginTransaction();
try{$db->exec("INSERT INTO own_environment_hash_probe(id,hidden_value) VALUES(2,'Own preceding caller transaction')");$refuse(fn()=>$command->run($callerInput,bin2hex(random_bytes(16))),'new command inside an existing caller transaction',409);$prove($db->inTransaction()&&(int)$db->query('SELECT COUNT(*) FROM own_environment_hash_probe WHERE id=2')->fetchColumn()===1,'preceding caller transaction remains open and exact');}
finally{$db->rollBack();}
$locker=new PDO($dsn,$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$lockedInput=$save(['profile'=>$rawProfile,'mapping'=>$rawMapping,'values_reviewed'=>'1']);$locker->beginTransaction();$locker->query("SELECT id FROM tax_filings WHERE club_id='own-balance-environment' AND id='".$id."' FOR UPDATE")->fetchColumn();
try{$refuse(fn()=>$command->run($lockedInput,bin2hex(random_bytes(16))),'actual independently held filing row lock',503);}
finally{$locker->rollBack();}
$before=$command->workspace(2025,$id);$stale=$save(['profile'=>$rawProfile,'mapping'=>$rawMapping,'values_reviewed'=>'1']);
Db::run($db,"UPDATE club_settings SET version=version+1 WHERE club_id=? AND section='club'",[$club]);
$refuse(fn()=>$command->run($stale,bin2hex(random_bytes(16))),'actual changed current club settings',409);
Db::run($db,"UPDATE club_settings SET version=version-1 WHERE club_id=? AND section='club'",[$club]);
$prove($command->workspace(2025,$id)===$before,'restored original settings reproduce the exact source context');
// A current reader may read original results, but cannot replay a write.
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('own-balance-environment','own-balance-current-reader','Own current reader','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('env-board','own-balance-environment','own-balance-current-reader',1);UPDATE actors SET is_superuser=0,role='member' WHERE id='env-board'");
$prove($command->receipt($key)===$first&&$command->history(2025)['count']===4,'current reader reads exact original and six-row first-result history');
$refuse(fn()=>$command->run($input,$key),'current reader cannot repeat a business instruction',403);
$db->exec("UPDATE actors SET is_superuser=1,role='treasurer' WHERE id='env-board';DELETE FROM settings_role_assignments WHERE actor_id='env-board' AND role_id='own-balance-current-reader';DELETE FROM settings_roles WHERE club_id='own-balance-environment' AND id='own-balance-current-reader'");
$foreign=new BalanceFilingCommands($db,'env-foreign',$policy);$prove($foreign->receipt($key)===null,'foreign club receives no other-club original result');
$refuse(fn()=>(new BalanceFilingCommands($db,'env-member',$policy))->receipt($key),'identity without financial read cannot inspect an original',403);
$history=$command->history(2025);$prove($history['count']===4&&count($history['rows'])===4&&$history['pages']===1,'local history has exact current count and independent stable keys');
$prove($policy->transmissions===0&&(int)$db->query('SELECT COUNT(*) FROM tax_filing_attempts')->fetchColumn()===0,'no local command opens a transport attempt or invokes transmission');
if(!function_exists('url')){function url(array $query):string{return '/vereinsverwaltung/?'.http_build_query($query);}}
// The existing markup, not a separate mock form, determines required complete controls.
$session=[];$query=['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>'2025','filing'=>$id];
$html='<form method="post" id="balance-local-main"><input name="csrf" type="hidden" value="own-csrf"><input name="filing" type="hidden" value="'.$id.'"><input name="version" type="hidden" value="'.$later['result']['version'].'"><input name="tax_year" type="hidden" value="2025"><label>Original zero<input name="profile[business_name]" value="0"></label><button name="action" value="tax-filing-balance-save">Save</button></form>';
$markup=BalanceFilingMarkup::protect($db,'env-board',$query,[],$html,$session);$dom=new DOMDocument();$dom->loadHTML($markup);$xp=new DOMXPath($dom);$protocol=[];
foreach($xp->query('//input[@type="hidden"]')as $node)$protocol[$node->getAttribute('name')]=$node->getAttribute('value');
$protocol+=['action'=>'tax-filing-balance-save','profile'=>['business_name'=>'0']];
$prove(count($session['balance_local_contexts'])===1&&$protocol[BalanceFilingForm::COMPLETE]==='1'&&str_contains($markup,'name="profile[business_name]"'),'actual rendered named controls receive a personal original source and final complete footer');
foreach(['csrf','balance_local_token','balance_local_key',BalanceFilingForm::COMPLETE]as $field){$bad=$protocol;$bad[$field]=['0','Übernahme'];$refuse(fn()=>BalanceFilingForm::handle($db,'env-board',$query,$bad,'own-csrf',$session),'raw scalar-array '.$field);}
$bad=$protocol;unset($bad['profile']);$refuse(fn()=>BalanceFilingForm::handle($db,'env-board',$query,$bad,'own-csrf',$session),'missing actually rendered field even with a footer',422);
$bad=$protocol;$bad['profile']['business_name']=['0','Übernahme'];$refuse(fn()=>BalanceFilingForm::handle($db,'env-board',$query,$bad,'own-csrf',$session),'array replacing actually rendered scalar',422);
$bad=$protocol;$bad['extra']='Own arbitrary value';$refuse(fn()=>BalanceFilingForm::handle($db,'env-board',$query,$bad,'own-csrf',$session),'unrendered field injection',422);
$badRoute=$query;$badRoute['tax_year']='2026';$refuse(fn()=>BalanceFilingForm::handle($db,'env-board',$badRoute,$protocol,'own-csrf',$session),'same original key under another year route',409);
$prove(str_contains(json_encode(BalanceFilingForm::original(['profile'=>['business_name'=>['0','Übernahme']],'certificate_pin'=>'own-never-retained']),JSON_UNESCAPED_UNICODE),'Übernahme')&&!str_contains(json_encode(BalanceFilingForm::original(['certificate_pin'=>'own-never-retained'])),'own-never-retained'),'rejected raw zero, Unicode and arrays remain exact while certificate secrets are excluded');
require __DIR__.'/balance-filing-command-more.php';
require __DIR__.'/balance-filing-period-checks.php';
$writeConfig($local);require __DIR__.'/balance-filing-specialist-checks.php';runOwnedBalanceSpecialistChecks($db,$id,$prove);
require __DIR__.'/balance-filing-reserve-checks.php';
require __DIR__.'/balance-filing-equalization-checks.php';
require __DIR__.'/balance-filing-special7g-checks.php';
require __DIR__.'/balance-filing-basis7g-checks.php';
require __DIR__.'/balance-filing-basis6b-checks.php';
require __DIR__.'/balance-filing-special7b-checks.php';
require __DIR__.'/balance-filing-pension-checks.php';
require __DIR__.'/balance-filing-own-checks.php';
require __DIR__.'/balance-filing-pool-checks.php';
echo json_encode(['suite'=>'native-balance-filing-interface','checks'=>$checks,'actual_commit_loss'=>true,'actual_python_xml'=>true,'all_refusals_whole_originals_exact'=>true,'local_commands'=>(int)$db->query('SELECT COUNT(*) FROM balance_filing_commands')->fetchColumn(),'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
