<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use DOMDocument;
use DOMNode;

/** Exact minutes source and lossless progressive editing, independent of current stored defaults. */
final class MinutesForm
{
 public const ACTIONS=['minutes-save','minutes-transition'];
 public const TRANSITIONS=['publish','withdraw','archive','restore'];
 public static function text(mixed $value,bool $multiline=false):string
 {
  if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Protokollangaben vollständig prüfen.',422);
  return$value;
 }
 public static function id(mixed $value,bool $empty=false):string
 {
  if($empty&&$value==='')return'';
  if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Protokollkennung vollständig prüfen.',422);
  return$value;
 }
 public static function version(mixed $value,bool $zero=false):int
 {
  if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)||(int)$value>2147483646||(!$zero&&$value==='0'))throw new DomainException('Ursprüngliche Protokollfassung prüfen.',422);
  return(int)$value;
 }
 public static function query(array $query):array
 {
  if(($query['view']??null)!=='minutes'||array_diff(array_keys($query),['view','tab','mid','new','compose','state','q','year','page','created_request']))throw new DomainException('Protokolle im ursprünglich geöffneten Bereich prüfen.',422);
  if(array_key_exists('tab',$query)&&$query['tab']!=='')throw new DomainException('Ursprünglichen Protokollbereich prüfen.',422);
  foreach(['mid','created_request']as$key)if(array_key_exists($key,$query))self::id($query[$key],$key==='mid');
  foreach(['new','compose']as$key)if(array_key_exists($key,$query)&&$query[$key]!=='1')throw new DomainException('Ursprüngliche Protokollansicht prüfen.',422);
  foreach(['q','year','state']as$key)if(array_key_exists($key,$query))self::text($query[$key]);
  if(strlen($query['q']??'')>150)throw new DomainException('Bitte einen kürzeren Protokoll-Suchtext eingeben.',422);
  if(($query['year']??'')!==''&&!preg_match('/\A[0-9]{4}\z/D',$query['year']))throw new DomainException('Ursprüngliches Protokolljahr prüfen.',422);
  if(array_key_exists('state',$query)&&!in_array($query['state'],['published','draft','archived','all'],true))throw new DomainException('Ursprünglichen Protokollstatus prüfen.',422);
  $page=array_key_exists('page',$query)?$query['page']:'1';
  if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)throw new DomainException('Ursprüngliche Protokollseite prüfen.',422);
  if(isset($query['created_request'])&&(($query['mid']??'')!==''||isset($query['new'])||isset($query['compose'])||array_intersect(['state','q','year','page'],array_keys($query))))throw new DomainException('Ursprünglichen Speichervorgang getrennt prüfen.',422);
  if(isset($query['new'])&&($query['mid']??'')!=='')throw new DomainException('Neues Protokoll ohne bestehende Protokollkennung öffnen.',422);
  return$query+['mid'=>'','q'=>'','year'=>'','state'=>'published','page'=>$page];
 }
 public static function command(array $query,#[\SensitiveParameter] array $post):array
 {
  $source=self::query($query);$action=$post['action']??null;
  if(!in_array($action,self::ACTIONS,true)||isset($source['created_request']))throw new DomainException('Protokollformular am ursprünglich geöffneten Vorgang prüfen.',422);
  $common=['action','csrf','mid','version'];
  $allowed=$action==='minutes-save'?['title','meeting_on','source_kind','body_html','body_text','body_mode','request_key','continue_edit','upload_required','pdf_existing','retry_original','retry_confirm','live_uploads']:['transition','confirm_transition'];
  if(array_diff(array_keys($post),[...$common,...$allowed]))throw new DomainException('Protokollformular vollständig am ursprünglichen Stand prüfen.',422);
  $id=self::id($post['mid']??null,true);
  if($id!==$source['mid'])throw new DomainException('Protokollformular gehört nicht zum ursprünglich geöffneten Protokoll.',409);
  $version=self::version($post['version']??null,$action==='minutes-save'&&$id==='');
  if($id===''&&($action!=='minutes-save'||$version!==0||(!isset($source['new'])&&!isset($source['compose']))))throw new DomainException('Neues Protokoll im ursprünglichen Anlageformular prüfen.',409);
  if($action==='minutes-transition'){
   if(isset($source['new'])||isset($source['compose'])||!in_array($post['transition']??null,self::TRANSITIONS,true))throw new DomainException('Ursprüngliche Protokollaktion prüfen.',422);
   if(array_key_exists('confirm_transition',$post)&&$post['confirm_transition']!=='1')throw new DomainException('Die ursprüngliche Protokollaktion ausdrücklich bestätigen.',422);
   return['id'=>$id,'version'=>$version,'transition'=>$post['transition'],'confirmed'=>($post['confirm_transition']??null)==='1'];
  }
  if(!isset($source['new'])&&!isset($source['compose']))throw new DomainException('Protokolländerung im ursprünglichen Bearbeitungsformular prüfen.',409);
  self::id($post['request_key']??null);foreach(['title','meeting_on','source_kind']as$key)self::text($post[$key]??null);
  self::text($post['body_html']??null,true);
  $mode=$post['body_mode']??null;if(!in_array($mode,['plain','html'],true))throw new DomainException('Ursprüngliche Protokolltext-Bearbeitung prüfen.',422);
  if($mode==='plain')self::text($post['body_text']??null,true);elseif(array_key_exists('body_text',$post))self::text($post['body_text'],true);
  if(array_key_exists('continue_edit',$post)&&!in_array($post['continue_edit'],['0','1'],true))throw new DomainException('Weiterschreiben oder Vorschau bewusst wählen.',422);
  foreach(['upload_required','pdf_existing','retry_original','retry_confirm']as$key)if(array_key_exists($key,$post)&&$post[$key]!=='1')throw new DomainException('Die ursprüngliche PDF-Auswahl vollständig prüfen.',422);
  if(array_key_exists('live_uploads',$post)){
   $uploads=$post['live_uploads'];$tokens=is_array($uploads)?($uploads['minute_pdf']??null):null;
   if(!is_array($uploads)||array_keys($uploads)!==['minute_pdf']||!is_array($tokens)||!array_is_list($tokens)||count($tokens)!==1||!is_string($tokens[0])||!preg_match('/\A[a-f0-9]{64}\z/D',$tokens[0]))throw new DomainException('Die ursprüngliche PDF-Auswahl vollständig prüfen.',422);
  }
  return['id'=>$id,'version'=>$version,'request_key'=>$post['request_key'],'continue_edit'=>($post['continue_edit']??null)==='1','upload_required'=>($post['upload_required']??null)==='1','pdf_existing'=>($post['pdf_existing']??null)==='1','retry_original'=>($post['retry_original']??null)==='1','retry_confirmed'=>($post['retry_confirm']??null)==='1','data'=>['title'=>$post['title'],'meeting_on'=>$post['meeting_on'],'source_kind'=>$post['source_kind'],'body_html'=>$post['source_kind']==='pdf'?'':($mode==='html'?$post['body_html']:self::fromPlain($post['body_text'],$post['body_html']))]];
 }
 public static function download(array $query):string
 {
  $id=self::id($query['minute_file']??null);unset($query['minute_file']);$source=self::query($query);
  if(isset($source['created_request'])||isset($source['compose'])||isset($source['new'])||($source['mid']!==''&&$source['mid']!==$id))throw new DomainException('PDF gehört nicht zum ursprünglich geöffneten Protokoll.',409);
  return$id;
 }
 public static function plain(string $html):string
 {
  self::text($html,true);if(strlen($html)>100000)throw new DomainException('Protokolltext ist ungültig oder zu lang.',422);
  $dom=new DOMDocument('1.0','UTF-8');$dom->resolveExternals=false;$dom->substituteEntities=false;$previous=libxml_use_internal_errors(true);
  try{$ok=$dom->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
  if(!$ok)throw new DomainException('Protokolltext konnte nicht gelesen werden.',422);
  $drop=['script','style','iframe','object','embed','svg','math','form','input','img','video','audio','link','meta','template','noscript','base','textarea','select','option','button','frame','frameset','canvas','source','picture'];
  $walk=static function(DOMNode $node)use(&$walk,$drop):string{
   if($node->nodeType===XML_TEXT_NODE)return$node->nodeValue??'';
   if($node->nodeType!==XML_ELEMENT_NODE||in_array(strtolower($node->nodeName),$drop,true))return'';
   $tag=strtolower($node->nodeName);if($tag==='br')return"\n";$text='';foreach($node->childNodes as$child)$text.=$walk($child);
   return in_array($tag,['p','div','h1','h2','h3','h4','h5','h6','li','blockquote','ul','ol','hr'],true)?"\n".$text."\n":$text;
  };
  $out='';$body=$dom->getElementsByTagName('body')->item(0);if($body)foreach($body->childNodes as$node)$out.=$walk($node);
  return trim(str_replace(["\r\n","\r"],"\n",$out),"\n");
 }
 public static function fromPlain(string $text,string $originalHtml):string
 {
  self::text($text,true);$text=str_replace(["\r\n","\r"],"\n",$text);
  if($text===self::plain($originalHtml))return$originalHtml;
  if($text==='')return'';
  return'<p>'.str_replace("\n",'<br>',htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>';
 }
}
