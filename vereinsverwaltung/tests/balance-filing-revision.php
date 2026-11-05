<?php declare(strict_types=1);
// Reuse the actual generator + strictly offline transport baseline; no real send.
require __DIR__.'/balance-tax-filing.php';
use Kulturhus\Verein\{BalanceClosingCorrections,BalanceLedger,BalanceTaxFiling,BalanceFilingRevision,TaxFilings};
$baseChecks=$checks;$original=$tax->get($id);$originalId=$id;$originalAttempts=$tax->attempts($id);
$raw=(new BalanceLedger($db,'board'))->report(2025);$rawHash=$raw['closed_sha256'];
$corrections=new BalanceClosingCorrections($db,'board');
$input=['kind'=>'error','reason'=>'Synthetic missed expense','legal_basis'=>'Synthetic legal review','confirmed'=>true,'entries'=>[
    ['id'=>str_repeat('c',32),'date'=>'2025-12-31','description'=>'Synthetic accrued expense','evidence'=>'Synthetic invoice','lines'=>$pair('expense','liabilities',101)]]];
$proposal=$corrections->save(2025,0,$rawHash,$input);$corrections->confirm(2025,1,$rawHash,$proposal['proof']['sha256'],true,'Synthetic amended closing review');
$current=(new BalanceLedger($db,'board'))->report(2025);check($current['calculation']['annual_result_cents']===7899,'amended closing values used exactly');
denied(fn()=>$svc->assertCurrent($tax->get($waiting)),'old prepared draft is no longer current');
$map=$mapping+['liabilities'=>'bs.eqLiab.liab.trade.genOther'];
$amend=$svc->create(2025);$svc->save($amend,1,$profile,$map,$review);
$data=['status'=>'B','reason'=>'Synthetic corrected invoice','legal_basis'=>'Synthetic reviewed correction permission','source_id'=>$originalId,'confirmed'=>true];
denied(fn()=>$tax->validate($amend,2),'corrected journal cannot silently use initial status');
denied(fn()=>$svc->revision($amend,2,array_replace($data,['confirmed'=>false])),'revision requires explicit review');
denied(fn()=>$svc->revision($amend,2,array_replace($data,['status'=>'E','source_id'=>''])),'already sent statement cannot become first filing');
denied(fn()=>$svc->revision($amend,2,array_replace($data,['status'=>'sK'])),'changed B GuV cannot be other correction');
denied(fn()=>$svc->revision($amend,2,array_replace($data,['source_id'=>$copy])),'different scope receipt cannot be selected');
denied(fn()=>(new BalanceTaxFiling($db,'other'))->revision($amend,2,$data),'cross club receipt isolation');
denied(fn()=>(new BalanceTaxFiling($db,'member'))->revision($amend,2,$data),'member cannot prepare revision');
$svc->revision($amend,2,$data);$r=$tax->get($amend);
check($r['state']==='draft'&&$r['xml_data']===null&&$r['form_data']['balance_preparation']['filing_revision']['source']['xml_sha256']===$original['xml_sha256'],'revision binds exact original receipt and resets validation');
denied(fn()=>$svc->revision($amend,2,$data),'stale revision form cannot overwrite new one');
check($tax->validate($amend,3)['valid'],'corrected values and revision generate offline');
$r=$tax->get($amend);check(str_contains($r['xml_data'],'revisionStatus.revisionStatus.B')&&str_contains($r['xml_data'],'reportStatus.reportStatus.E')&&!str_contains($r['xml_data'],'revisionStatus.revisionStatus.E'),'correct revision flag separate from finality');
denied(fn()=>$tax->ready($amend,4,'SEND-TEST',true,true),'amended filing needs independent board approval');
$bs->approve($amend,4,true);
$first=$svc->create(2025);$firstProfile=$profile;$firstProfile['business_name']='Synthetic not previously submitted';$firstProfile['company']['tax_number']='5192050001999';$svc->save($first,1,$firstProfile,$map,$review);
$initial=array_replace($data,['status'=>'E','reason'=>'Synthetic no prior submissions','legal_basis'=>'','source_id'=>'']);$svc->revision($first,2,$initial);
check($tax->validate($first,3)['valid']&&str_contains($tax->get($first)['xml_data'],'revisionStatus.revisionStatus.E'),'internally corrected but never filed closing may be first declaration');
$bs->approve($first,4,true);
// A competing approved amendment must be rechecked under the send lock.
$competing=$svc->create(2025);$svc->save($competing,1,$profile,$map,$review);$svc->revision($competing,2,$data);$tax->validate($competing,3);$bs->approve($competing,4,true);
check($tax->send($amend,5,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','amended transport fixture binds a new receipt');
denied(fn()=>$tax->ready($competing,5,'SEND-TEST',true,true),'older competing amendment invalidated by newer receipt');
check($original===$tax->get($originalId)&&$originalAttempts===$tax->attempts($originalId),'original declaration approval XML and receipt unchanged');
check($tax->download($originalId,'receipt')['mime']==='application/xml','old receipt still downloadable after amendment');
$d=$tax->get($amend)['form_data']['balance_preparation']['filing_revision'];
$second=$svc->create(2025);$svc->save($second,1,$profile,$map,$review);
check(array_column((new BalanceFilingRevision($db,'finance'))->choices($tax->get($second)),'filing_id')===[$amend],'revision picker offers only most recent confirmed filing');
denied(fn()=>$svc->revision($second,2,$data),'obsolete original cannot skip most recent corrected filing');
foreach(['G','BG'] as $status){$svc->revision($second,(int)$tax->get($second)['version'],array_replace($data,['status'=>$status,'source_id'=>$amend]));$tax->validate($second,(int)$tax->get($second)['version']);check(str_contains($tax->get($second)['xml_data'],'revisionStatus.revisionStatus.'.$status),'native schema choice '.$status.' emitted');}
$other=$svc->create(2025);$svc->save($other,1,$profile,$map,$review);$svc->revision($other,2,array_replace($data,['status'=>'sK','source_id'=>$amend]));
check($tax->validate($other,3)['valid'],'unchanged B GuV may use other correction');
$svc->appendAdjustment($other,4,'current_adjustments','Synthetic tax balance change','cash','expense',10);
$changedReview=$tax->get($other)['form_data']['balance_preparation']['review'];unset($changedReview['year'],$changedReview['journal_sha256']);$changedReview['complete']=true;$svc->save($other,5,$profile,$map,$changedReview);
denied(fn()=>$tax->validate($other,6),'tax balance value changes cannot hide in other correction');
$damaged=$tax->get($amend);$damaged['xml_data']='<bad/>';$db->prepare('UPDATE tax_filings SET xml_data=? WHERE id=?')->execute([$damaged['xml_data'],$amend]);
denied(fn()=>$tax->validate($second,(int)$tax->get($second)['version']),'damaged original receipt invalidates amendment');$db->prepare('UPDATE tax_filings SET xml_data=? WHERE id=?')->execute([$r['xml_data'],$amend]);
// Source form_data tampering is detected even if the XML receipt is intact.
$restore=$tax->get($amend);$bad=$restore['form_data'];$bad['balance_preparation']['profile']['supplier']='Synthetic changed historical supplier';$db->prepare('UPDATE tax_filings SET form_data=? WHERE id=?')->execute([json_encode($bad),$amend]);
denied(fn()=>$tax->validate($second,(int)$tax->get($second)['version']),'mutated original approval invalidates revision');$db->prepare('UPDATE tax_filings SET form_data=? WHERE id=?')->execute([json_encode($restore['form_data']),$amend]);
check((new BalanceLedger($db,'board'))->report(2025,true)===$raw,'filing changes never alter original journal');
$html=$render($second,'finance');check(str_contains($html,'tax-filing-balance-revision')&&str_contains($html,'synthetic-csrf')&&str_contains($html,'Vorherige Abgabe'),'revision UI shows source choice and CSRF form');
file_put_contents(__DIR__.'/balance-filing-revision-ui.html',$html);
$html=$render($second,'member');check(!str_contains($html,'name="revision_status"'),'member cannot see revision form');
$html=$render($second,'finance',['action'=>'tax-filing-balance-revision','filing'=>$second,'version'=>'1','revision_status'=>'B','revision_reason'=>'<preserved>','revision_legal_basis'=>'<legal>','revision_source'=>$amend]);
check(str_contains($html,'&lt;preserved&gt;')&&!str_contains($html,'<preserved>')&&str_contains($html,'name="version" value="1"'),'rejected revision form preserves escaped inputs and stale version');
class RevisionRedirect extends RuntimeException{public function __construct(public array $route){parent::__construct('Synthetic redirect');}}
function redirectTo(array $route):never{throw new RevisionRedirect($route);}
$controller=static function(array $post,string $actor='finance')use($db):array{$_POST=$post;$_SESSION=['actor'=>$actor];$action=$post['action'];try{require __DIR__.'/../src/Finance/Tax/TaxFilingActions.php';}catch(RevisionRedirect $e){return $e->route;}throw new RuntimeException('Missing redirect');};
$post=['action'=>'tax-filing-balance-revision','filing'=>$second,'version'=>(string)$tax->get($second)['version'],'tax_year'=>'2025','revision_status'=>'BG','revision_reason'=>'Synthetic controller review','revision_legal_basis'=>'Synthetic permission','revision_source'=>$amend,'revision_confirmed'=>'1'];
denied(fn()=>$controller($post,'member'),'controller rechecks write role');
denied(fn()=>$controller(array_replace($post,['revision_confirmed'=>''])),'controller requires explicit confirmation');
denied(fn()=>$controller(array_replace($post,['version'=>'1'])),'controller rejects stale version');
$route=$controller($post);$after=$tax->get($second);
check($route['filing']===$second&&$after['state']==='draft'&&$after['xml_data']===null&&$after['form_data']['balance_preparation']['filing_revision']['status']==='BG','controller saves exact status and invalidates generated XML');
echo ($checks-$baseChecks)." filing revision checks passed; actual generator, simulated transport only\n";
