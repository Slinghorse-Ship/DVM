<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use PDO;
use DomainException;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Chat\{Workspace,Polls,Attachments,Notifications,Catalogue};

/** Composable messenger adapter; persistence is independent from the website/media modules. */
final class ChatView
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    private Workspace $chat;private Polls $polls;private Attachments $files;private Notifications $alerts;private array $context=[];private ?array $submitted=null;private int $historyBefore=PHP_INT_MAX;
    public function __construct(private PDO $db,private Access $access,string $privateRoot,private string $csrf='')
    {$this->chat=new Workspace($db,$access);$this->polls=new Polls($db,$access);$this->files=new Attachments($db,$access,$privateRoot);$this->alerts=new Notifications($db,$access);}
    private function can(string $cap):bool{try{$this->access->require('chat',$cap);return true;}catch(DomainException){return false;}}
    private function form(string $op,string $id='',int $version=0,string $extra=''):string
    {$h='<form method="post" '.$extra.'>'.Html::hidden('csrf',$this->csrf).Html::hidden('operation',$op).Html::hidden('id',$id).Html::hidden('version',$version);foreach($this->context as$key=>$value)if($op!=='chat-send'||!in_array($key,['chat_query','chat_filter'],true))$h.=Html::hidden($key,$value);if($this->historyBefore<PHP_INT_MAX)$h.=Html::hidden('chat_before',$this->historyBefore);return$h;}
    public function browse(array $post):array
    {
        if(!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.');
        $state=ChatFormState::restore($post);if($state['operation']!=='chat-send')throw new DomainException('Nachrichteneingabe öffnen.');$this->access->require('chat','edit');$this->chat->get($state['id']);$command=$post['chat_browse']??'';
        if($command==='cancel-reply')$state['reply_to']=null;
        elseif(is_string($command)&&preg_match('/\Areply:([1-9][0-9]{0,18})\z/D',$command,$match)){$seq=ChatFormState::number($match[1]);$this->chat->reply($state['id'],$seq);$state['reply_to']=$seq;}
        elseif($command==='rooms-search'){$state['context']['chat_page']=1;$state['context']['chat_rooms']='1';unset($state['target_page']);}
        elseif($command==='rooms-page-selected'){$state['context']['chat_page']=ChatFormState::number($post['chat_target_page']??null);$state['context']['chat_rooms']='1';Catalogue::state($state['context']);}
        elseif(is_string($command)&&preg_match('/\Arooms-page:([1-9][0-9]{0,6})\z/D',$command,$match)){$state['context']['chat_page']=(int)$match[1];$state['context']['chat_rooms']='1';Catalogue::state($state['context']);}
        else throw new DomainException('Antwortaktion prüfen.');return$state;
    }
    public function action(array $post,array $files):string
    {
        if(!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.');
        if(ChatFormState::submitted($post))ChatFormState::restore($post);
        $id=(string)($post['id']??'');$op=(string)($post['operation']??'');$version=(int)($post['version']??0);$key=(string)($post['request_key']??'');
        if($op==='chat-create')return $this->chat->create((string)($post['title']??''),(array)($post['people']??[]))['id'];
        if($op==='chat-alerts'){
            $enabled=isset($post['enabled']);$this->alerts->set($enabled,$version);
            if($enabled&&($post['device_key']??'')!=='')$this->alerts->device((string)$post['device_key'],true);
        }elseif($op==='chat-devices-off')$this->alerts->disableDevices();
        elseif($op==='chat-device-off')$this->alerts->device((string)($post['device_key']??''),false);
        elseif($op==='chat-mute')$this->alerts->mute($id,($post['muted']??'')==='1');
        elseif($op==='chat-send'){
            $reply=($post['reply_to']??'')!==''?(int)$post['reply_to']:null;$input=$files['chat_file']??null;
            if($input!==null&&!is_array($input))throw new DomainException('Anhangauswahl prüfen.');
            $uploads=Attachments::uploads($input);
            if($uploads){
                $this->files->sendMany($id,(string)($post['body']??''),$key,$uploads,$reply);
            }else $this->chat->send($id,(string)($post['body']??''),$key,$reply);
        }elseif($op==='chat-edit')$this->chat->edit($id,(int)($post['seq']??0),(string)($post['body']??''),$version);
        elseif($op==='chat-read')$this->chat->markRead($id,(int)($post['through']??0));
        elseif($op==='chat-add')$this->chat->addPeople($id,(array)($post['people']??[]));
        elseif($op==='chat-leave'){$this->chat->leave($id);return '';}
        elseif($op==='poll-create'){
            $end=null;$local=(string)($post['closes_at']??'');
            if($local!==''){$date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,new \DateTimeZone('Europe/Berlin'));if(!$date||$date->format('Y-m-d\TH:i')!==$local)throw new DomainException('Gültiges Umfrageende in Europe/Berlin angeben.');$end=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}
            $options=array_values(array_filter(array_map('trim',preg_split('/\R/u',(string)($post['options']??''))),static fn($s)=>$s!==''));
            $this->polls->create($id,(string)($post['question']??''),$options,$end,$key);
        }elseif($op==='poll-vote'){
            $option=$post['option']??'';if((!is_string($option)&&!is_int($option))||!preg_match('/\A(?:[1-9]|10)\z/D',(string)$option))throw new DomainException('Eine Antwort auswählen.');
            $this->polls->vote($id,(string)($post['poll_id']??''),(int)$option,$version,$key);
        }
        elseif($op==='poll-close')$this->polls->close($id,(string)($post['poll_id']??''),$version);
        else throw new DomainException('Unbekannte Chataktion.');
        return $id;
    }
    public function attachment(string $threadId,int $seq,int $ordinal=1):array{return $this->files->file($threadId,$seq,$ordinal);}
    public function status(string $id='',array $query=[]):array
    {
        $list=Catalogue::page($this->db,$this->access,$query);$stamp='';
        if($id!==''){$messages=$this->chat->history($id,PHP_INT_MAX,20);$polls=[];foreach($messages as $m)if($m['poll_id'])$polls[]=$this->polls->get($id,$m['poll_id']);$stamp=hash('sha256',json_encode([$messages,$polls,$this->chat->people($id)],JSON_THROW_ON_ERROR));}
        return ['actor_id'=>$this->access->user()['id'],'threads'=>array_map(static fn($t)=>['id'=>$t['id'],'unread'=>(int)$t['unread']],$list['rows']),'unread_total'=>$list['unread_total'],'stamp'=>$stamp,'notifications'=>$this->alerts->state(),'events'=>$this->alerts->queue()];
    }
    public function manager(string $id='',int $before=PHP_INT_MAX,array $query=[],?array $submitted=null):string
    {
        $e=Html::e(...);$actor=$this->access->require('chat','read');$write=$this->can('edit');$this->submitted=$submitted;$before=$submitted['before']??$before;$this->historyBefore=$before;
        if($submitted!==null&&$submitted['id']!==$id)throw new DomainException('Ursprüngliche Nachrichteneingabe öffnen.');
        $list=Catalogue::page($this->db,$this->access,$submitted['context']??$query);$this->context=array_intersect_key($list,array_flip(['chat_query','chat_filter','chat_page','chat_rooms']));$state=$this->alerts->state();
        $h='<div class="chat-page-heading">'.($write?('<a class="btn btn-solid theme-primary button" href="#chat-create" data-chat-open-create>' . Html::e($this->contentText('+ Neue Unterhaltung')) . '</a>'):'').'</div><div class="native-chat" data-native-chat data-thread="'.$e($id).'" data-actor="'.$e($actor['id']).'" data-history="'.($before<PHP_INT_MAX?'1':'0').'" data-chat-query="'.$e($list['chat_query']).'" data-chat-filter="'.$e($list['chat_filter']).'" data-chat-page="'.$list['chat_page'].'"><aside class="chat-rail">'.$this->rooms($id,$list,$write);
        if($write){$h.=('<details id="chat-create" class="chat-create"><summary>' . Html::e($this->contentText('＋ Neue Unterhaltung')) . '</summary>').$this->form('chat-create').Html::input('title',$this->contentText('Thema'),'','text','required maxlength="150"').('<fieldset><legend>' . Html::e($this->contentText('Teilnehmer auswählen')) . '</legend>');foreach($this->chat->directory() as $person)if($person['id']!==$actor['id'])$h.='<label class="check"><input class="check" type="checkbox" name="people[]" value="'.$e($person['id']).'">'.$e($person['name']).'</label>';$h.=('</fieldset><button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Unterhaltung starten')) . '</button></form></details>');}
        $h.=$this->notificationSettings($id,$state).'</aside><section class="chat-conversation">';
        if($id==='')return $h.('<div class="chat-welcome"><span aria-hidden="true">☏</span><h2>' . Html::e($this->contentText('Platz für eure Absprachen')) . '</h2><p>' . Html::e($this->contentText('Wähle eine Unterhaltung aus oder starte eine neue.')) . '</p></div></section></div>');
        $thread=$this->chat->get($id);$people=$this->chat->people($id);$messages=$this->chat->history($id,$before,20);$through=$messages?(int)end($messages)['seq']:0;
        $h.=('<header class="chat-conversation-head"><div><p class="eyebrow">' . Html::e($this->contentText('Unterhaltung')) . '</p><h2>').$e($thread['title']).'</h2><small>'.count($people).(' ' . Html::e($this->contentText('Personen ·')) . ' <a href="#chat-people" data-chat-open-people>' . Html::e($this->contentText('Teilnehmer ansehen')) . '</a></small></div><a class="btn btn-outline theme-secondary button secondary" data-nav href="').$e($this->url($id)).('">' . Html::e($this->contentText('Aktualisieren')) . '</a></header><div class="chat-live" role="status" aria-live="polite" data-chat-live></div><div class="chat-scroll" data-chat-scroll tabindex="0" aria-label="'.Html::e($this->contentText('Nachrichtenverlauf')).'">');
        if($messages&&$this->chat->hasHistory($id,(int)$messages[0]['seq']))$h.='<a class="chat-history-link" data-nav href="'.$e($this->url($id,['chat_before'=>(int)$messages[0]['seq']])).('">' . Html::e($this->contentText('Ältere Nachrichten')) . '</a>');
        if($before<PHP_INT_MAX)$h.='<a data-nav href="'.$e($this->url($id)).('">' . Html::e($this->contentText('Zu den neuesten Nachrichten')) . '</a>');
        $h.='<div class="messages" data-native-chat-messages>';
        $lastDate='';$latest=$before===PHP_INT_MAX&&$messages?(int)end($messages)['seq']:0;foreach($messages as $m){$m['_latest']=(int)$m['seq']===$latest;$date=$this->date($m['created_at'],'d.m.Y');if($date!==$lastDate){$h.='<p class="chat-date-separator">'.$e($date).'</p>';$lastDate=$date;}$h.=$this->message($id,$m,$actor,$write);}
        if(!$messages)$h.=('<p class="chat-empty">' . Html::e($this->contentText('Noch keine Nachrichten in diesem Ausschnitt.')) . '</p>');
        $h.='</div></div><div class="chat-bottom">'.$this->form('chat-read',$id).'<input type="hidden" name="through" data-navigation-metadata value="'.(int)$through.'">'.('<button class="btn btn-outline theme-secondary chat-read secondary">' . Html::e($this->contentText('Bis hier als gelesen markieren')) . '</button></form>');
        if($write){
            $sending=$submitted!==null&&$submitted['operation']==='chat-send';$reply=$sending?($submitted['reply_to']??null):null;$replyBody='';if($reply!==null)try{$replyBody=$this->chat->reply($id,$reply)['body'];}catch(DomainException){$replyBody='';}
            $h.=isset($submitted['error'])?'<p id="chat-result" tabindex="-1" autofocus role="alert">'.$e($submitted['error']).'</p>':'';
            $h.=$this->form('chat-send',$id,0,'id="chat-compose" action="#chat-result" enctype="multipart/form-data" class="chat-compose" data-chat-compose').Html::hidden('request_key',$sending?$submitted['request_key']:bin2hex(random_bytes(16))).Html::hidden('reply_to',$reply??'').'<div data-chat-reply-box'.($reply===null?' hidden':'').'><span data-chat-reply-label>'.($reply!==null?'Antwort auf Nachricht '.$reply.': '.$e(mb_substr($replyBody,0,200)):'').('</span><button type="submit" name="chat_browse" value="cancel-reply" class="btn btn-outline theme-secondary secondary" data-chat-reply-cancel>' . Html::e($this->contentText('Antwort verwerfen')) . '</button></div><label>' . Html::e($this->contentText('Deine Nachricht')) . '<textarea class="form-control" name="body" rows="3" maxlength="5000" placeholder="'.Html::e($this->contentText('Nachricht schreiben …')).'">').($sending?$e($submitted['body']):'').('</textarea></label><div class="chat-compose-tools"><details data-chat-files><summary>' . Html::e($this->contentText('Dateien anhängen')) . ' <span data-chat-file-count></span></summary><label>' . Html::e($this->contentText('Fotos, PDF oder Text · bis 8 MB je gespeicherter Datei. HEIC/RAW bis 256 MiB je Original, zusammen 512 MiB, werden als JPEG gespeichert.')) . '<input class="form-control" type="file" data-photo-server name="chat_file[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.heic,.heif,.hif,.dng,.cr2,.cr3,.crw,.nef,.nrw,.arw,.sr2,.srf,.rw2,.rwl,.orf,.raf,.pef,.ptx,.raw,.iiq,.3fr,.fff,.kdc,.dcr,.mos,.mef,.mrw,.erf"></label><ul data-chat-file-selection></ul></details><button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Nachricht senden')) . '</button></div></form>');
            $creating=$submitted!==null&&$submitted['operation']==='poll-create';$end=$creating?$submitted['closes_at']:'';
            $endType=$end===''||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/D',$end)?'datetime-local':'text';
            $h.='<details class="chat-poll-create"'.($creating?' open':'').('><summary>' . Html::e($this->contentText('+ Umfrage erstellen')) . '</summary>').$this->form('poll-create',$id,0,'action="#chat-result"').Html::hidden('request_key',$creating?$submitted['request_key']:bin2hex(random_bytes(16))).Html::input('question','Frage',$creating?$submitted['question']:'','text','required maxlength="500"').('<label>' . Html::e($this->contentText('Antworten · eine je Zeile')) . '<textarea class="form-control" name="options" rows="4" required>').$e($creating?$submitted['options']:'').'</textarea></label>'.Html::input('closes_at',$this->contentText('Optionales Ende (Europe/Berlin)'),$end,$endType).('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Umfrage erstellen')) . '</button></form></details>');
        }else $h.=('<p class="hint">' . Html::e($this->contentText('Du hast Leserechte für Nachrichten, Umfragen und Anhänge.')) . '</p>');
        $h.=('</div><aside class="chat-details"><details id="chat-people"><summary>' . Html::e($this->contentText('Teilnehmer ansehen oder hinzufügen ·')) . ' ').count($people).'</summary><ul>';foreach($people as $person)$h.='<li>'.$e($person['name']).($person['id']===$thread['owner_id']?' · Verwaltung':'').'</li>';$h.='</ul>';
        if($write&&($thread['owner_id']===$actor['id']||$this->can('manage'))){$h.=$this->form('chat-add',$id).('<fieldset><legend>' . Html::e($this->contentText('Personen hinzufügen')) . '</legend><p class="hint">' . Html::e($this->contentText('Neu hinzugefügte Personen können den bisherigen Verlauf und seine Anhänge lesen.')) . '</p>');foreach($this->chat->directory() as $person)if(!in_array($person['id'],array_column($people,'id'),true))$h.='<label class="check"><input class="check" type="checkbox" name="people[]" value="'.$e($person['id']).'">'.$e($person['name']).'</label>';$h.=('</fieldset><button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Teilnehmer hinzufügen')) . '</button></form>');}
        $muted=$this->alerts->muted($id);$h.=('</details><details><summary>' . Html::e($this->contentText('Unterhaltung einstellen')) . '</summary>').$this->form('chat-mute',$id).Html::hidden('muted',$muted?'0':'1').'<p>'.($muted?Html::e($this->contentText('Diese Unterhaltung ist stumm.')):Html::e($this->contentText('Benachrichtigungen richten sich nach deinen Einstellungen.'))).'</p><button class="btn btn-outline theme-secondary secondary">'.($muted?Html::e($this->contentText('Stummschaltung aufheben')):'Stummschalten').'</button></form>'.$this->form('chat-leave',$id).('<p>' . Html::e($this->contentText('Nach dem Verlassen sind Verlauf und Anhänge für dich gesperrt.')) . '</p><button class="btn btn-outline theme-secondary secondary">' . Html::e($this->contentText('Unterhaltung verlassen')) . '</button></form></details></aside></section></div>');
        return $h;
    }
    private function date(string $value,string $format):string
    {return (new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format($format);}
    private function url(string $id,array $extra=[]):string
    {return '/?'.http_build_query(array_replace(['view'=>'chat','item'=>$id],$this->context,$extra));}
    private function rooms(string $id,array $list,bool $write):string
    {
        $e=Html::e(...);$associated=$id!==''&&$write;$attr=$associated?' form="chat-compose"':'';
        $h='<details id="chat-rooms" class="chat-rooms"'.($id===''||$list['chat_rooms']==='1'?' open':'').('><summary class="chat-rail-title">' . Html::e($this->contentText('Unterhaltungen ·')) . ' ').$list['total'].'</summary>';
        $h.=$associated?'<div class="chat-filter">':'<form class="chat-filter" method="get" action="/#chat-rooms">'.Html::hidden('view','chat').Html::hidden('item',$id).Html::hidden('chat_rooms','1');
        $h.=('<label>' . Html::e($this->contentText('Unterhaltung suchen')) . '<input class="form-control" type="search" name="chat_query" maxlength="200" value="').$e($list['chat_query']).'"'.$attr.('></label><label>' . Html::e($this->contentText('Anzeige')) . '<select class="form-control" name="chat_filter"').$attr.'><option value="all"'.($list['chat_filter']==='all'?' selected':'').('>' . Html::e($this->contentText('Alle')) . '</option><option value="unread"').($list['chat_filter']==='unread'?' selected':'').('>' . Html::e($this->contentText('Ungelesen')) . '</option></select></label><button class="btn btn-solid theme-primary"').($associated?' form="chat-compose" name="chat_browse" value="rooms-search"':'').('>' . Html::e($this->contentText('Suchen')) . '</button>').($associated?'</div>':'</form>');
        $h.='<nav aria-label="'.Html::e($this->contentText('Unterhaltungen')).'" data-chat-rooms>';
        foreach($list['rows']as$row)$h.='<a class="item chat-room'.($row['id']===$id?' is-selected':'').'" data-nav data-chat-room data-title="'.$e($row['title']).'" href="'.$e($this->url($row['id'],['chat_rooms'=>'0'])).'"'.($row['id']===$id?' aria-current="page"':'').'><span class="chat-avatar" aria-hidden="true">'.$e(mb_strtoupper(mb_substr($row['title'],0,1))).'</span><span><strong>'.$e($row['title']).'</strong></span><span class="chat-unread" data-room-id="'.$e($row['id']).'"'.(!(int)$row['unread']?' hidden':'').'>'.(int)$row['unread'].'</span></a>';
        if(!$list['rows'])$h.=('<p class="chat-empty">' . Html::e($this->contentText('Keine Unterhaltungen gefunden.')) . '</p>');$h.='</nav>';
        if($list['pages']>1){$h.=$associated?'<div class="chat-room-pages">':'<form class="chat-room-pages" method="get" action="/#chat-rooms">'.Html::hidden('view','chat').Html::hidden('item',$id).Html::hidden('chat_query',$list['chat_query']).Html::hidden('chat_filter',$list['chat_filter']).Html::hidden('chat_rooms','1');
            $h.=('<label>' . Html::e($this->contentText('Seite')) . '<input class="form-control" ').($associated?'type="text" inputmode="numeric" maxlength="20"':'type="number" min="1" max="'.$list['pages'].'" required').' value="'.$e($associated?($this->submitted['target_page']??$list['chat_page']):$list['chat_page']).'" name="'.($associated?'chat_target_page':'chat_page').'"'.$attr.('></label><span>' . Html::e($this->contentText('von')) . ' ').$list['pages'].'</span><button class="btn btn-solid theme-primary"'.($associated?' form="chat-compose" name="chat_browse" value="rooms-page-selected"':'').('>' . Html::e($this->contentText('Seite anzeigen')) . '</button>').($associated?'</div>':'</form>');}
        return$h.'</details>';
    }
    private function message(string $id,array $m,array $actor,bool $write):string
    {
        $e=Html::e(...);$seq=(int)$m['seq'];$own=$m['actor_id']===$actor['id'];$h='<article class="chat-message'.($own?' is-own':'').'" id="chat-message-'.$seq.'" data-chat-message="'.$seq.'"'.(!empty($m['_latest'])&&$this->submitted===null?' tabindex="-1" autofocus':'').'><header><strong>'.($own?'Du':$e($m['name']??$m['actor_id'])).'</strong><time datetime="'.$e(str_replace(' ','T',$m['created_at']).'Z').'" title="'.$e($this->date($m['created_at'],'d.m.Y · H:i')).'">'.$e($this->date($m['created_at'],'H:i')).'</time></header>';
        if($m['reply_to'])$h.='<blockquote class="chat-reply"><a href="#chat-message-'.(int)$m['reply_to'].('">' . Html::e($this->contentText('Antwort auf Nachricht')) . ' ').(int)$m['reply_to'].'</a><p>'.$e(mb_substr($m['reply_body']??'',0,200)).'</p></blockquote>';
        if($m['poll_id'])$h.=$this->poll($id,$this->polls->get($id,$m['poll_id']),$actor,$write);else $h.='<p class="chat-message-text">'.Html::text($m['body']).'</p>';
        if($m['file_name']){
            $files=$m['attachments']??[['ordinal'=>1,'name'=>$m['file_name'],'mime'=>$m['file_mime'],'bytes'=>$m['file_bytes']]];
            $render=function(array $file)use($id,$seq,$e):string{
                $suffix=(int)$file['ordinal']===1?'':'?attachment='.(int)$file['ordinal'];$h='';
                if(in_array($file['mime'],['image/png','image/jpeg','image/webp'],true))$h.='<a href="/chat-file/'.$e($id).'/'.$seq.$suffix.'" download><img class="chat-image" src="/chat-image/'.$e($id).'/'.$seq.$suffix.'" alt="'.$e($file['name']).'" loading="lazy"></a>';
                return'<div class="chat-attachment-card">'.$h.'<a class="chat-file" href="/chat-file/'.$e($id).'/'.$seq.$suffix.'" download>📎 '.$e($file['name']).'<small>'.number_format((int)$file['bytes']/1024,1,',','.').(' ' . Html::e($this->contentText('KB · Herunterladen')) . '</small></a></div>');
            };
            $h.='<div class="chat-attachments">';foreach(array_slice($files,0,4)as$file)$h.=$render($file);
            if(count($files)>4){$h.='<details class="chat-more-files"><summary>'.(count($files)-4).(' ' . Html::e($this->contentText('weitere Dateien')) . '</summary>');foreach(array_slice($files,4)as$file)$h.=$render($file);$h.='</details>';}
            $h.='</div>';
        }
        $h.='<footer>'.($m['edited_at']?('<small>' . Html::e($this->contentText('bearbeitet')) . '</small>'):'');
        if($write)$h.='<button type="submit" form="chat-compose" name="chat_browse" value="reply:'.$seq.'" class="btn btn-solid theme-primary chat-quiet" data-chat-reply="'.$seq.'" data-reply-preview="'.$e(mb_substr($m['body'],0,120)).('">' . Html::e($this->contentText('Antworten')) . '</button>');
        if($write&&$own&&!$m['poll_id']){$editing=$this->submitted!==null&&$this->submitted['operation']==='chat-edit'&&$this->submitted['seq']===$seq;$h.='<details class="chat-edit"'.($editing?' open':'').('><summary>' . Html::e($this->contentText('Bearbeiten')) . '</summary>').$this->form('chat-edit',$id,$editing?$this->submitted['version']:(int)$m['version']).Html::hidden('seq',$seq).('<label>' . Html::e($this->contentText('Nachricht ändern')) . '<textarea class="form-control" name="body" rows="3" maxlength="5000" required>').$e($editing?$this->submitted['body']:$m['body']).('</textarea></label><button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Änderung speichern')) . '</button></form></details>'); }
        return $h.'</footer></article>';
    }
    private function poll(string $id,array $p,array $actor,bool $write):string
    {
        $e=Html::e(...);$h='<section class="chat-poll"><h3>'.$e($p['question']).'</h3><p class="hint">'.($p['open']?Html::e($this->contentText('Offene Umfrage')):Html::e($this->contentText('Umfrage beendet'))).' · '.(int)$p['total_votes'].' Stimmen'.($p['closes_at']?' · Ende '.$e($this->date($p['closes_at'],'d.m.Y · H:i')).' Uhr':'').'</p>';
        $pending=$write&&$this->submitted!==null&&($this->submitted['poll_id']??null)===$p['id'];$voting=$pending&&$this->submitted['operation']==='poll-vote';$closing=$pending&&$this->submitted['operation']==='poll-close';
        if($pending)$h.='<p role="alert" data-local-edit="1">'.$e($this->submitted['error']??'Umfrageeingabe prüfen.').'</p>';
        if($write&&$p['open'])$h.=$this->form('poll-vote',$id,$voting?$this->submitted['version']:(int)$p['vote_version'],'action="#chat-result"'.($voting?' data-local-edit="1"':'')).Html::hidden('poll_id',$p['id']).Html::hidden('request_key',$voting?$this->submitted['request_key']:bin2hex(random_bytes(16)));
        foreach($p['options'] as $o){$h.='<div class="chat-poll-option"><label>';if($write&&$p['open'])$h.='<input class="check" type="radio" name="option" value="'.(int)$o['option_number'].'" required'.(($voting?$this->submitted['option']===(string)$o['option_number']:(int)$p['own_option']===(int)$o['option_number'])?' checked':'').'>';else if((int)$p['own_option']===(int)$o['option_number'])$h.='<span aria-label="'.Html::e($this->contentText('Deine Stimme')).'">✓ </span>';$h.=$e($o['label']).'<strong>'.(int)$o['votes'].'</strong></label><progress max="'.max(1,(int)$p['total_votes']).'" value="'.(int)$o['votes'].'" aria-label="'.$e($o['label']).'">'.(int)$o['votes'].'</progress></div>';}
        if($voting&&!$p['open'])foreach($p['options']as$o)if($this->submitted['option']===(string)$o['option_number'])$h.=('<p>' . Html::e($this->contentText('Auswahl nicht gespeichert:')) . ' ').$e($o['label']).'</p>';
        if($write&&$p['open'])$h.='<button class="btn btn-solid theme-primary">'.($p['own_option']?Html::e($this->contentText('Stimme ändern')):Html::e($this->contentText('Abstimmen'))).'</button></form>';
        if($write&&$p['open']&&($p['author_id']===$actor['id']||$this->can('manage')))$h.=$this->form('poll-close',$id,$closing?$this->submitted['version']:(int)$p['version'],'action="#chat-result"').Html::hidden('poll_id',$p['id']).('<button class="btn btn-solid theme-primary chat-quiet">' . Html::e($this->contentText('Umfrage beenden')) . '</button></form>');
        return $h.'</section>';
    }
    private function notificationSettings(string $id,array $state):string
    {
        $h=('<details class="chat-alert-settings"><summary>' . Html::e($this->contentText('Benachrichtigungen')) . '</summary>').$this->form('chat-alerts',$id,$state['version'],'data-chat-alert-form').'<label class="check"><input class="check" type="checkbox" name="enabled"'.($state['enabled']?' checked':'').('>' . Html::e($this->contentText('Über neue Chatnachrichten benachrichtigen')) . '</label>').Html::hidden('device_key','').('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Einstellung speichern')) . '</button><button type="button" class="btn btn-outline theme-secondary secondary" data-chat-permission>' . Html::e($this->contentText('Bei geöffneter Anwendung erlauben')) . '</button></form><p data-chat-permission-status role="status"></p>');
        if($state['account_devices']['linked'])$h.='<p>'.(int)$state['account_devices']['count'].' '.Html::e($this->contentText('Geräte im Konto')).(!$state['account_devices']['enabled']?' · '.Html::e($this->contentText('Chat dort ausgeschaltet')):'').('</p><a class="btn btn-outline theme-secondary button secondary" href="/vereinsverwaltung/?view=settings&amp;tab=account#push-settings">' . Html::e($this->contentText('Gerätebenachrichtigungen einrichten')) . '</a>');
        $h.=$this->form('chat-device-off',$id).Html::hidden('device_key','').('<button class="btn btn-solid theme-primary chat-quiet" data-chat-device-disable>' . Html::e($this->contentText('Dieses Gerät ausschalten')) . '</button></form>').$this->form('chat-devices-off',$id).('<button class="btn btn-solid theme-primary chat-quiet" data-chat-devices-disable>' . Html::e($this->contentText('Alle meine Geräte abmelden')) . '</button></form>');
        return $h.'</details>';
    }
}
