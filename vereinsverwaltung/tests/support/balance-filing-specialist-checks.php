<?php declare(strict_types=1);
use Kulturhus\Verein\Web\{BalanceFilingForm as Form,BalanceFilingMarkup as Markup};
if(!function_exists('e')){function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
if(!function_exists('hidden')){function hidden(string $name,mixed $value):string{return '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';}}
if(!function_exists('input')){function input(array $data,string $name,string $default=''):string{return is_string($data[$name]??null)?$data[$name]:$default;}}
if(!function_exists('csrf')){function csrf():string{return hidden('csrf','own-specialist-csrf');}}
if(!function_exists('url')){function url(array $query):string{return '/vereinsverwaltung/?'.http_build_query($query);}}
function ownActualSpecialistHtml(PDO $db,string $fid,array $query,array $post,string $locale='de',string $actor='env-board'):string{
 $documents=new \Kulturhus\Verein\Documents($db,$actor);$filings=new \Kulturhus\Verein\TaxFilings($db,$actor);$filing=$filings->get($fid);$year=$filing['tax_year'];$canFile=$filings->canWrite();$base=['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>(string)$year];$states=['draft'=>'In Bearbeitung','validated'=>'Geprüft · noch nicht abgegeben','sending'=>'Übermittlung gestartet','unknown'=>'Abgabestatus unklar','failed'=>'Nicht übermittelt'];$annualTaxMessages=\Kulturhus\Native\Language\ManagerMessages::messages($locale);
 $_SESSION=['actor'=>$actor,'csrf'=>'own-specialist-csrf'];$_GET=$query;$_POST=$post;$_SERVER['REQUEST_METHOD']=$post?'POST':'GET';ob_start();require dirname(__DIR__,2).'/views/Finance/Balance/balance-tax-filing.php';return ob_get_clean();
}
function ownSuccessfulSpecialist(string $html,string $action):array{
 $dom=new DOMDocument();$old=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);$xp=new DOMXPath($dom);$forms=$xp->query('//form[@data-balance-specialist-form]');if($forms->length!==1)throw new RuntimeException('Actual single complete specialist form needed');$pairs=[];
 foreach($xp->query('.//input[@name]|.//select[@name]|.//textarea[@name]',$forms->item(0))as$n){if($n->hasAttribute('disabled')||$xp->query('ancestor::fieldset[@disabled]',$n)->length)continue;$type=$n->getAttribute('type');if(in_array($type,['checkbox','radio'],true)&&!$n->hasAttribute('checked'))continue;$value=$n->tagName==='textarea'?$n->textContent:$n->getAttribute('value');if($n->tagName==='select'){$o=$xp->query('.//option[@selected]',$n);if(!$o->length)$o=$xp->query('.//option[1]',$n);$value=$o->item(0)?->getAttribute('value')??'';}$pairs[]=urlencode($n->getAttribute('name')).'='.urlencode($value);}
 $pairs[]='action='.urlencode($action);parse_str(implode('&',$pairs),$post);return$post;
 }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
}
function runOwnedBalanceSpecialistChecks(PDO $db,string $fid,callable $check):void
{
 $cfg=\Kulturhus\Native\TestSupport::config();if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-balance-filing-interface'||($cfg['port']??0)!==13371||!preg_match('/\\Akh_native_[a-f0-9]{24}\\z/D',$cfg['database']??'')||$db->query('SELECT DATABASE()')->fetchColumn()!==$cfg['database'])throw new RuntimeException('Exact owned specialist source fixture required');
 $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences']));$allBefore=ownOriginalDatabaseHash($db,$tables);
 $globals=[$_GET??[],$_POST??[],$_SESSION??[], $_SERVER['REQUEST_METHOD']??null];
 try{
$query=['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>'2025','filing'=>$fid,'balance_local_details'=>'1'];$query=Form::source($query);$session=[];$html=ownActualSpecialistHtml($db,$fid,$query,[]);$menu=Markup::protect($db,'env-board',$query,[],$html,$session);
$dom=new DOMDocument();@$dom->loadHTML($menu);$xp=new DOMXPath($dom);$links=$xp->query('//a[@data-balance-specialist-open]');$check($links->length===2&&$xp->query('//form')->length===0&&$session===[],'actual specialist menu opens no editable forms or source contexts');
$href=$xp->query('//article[h3="Abgabeart bearbeiten"]/a')->item(0)->getAttribute('href');parse_str(parse_url($href,PHP_URL_QUERY),$selected);
$view=Markup::protect($db,'env-board',$selected,[],ownActualSpecialistHtml($db,$fid,$selected,[]),$session);
$actionDom=new DOMDocument();@$actionDom->loadHTML($view);$actionPath=new DOMXPath($actionDom);$check($actionPath->query('//form[@data-balance-specialist-form]//input[@name="action"]')->length===0&&$actionPath->query('//form[@data-balance-specialist-form]//button[@name="action" and @value="tax-filing-balance-revision"]')->length===1,'actual specialist form has one clicked business action and no hidden action that can override an earlier rebase');
$post=ownSuccessfulSpecialist($view,'balance-local-specialist-back');$check(Form::handle($db,'env-board',$selected,$post,'own-specialist-csrf',$session)['navigate']===$query,'unchanged actual complete specialist returns without saving');
$deny=static function(array $p,int $code,string $label,?array $route=null)use($check,$db,$selected,&$session):void{$denied=false;try{Form::handle($db,'env-board',$route??$selected,$p,'own-specialist-csrf',$session);}catch(DomainException $e){$denied=$e->getCode()===$code;}$check($denied,$label);};
$dirty=$post;$dirty['revision_reason']='0 Own specialist reason Übernahme <&> 🙂';$deny($dirty,409,'dirty actual back keeps zero Unicode and reason');
$stateHtml=Markup::protect($db,'env-board',$selected,$dirty,ownActualSpecialistHtml($db,$fid,$selected,$dirty),$session);$retained=ownSuccessfulSpecialist($stateHtml,'balance-local-specialist-back');$check($retained['revision_reason']===$dirty['revision_reason'],'actual PHP view and metadata preserve all dirty field characters after a navigation refusal');
$p=$dirty;$p['revision_reason']=['0','Übernahme'];$deny($p,422,'array replacing a specialist scalar refuses');
$p=$dirty;unset($p['balance_local_complete']);$deny($p,422,'missing final specialist footer refuses');
$p=$dirty;$p['csrf']='forged';$deny($p,403,'forged specialist csrf refuses');
$p=$dirty;$p['action']='balance-local-specialist-discard';$deny($p,422,'discard requires its actual express checkbox');
$p['balance_local_discard_confirmed']='1';$check(Form::handle($db,'env-board',$selected,$p,'own-specialist-csrf',$session)['navigate']===$query,'express complete discard only navigates');
$other=$selected;$other['balance_local_task']=str_repeat('a',24);$deny($dirty,409,'another task cannot replace the original selection',$other);
$token=$post['balance_local_token'];$session['balance_local_contexts'][$token]['issued']=time()-20000;$check(Form::handle($db,'env-board',$selected,$post,'own-specialist-csrf',$session)['navigate']===$query,'pure unchanged navigation remains available after write-source expiry');

$fragments='';foreach(['revision','adjust','capital','carry','partnership','own-intangible','pool-original','pension-offsets']as$operation)$fragments.='<details><summary>Own offered '.$operation.'</summary><form><input type="hidden" name="action" value="tax-filing-balance-'.$operation.'"><button>Own offered complete action</button></form></details>';
$render=static fn(string$html,array$route):string=>\Kulturhus\Verein\Web\BalanceFilingSpecialists::render($html,$route,[],static fn(string$source):string=>$source);
$pageOne=$render($fragments,$query);$pageTwo=$render($fragments,$query+['balance_local_page'=>'2']);
$parse=static function(string$html):DOMXPath{$d=new DOMDocument();$old=libxml_use_internal_errors(true);try{$d->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);return new DOMXPath($d);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}};
$firstPage=$parse($pageOne);$secondPage=$parse($pageTwo);
$check($firstPage->query('//a[@data-balance-specialist-open]')->length===6&&$secondPage->query('//a[@data-balance-specialist-open]')->length===2,'eight explicitly offered form fragments have bounded six and two item pages');
$identities=[];foreach([$firstPage,$secondPage]as$path)foreach($path->query('//a[@data-balance-specialist-open]')as$link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$choice);$identities[]=$choice['balance_local_task'];}
$check(count(array_unique($identities))===8,'all offered task identities remain unique across bounded pages');
$disabled='<form><input type="hidden" name="action" value="tax-filing-balance-basis-6b"><fieldset disabled><button>Own unavailable action</button></fieldset></form>';
$check($parse($render($fragments.$disabled,$query+['balance_local_page'=>'2']))->query('//a[@data-balance-specialist-open]')->length===2,'an actual disabled fieldset does not invent another offered task');
foreach([['balance_local_task'=>['0'], 'code'=>422],['balance_local_task'=>str_repeat('f',24),'code'=>404],['balance_local_page'=>'02','code'=>422]]as$bad){
 $route=$query;foreach($bad as$name=>$value)if($name!=='code')$route[$name]=$value;$denied=false;try{$render($fragments,$route);}catch(DomainException$e){$denied=$e->getCode()===$bad['code'];}$check($denied,'malformed or unavailable offered selection refuses without a business action');
}
$denied=false;try{$render($fragments.$fragments,$query);}catch(DomainException$e){$denied=$e->getCode()===503;}$check($denied,'duplicate original form identities require review instead of choosing silently');
$check(ownOriginalDatabaseHash($db,$tables)===$allBefore,'all whole original rows columns primary keys files and money remain exact');

 }finally{[$_GET,$_POST,$_SESSION,$method]=$globals;if($method===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$method;}
}
