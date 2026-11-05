<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Settings\{GeneralRequests,FinanceTaxInput,FinanceTaxContext};
// Both connection probes establish their old repeatable-read view on unrelated finance rows.
$txGuardId=$txDocuments->upload('own-current-notice-guard.pdf',$pdf('current-guard-first'),['title'=>'Own separate current notice guard','kind'=>'other','folder'=>$txFolder,'scope'=>'finance','document_date'=>'2026-10-21','links'=>[]],$key());
$txGuardFirstFile=$txDocuments->file($txGuardId,1);$txOther=new PDO($txDsn,$txConfig['username'],$txConfig['password'],$txOptions);$txOtherDocuments=new \Kulturhus\Verein\Documents($txOther,'own-list-admin');
$txCaller->beginTransaction();$txCaller->query('SELECT * FROM finance_entries')->fetchAll();
$txOtherDocuments->newVersion($txGuardId,1,'own-current-notice-is-text.txt','Own consciously current text original','Own actual independent source replacement');
try{
 $old=(new \Kulturhus\Verein\Documents($txCaller,'own-list-admin'))->document($txGuardId);
 $actual=$txOtherDocuments->document($txGuardId);$wanted=$txSettings->section('tax')['data'];$wanted['notice_document_id']=$txGuardId;$version=$txSettings->section('tax')['version'];
 $before=ownOriginalDatabaseHash($txCaller,$tables);$closed=false;
 try{(new \Kulturhus\Verein\Settings($txCaller,'own-list-admin'))->save('tax',$wanted,$version);}catch(DomainException $failure){$closed=$failure->getCode()===422;}
 $check($old['mime']==='application/pdf'&&$actual['mime']==='text/plain'&&$closed&&$txCaller->inTransaction()&&ownOriginalDatabaseHash($txCaller,$tables)===$before,'actual current non-PDF source rejects a tax save behind an unrelated older PDF snapshot preserving every entire caller row and transaction');
}finally{$txCaller->rollBack();}
$check($txDocuments->file($txGuardId,1)===$txGuardFirstFile&&$txSettings->section('tax')===$txLater['result'],'current source denial preserves the actual first PDF bytes and latest consciously accepted tax settings');
$txDocuments->newVersion($txGuardId,2,'own-current-notice-is-pdf-again.pdf',$pdf('current-guard-third'),'Own deliberate valid later PDF original');

// A private first approval cannot disclose a notice after its current document scopes change.
$txNoticeActor='own-tax-notice-writer';
Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser)VALUES(?,'own-list-club','member',0)",[$txNoticeActor]);
Db::run($db,'INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version)VALUES(?,?,?,?,?,?,?,1)',['own-list-club',$txNoticeActor,$txNoticeActor,'','none','write',0]);
Db::run($db,'INSERT INTO settings_role_assignments VALUES(?,?,?,1)',[$txNoticeActor,'own-list-club',$txNoticeActor]);
$txNoticeRequests=new GeneralRequests($db,$txNoticeActor);$txNoticeInput=FinanceTaxInput::initial($txSettings->section('tax'));$txNoticeInput['data']['office']='Own finance-only notice review';
$txNoticePlan=$txNoticeRequests->prepare('tax',$txNoticeInput);$txNoticeOriginal=$txNoticeRequests->accept('tax',$txNoticeInput,$key(),$txNoticePlan['hash'],true);
$txRestrictedFolder=$txDocuments->saveFolder('',0,'Own newly restricted original notices','','members');
$txCaller->beginTransaction();$txCaller->query('SELECT * FROM finance_entries')->fetchAll();
$txDocuments->update($txSelected,3,['title'=>str_repeat('ü',75),'kind'=>'other','document_date'=>'2026-10-21','state'=>'active','notes'=>'Own complete original notice note','folder'=>$txRestrictedFolder,'links'=>[],'reason'=>'Own current document scope restriction']);
try{
 $before=ownOriginalDatabaseHash($txCaller,$tables);$closed=false;
 try{(new GeneralRequests($txCaller,$txNoticeActor))->receipt('tax',$txNoticeOriginal['key']);}catch(DomainException $failure){$closed=$failure->getCode()===403;}
 $check($closed&&$txCaller->inTransaction()&&ownOriginalDatabaseHash($txCaller,$tables)===$before,'actual current whole document and ancestor scope restriction closes a known private tax original behind an older caller snapshot without changing any caller row');
}finally{$txCaller->rollBack();}
$db->beginTransaction();try{
 $allowed=FinanceTaxContext::page($db,$txNoticeActor,'','1');$ids=array_column(array_column($allowed['rows'],'document'),'id');
 $check(!in_array($txSelected,$ids,true)&&$allowed['total']===9,'current finance-only chooser omits an inaccessible tax notice from its candidates and total without exposing its title path or PDF');
 $txDeny(fn()=>FinanceTaxContext::read($db,$txNoticeActor,['notice_document_id'=>$txSelected]),403,'typed known original notice identifier cannot bypass all current document scopes');
}finally{$db->rollBack();}
Db::run($db,"UPDATE settings_roles SET members_access='read' WHERE club_id='own-list-club' AND id=?",[$txNoticeActor]);
try{$check($txNoticeRequests->receipt('tax',$txNoticeOriginal['key'])===$txNoticeOriginal,'restored actual source permission reads identical first tax original bytes without replacing its earlier PDF metadata');}
finally{Db::run($db,"UPDATE settings_roles SET members_access='none' WHERE club_id='own-list-club' AND id=?",[$txNoticeActor]);}
$txExpectedDocument=array_replace($txDocumentBefore,['title'=>str_repeat('ü',75),'version'=>4,'folder_id'=>$txRestrictedFolder,'scopes'=>json_encode(['finance','members'],JSON_THROW_ON_ERROR)]);
$check(Db::run($db,"SELECT * FROM documents WHERE club_id='own-list-club' AND id=?",[$txSelected])->fetch()===$txExpectedDocument&&$txDocuments->file($txSelected,1)===$txPdfBefore,'canonical notice scope and folder changes retain every other complete original document field and exact earlier PDF bytes');
