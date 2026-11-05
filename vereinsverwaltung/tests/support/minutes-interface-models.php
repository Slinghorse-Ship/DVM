<?php declare(strict_types=1);
require dirname(__DIR__,2).'/src/Web/View.php';
use Kulturhus\Native\Language\MinutesMessages;
use Kulturhus\Verein\Web\{MinutesForm,MinutesPageState};
$messages=MinutesMessages::messages('en');
$check(MinutesMessages::text('Protokolle',$messages)==='Minutes','fixed minutes labels follow the personal English language');
$check(MinutesMessages::text('Ursprünglicher Wert: {value}',$messages,['value'=>'Protokolle {value} ä🙂'])==='Original value: Protokolle {value} ä🙂','translation leaves human values and embedded placeholders literal');
$render=static function(array $query,array $post=[],int $status=200,array $files=[],?string $asActor=null)use($db,$actor,$messages):array{
 $_SESSION=['actor'=>$asActor??$actor,'csrf'=>'Own renderer current csrf'];$_GET=$query;$_POST=$post;$_FILES=$files;$_SERVER['REQUEST_METHOD']=$post===[]?'GET':'POST';$managerLanguageMessages=$messages;http_response_code($status);ob_start();
 try{require dirname(__DIR__,2).'/views/Governance/minutes.php';return['html'=>(string)ob_get_contents(),'status'=>http_response_code()];}finally{ob_end_clean();}
};
$xpathOf=static function(string $html):DOMXPath{$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($dom);};
$ownHtml='<h2>Own unsaved <strong>exact original</strong> ä🙂</h2><p>Not the saved record</p>';
$ownPost=array_replace($edit,['title'=>'0 Own overlong original '.str_repeat('ä🙂 <&> ',90),'meeting_on'=>'0 Own invalid original day','body_html'=>$ownHtml]);
$beforeRenderer=ownOriginalDatabaseHash($db,$tables);
foreach([422,409,503]as$status){
 $view=$render($editQuery,$ownPost,$status);$xp=$xpathOf($view['html']);
 $check($view['status']===$status&&$xp->query('//form[@data-minutes-form]//input[@name="title"]')->item(0)?->getAttribute('value')===$ownPost['title'],'actual rejected minutes renderer preserves the whole original title '.$status);
 $check($xp->query('//form[@data-minutes-form]//input[@name="meeting_on"]')->item(0)?->getAttribute('type')==='text'&&$xp->query('//form[@data-minutes-form]//input[@name="meeting_on"]')->item(0)?->getAttribute('value')===$ownPost['meeting_on'],'invalid original date remains fully visible and correctable '.$status);
 $check($xp->query('//form[@data-minutes-form]//input[@name="version"]')->item(0)?->getAttribute('value')===$ownPost['version']&&$xp->query('//form[@data-minutes-form]//input[@name="request_key"]')->item(0)?->getAttribute('value')===$ownPost['request_key']&&$xp->query('//textarea[@name="body_html"]')->item(0)?->textContent===$ownHtml,'rejected own text keeps exact markup version and original key '.$status);
 $check(!str_contains($view['html'],$otherBody)&&!str_contains($view['html'],$ownPost['csrf']),'rejected renderer never substitutes concurrent stored text or reflects submitted authentication '.$status);
 if($status!==422)$check($xp->query('//fieldset[@disabled]//form[@data-minutes-readonly]')->length===1&&$xp->query('//textarea[@data-minutes-copy-original and @readonly]')->length===1&&$xp->query('//a[@target="_blank"]')->length>=1,'conflicting or unconfirmed own input is read-only with focusable copy and separate stored read '.$status);
 else$check($xp->query('//form[@data-minutes-readonly]')->length===0,'validation correction stays editable without a fresh stored version');
 if($status===503)$check($xp->query('//form[@data-minutes-original-retry]//input[@name="request_key"]')->item(0)?->getAttribute('value')===$ownPost['request_key']&&$xp->query('//form[@data-minutes-original-retry]//input[@name="version"]')->item(0)?->getAttribute('value')===$ownPost['version']&&$xp->query('//form[@data-minutes-original-retry]//input[@name="retry_confirm" and @required]')->length===1,'manual uncertain-result check requires confirmation and retains the exact original intention');
}
$pdfPost=array_replace($ownPost,['source_kind'=>'pdf','pdf_existing'=>'1']);$attempted=['minute_pdf'=>['name'=>'Own attempted original.pdf','tmp_name'=>'/own/private/path/never-reflect','size'=>strlen($pdfC),'type'=>'application/pdf','error'=>UPLOAD_ERR_OK]];
$view=$render($editQuery,$pdfPost,422,$attempted);$xp=$xpathOf($view['html']);
$check($xp->query('//input[@name="upload_required"]')->item(0)?->getAttribute('value')==='1'&&$xp->query('//input[@name="minute_pdf" and @required]')->length===1,'failed PDF selection explicitly requires reselecting the original file');
$check(str_contains($view['html'],'Own attempted original.pdf')&&!str_contains($view['html'],'/own/private/path/never-reflect'),'original PDF packet includes human file metadata without private upload paths');
$view=$render($compose,array_replace($post,['source_kind'=>'pdf','upload_required'=>'1']),503,$attempted);$xp=$xpathOf($view['html']);
$check($xp->query('//form[@data-minutes-original-retry]//input[@name="minute_pdf" and @required]')->length===1&&$xp->query('//form[@data-minutes-original-retry]//input[@name="upload_required"]')->length===1,'uncertain new PDF retry cannot silently use an older or absent file');
$lookup=$render($query+['created_request'=>$createdKey]);
$check(str_contains($lookup['html'],'data-minutes-reader')&&str_contains($lookup['html'],$createdData['title'])&&!str_contains($lookup['html'],'name="action"')&&!str_contains($lookup['html'],'name="request_key"'),'explicit original creation lookup shows its current saved result without mutation forms or new key');
$lookup=$render($query+['created_request'=>$key()]);
$check(str_contains($lookup['html'],'data-minutes-creation-empty')&&!str_contains($lookup['html'],'data-minutes-form')&&!str_contains($lookup['html'],'name="request_key"'),'missing original creation lookup never becomes a replacement draft');
foreach([$query+['page'=>'1000000'],$query+['q'=>['Own original query']]]as$raw){$view=$render($raw);$check($view['status']===422&&str_contains($view['html'],'data-minutes-rejected')&&!str_contains($view['html'],'data-minutes-reader')&&!str_contains($view['html'],'data-minutes-form'),'unusable original list selection stays visible without a substitute document or form');}
$view=$render($query+['mid'=>$id],$transition,422);$xp=$xpathOf($view['html']);
$check($xp->query('//form[@data-minutes-transition]//input[@name="version"]')->item(0)?->getAttribute('value')==='2'&&$xp->query('//input[@name="confirm_transition" and @required]')->length===1,'missing transition confirmation retains the exact original version and explicit native checkbox');
$reader=$f['accounts']['member']['actor'];$readerMinutes=new Kulturhus\Verein\Minutes($db,$reader);
$check($readerMinutes->canRead()&&!$readerMinutes->canWrite(),'actual ordinary member has read-only published minutes access');
$view=$render($query+['mid'=>$publicationId],[],200,[],$reader);
$check($view['status']===200&&str_contains($view['html'],'data-minutes-reader')&&!str_contains($view['html'],'name="action"'),'ordinary member reads the exact published document without mutation forms');
$view=$render($query+['mid'=>$id],[],200,[],$reader);
$check($view['status']===403&&str_contains($view['html'],'data-minutes-rejected')&&!str_contains($view['html'],$otherBody),'ordinary member cannot read an exact private draft or a substitute publication');
$view=$render($query+['state'=>'draft'],[],200,[],$reader);
$check($view['status']===403&&!str_contains($view['html'],'minutes-item'),'ordinary member draft filter is refused instead of silently falling back to another list');
$deny(static fn()=>$readerMinutes->save($id,2,$base,$key()),'actual ordinary member cannot save a minutes draft');
$deny(static fn()=>$readerMinutes->transition($publicationId,2,'withdraw'),'actual ordinary member cannot withdraw an exact publication');
$deny(static fn()=>$readerMinutes->creationResult($createdKey),'ordinary reader cannot inspect private creation intentions');
foreach(['pdf_existing','retry_original','retry_confirm']as$flag)$deny(static fn()=>MinutesForm::command($compose,$post+[$flag=>'0']),'unknown original recovery flag is refused '.$flag,422);
$stagedToken=str_repeat('b',64);
$check(MinutesForm::command($compose,$post+['live_uploads'=>['minute_pdf'=>[$stagedToken]]])['request_key']===$post['request_key'],'one exact session-bound prepared PDF retains the same original save intention');
foreach([null,'0',[],['other'=>[$stagedToken]],['minute_pdf'=>[$stagedToken,$stagedToken]],['minute_pdf'=>['0']],['minute_pdf'=>['other'=>$stagedToken]]]as$raw)$deny(static fn()=>MinutesForm::command($compose,$post+['live_uploads'=>$raw]),'unsupported prepared PDF metadata never silently changes the selected file',422);
$check(!str_contains(MinutesPageState::originals($compose,$post+['live_uploads'=>['minute_pdf'=>[$stagedToken]]],$attempted),$stagedToken),'lossless human PDF metadata does not expose private staging tokens');
$check(ownOriginalDatabaseHash($db,$tables)===$beforeRenderer,'every whole row remains exact through actual renderer roles recovery and PDF metadata checks');
$_POST=[];$_FILES=[];$_GET=[];$_SERVER['REQUEST_METHOD']='GET';http_response_code(200);
