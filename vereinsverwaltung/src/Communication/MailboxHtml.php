<?php declare(strict_types=1);
namespace Kulturhus\Verein\Communication;
use Kulturhus\Native\Communication\Mail\Mime;
use Kulturhus\Native\Web\Html;
/** A private passive document: email styles never enter the manager document. */
final class MailboxHtml
{
 public static function document(string $raw,bool $remote=false,?callable $attachedImage=null):string
 {
  try{$content=Mime::htmlMessage($raw);}catch(\DomainException){$content=['html'=>null,'images'=>[]];}$html=$content['html'];
  if($html===null){$plain=Mime::mailbox($raw);$html='<div style="white-space:pre-wrap">'.Html::e($content['text']??($plain['readable']?$plain['text']:'Der Mailtext kann nicht vollständig angezeigt werden.')).'</div>';}
  $dom=\Kulturhus\Native\Communication\Mail\HtmlDocument::parse($html);
  $xpath=new \DOMXPath($dom);
  foreach(iterator_to_array($xpath->query('//script|//iframe|//frame|//frameset|//object|//embed|//applet|//form|//input|//button|//textarea|//select|//base|//meta|//link|//svg|//math|//video|//audio|//source|//track|//template'))as$n)$n->parentNode?->removeChild($n);
  foreach($xpath->query('//*')as$n){
   foreach(iterator_to_array($n->attributes)as$a)if(str_starts_with(strtolower($a->name),'on')||in_array(strtolower($a->name),['srcdoc','srcset','action','formaction','ping','background','poster','autofocus'],true))$n->removeAttribute($a->name);
   if($n->tagName==='img'){
    $src=$n->getAttribute('src');$cid=str_starts_with(strtolower($src),'cid:')?rawurldecode(substr($src,4)):null;$safe=$cid!==null?($content['images'][$cid]??($attachedImage?$attachedImage($cid):'')):($remote?self::imageUrl($src):'');
    if($safe===''){$n->removeAttribute('src');$n->setAttribute('alt',$n->getAttribute('alt')?:'Externes Bild');}else{$n->setAttribute('src',$safe);$n->setAttribute('referrerpolicy','no-referrer');}
   }elseif($n->hasAttribute('src'))$n->removeAttribute('src');
   if($n->hasAttribute('href')){if($n->tagName!=='a'||!self::link($n->getAttribute('href')))$n->removeAttribute('href');else{$n->setAttribute('target','_blank');$n->setAttribute('rel','noopener noreferrer nofollow');}}
  }
  foreach($xpath->query('//style')as$n)$n->nodeValue=self::css($n->textContent);foreach($xpath->query('//*[@style]')as$n)$n->setAttribute('style',self::css($n->getAttribute('style')));
  $styles='';foreach($xpath->query('//head/style')as$n)$styles.=$dom->saveHTML($n);
  $body=$xpath->query('//body')->item(0);$bodyHtml='';if($body)foreach($body->childNodes as$n)$bodyHtml.=$dom->saveHTML($n);
  $bodyStyle=$body?->getAttribute('style')??'';$bg=$body?->getAttribute('bgcolor')??'';if(preg_match('/\A#[a-f0-9]{3}(?:[a-f0-9]{3})?\z/iD',$bg))$bodyStyle.=';background-color:'.$bg;
  return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>E-Mail</title><style>html{color-scheme:light}body{margin:12px;color:#172b25;background:#fff;font:16px/1.5 Arial,sans-serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}</style>'.$styles.'</head><body style="'.Html::e($bodyStyle).'">'.$bodyHtml.'</body></html>';
 }
 /** Preserve typed line breaks in both the displayed draft and the actual HTML MIME part. */
 public static function reply(string $html):string
 {
  $html=\Kulturhus\Native\Shared\RichText::sanitize($html);if(!str_contains($html,"\n")&&!str_contains($html,"\r"))return$html;
  $dom=new \DOMDocument();$old=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8" ?><html><body>'.$html.'</body></html>',LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
  foreach(iterator_to_array((new \DOMXPath($dom))->query('//body//text()'))as$n){$text=str_replace(["\r\n","\r"],"\n",$n->nodeValue);if(!str_contains($text,"\n")||trim($text)==='')continue;$fragment=$dom->createDocumentFragment();foreach(explode("\n",$text)as$i=>$line){if($i)$fragment->appendChild($dom->createElement('br'));$fragment->appendChild($dom->createTextNode($line));}$n->parentNode->replaceChild($fragment,$n);}
  $body=$dom->getElementsByTagName('body')->item(0);$out='';foreach($body->childNodes as$n)$out.=$dom->saveHTML($n);return \Kulturhus\Native\Shared\RichText::sanitize($out);
 }
 private static function css(string $css):string
 {
  // Mail tables and typography remain; CSS cannot fetch tracking pixels or fonts.
  return preg_replace(['/\@import\b[^;]*;?/i','/url\s*\([^)]*\)/i'],['','none'],$css)??'';
 }
 private static function link(string $url):bool
 {
  return strlen($url)<=4096&&!preg_match('/[\x00-\x20\x7f\\\\]/',$url)&&((str_starts_with($url,'https://')&&filter_var($url,FILTER_VALIDATE_URL)&&!isset(parse_url($url)['user'])&&!isset(parse_url($url)['pass']))||preg_match('/\Amailto:[^\s<>]+\z/D',$url));
 }
 private static function imageUrl(string $url):string
 {
  if(!str_starts_with($url,'https://')||!self::link($url))return'';$host=parse_url($url,PHP_URL_HOST);if(!$host||filter_var($host,FILTER_VALIDATE_IP)||!str_contains($host,'.')||preg_match('/(?:^|\.)(?:localhost|local|internal)\z/iD',$host))return'';return $url;
 }
}
