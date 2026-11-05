<?php declare(strict_types=1);
namespace Kulturhus\Verein\Communication;
use DomainException;
use Kulturhus\Verein\BoardAccess;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Delivery\DeliveryNotStarted;
use Kulturhus\Native\Language\MailboxMessages;
/** Private ordinary forms. Originals and command metadata remain bound to the opened context. */
final class MailboxPage
{
 private Mailbox $mailbox;
 private array $sourceQuery=[];
 private bool $blocked=false;
 public function __construct(private \PDO $db,private string $actor,private string $csrf,?Mailbox $mailbox=null,private array $messages=[]){$this->mailbox=$mailbox??new Mailbox($db,$actor,mailbox:Mailbox::selection($_GET));}
 private function t(string $source,array $values=[]):string{return MailboxMessages::text($source,$this->messages,$values);}
 private static function text(array $input,string $key,string $default='',int $limit=80000):string{return MailboxForm::text(array_key_exists($key,$input)?$input[$key]:$default,$limit,$key==='reply_text');}
 private static function version(array $input,string $key):int{return MailboxForm::version($input[$key]??null);}
 private static function context(array $input):array
 {
  $q=MailboxForm::query(['view'=>'messages','tab'=>'inbox']+array_intersect_key($input,array_flip(['mailbox_account','mailbox_state','mailbox_assigned','mailbox_page','mailbox_q'])));
  return['mailbox_account'=>$q['mailbox_account'],'mailbox_state'=>$q['mailbox_state'],'mailbox_assigned'=>$q['mailbox_assigned'],'mailbox_page'=>Mailbox::page($q['mailbox_page']),'mailbox_q'=>$q['mailbox_q']];
 }
 public function action(#[\SensitiveParameter] array $post,?array $query=null):array
 {
  if(!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new DomainException('Formular abgelaufen. Bitte Seite neu laden.',403);
  $source=MailboxForm::command($query,$post);BoardAccess::require($this->db,$this->actor);
  if(($source['mail']??'')!==''){
   $selected=$this->mailbox->get($source['mail']);
   if(isset($source['reply'])&&$this->mailbox->reply($source['reply'])['message_id']!==$selected['id'])throw new DomainException('Antwort gehört zu einer anderen Nachricht.',409);
  }
  if($this->mailbox->selected()!==$source['mailbox_account'])throw new DomainException('Postfachformular gehört nicht zur ursprünglichen Auswahl.',409);$context=self::context($post);$operation=$post['action'];$id=$query!==null?($source['mail']??''):self::text($post,'mail','',32);
  if($operation==='mailbox-settings'){
   if(!BoardAccess::superuser($this->db,$this->actor))throw new DomainException('Postfachkonfiguration benötigt aktuelle Superuserrechte.',403);
   $this->mailbox->saveSettings($post['mailbox'],self::version($post,'mailbox_version'));return$context+['mailbox_settings'=>'1','notice'=>$this->t('Postfach gespeichert.')];
  }
  if($operation==='mailbox-fetch'){$result=$this->mailbox->fetch();return$context+['mail'=>$id,'notice'=>$this->t('{new} neue Nachrichten übernommen; {existing} unveränderte Originale bereits vorhanden.',$result)];}
  $id=$post['mail'];
  if(in_array($operation,['mailbox-trash','mailbox-restore'],true)){$original=$this->mailbox->get($id);if($operation==='mailbox-restore'&&$original['state']!=='trashed')throw new MailboxConflict('Die Nachricht ist nicht mehr im Papierkorb. Aktuellen Stand öffnen.');$this->mailbox->change($id,self::version($post,'mail_version'),$original['assigned_to']??'',$operation==='mailbox-trash'?'trashed':'active');return$context+['mail'=>$id,'notice'=>$this->t($operation==='mailbox-trash'?'Mail in den Papierkorb verschoben.':'Mail wiederhergestellt.')];}
  if($operation==='mailbox-change'){$this->mailbox->change($id,self::version($post,'mail_version'),$post['assigned_to'],$post['message_state']);return$context+['mail'=>$id,'notice'=>$this->t('Zuständigkeit und Ablage gespeichert.')];}
  if(in_array($operation,['mailbox-reply-save','mailbox-reply-save-send'],true)){
   $reply=$this->mailbox->saveReply($id,$post['reply_id'],self::version($post,'reply_version'),$post['reply_text'],$post['reply_html']??null);
   if($operation==='mailbox-reply-save-send'){
    try{$reply=$this->mailbox->send($reply['id'],(int)$reply['version'],true);}catch(DeliveryNotStarted $error){throw new DomainException(DeliveryNotStarted::LABELS[$error->stage].' · Die Antwort bleibt gespeichert.',422,$error);}
    return$context+['mail'=>$id,'reply'=>$reply['id'],'notice'=>$this->replyState($reply['state'])];
   }
   return$context+['mail'=>$id,'reply'=>$reply['id'],'notice'=>$this->t('Antwort gespeichert. Die genaue Fassung vor dem Senden prüfen.')];
  }
  $reply=$this->mailbox->reply($post['reply_id']);if($reply['message_id']!==$id)throw new DomainException('Antwort gehört zu einer anderen Nachricht.',409);
  try{$reply=$this->mailbox->send($reply['id'],self::version($post,'reply_version'),($post['confirm_send']??null)==='1');}
  catch(DeliveryNotStarted $error){throw new DomainException(DeliveryNotStarted::LABELS[$error->stage].' · Die Antwort bleibt gespeichert.',422,$error);}
  return$context+['mail'=>$id,'reply'=>$reply['id'],'notice'=>$this->replyState($reply['state'])];
 }
 private static function url(array $query):string{return'?'.http_build_query(['view'=>'messages','tab'=>'inbox']+$query,'','&',PHP_QUERY_RFC3986);}
 private function form(string $action,array $values,array $context):string
 {
  $h='<form method="post" action="'.Html::e(self::url($this->sourceQuery)).'">'.Html::hidden('csrf',$this->csrf).Html::hidden('action',$action);
  foreach($values+$context as$key=>$value)$h.=Html::hidden($key,(string)$value);return$h;
 }
 private function stamp(string $value):string
 {
  try{return(new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');}catch(\Throwable){return$this->t('Zeitpunkt nicht verfügbar');}
 }
 private function replyState(string $state):string{return$this->t(['draft'=>'Entwurf','blocked'=>'Nicht gesendet','sending'=>'Wird gesendet','uncertain'=>'','accepted'=>'Gesendet'][$state]??'');}
 private function incoming(array $message,array $context,bool $enabled):string
 {
  $e=Html::e(...);$id=$message['id'];$envelope=$message['envelope'];$selected=['mail'=>$id]+$context;
  $h='<article class="mailbox-bubble mailbox-incoming" data-mailbox-message data-mailbox-message-id="'.$e($id).'"><p class="mailbox-meta"><strong>'.$e($envelope['from']).'</strong> · '.$e($this->t('Empfangen')).' · '.$e($this->stamp($message['received_at'])).'</p><iframe class="mailbox-html-message" data-mailbox-html name="mailbox-html-'.$e($id).'" title="'.$e($this->t('E-Mail in HTML-Ansicht')).'" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" src="'.$e(self::url(['mailbox_html'=>$id]+$selected)).'"></iframe><p class="mailbox-image-choice"><a href="'.$e(self::url(['mailbox_html'=>$id,'mailbox_images'=>'1']+$selected)).'" target="mailbox-html-'.$e($id).'">'.$e($this->t('Externe Bilder anzeigen')).'</a></p><details><summary>'.$e($this->t('Textversion anzeigen')).'</summary><div class="mailbox-original-text">'.$e($envelope['readable']?$envelope['text']:$this->t('Der Mailtext kann hier nicht vollständig angezeigt werden. Bitte das vollständige Original öffnen.')).'</div></details>';
  try{$files=$this->mailbox->attachments($id);}catch(DomainException){$files=[];}
  if($files){$h.='<section class="mailbox-attachments" aria-label="'.$e($this->t('Anhänge')).'"><h3>'.$e($this->t('Anhänge')).'</h3><ul>';foreach($files as$file){$download=self::url(['mailbox_attachment'=>(string)$file['part']]+$selected);$h.='<li><a class="mailbox-attachment-download" download="'.$e($file['name']).'" href="'.$e($download).'">'.$e($file['name']).'</a><span class="mailbox-meta">'.number_format($file['size']/1024,1,',','.').' KB</span>';if($file['preview']!==''&&!$file['embedded'])$h.='<img class="mailbox-attachment-preview" loading="lazy" src="'.$e(self::url(['mailbox_inline'=>'1','mailbox_attachment'=>(string)$file['part']]+$selected)).'" alt="'.$e($file['name']).'">';$h.='</li>';}$h.='</ul></section>';}
  if($enabled&&in_array($message['state'],['active','archived'],true)&&$envelope['reply_to']!=='')$h.='<p><a class="btn btn-outline theme-secondary" href="'.$e(self::url($selected)).'#mailbox-reply">'.$e($this->t('Auf diese Mail antworten')).'</a></p>';
  return $h.'<p><a class="btn btn-outline theme-secondary" download="nachricht-original.eml" href="'.$e(self::url(['mailbox_original'=>$id]+$selected)).'">'.$e($this->t('Original mit Anhängen herunterladen (.eml)')).'</a></p></article>';
 }
 private function pages(array $page,string $field,array $context):string
 {
  if($page['pages']<=1)return'';$e=Html::e(...);$h='<nav aria-label="'.$e($this->t('Weitere Postfacheinträge')).'">';
  foreach([-1=>'Zurück',1=>'Weiter']as$direction=>$label){$next=$page['page']+$direction;if($next>=1&&$next<=$page['pages'])$h.='<a class="btn btn-outline theme-secondary" href="'.$e(self::url([$field=>$next]+$context)).'">'.$e($this->t($label)).'</a> ';}
  $h.='<form method="get">'.Html::hidden('view','messages').Html::hidden('tab','inbox');foreach($context as$key=>$value)if($key!==$field)$h.=Html::hidden($key,(string)$value);
  return$h.Html::input($field,$this->t('Seite'),(string)$page['page'],'number','min="1" max="'.$page['pages'].'" required').'<button class="btn btn-outline theme-secondary">'.$e($this->t('Seite öffnen')).'</button></form></nav>';
 }
 private function originalPanel(array $query,#[\SensitiveParameter] array $post,bool $open=false):string
 {
  // Keep rejected context for diagnostics without putting technical metadata into the workflow.
  $json=str_replace(['<','>','&'],['\\u003c','\\u003e','\\u0026'],MailboxForm::originals($query,$post));$out='<script type="application/json" data-mailbox-preserved-input>'.$json.'</script>';
  if(in_array($post['action']??null,['mailbox-reply-save','mailbox-reply-save-send'],true)&&is_string($post['reply_text']??null))$out.='<label class="mailbox-failed-reply">'.Html::e($this->t('Dein Antworttext')).'<textarea class="form-control" rows="5" readonly>'.Html::e($post['reply_text']).'</textarea></label>';
  return$out;
 }
 private function reviewLink(array $query,#[\SensitiveParameter] array $post=[]):string
 {
  $mail=$query['mail']??null;
  if(!is_string($mail)||!preg_match('/\A[a-f0-9]{32}\z/D',$mail))return'<a class="btn btn-outline" href="'.Html::e(self::url([])).'">'.Html::e($this->t('Postfach öffnen')).'</a>';
  $target=['mail'=>$mail];$reply=$query['reply']??($post['reply_id']??null);
  if(is_string($reply)&&preg_match('/\A[a-f0-9]{32}\z/D',$reply)&&(!isset($post['mail'])||$post['mail']===$mail))$target['reply']=$reply;
  return'<a class="btn btn-outline" href="'.Html::e(self::url($target)).'">'.Html::e($this->t('Nachricht öffnen')).'</a>';
 }
 public function render(array $query,#[\SensitiveParameter] array $post=[]):string
 {
  BoardAccess::require($this->db,$this->actor);$e=Html::e(...);$start='<link rel="stylesheet" href="mailbox-workflows.css"><script src="mailbox-client.js" defer></script><section data-mailbox-workspace>';
  try{
   $this->sourceQuery=MailboxForm::query($query,!array_key_exists('view',$query)&&!array_key_exists('tab',$query));
   if($post!==[])MailboxForm::command($this->sourceQuery,$post);
   $this->blocked=$post!==[]&&in_array(http_response_code(),[403,404,409,500,503],true);
   if($this->sourceQuery['mailbox_account']!==$this->mailbox->selected())throw new DomainException('Postfachformular gehört nicht zur ursprünglichen Auswahl.',409);$body=$this->renderContent($this->sourceQuery,$post);$notice='';
   if($post!==[]&&http_response_code()>=400)$notice=$this->originalPanel($query,$post);
   if($this->blocked)$notice='<p role="status">'.$e($this->t('Bitte öffne die Nachricht erneut.')).'</p>'.$this->reviewLink($query,$post).$notice;
   return$start.$notice.($this->blocked?'<fieldset disabled data-mailbox-blocked>':'').$body.($this->blocked?'</fieldset>':'').'</section>';
  }catch(DomainException $error){
   if(http_response_code()<400)http_response_code($error instanceof MailboxConflict?409:(in_array($error->getCode(),[403,404,409,422,503],true)?$error->getCode():422));
   return$start.'<div class="content"><h1>'.$e($this->t('Postfach nicht verfügbar')).'</h1><p role="alert">'.$e(MailboxMessages::notice($error->getMessage(),$this->messages)).'</p>'.$this->originalPanel($query,$post,true).$this->reviewLink($query,$post).'</div></section>';
  }
 }
 private function renderContent(array $query,#[\SensitiveParameter] array $post):string
 {
  $e=Html::e(...);$context=self::context($query);$settings=$this->mailbox->settings();$data=$settings['data'];$people=array_column($this->mailbox->people(),'name','id');$counts=$this->mailbox->counts($context['mailbox_assigned']);$id=self::text($query,'mail','',32);
  $h='<div class="page-heading"><div><h1>'.$e($this->t('Postfach')).' · '.$e($this->t(Mailbox::ACCOUNTS[$this->mailbox->selected()])).'</h1><p>'.$e($this->t('Nachrichten und Antworten gemeinsam im Gespräch verfolgen.')).'</p></div><a class="btn btn-outline theme-secondary" href="?view=messages">'.$e($this->t('Sendungen & Postausgang')).'</a></div><div class="content" data-mailbox>';
  $h.='<nav class="workspace-tabs" aria-label="'.$e($this->t('Gemeinsame Postfächer')).'">';foreach(Mailbox::ACCOUNTS as$key=>$label)$h.='<a class="btn btn-outline theme-secondary"'.($key===$this->mailbox->selected()?' aria-current="page"':'').' href="'.$e(self::url(['mailbox_account'=>$key])).'">'.$e($this->t($label)).'</a>';$h.='</nav><nav class="workspace-tabs mailbox-folders" aria-label="'.$e($this->t('Postfachordner')).'">';foreach(['active'=>'Posteingang','archived'=>'Ablage','trashed'=>'Papierkorb']as$state=>$label)$h.='<a class="btn btn-outline theme-secondary"'.($state===$context['mailbox_state']?' aria-current="page"':'').' href="'.$e(self::url(array_replace($context,['mailbox_state'=>$state,'mailbox_page'=>'1']))).'">'.$e($this->t($label)).'<span class="mailbox-folder-count" aria-hidden="true">'.$counts[$state].'</span></a>';$h.='</nav>';if($context['mailbox_state']==='trashed')$h.='<p class="mailbox-meta">'.$e($this->t('Der Papierkorb gilt im Vereinsmanager. Die Originale im E-Mail-Konto bleiben erhalten.')).'</p>';
  if($data['enabled'])$h.=$this->form('mailbox-fetch',[],$context).'<button class="btn btn-outline theme-secondary mailbox-fetch">'.$e($this->t('Nachrichten abrufen')).'</button></form>';
  $h.='<form method="get" class="mailbox-search" role="search">'.Html::hidden('view','messages').Html::hidden('tab','inbox').Html::hidden('mailbox_account',$context['mailbox_account']).Html::hidden('mailbox_state',$context['mailbox_state']).Html::hidden('mailbox_assigned',$context['mailbox_assigned']).Html::input('mailbox_q',$this->t('E-Mails durchsuchen'),$context['mailbox_q'],'search','maxlength="200" placeholder="'.$e($this->t('Absender, Betreff oder Nachrichtentext')).'"').'<button class="btn btn-outline theme-secondary">'.$e($this->t('Suchen')).'</button>'.($context['mailbox_q']!==''?'<a class="btn btn-outline theme-secondary" href="'.$e(self::url(array_replace($context,['mailbox_q'=>'','mailbox_page'=>'1']))).'">'.$e($this->t('Suche zurücksetzen')).'</a>':'').'</form>';
  $h.='<details class="mailbox-tools"'.((isset($query['mailbox_settings'])||($post['action']??null)==='mailbox-settings')?' open':'').'><summary>'.$e($this->t('Postfach und Filter')).'</summary><section class="card"><h2>'.$e($this->t('Nachrichten')).'</h2><p>'.$e($this->t('Gemeinsamer Zugang für aktuellen Vorstand und Superuser · Empfang {state}',['state'=>$this->t($data['enabled']?'eingeschaltet':'ausgeschaltet')])).'</p>';

  if(BoardAccess::superuser($this->db,$this->actor)){
   if(($post['action']??null)==='mailbox-settings'){
    foreach(['email','imap_host','imap_user','folder','since']as$key)$data[$key]=$post['mailbox'][$key];
    $data['enabled']=($post['mailbox']['enabled']??null)==='1';$settings['version']=self::version($post,'mailbox_version');
   }
   $h.='<details'.((isset($query['mailbox_settings'])||($post['action']??null)==='mailbox-settings')?' open':'').'><summary>'.$e($this->t('Postfach einrichten')).'</summary>'.$this->form('mailbox-settings',['mailbox_version'=>$settings['version']],$context).'<label class="check"><input class="check" type="checkbox" name="mailbox[enabled]" value="1"'.($data['enabled']?' checked':'').'> '.$e($this->t('Empfang einschalten')).'</label><div class="form-grid">';
   foreach(['email'=>'Postfachadresse','imap_host'=>'IMAP-Server · TLS 993','imap_user'=>'Postfach-Benutzername','folder'=>'Postfachordner','since'=>'Nachrichten abrufen ab']as$key=>$label){
    $type=$key==='email'?'email':($key==='since'?'date':'text');
    if($key==='since'&&$post!==[]){$day=\DateTimeImmutable::createFromFormat('!Y-m-d',$data[$key]);if(!$day||$day->format('Y-m-d')!==$data[$key])$type='text';}
    $h.=Html::input('mailbox['.$key.']',$this->t($label),$data[$key],$type,'maxlength="254"');
   }
   $h.='<label>'.$e($this->t('Postfachpasswort')).'<input class="form-control" name="mailbox[imap_password]" type="password" autocomplete="new-password" maxlength="1000" value=""><small>'.$e($this->t($settings['password_set']?'Gespeichert · leer lassen zum Behalten':'Noch nicht gespeichert')).'</small></label></div><label class="check"><input class="check" type="checkbox" name="mailbox[clear_password]" value="1"'.((($post['mailbox']['clear_password']??null)==='1')?' checked':'').'> '.$e($this->t('Gespeichertes Passwort entfernen')).'</label><p>'.$e($this->t('Antworten verwenden das zugeordnete Versandpostfach. Dessen Absender muss dieser Postfachadresse entsprechen.')).'</p><p>'.$e($this->t($settings['imap_available']?'IMAP-Erweiterung verfügbar.':'Für den Empfang muss die IMAP-Erweiterung auf dem Server eingerichtet sein.')).'</p><p>'.$e($this->t('Bis zu 25 E-Mails und 40 MB je Abruf, höchstens 40 MB je Nachricht.')).'</p><button class="btn btn-solid theme-primary">'.$e($this->t('Postfach speichern')).'</button></form><p><a href="'.$e(self::url(['mailbox_settings'=>'1']+$context)).'">'.$e($this->t('Gespeicherten Postfachzugang öffnen')).'</a></p></details>';
  }
  $states=['active'=>$this->t('Offen'),'archived'=>$this->t('Abgelegt'),'trashed'=>$this->t('Papierkorb')];$h.='</section><form method="get" class="card">'.Html::hidden('view','messages').Html::hidden('tab','inbox').Html::hidden('mailbox_account',$context['mailbox_account']).Html::hidden('mailbox_q',$context['mailbox_q']).'<div class="form-grid">'.Html::select('mailbox_state',$this->t('Ablage'),$states+['all'=>$this->t('Alle')],$context['mailbox_state']).Html::select('mailbox_assigned',$this->t('Zuständigkeit'),['all'=>$this->t('Alle'),'mine'=>$this->t('Mir zugewiesen'),'unassigned'=>$this->t('Noch nicht zugewiesen')],$context['mailbox_assigned']).'</div><button class="btn btn-outline theme-secondary">'.$e($this->t('Anzeigen')).'</button></form>';
  $h.='</details><div class="mailbox-layout" data-mailbox-selected="'.($id!==''?'1':'0').'">';
  $list=$this->mailbox->listing($context['mailbox_state'],$context['mailbox_assigned'],$context['mailbox_page'],$context['mailbox_q']);$missing=$list['page']!==$context['mailbox_page'];
  if($missing&&$id==='')throw new DomainException('Die ursprüngliche Ergebnisseite ist nicht mehr verfügbar. Eine neue Seite bewusst wählen.',422);
  $h.='<section class="card" data-mailbox-list><h2>'.$e($this->t('{total} Nachrichten · Seite {page}/{pages}',['total'=>$list['total'],'page'=>$context['mailbox_page'],'pages'=>$list['pages']])).'</h2>';
  if($missing)$h.='<p role="status">'.$e($this->t('Diese Seite ist nicht mehr verfügbar.')).'</p><a class="btn btn-outline" href="'.$e(self::url(array_replace($context,['mail'=>$id,'mailbox_page'=>'1']))).'">'.$e($this->t('Zur ersten Seite')).'</a>';
  else{
   foreach($list['rows']as$row){
    $assigned=$row['assigned_to']!==null?($people[$row['assigned_to']]??$this->t('Früher zugewiesene Person')):$this->t('Noch nicht zugewiesen');$last=$this->mailbox->latestReply($row['id']);$preview=$last&&$last['updated_at']>$row['received_at']?$last['payload']['text']:$row['envelope']['text'];
    $h.='<article class="mailbox-message-card"'.(in_array($id,$row['conversation_ids']??[$row['id']],true)?' aria-current="true"':'').'><div class="mailbox-message-top"><span class="mailbox-avatar" aria-hidden="true">'.$e(mb_strtoupper(mb_substr(ltrim($row['envelope']['from'],"\"' "),0,1))?:'@').'</span><span class="mailbox-sender">'.$e($row['envelope']['from']).'</span><time class="mailbox-meta" datetime="'.$e($row['received_at']).'">'.$e($this->stamp($row['received_at'])).'</time></div><h3><a href="'.$e(self::url(['mail'=>$row['id']]+$context)).'">'.$e($row['envelope']['subject']).'</a></h3><p class="mailbox-list-preview">'.$e(mb_strimwidth($preview,0,140,'…','UTF-8')).'</p>';
    if($last){$label=$this->replyState($last['state']);$h.='<p class="mailbox-meta">'.$e($people[$last['sent_by']??$last['created_by']]??$this->t('Frühere Verwaltungsperson')).' · '.($label!==''?$e($label).' · ':'').$e($this->stamp($last['updated_at'])).'</p>';}else $h.='<p class="mailbox-meta">'.$e($this->t('Noch keine Antwort')).'</p>';
    $h.='<p class="mailbox-meta">'.$e((string)($row['conversation_count']??1)).' '.$e($this->t('Nachrichten im Gespräch')).'</p>';
    $h.='<p class="mailbox-meta">'.$e($states[$row['state']]).($row['assigned_to']!==null?' · '.$e($assigned):'').'</p></article>';
   }
   if(!$list['rows'])$h.='<p>'.$e($this->t('Keine Nachrichten für diese Auswahl.')).'</p>';$h.=$this->pages($list,'mailbox_page',$context);
  }
  $h.='</section>';if($id==='')return$h.'<section class="mailbox-empty-state"><span class="mailbox-empty-icon" aria-hidden="true">✉</span><h2>'.$e($this->t('Wähle eine E-Mail aus')).'</h2><p>'.$e($this->t('Hier kannst du Nachrichten lesen, direkt antworten und den Verlauf sehen.')).'</p></section></div></div>';
  $message=$this->mailbox->get($id);$envelope=$message['envelope'];$selected=['mail'=>$id]+$context;$canReply=(in_array($message['state'],['active','archived'],true)&&$settings['data']['enabled']&&$envelope['reply_to']!=='')||in_array($post['action']??null,['mailbox-reply-save','mailbox-reply-save-send'],true);
  $replyId=self::text($query,'reply','',32);$reply=$replyId!==''?$this->mailbox->reply($replyId):null;if($reply&&$reply['message_id']!==$id)throw new DomainException('Antwort gehört zu einer anderen Nachricht.',409);
  $replies=$this->mailbox->conversationPage($id,Mailbox::page($query['reply_page']??'1'));if(isset($query['reply_page'])&&$replies['page']!==(int)$query['reply_page'])throw new DomainException('Die ursprüngliche Ergebnisseite ist nicht mehr verfügbar. Eine neue Seite bewusst wählen.',422);
  $history=$this->mailbox->history($id,Mailbox::page($query['history_page']??'1'));if(isset($query['history_page'])&&$history['page']!==(int)$query['history_page'])throw new DomainException('Die ursprüngliche Ergebnisseite ist nicht mehr verfügbar. Eine neue Seite bewusst wählen.',422);
  $threadActions='<div class="mailbox-message-actions">'.($canReply?'<a class="btn btn-solid theme-primary" data-mailbox-reply-jump href="#mailbox-reply">'.$e($this->t('Antworten')).'</a>':'');$trash=$message['state']==='trashed';$threadActions.=$this->form($trash?'mailbox-restore':'mailbox-trash',['mail'=>$id,'mail_version'=>$message['version']],$context).'<button class="btn btn-outline theme-secondary">'.$e($this->t($trash?'Wiederherstellen':'Mail löschen')).'</button></form></div>';
  $h.='<section class="mailbox-thread" data-mailbox-conversation><header class="mailbox-thread-heading"><a class="btn btn-outline theme-secondary" href="'.$e(self::url($context)).'">'.$e($this->t('Alle Gespräche')).'</a><h2>'.$e($envelope['subject']).'</h2><p>'.$e($envelope['from']).'</p>'.$threadActions.'</header><div class="mailbox-conversation-log" aria-label="'.$e($this->t('Gesprächsverlauf')).'">';
  $shown=$replies['rows'];if($reply&&!in_array($reply['id'],array_column($shown,'id'),true))$shown[]=$reply;usort($shown,static fn($a,$b)=>[$b['updated_at'],$b['id']]<=>[$a['updated_at'],$a['id']]);
  foreach($shown as$row){if(($row['direction']??'outgoing')==='incoming'){$h.=$this->incoming($row,$context,(bool)$settings['data']['enabled']);continue;}$h.='<article class="mailbox-bubble mailbox-outgoing" data-mailbox-reply data-mailbox-reply-id="'.$e($row['id']).'" data-mailbox-reply-state="'.$e($row['state']).'"><p class="mailbox-meta"><strong>'.$e($people[$row['sent_by']??$row['created_by']]??$this->t('Frühere Verwaltungsperson')).'</strong> · '.$e($this->stamp($row['updated_at'])).'</p><div class="mailbox-reply-html kh-richtext'.(str_contains($row['payload']['html']??'','white-space:pre-wrap')?' mailbox-original-text':'').'">'.\Kulturhus\Native\Shared\RichText::sanitize($row['payload']['html']??'<p>'.$e($row['payload']['text']).'</p>').'</div>'.(($label=$this->replyState($row['state']))!==''?'<p class="mailbox-reply-status">'.$e($label).'</p>':'');
   if($canReply&&in_array($row['state'],['draft','blocked'],true))$h.='<a class="btn btn-outline theme-secondary" href="'.$e(self::url(['mail'=>$row['message_id'],'reply'=>$row['id'],'edit_reply'=>'1']+$context)).'">'.$e($this->t('Entwurf bearbeiten')).'</a>';
   if($canReply&&$reply&&$reply['id']===$row['id']&&in_array($row['state'],['draft','blocked'],true)&&!isset($query['edit_reply']))$h.=$this->form('mailbox-reply-send',['mail'=>$id,'reply_id'=>$row['id'],'reply_version'=>$row['version'],'confirm_send'=>'1'],$context).'<p>'.$e($this->t('Diese gespeicherte Fassung an {address} senden',['address'=>$row['payload']['to']])).'</p><button class="btn btn-solid theme-primary">'.$e($this->t('Gespeicherte Antwort senden')).'</button></form>';
   $h.='</article>';
  }
  $h.=$this->pages($replies,'reply_page',$selected).'</div>';
  $correction=in_array($post['action']??null,['mailbox-reply-save','mailbox-reply-save-send'],true);$editing=$correction||($reply&&($query['edit_reply']??null)==='1');
  if($canReply){
   $draftId=$correction?$post['reply_id']:($editing?$reply['id']:bin2hex(random_bytes(16)));$draftVersion=$correction?self::version($post,'reply_version'):($editing?$reply['version']:0);$text=$correction?$post['reply_text']:($editing?$reply['payload']['text']:'');
   $richHtml=$correction?($post['reply_html']??null):($editing?$reply['payload']['html']:null);if($richHtml===null||str_contains($richHtml,'white-space:pre-wrap'))$richHtml='<p>'.str_replace("\n",'<br>',$e($text)).'</p>';$rich=\Kulturhus\Native\Web\RichTextEditor::render('reply_html',$richHtml,$this->t('Deine Antwort'),['required'=>true,'max'=>20000],interfaceText:$this->t(...));$rich=str_replace('name="reply_html"','name="reply_html" disabled data-mailbox-html-input',$rich);
   $h.='<section class="mailbox-composer" id="mailbox-reply" tabindex="-1"><h3>'.$e($this->t($editing?'Antwortentwurf bearbeiten':'Neue Antwort schreiben')).'</h3><p>'.$e($this->t('Antwort an {address}',['address'=>$envelope['reply_to']])).'</p><p class="mailbox-meta">'.$e($this->t('Du kannst auch ohne zugewiesene Person antworten.')).'</p>'.$this->form('mailbox-reply-save',['mail'=>$id,'reply_id'=>$draftId,'reply_version'=>$draftVersion],$context).'<div data-mailbox-rich hidden><button class="btn btn-outline theme-secondary mailbox-format-toggle" type="button" data-mailbox-format-toggle data-more-label="'.$e($this->t('Mehr Formatierung')).'" data-less-label="'.$e($this->t('Weniger Formatierung')).'" aria-expanded="false">'.$e($this->t('Mehr Formatierung')).'</button>'.$rich.'</div><label data-mailbox-plain>'.$e($this->t('Deine Antwort')).'<textarea class="form-control" name="reply_text" rows="3" maxlength="20000" placeholder="'.$e($this->t('Nachricht schreiben …')).'" required>'.$e($text).'</textarea></label><div class="mailbox-composer-actions"><button class="btn btn-solid theme-primary" name="action" value="mailbox-reply-save-send">'.$e($this->t('Antwort senden')).'</button><button class="btn btn-outline theme-secondary">'.$e($this->t('Entwurf speichern')).'</button></div></form><p><a href="'.$e(self::url($selected)).'">'.$e($this->t('Gespeicherten Stand öffnen')).'</a></p></section>';
  }
  if(!$canReply){$reason=$message['state']==='trashed'?'Die Nachricht liegt im Papierkorb. Stelle sie wieder her, um zu antworten.':(!$settings['data']['enabled']?'Das Postfach ist ausgeschaltet. Den Postfachzugang zum Antworten einschalten.':'Für diese Nachricht fehlt eine eindeutige Antwortadresse. Bitte die Originalmail prüfen.');$h.='<p class="mailbox-meta" role="status">'.$e($this->t($reason)).'</p>';}
  $changePosted=($post['action']??null)==='mailbox-change';$assigned=$changePosted?$post['assigned_to']:($message['assigned_to']??'');$choices=[''=>$this->t('Noch nicht zugewiesen')]+$people;if($assigned!==''&&!isset($choices[$assigned]))$choices[$assigned]=$this->t('Früher gewählte Person · bitte neu auswählen');$messageState=$changePosted?$post['message_state']:$message['state'];$stateChoices=$states;if(!isset($stateChoices[$messageState]))$stateChoices[$messageState]=$this->t('Gespeicherter Wert: {value}',['value'=>$messageState]);
  $h.='<details class="card"'.($changePosted?' open':'').'><summary>'.$e($this->t('Zuständigkeit und Ablage')).'</summary>'.$this->form('mailbox-change',['mail'=>$id,'mail_version'=>$changePosted?self::version($post,'mail_version'):$message['version']],$context).Html::select('assigned_to',$this->t('Zuständige Person (optional)'),$choices,$assigned).Html::select('message_state',$this->t('Ablage'),$stateChoices,$messageState).'<button class="btn btn-outline theme-secondary">'.$e($this->t('Zuständigkeit und Ablage speichern')).'</button></form><p><a href="'.$e(self::url($selected)).'">'.$e($this->t('Aktuellen Stand öffnen')).'</a></p></details>';
  $h.='<details class="card"><summary>'.$e($this->t('Verlauf der Zuständigkeit und Ablage · {total}',['total'=>$history['total']])).'</summary>';
  foreach($history['rows']as$row){$after=$row['payload']['after'];$assigned=$after['assigned']!==null?($people[$after['assigned']]??$this->t('Früher zugewiesene Person')):$this->t('Noch nicht zugewiesen');$h.='<p>'.$e($this->t('Fassung {version}',['version'=>$row['version']])).' · '.$e($people[$row['actor_id']]??$this->t('Frühere Verwaltungsperson')).' · '.$e($this->stamp($row['happened_at'])).' · '.$e($states[$after['state']]).' · '.$e($assigned).'</p>';}
  return$h.$this->pages($history,'history_page',$selected).'</details></section></div></div>';

 }
}
