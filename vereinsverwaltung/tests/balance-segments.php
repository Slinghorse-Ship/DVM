<?php declare(strict_types=1);
ob_start();require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceSegments.php';require_once __DIR__.'/mysql-bootstrap.php';require __DIR__.'/fixtures/balance-segments.php';
use Kulturhus\Verein\{BalanceSegments,BalanceSegmentCalculation,Database,BalanceLedger,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Full Club'),('b','Synthetic Other Club'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('finance','a','treasurer',0),('member','a','member',0),('editor','a','editor',0),('other','b','treasurer',1)");
$settings=SettingsSchema::defaults('tax');$settings['accounting_basis']='balance';$q=$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)");$q->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');
[$fixture,$units,$alloc]=segmentFixture();$ledger=new BalanceLedger($db,'board');$ledger->create(2025);$ledger->setup(2025,1,$fixture['accounts'],$fixture['opening'],'equity','Synthetic opening evidence',true);
$version=2;foreach($fixture['entries'] as $e)$ledger->post(2025,$version++,$e['id'],$e['date'],$e['description'],'Synthetic original',$e['lines']);
$finance=new BalanceSegments($db,'finance');$board=new BalanceSegments($db,'board');
denied(fn()=>$finance->get(2025),'unclosed ledger cannot be allocated');
$ledger->close(2025,$version,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic complete review');
$report=$ledger->report(2025);$originalJournal=MySqlFixture::rows($db,'balance_years');$originalEntries=MySqlFixture::rows($db,'balance_entries');
$s=$finance->get(2025);check($s['version']===0&&!$s['allocation']['complete']&&!$s['current_approval'],'new allocation is visibly incomplete');
denied(fn()=>(new BalanceSegments($db,'member'))->get(2025),'member cannot read financial allocation');
denied(fn()=>(new BalanceSegments($db,'editor'))->get(2025),'website editor cannot read financial allocation');
denied(fn()=>(new BalanceSegments($db,'other'))->get(2025),'other club cannot read allocation');
$before=MySqlFixture::snapshot($db);$finance->get(2025);check(MySqlFixture::snapshot($db)===$before,'reading does not write data');
putenv('VEREIN_BALANCE_WORKSPACE=0');denied(fn()=>$finance->save(2025,0,$units,$alloc),'operator gate retained');putenv('VEREIN_BALANCE_WORKSPACE=1');
$euer=$settings;$euer['accounting_basis']='euer';$q=$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'");$q->execute([json_encode($euer)]);
denied(fn()=>$finance->save(2025,0,$units,$alloc),'EÜR cannot store allocation');$q->execute([json_encode($settings)]);
$missing=$alloc;unset($missing['opening:0']);$saved=$finance->save(2025,0,$units,$missing);
$firstIncomplete=$saved;$sameRows=MySqlFixture::snapshot($db);check($finance->save(2025,0,$units,$missing)===$firstIncomplete&&MySqlFixture::snapshot($db)===$sameRows,'same initial preparation returns its exact first allocation without another whole row');
check($saved['revision']===1&&!$saved['allocation']['complete'],'incomplete work can be retained without approval');
denied(fn()=>$board->confirm(2025,1,true,'Review'),'missing beginning balance prevents approval');
denied(fn()=>$finance->save(2025,0,$units,$alloc),'stale version cannot overwrite newer draft');
$invalid=$alloc;$invalid['opening:0']['parts']['food']++;$before=MySqlFixture::snapshot($db);
denied(fn()=>$finance->save(2025,1,$units,$invalid),'excess cents prevent persistence');check(MySqlFixture::snapshot($db)===$before,'failed save leaves history and audit unchanged');
$saved=$finance->save(2025,1,$units,$alloc);check($saved['allocation']['complete'],'all exact allocations persist');
denied(fn()=>$finance->confirm(2025,2,true,'Review'),'treasurer cannot grant board approval');
denied(fn()=>$board->confirm(2025,2,false,'Review'),'explicit board confirmation required');
denied(fn()=>$board->confirm(2025,2,true,''),'review note required');
$approved=$board->confirm(2025,2,true,'Synthetic complete evidence review');$s=$finance->get(2025);
check($s['version']===3&&$s['current_approval']&&!$s['tax_export_ready'],'board approved allocation is not itself an ELSTER release');
denied(fn()=>$board->confirm(2025,3,true,'Duplicate'),'duplicate approval blocked');
$db->exec("UPDATE actors SET is_superuser=0 WHERE id='board'");check(!$finance->get(2025)['current_approval'],'revoked board permission invalidates current approval');$db->exec("UPDATE actors SET is_superuser=1 WHERE id='board'");
$changed=$alloc;$changed['opening:0']['reason']='Synthetic revised evidence';$finance->save(2025,3,$units,$changed);
$s=$finance->get(2025);check(!$s['current_approval']&&$s['state']==='draft'&&count($s['history'])===4,'any edit retains old history and needs new approval');
$board->confirm(2025,4,true,'Synthetic updated review');
check(MySqlFixture::rows($db,'balance_years')===$originalJournal&&MySqlFixture::rows($db,'balance_entries')===$originalEntries,'original journal remains byte-for-byte unchanged');
foreach(['UPDATE balance_segment_reviews SET sha256=REPEAT(\'a\',64)','DELETE FROM balance_segment_reviews'] as $sql){$blocked=false;try{$db->exec($sql);}catch(PDOException $e){$blocked=$e->getCode()==='45000';}check($blocked,'native guard forbids changing history');}
check(count($finance->get(2025)['history'])===5,'history chain verifies all revisions');
$sameRows=MySqlFixture::snapshot($db);check($finance->save(2025,0,$units,$missing)===$firstIncomplete&&MySqlFixture::snapshot($db)===$sameRows,'first incomplete allocation remains exact after genuinely later approvals');
check($finance->original(2025,1,$firstIncomplete['sha256'])===$firstIncomplete,'first immutable allocation remains separately readable');
check($board->confirm(2025,2,true,'Synthetic complete evidence review')===$approved&&MySqlFixture::snapshot($db)===$sameRows,'same first board approval remains exact after genuinely later decisions');
denied(fn()=>$board->confirm(2025,2,false,'Synthetic complete evidence review'),'unchecked repeat cannot adopt earlier allocation approval');
denied(fn()=>$finance->save(2025,0,$units,$alloc),'different complete allocation cannot adopt earlier incomplete result');
if(getenv('VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP')!=='1'){
$restored=MySqlFixture::restoreBackup($db);check(MySqlFixture::rows($restored,'balance_segment_reviews')===MySqlFixture::rows($db,'balance_segment_reviews'),'encrypted restore preserves allocation history');
check((new BalanceSegments($restored,'finance'))->get(2025)['allocation']===$finance->get(2025)['allocation'],'restored allocation recomputes identically');
check(!MySqlFixture::foreignKeyErrors($restored),'restored year and club boundaries valid');
}
// Render the actual view without changing any browser/session belonging to a user.
function input(array $a,string $k,string $default=''):string{return is_string($a[$k]??null)?$a[$k]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic');}function url(array $v):string{return '?'.http_build_query($v);}
$render=static function(string $actor,array $get=[],array $post=[])use($db):string{
    $_GET=$get;$_POST=$post;$_SESSION=['actor'=>$actor];$year=2025;ob_start();require __DIR__.'/../views/Finance/Balance/balance-segments.php';return ob_get_clean();
};
$html=$render('board',['segment_voucher'=>str_repeat('a',32),'segment_line'=>str_repeat('a',32).':0']);
check(str_contains($html,'Aufteilung freigegeben')&&str_contains($html,'Gastronomie')&&str_contains($html,'Verkauf'),'UI shows both businesses and approved state');
check(!str_contains($html,'value="balance-segment-confirm"'),'approved UI cannot approve twice');
$recoveryPost=['action'=>'balance-segment-line','balance_year'=>'2025','version'=>'3','segment_voucher'=>str_repeat('a',32),'segment_line'=>str_repeat('a',32).':0','parts'=>['food'=>'1234,56','shop'=>'<invalid>'],'evidence'=>'Preserved <draft>'];$recoveryBefore=MySqlFixture::snapshot($db);$oldRole=$db->query("SELECT role FROM actors WHERE id='finance'")->fetchColumn();$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version)VALUES('a','recovery-read','Own temporary read','','none','read',0,1);INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version)VALUES('finance','a','recovery-read',1);UPDATE actors SET role='member'WHERE id='finance'");try{$recovered=$render('finance',[],$recoveryPost);preg_match('/<pre hidden data-original-finance-retained-fields>(.*?)<\/pre>/s',$recovered,$recoveryMatch);check(str_contains($recovered,'Preserved &lt;draft&gt;')&&!preg_match('/<form[^>]+method=[\"\']?post/i',$recovered)&&json_decode(html_entity_decode($recoveryMatch[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),true,512,JSON_THROW_ON_ERROR)===$recoveryPost,'actual original actor losing write rights retains complete allocation inputs without forms');}finally{$db->exec("DELETE FROM settings_role_assignments WHERE actor_id='finance'AND role_id='recovery-read';DELETE FROM settings_roles WHERE club_id='a'AND id='recovery-read'");$db->prepare("UPDATE actors SET role=? WHERE id='finance'")->execute([$oldRole]);}check(MySqlFixture::snapshot($db)===$recoveryBefore,'read-only allocation recovery and restored actor rights preserve all whole originals');
$html=$render('finance',[],['action'=>'balance-segment-line','balance_year'=>'2025','version'=>'3','segment_voucher'=>str_repeat('a',32),'segment_line'=>str_repeat('a',32).':0','parts'=>['food'=>'1234,56','shop'=>'<invalid>'],'evidence'=>'Preserved <draft>']);
check(str_contains($html,'value="1234,56"')&&str_contains($html,'value="&lt;invalid&gt;"')&&str_contains($html,'Preserved &lt;draft&gt;'),'failed form preserves and escapes posted input');
check(str_contains($html,'name="version" value="3"'),'stale form never silently takes newer version');
$renderTax=static function()use($db):string{
 $_GET=['view'=>'finance','tab'=>'tax','balance'=>'1','balance_year'=>'2025','segments'=>'1','segment_voucher'=>str_repeat('a',32),'segment_line'=>str_repeat('a',32).':0'];$_POST=['action'=>'balance-segment-line','balance_year'=>'2025','version'=>'3','segment_voucher'=>str_repeat('a',32),'segment_line'=>str_repeat('a',32).':0','parts'=>['food'=>'1.234','shop'=>''],'evidence'=>'Own original failed line'];$_SESSION=['actor'=>'finance'];$_SERVER['REQUEST_METHOD']='POST';$balanceLocalHandled=false;$balanceLocalRead=false;$balanceLocalError='';$year=2025;ob_start();require __DIR__.'/../views/Finance/Tax/tax-preparation.php';return ob_get_clean();
};
$parentHtml=$renderTax();check(str_contains($parentHtml,'data-balance-local')&&str_contains($parentHtml,'value="1.234"')&&str_contains($parentHtml,'Formularstand'),'actual embedded tax page retains original failed allocation form');
$finance->save(2025,5,$units,$changed);
$html=$render('finance');check(!str_contains($html,'value="balance-segment-confirm"'),'treasurer sees no board action');
$html=$render('board',['segment_voucher'=>str_repeat('a',32),'segment_line'=>str_repeat('a',32).':0']);
check(str_contains($html,'value="balance-segment-confirm"')&&str_contains($html,'synthetic'),'board sees explicit CSRF-protected confirmation');
file_put_contents(__DIR__.'/balance-segments-ui.html',$html);
$html=$render('member');check(!str_contains($html,'Gastronomie')&&!str_contains($html,'name="parts'),'member render does not expose allocation');
// Actual controller paths. They receive only synthetic POST data in this fixture.
class SegmentRedirect extends RuntimeException {public function __construct(public array $parameters){parent::__construct('Synthetic redirect');}}
function redirectTo(array $parameters):never{throw new SegmentRedirect($parameters);}
$act=static function(string $action,array $post,string $actor='finance')use($db):array{
    $_POST=$post+['balance_year'=>'2025','segment_journal_sha256'=>(new BalanceSegments($db,$actor))->forAction(2025,(int)input($post,'version'))['journal']['closed_sha256']];$_GET=[];$_SESSION=['actor'=>$actor];$year=2025;$version=(int)input($post,'version');
    try{require __DIR__.'/../src/Finance/Balance/BalanceSegmentActions.php';}catch(SegmentRedirect $r){return $r->parameters;}
    throw new RuntimeException('No redirect');
};
$next=$act('balance-segment-line',['version'=>'6','segment_line'=>str_repeat('a',32).':0','parts'=>['food'=>'3,34','shop'=>'6,69'],'evidence'=>'Controller split']);
check($next['segments']==='1'&&$finance->get(2025)['version']===7,'controller preserves navigation and exact amount split');
$before=MySqlFixture::snapshot($db);denied(fn()=>$act('balance-segment-line',['version'=>'7','segment_line'=>str_repeat('a',32).':0','parts'=>['food'=>'1.000,00'],'evidence'=>'Bad']),'controller rejects thousands input');check(MySqlFixture::snapshot($db)===$before,'invalid controller input has no writes');
denied(fn()=>$act('balance-segment-remove',['version'=>'7','unit_id'=>'food']),'used unit cannot be removed');
denied(fn()=>$act('balance-segment-confirm',['version'=>'7','reviewed'=>'1','evidence'=>'Unauthorized']),'controller repeats board authorization');
$act('balance-segment-unit',['version'=>'7','unit_id'=>'food','unit_name'=>'Gastronomie <img src=x onerror=alert(1)>','unit_sphere'=>'4']);
$html=$render('board');check(str_contains($html,'&lt;img')&&!str_contains($html,'<img src=x'),'untrusted business names escaped');
$act('balance-segment-confirm',['version'=>'8','reviewed'=>'1','evidence'=>'Synthetic reviewed split'],'board');check($finance->get(2025)['current_approval'],'controller confirmation persists');
$bundle=(new \Kulturhus\Verein\TaxPreparation($db,'finance'))->build(2025,2025,'Synthetic activities');
check(isset($bundle['files']['2025/bereiche/kontennachweis.csv'])&&$bundle['snapshot']['reports'][2025]['balance_segments']['current_approval'],'actual tax working-paper bundle includes reviewed allocation');
check($bundle['snapshot']['file_hashes']['2025/bereiche/datenstand.json']===hash('sha256',$bundle['files']['2025/bereiche/datenstand.json']),'allocation bound to immutable package digest');
$bundle=(new \Kulturhus\Verein\TaxPreparation($db,'other'))->build(2025,2025,'Synthetic EÜR');
check(!isset($bundle['files']['2025/bereiche/datenstand.json']),'EÜR working papers unchanged');
$removeHtml=$render('finance',[],['action'=>'balance-segment-remove','balance_year'=>'2025','version'=>'9','unit_id'=>'shop']);$removeDom=new DOMDocument();$quiet=libxml_use_internal_errors(true);try{$removeDom->loadHTML($removeHtml);}finally{libxml_clear_errors();libxml_use_internal_errors($quiet);}$removeXp=new DOMXPath($removeDom);
check($removeXp->query('//form[@method="post"]')->length===1&&$removeXp->query('//select[@name="unit_id"]/option[@value="shop"and@selected]')->length===1,'failed original removal retains its own chosen area and no competing business form');
// Stable original new-area intent must survive later changes and redirect to the first receipt.
$createFields=['version'=>'9','unit_id'=>'','unit_request_key'=>str_repeat('b',32),'unit_name'=>'Own original retry area <&>','unit_sphere'=>'4'];
$createdRoute=$act('balance-segment-unit',$createFields);$created=$finance->get(2025);$newIds=array_diff(array_keys($created['allocation']['units']),array_keys($units));
check(count($newIds)===1&&$created['version']===10,'actual new-area action creates exactly one stable original unit');$newId=array_values($newIds)[0];
check($createdRoute['segment_original']==='10'&&$createdRoute['segment_original_sha256']===$created['history'][9]['sha256'],'new-area redirect binds exact first original revision and digest');
$act('balance-segment-unit',['version'=>'10','unit_id'=>$newId,'unit_name'=>'Own later area name','unit_sphere'=>'4']);$stable=MySqlFixture::snapshot($db);
check($act('balance-segment-unit',$createFields)===$createdRoute&&MySqlFixture::snapshot($db)===$stable,'actual new-area repeat after later rename returns first redirect without duplicating an area');
$bad=$createFields;$bad['unit_name']='A changed original request';denied(fn()=>$act('balance-segment-unit',$bad),'changed original new-area name cannot adopt accepted first outcome');
$bad=$createFields;unset($bad['unit_request_key']);denied(fn()=>$act('balance-segment-unit',$bad),'new area without original retry key refused');
foreach(['balance_year'=>['2025e0','2024',[]],'version'=>['09','9e0',[]],'segment_journal_sha256'=>[str_repeat('a',64),'',[]]]as$field=>$values)foreach($values as$value){$bad=$createFields;$bad[$field]=$value;denied(fn()=>$act('balance-segment-unit',$bad),'original scalar year revision and journal metadata cannot be replaced or ambiguously cast');}
check(MySqlFixture::snapshot($db)===$stable,'every rejected original metadata variant leaves all persisted rows unchanged');
$html=$render('board',['segment_original'=>'10','segment_original_sha256'=>$createdRoute['segment_original_sha256']]);
check(str_contains($html,'Own original retry area &lt;&amp;&gt;')&&!str_contains($html,'<form')&&!str_contains($html,'name="action"'),'pure original receipt retains original literal area and offers no business form after later rename');
// Two closed financial years with an explicitly reviewed per-business carry.
$db->exec("INSERT INTO clubs VALUES('carry','Synthetic Carry Club'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('carry-board','carry','treasurer',1)");
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('carry','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
$carryLedger=new BalanceLedger($db,'carry-board');$carryLedger->create(2024);$carryLedger->setup(2024,1,$fixture['accounts'],$fixture['opening'],'equity','Synthetic opening',true);$carryLedger->close(2024,2,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic review');
$carryService=new BalanceSegments($db,'carry-board');$openingAlloc=array_filter($alloc,static fn($k)=>str_starts_with($k,'opening:'),ARRAY_FILTER_USE_KEY);
$carryService->save(2024,0,$units,$openingAlloc);$carryService->confirm(2024,1,true,'Synthetic allocations');$carryLedger->create(2025);$carryLedger->close(2025,1,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic unchanged balances');
denied(fn()=>$carryService->carry(2025,0,false),'carry requires explicit confirmation');
denied(fn()=>$carryService->save(2025,0,$units,$openingAlloc),'manual initial save cannot bypass existing prior review');
$originalCarry=$carryService->carry(2025,0,true);$carried=$carryService->get(2025);check($carried['allocation']['complete']&&$carried['carry_current']&&$carried['carry_forward']['revision']===2,'carry bound to exact approved prior revision');
$bad=$carried['allocation']['allocations'];$bad['opening:0']['parts']['food']+=1;$bad['opening:0']['parts']['shop']-=1;
denied(fn()=>$carryService->save(2025,1,$units,$bad),'carried beginning balances cannot be overwritten');
$carryService->confirm(2025,1,true,'Synthetic next-year review');$carryService->save(2024,2,$units,$openingAlloc);
check(!$carryService->get(2025)['current_approval']&&!$carryService->get(2025)['carry_current'],'changed prior review invalidates following-year approval');
denied(fn()=>$carryService->save(2025,2,$units,$carried['allocation']['allocations']),'superseded carry cannot be edited unnoticed');
denied(fn()=>$carryService->rebase(2025,2,true,'Synthetic change'),'unapproved prior revision cannot replace opening');
$carryService->confirm(2024,3,true,'Synthetic new prior review');
denied(fn()=>$carryService->rebase(2025,1,true,'Synthetic change'),'stale carry rebase cannot overwrite');
denied(fn()=>$carryService->rebase(2025,2,false,'Synthetic change'),'explicit rebase confirmation required');
denied(fn()=>$carryService->rebase(2025,2,true,''),'rebase evidence required');
$originalRebase=$carryService->rebase(2025,2,true,'Synthetic reviewed correction');$rebased=$carryService->get(2025);
check($rebased['carry_current']&&!$rebased['current_approval']&&$rebased['carry_forward']['revision']===4&&$rebased['version']===3,'rebase binds latest approved source and requires fresh next-year approval');
check(count($rebased['history'])===3&&$rebased['history'][1]['carry_forward']['revision']===2,'rebase retains previous opening and its approval immutably');
$carryService->confirm(2025,3,true,'Synthetic rebased next year');check($carryService->get(2025)['current_approval'],'rebased next year can be reviewed again');
denied(fn()=>$carryService->rebase(2025,4,true,'Duplicate'),'current carry cannot be needlessly replaced');
$extra=$units+['new-unused'=>['name'=>'Unbenutzter neuer Betrieb','sphere'=>'4']];$carryService->save(2025,4,$extra,$rebased['allocation']['allocations']);
$carryService->save(2025,5,$units,$rebased['allocation']['allocations']);check($carryService->get(2025)['version']===6,'unused new unit may be removed without altering carried units');
$originalCarryRows=MySqlFixture::snapshot($db);check($carryService->carry(2025,0,true)===$originalCarry&&$carryService->rebase(2025,2,true,'Synthetic reviewed correction')===$originalRebase&&MySqlFixture::snapshot($db)===$originalCarryRows,'original carry and rebase remain exact after later following-year allocation changes');
$carryHtml=$render('carry-board',['segment_original'=>(string)$originalCarry['revision'],'segment_original_sha256'=>$originalCarry['sha256']]);
check(empty($originalCarry['allocation']['capital_carry'])&&!str_contains($carryHtml,'Übernommenes Bereichskapital')&&str_contains($carryHtml,'Soll')&&str_contains($carryHtml,'Haben')&&!str_contains($carryHtml,'<form'),'ordinary original carry retains debit credit sources and invents no opposing capital');
// Template contract: signed opposing capital is displayed in credit-positive accounting form.
$renderSignedCapital=static function()use($originalCarry,$carryLedger):string{
 $example=$originalCarry;$example['allocation']['capital_carry']=['equity'=>['club'=>100,'shop'=>-200]];$journal=$carryLedger->archivedReport(2025,$originalCarry['journal_sha256']);
 $segments=new class($example,$journal){public function __construct(private array$p,private array$j){}public function originalDetails(int$year,int$revision,string$sha):array{return ['original'=>$this->p,'journal'=>$this->j,'sources'=>BalanceSegmentCalculation::sources($this->j)];}public function get(int$year):array{throw new DomainException('Own separate current-state notice');}};
 $year=2025;$segmentText=static fn(string$s):string=>$s;$_GET=['segment_original'=>(string)$example['revision'],'segment_original_sha256'=>$example['sha256']];ob_start();require __DIR__.'/../views/Finance/Balance/balance-segment-original.php';return ob_get_clean();
};
$signedHtml=$renderSignedCapital();check(str_contains($signedHtml,'Allgemeine Vereinsarbeit: -1,00 €')&&str_contains($signedHtml,'Verkauf: 2,00 €')&&str_contains($signedHtml,'Own separate current-state notice')&&!str_contains($signedHtml,'<form'),'original template displays signed capital literally while current-state failure stays separate');
// A matching row digest cannot replace the actual historical prior-year source.
$invalidPrior=$carryService->get(2025)['history'][5];unset($invalidPrior['sha256']);$invalidPrior['revision']=7;$invalidPrior['previous_sha256']=$carryService->get(2025)['history'][5]['sha256'];$invalidPrior['carry_forward']['revision']=6;$invalidPrior['carry_forward']['sha256']=str_repeat('a',64);$invalidPrior['note']='Vortrag neu abgestimmt: Own invalid historical prior reference';$invalidPriorSha=BalanceSegmentCalculation::hash($invalidPrior);
$db->prepare('INSERT INTO balance_segment_reviews VALUES(?,?,?,?,?)')->execute(['carry',2025,7,json_encode($invalidPrior),$invalidPriorSha]);
denied(fn()=>$carryService->get(2025),'current carry rejects nonexistent selected prior source despite a matching row digest');
denied(fn()=>$carryService->original(2025,7,$invalidPriorSha),'original carry rejects nonexistent selected prior source despite a matching row digest');
// A new forged row cannot hide a chain break even though direct INSERT is a DBA power.
$p=$approved;$p['revision']=$finance->get(2025)['version']+1;$p['previous_sha256']=str_repeat('0',64);unset($p['sha256']);
$db->prepare('INSERT INTO balance_segment_reviews VALUES(?,?,?,?,?)')->execute(['a',2025,$p['revision'],json_encode($p),BalanceSegmentCalculation::hash($p)]);
denied(fn()=>(new BalanceSegments($db,'finance'))->get(2025),'rehashed foreign chain link rejected');
echo "$checks business allocation persistence checks passed; isolated synthetic MySQL only\n";
