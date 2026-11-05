<?php declare(strict_types=1);
namespace Kulturhus\Verein\Communication;
use DomainException;
/** Exact private inbox context; no selected message or draft is substituted after rejection. */
final class MailboxForm
{
 public const ACTIONS=['mailbox-settings','mailbox-fetch','mailbox-change','mailbox-trash','mailbox-restore','mailbox-reply-save','mailbox-reply-save-send','mailbox-reply-send'];
 private const QUERY=['view','tab','mailbox_account','mailbox_state','mailbox_assigned','mailbox_page','mailbox_q','mail','reply','edit_reply','mailbox_settings','reply_page','history_page'];
 public static function text(mixed $value,int $limit=80000,bool $multiline=false):string
 {
  if(!is_string($value)||strlen($value)>$limit||!mb_check_encoding($value,'UTF-8')||preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Postfachangaben vollständig prüfen.',422);return$value;
 }
 public static function id(mixed $value,bool $empty=false):string
 {
  if($empty&&$value==='')return'';if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ursprüngliche Nachricht und Antwortkennung vollständig prüfen.',422);return$value;
 }
 public static function version(mixed $value,bool $zero=true):int
 {
  if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)||(int)$value>4294967294||(!$zero&&$value==='0'))throw new DomainException('Ursprüngliche Postfachfassung prüfen.',422);return(int)$value;
 }
 public static function query(array $query,bool $internal=false):array
 {
  if($internal&&!array_key_exists('view',$query)&&!array_key_exists('tab',$query))$query=['view'=>'messages','tab'=>'inbox']+$query;
  if(($query['view']??null)!=='messages'||($query['tab']??null)!=='inbox'||array_diff(array_keys($query),self::QUERY))throw new DomainException('Postfach im ursprünglich geöffneten Bereich prüfen.',422);
  Mailbox::selection($query);$query['mailbox_q']=Mailbox::searchQuery($query['mailbox_q']??'');
  $state=array_key_exists('mailbox_state',$query)?$query['mailbox_state']:'active';$assigned=array_key_exists('mailbox_assigned',$query)?$query['mailbox_assigned']:'all';
  if(!in_array($state,['active','archived','trashed','all'],true)||!in_array($assigned,['all','mine','unassigned'],true))throw new DomainException('Ursprüngliche Postfachfilter prüfen.',422);
  foreach(['mailbox_page','reply_page','history_page']as$key)if(array_key_exists($key,$query)){
   $page=$query[$key];if($internal&&is_int($page))$page=(string)$page;
   if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||(int)$page>1000000)throw new DomainException('Ursprüngliche Postfachseite prüfen.',422);$query[$key]=$page;
  }
  foreach(['mail','reply']as$key)if(array_key_exists($key,$query))self::id($query[$key],$key==='mail');
  foreach(['edit_reply','mailbox_settings']as$key)if(array_key_exists($key,$query)&&$query[$key]!=='1')throw new DomainException('Ursprüngliche Postfachansicht prüfen.',422);
  if((isset($query['reply'])||isset($query['reply_page'])||isset($query['history_page']))&&($query['mail']??'')==='')throw new DomainException('Die ursprüngliche Nachricht zuerst auswählen.',422);
  if(isset($query['edit_reply'])&&!isset($query['reply']))throw new DomainException('Die ursprüngliche Antwort zuerst auswählen.',422);
  return$query+['mailbox_account'=>'board','mailbox_state'=>$state,'mailbox_assigned'=>$assigned,'mailbox_page'=>'1'];
 }
 public static function command(?array $query,#[\SensitiveParameter] array $post):array
 {
  $action=$post['action']??null;if(!in_array($action,self::ACTIONS,true))throw new DomainException('Unbekannte ursprüngliche Postfachaktion.',422);
  $shared=['action','csrf','mailbox_account','mailbox_state','mailbox_assigned','mailbox_page','mailbox_q'];
  $fields=match($action){'mailbox-settings'=>['mailbox','mailbox_version'],'mailbox-fetch'=>[], 'mailbox-change'=>['mail','mail_version','assigned_to','message_state'],'mailbox-trash','mailbox-restore'=>['mail','mail_version'],'mailbox-reply-save','mailbox-reply-save-send'=>['mail','reply_id','reply_version','reply_text','reply_html'],'mailbox-reply-send'=>['mail','reply_id','reply_version','confirm_send']};
  if(array_diff(array_keys($post),[...$shared,...$fields]))throw new DomainException('Postfachformular vollständig am ursprünglichen Stand prüfen.',422);
  foreach(['mailbox_state','mailbox_assigned','mailbox_page']as$key)if(!array_key_exists($key,$post))throw new DomainException('Postfachformular vollständig am ursprünglichen Stand prüfen.',422);
  $context=self::query(['view'=>'messages','tab'=>'inbox']+array_intersect_key($post,array_flip(['mailbox_account','mailbox_state','mailbox_assigned','mailbox_page','mailbox_q'])));
  $source=$query!==null?self::query($query):$context;
  foreach(['mailbox_account','mailbox_state','mailbox_assigned','mailbox_page','mailbox_q']as$key)if($context[$key]!==$source[$key])throw new DomainException('Postfachformular gehört nicht zur ursprünglichen Auswahl.',409);
  if($action==='mailbox-settings'){
   self::version($post['mailbox_version']??null);$data=$post['mailbox']??null;
   if(!is_array($data)||array_diff(array_keys($data),['enabled','email','imap_host','imap_user','imap_password','clear_password','folder','since']))throw new DomainException('Ursprüngliche Postfachkonfiguration vollständig prüfen.',422);
   foreach(['email','imap_host','imap_user','folder','since']as$key)self::text($data[$key]??null);
   if(array_key_exists('imap_password',$data))self::text($data['imap_password'],16384);
   if(array_key_exists('enabled',$data)&&$data['enabled']!=='1')throw new DomainException('Empfang ausdrücklich ein- oder ausschalten.',422);
   if(array_key_exists('clear_password',$data)&&$data['clear_password']!=='1')throw new DomainException('Passwortentfernung ausdrücklich auswählen.',422);
  }elseif($action!=='mailbox-fetch'){
   $id=self::id($post['mail']??null);if($query!==null&&($source['mail']??null)!==$id)throw new DomainException('Postfachformular gehört nicht zur ursprünglichen Nachricht.',409);
   if(in_array($action,['mailbox-trash','mailbox-restore'],true))self::version($post['mail_version']??null,false);
   elseif($action==='mailbox-change'){self::version($post['mail_version']??null,false);self::text($post['assigned_to']??null,64);self::text($post['message_state']??null,32);}
   else{
    $reply=self::id($post['reply_id']??null);$version=self::version($post['reply_version']??null,in_array($action,['mailbox-reply-save','mailbox-reply-save-send'],true));
    if($query!==null&&($action==='mailbox-reply-send'||$version>0)&&(($source['reply']??null)!==$reply||(in_array($action,['mailbox-reply-save','mailbox-reply-save-send'],true)&&($source['edit_reply']??null)!=='1')))throw new DomainException('Postfachformular gehört nicht zur ursprünglichen Antwort.',409);
    if(in_array($action,['mailbox-reply-save','mailbox-reply-save-send'],true)){self::text($post['reply_text']??null,80000,true);if(array_key_exists('reply_html',$post))self::text($post['reply_html'],262144,true);}
    elseif(array_key_exists('confirm_send',$post)&&$post['confirm_send']!=='1')throw new DomainException('Die genaue Antwort und Empfängeradresse bewusst bestätigen.',422);
   }
  }
  return$source;
 }
 public static function download(array $query):string
 {
  if(!array_key_exists('mailbox_original',$query))throw new DomainException('Ursprüngliche Originalnachricht auswählen.',422);$id=self::id($query['mailbox_original']);unset($query['mailbox_original']);$source=self::query($query);
  if(($source['mail']??$id)!==$id)throw new DomainException('Originaldownload gehört nicht zur ursprünglichen Nachricht.',409);return$id;
 }
 public static function html(array $query):array
 {
  if(!array_key_exists('mailbox_html',$query))throw new DomainException('Ursprüngliche Nachricht auswählen.',422);$id=self::id($query['mailbox_html']);unset($query['mailbox_html']);$images=$query['mailbox_images']??null;unset($query['mailbox_images']);if($images!==null&&$images!=='1')throw new DomainException('Bilderansicht prüfen.',422);$source=self::query($query);if(($source['mail']??null)!==$id)throw new DomainException('HTML-Ansicht gehört nicht zur ausgewählten Nachricht.',409);return ['id'=>$id,'images'=>$images==='1'];
 }
 public static function attachment(array $query):array
 {
  $part=$query['mailbox_attachment']??null;$inline=$query['mailbox_inline']??null;unset($query['mailbox_attachment'],$query['mailbox_inline']);
  if(!is_string($part)||!preg_match('/\A[1-9][0-9]{0,2}\z/D',$part)||(int)$part>256||($inline!==null&&$inline!=='1'))throw new DomainException('Anhang auswählen.',422);
  $source=self::query($query);$id=self::id($source['mail']??null);return ['id'=>$id,'part'=>(int)$part,'inline'=>$inline==='1'];
 }
 public static function originals(array $query,#[\SensitiveParameter] array $post):string
 {
  $encode=static function(mixed$value)use(&$encode):array{
   if(is_string($value))return mb_check_encoding($value,'UTF-8')?['type'=>'text','value'=>$value]:['type'=>'bytes','base64'=>base64_encode($value)];
   if(is_array($value)){$entries=[];foreach($value as$key=>$item){if(is_string($key)&&$key!=='clear_password'&&preg_match('/csrf|password|secret|token|factor_code|recovery_code/i',$key))continue;$entries[]=['key'=>$encode($key),'value'=>$encode($item)];}return['type'=>'array','entries'=>$entries];}
   return['type'=>get_debug_type($value),'value'=>is_scalar($value)||$value===null?$value:null];
  };
  return json_encode(['query'=>$encode($query),'form'=>$encode($post)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }
}
