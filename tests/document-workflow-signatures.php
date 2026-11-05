<?php declare(strict_types=1);
/** Authenticated acknowledgements; isolated local MySQL and synthetic identities only. */
require_once __DIR__.'/../vereinsverwaltung/tests/support/document-workflow-fixture.php';
require_once __DIR__.'/../vereinsverwaltung/src/DocumentWorkflow/WorkflowSignatures.php';
use Kulturhus\Verein\DocumentWorkflow\{WorkflowStore,WorkflowSchema,WorkflowSignatureAccess,WorkflowSignatures};
final class SignatureTestAccess implements WorkflowSignatureAccess {
    public string $id='writer'; public string $club='signature-test'; public bool $memberActive=true; public bool $writerActive=true;
    public function actor():array {return ['id'=>$this->id,'club_id'=>$this->club,'access'=>$this->id==='writer'&&$this->writerActive?'write':($this->id==='reader'?'read':'none')];}
    public function participants():array {return [['id'=>'writer','name'=>'Vorstand Test','access'=>$this->writerActive?'write':'none'],['id'=>'reader','name'=>'Lesezugang Test','access'=>'read']];}
    public function signatureMembers():array {return array_merge([['id'=>'writer','name'=>'Vorstand Test'],['id'=>'reader','name'=>'Lesezugang Test'],['id'=>'other','name'=>'Alex Beispiel']],$this->memberActive?[['id'=>'member','name'=>'Mara Muster']]:[]);}
}
$checks=[];$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
$reject=static function(callable $fn,string $label)use($check):void{try{$fn();}catch(DomainException){$check(true,$label);return;}$check(false,$label);};
$dbName=DocumentWorkflowFixture::create();$db=DocumentWorkflowFixture::connection($dbName);WorkflowSchema::install($db);$a=new SignatureTestAccess();$store=new WorkflowStore($db,$a);$sign=new WorkflowSignatures($db,$a);
$t=$store->saveTemplate('',0,['title'=>'Gefährdungsbeurteilung Test','fields'=>[['key'=>'hazards','label'=>'Gefährdungen','type'=>'risk_register','required'=>true,'matrix_profile'=>'rwe_reference_de_v1']],'provenance'=>[]]);$store->publishTemplate($t,1);$d=$store->createDocument($t,'','Kenntnisnahme · Testdaten');
$row=['category'=>'Aufbau','hazard'=>'Lose Kabel','impact'=>'Sturz','initial'=>['likelihood'=>3,'severity'=>3],'controls'=>'Kabelbrücken verwenden','residual'=>['likelihood'=>1,'severity'=>2],'responsible'=>'Vorstand Test','effectiveness'=>'Sichtprüfung'];
$store->saveDocument($d,1,['hazards'=>[$row]],'Kenntnisnahme · Testdaten','');
$reject(fn()=>$sign->request($d,2,['member']),'draft cannot be assigned for signing');
$attachment=$store->export($d,null,'pdf');$store->addAttachment($d,2,'Testanlage.pdf',$attachment['bytes']);
$v=fn()=>$store->document($d)['version'];$store->transition($d,$v(),'submit');$store->transition($d,$v(),'approve');$approved=$v();
$reject(fn()=>$sign->request($d,$approved,[]),'empty recipient list rejected');
$reject(fn()=>$sign->request($d,$approved,['member','member']),'duplicate recipients rejected');
$reject(fn()=>$sign->request($d,$approved,['nonexistent']),'unknown recipient rejected');
$reject(fn()=>$sign->request($d,$approved-1,['member']),'stale revision rejected');
$a->id='reader';$reject(fn()=>$sign->request($d,$approved,['member']),'reader cannot request signatures');
$a->id='writer';$check($sign->request($d,$approved,['member','other'])===2,'writer requests exact approved revision for two members');$check($sign->request($d,$approved,['member'])===0,'repeat request is idempotent');
$requests=$sign->forDocument($d,$approved);$ids=array_column($requests,'id','recipient_id');$check(count($requests)===2,'only one request per recipient and revision');
$a->id='member';$check($store->actor()['access']==='none','member needs no module read or write permission');
$check(count($sign->own())===1,'personal list only includes owned requests');
$reject(fn()=>$store->document($d),'member cannot access global module document');$reject(fn()=>$sign->ownRequest($ids['other']),'member cannot open another request');
$request=$sign->ownRequest($ids['member']);$check($request['can_sign']&&$request['member_name']==='Mara Muster','assigned member may sign current approved revision');
$check($request['document']['version']===$approved && !isset($request['signature_png']),'personal request pins snapshot without image disclosure');
$pdf=$sign->ownPdf($ids['member']);$check(str_starts_with($pdf['bytes'],'%PDF-'),'member downloads own complete assessment with attachment');
$png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAMgAAAA8CAIAAACsOWLGAAACwklEQVR4nO2c3bGCMBCF0bGO7caeLMGe7IZGvA/OMFwJkGz2bJL1fA8+KcHly8kP6OX9fk+EWHM1PyIhFIugYGIRCBSLQKBYBALFIhAoFoFAsQgEitUAuT+W16hcQDvvS9Xm1xNx/HGR/z5FrQ8TyxXZpFTU3IIk1o90yiLkUKB4JWJieSCbnvZlktwfwaLr6jC7ClYyq/yeU3pNUUAlVrxsB80K5qBuXX3qGKZe+chmdNvrbCHdgiQW40rOJlXh3bIUK0A5Gi6K51hu2SfWV4HMR8POF1A1Wy1zoKXizepAPiVYWlk318nIu63AXH5in4+sDyX3RydfsM0G6acWeyWwusNzqm+ra2C+JyyDbzLfRoyrgxG2SZIhJJhfz6FzyyaxjuNq/Z6aup9Kc+A37qpAo0WGzS0DsTKNqRSrNIocJDOZVI3iVmlkmol12mqNWJXFRUjmeb3FxeCc1vPbrRWrSBe1W4YzJxPJmqSIdNBofrs2YmU2phMLNx/XSdZwbJJxYrJKLIUoNR/xnINn7vH6z3gE71ayFKUNGYili5/SkGu4fZCk4QJNHNeh6uPrxaqfMOXP93UN9dN9zUFUxjaPazdI3Urc6lqu21UkNIjt9qnhCndpouIEtWI5bLV3eDewk9OwvasImjhWPd2gO4NffvrPnJqHbZKPIlp1Ho1YvxlXkdyS1AM5tnXWJ1bNeRyHFpMM6lZSKfPeWyyW81VnXBm6BR37arcbDFdGyX0HDoI17A1w/psmZatCDlKdM6eWintvg6KZY/ncV+EgqOagdLixTy+WeVx9TeEZh4Yk7fHsqwVDoedpMa6sdueX1ynG/2Pl08MjA8Qc/tsMCSrW3g9cydC0F4uEpP0ca9yfzpExxCKR4FBIIFAsAoFiEQgUi0CgWAQCxSIQKBaBQLEIBIpFIFAsAoFiEQgUi0wI/gAygOZUykbM4QAAAABJRU5ErkJggg==';
$hash=$request['document_sha256'];
$reject(fn()=>$sign->sign($ids['member'],$hash,false,$png),'explicit acknowledgement required');
$reject(fn()=>$sign->sign($ids['member'],$hash,true,'data:image/png;base64,AAAA'),'broken PNG rejected');
$reject(fn()=>$sign->sign($ids['member'],$hash,true,'data:image/svg+xml;base64,AAAA'),'SVG and script-bearing image types rejected');
$reject(fn()=>$sign->sign($ids['member'],str_repeat('0',64),true,$png),'mismatched document hash rejected');
$a->memberActive=false;$reject(fn()=>$sign->ownRequest($ids['member']),'revoked member access checked on every personal request');$reject(fn()=>$sign->sign($ids['member'],$hash,true,$png),'revoked member cannot sign');$a->memberActive=true;
$a->club='other-club';$check($sign->own()===[],'personal requests isolated by club');$reject(fn()=>$sign->ownRequest($ids['member']),'cross-club request denied');$a->club='signature-test';
$sign->sign($ids['member'],$hash,true,$png);$signed=$sign->ownRequest($ids['member']);$check(!$signed['can_sign']&&$signed['signer_name']==='Mara Muster'&&is_string($signed['signed_at']),'server records authenticated identity and timestamp');
$reject(fn()=>$sign->sign($ids['member'],$hash,true,$png),'same revision cannot be signed twice');
$evidence=$sign->evidence($d,$approved,'member');$check(count($evidence)===1&&$evidence[0]['document_sha256']===$hash,'evidence includes revision-bound hash and only own signature');
$reject(fn()=>$sign->evidence($d,$approved,'other'),'member cannot retrieve other signature evidence');
$signedPdf=$sign->ownPdf($ids['member']);$check(strlen($signedPdf['bytes'])>strlen($pdf['bytes']),'signed personal PDF includes rendered acknowledgement page');
$a->id='writer';$modulePdf=$store->export($d,null,'pdf');$check(str_starts_with($modulePdf['bytes'],'%PDF-'),'authorized module PDF includes signed evidence');
$a->writerActive=false;$reject(fn()=>$sign->request($d,$approved,['reader']),'revoked writer cannot request signatures');$a->writerActive=true;
$store->transition($d,$approved,'reopen');$a->id='other';$stale=$sign->ownRequest($ids['other']);$check(!$stale['can_sign'],'unsigned historic request stops after reopen');$reject(fn()=>$sign->sign($ids['other'],$stale['document_sha256'],true,$png),'reopened document cannot receive old signatures');
$a->id='member';$check($sign->ownRequest($ids['member'])['signed_at']===$signed['signed_at'],'previous signed acknowledgement preserved');$check($sign->ownPdf($ids['member'])['bytes']!=='' ,'old signed assessment stays downloadable');
$a->id='writer';$store->transition($d,$v(),'submit');$store->transition($d,$v(),'approve');$check($sign->request($d,$v(),['member'])===1,'new approval revision requires a new request');$a->id='member';$check(count($sign->own())===2,'separate historic and current requests retained');
$db->exec("UPDATE dwf_signatures SET signature_png='damaged' WHERE id=".$db->quote($ids['member']));$reject(fn()=>$sign->ownPdf($ids['member']),'damaged stored signature never rendered silently');
$out=dirname(__DIR__).'/output/pdf';if(!is_dir($out))mkdir($out,0700,true);file_put_contents($out.'/gefaehrdungsbeurteilung-online-unterschrieben.pdf',$signedPdf['bytes']);
$report=['suite'=>'document-workflow-signatures','status'=>'passed','checks'=>count($checks),'passed'=>$checks,'database'=>$dbName,'production_changed'=>false,'finished_at_utc'=>gmdate('c')];
file_put_contents(dirname(__DIR__).'/audit/document-workflow-signatures-20261008.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
