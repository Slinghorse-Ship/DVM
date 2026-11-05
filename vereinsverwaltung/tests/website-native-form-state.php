<?php declare(strict_types=1);
require __DIR__.'/../src/Web/View.php';
set_error_handler(static function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
$checks=0;
function check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function renderNative(string $tab,array $post=[],array $override=[]):DOMXPath{
 $_GET=['item'=>'10'];$_POST=$post;$_SESSION=['csrf'=>'synthetic'];$websiteTab=$tab;$websiteAreas=[$tab=>['Synthetic']];
 $item=['id'=>10,'title'=>'Synthetic article','version'=>'current-version','summary'=>'Saved summary','body'=>'Saved body','image_rights'=>'','published'=>true,'publish_at'=>0,'workflow_status'=>'draft','channels'=>'website,rss','template'=>'news','images'=>[
 ['filename'=>'first.jpg','url'=>'/first.jpg','description'=>'First saved','tags'=>'first','field'=>'image'],
 ['filename'=>'second.jpg','url'=>'/second.jpg','description'=>'Second saved','tags'=>'second','field'=>'image']]];
 if($tab==='recipients')$item=[];
 if($tab==='social')$item+=['facebook_event_url'=>'https://example.invalid/','facebook_message'=>'Saved social','instagram_caption'=>'Saved caption'];
 $native=array_replace(['item'=>$item,'mail_allowed'=>false,'external_allowed'=>false,'rows'=>[],'pages'=>1],$override);
 $website=new class($native){function __construct(private array $data){}function editorialRead(...$args):array{return $this->data;}function canWrite(...$args):bool{return true;}};
 ob_start();require __DIR__.'/../views/Website/website-native.php';$html=(string)ob_get_clean();$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);return new DOMXPath($dom);
}
function posted(string $op,array $fields):array{return ['action'=>'website-native','operation'=>$op,'version'=>'submitted-version','request_key'=>'submitted-key','fields'=>$fields];}
$x=renderNative('news',posted('news/save',['id'=>'10','workflow_status'=>'review','channels'=>['rss'],'summary'=>'Unsaved correction']));
check($x->query('//select[@name="fields[workflow_status]"]/option[@value="review"][@selected]')->length===1,'failed article save retains selected workflow');
check($x->query('//input[@name="fields[channels][]"][@value="website"][@checked]')->length===0&&$x->query('//input[@name="fields[channels][]"][@value="rss"][@checked]')->length===1,'failed article save retains channel choices');
check($x->query('//form[input[@value="news/save"]]/input[@name="version"]')->item(0)->getAttribute('value')==='submitted-version','stale article form keeps original version');
$x=renderNative('news',posted('news/save',['id'=>'99','workflow_status'=>'review','channels'=>[]]));
check($x->query('//select[@name="fields[workflow_status]"]/option[@value="draft"][@selected]')->length===1,'other article does not leak draft state');
$x=renderNative('news',posted('image/save',['id'=>'10','field'=>'image','filename'=>'first.jpg','description'=>'Corrected first','tags'=>'draft','direction'=>'down']));
check($x->query('//article[@data-image-name="first.jpg"]//input[@name="fields[description]"]')->item(0)->getAttribute('value')==='Corrected first','selected image keeps correction');
check($x->query('//article[@data-image-name="second.jpg"]//input[@name="fields[description]"]')->item(0)->getAttribute('value')==='Second saved','another image does not inherit correction');
check($x->query('//article[@data-image-name="first.jpg"]//option[@value="down"][@selected]')->length===1,'image move choice survives failed save');
$x=renderNative('recipients',posted('recipient/paper',['newsletter_topics'=>['events']]));
check($x->query('//input[@name="fields[newsletter_topics][]"][@value="events"][@checked]')->length===1&&$x->query('//input[@name="fields[newsletter_topics][]"][@value="news"][@checked]')->length===0,'consent topics are not silently broadened after error');
$x=renderNative('recipients',posted('recipient/paper',[]));
check($x->query('//input[@name="fields[newsletter_topics][]"][@checked]')->length===0,'empty consent choices remain empty after error');
$x=renderNative('social',posted('social/draft',['event_id'=>'99','facebook_message'=>'Other event draft']));
check($x->query('//textarea[@name="fields[facebook_message]"]')->item(0)->textContent==='Saved social','other event cannot supply social draft');
$x=renderNative('recipients',['action'=>'website-native','operation'=>'recipient/paper','fields'=>'invalid']);
check($x->query('//input[@name="fields[newsletter_topics][]"][@checked]')->length===0,'invalid form container renders without inventing consent');
echo "$checks website native form checks passed; rendering only, no API or mail\n";
if($fixture=getenv('WEBSITE_STATE_FIXTURE')){
 $x=renderNative('news',posted('news/save',['id'=>'10','workflow_status'=>'review','channels'=>['rss'],'summary'=>'Unsaved correction']));
 file_put_contents($fixture,$x->document->saveHTML());
}
