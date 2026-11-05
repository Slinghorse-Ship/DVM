<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{Pages,PageTrash,TemplatePolicy};
use Kulturhus\Native\Shared\{Access,Db,SecretBox,TextFormatter};
use Kulturhus\Native\Web\{Html,Pager};
use PDO;

/** Current editorial authority and exact versions govern every private decision. */
final class ModerationView
{
    private const STATES=['pending'=>'Wartet auf Freigabe','approved'=>'Veröffentlicht','rejected'=>'Abgelehnt','spam'=>'Spam','removed'=>'Ausgeblendet'];
    private ?array $failedPost=null;
    private array $pageLabels=[];
    public function __construct(private PDO $db,private Access $access,private SecretBox $box,private string $csrf){}
    private function service():Streams{return new Streams($this->db,$this->access,$this->box);}
    private static function scalar(array $input,string $key,string $default=''):string{if(!is_string($input[$key]??$default))throw new InvalidArgumentException('Kommentarangaben prüfen.');return$input[$key]??$default;}
    private static function version(mixed $value,bool $zero=false):int{$value=is_int($value)?(string)$value:$value;if(!is_string($value)||!preg_match($zero?'/\A(?:0|[1-9][0-9]{0,9})\z/D':'/\A[1-9][0-9]{0,9}\z/D',$value)||(int)$value>4294967293)throw new InvalidArgumentException('Gespeicherte Kommentarversion prüfen.');return(int)$value;}

    public function action(array $post):array
    {
        if(!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.');
        $operation=self::scalar($post,'operation');$service=$this->service();$result=['view'=>'website','section'=>'comments'];
        if($operation==='website-comment-configure'){
            $page=Values::id($post['comments_page']??null);$stream=Values::stream(self::scalar($post,'comments_stream','comments'));$service->configure($page,$stream,self::version($post['version']??null,true),['enabled'=>($post['enabled']??null)==='1','moderation'=>($post['moderation']??null)==='1','anonymous'=>($post['anonymous']??null)==='1','max_length'=>self::version($post['max_length']??null)]);
            return$result+['comments_page'=>$page,'comments_stream'=>$stream,'comments_state'=>'settings','notice'=>'Kommentareinstellungen gespeichert.'];
        }
        $id=Values::id($post['comment']??null);$version=self::version($post['version']??null);
        if($operation==='website-comment-moderate'){$value=$service->moderate($id,$version,self::scalar($post,'comment_state'));$notice='Kommentarentscheidung gespeichert.';}
        elseif($operation==='website-comment-edit'){$value=$service->edit($id,$version,['name'=>self::scalar($post,'name'),'body'=>self::scalar($post,'body'),'format'=>self::scalar($post,'format','plain')]);$notice='Kommentarkorrektur gespeichert.';}
        else throw new InvalidArgumentException('Kommentaraktion auswählen.');
        $current=$service->get($id);return$result+['comment'=>$id,'comments_page'=>$current['page_id'],'comments_stream'=>$current['stream'],'comments_state'=>$value['state'],'notice'=>$notice];
    }

    public function render(array $query):string
    {
        $state=self::scalar($query,'comments_state','pending');$service=$this->service();$e=Html::e(...);$manage=$this->can('manage');
        if($state==='settings'){$this->access->require('website','manage');return$this->settings($query);}
        $this->access->require('website','publish');if(!isset(self::STATES[$state]))throw new InvalidArgumentException('Kommentarfilter auswählen.');
        $number=Http::number($query['comments_list_page']??'1',50001);$comment=self::scalar($query,'comment');$original=self::scalar($query,'comments_page');
        $h='<link rel="stylesheet" href="/assets/comments.css"><section class="content native-comment-admin"><header class="comment-heading"><h2>Website-Kommentare</h2><nav aria-label="Kommentare"><a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url()).'">Freigaben</a>'.($manage?'<a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comments_state'=>'settings']+($original!==''?['comments_page'=>$original,'comments_stream'=>self::scalar($query,'comments_stream','comments')]:[]))).'">Einstellungen</a>':'').'</nav></header>';
        if($comment!=='')return$h.$this->detail(Values::id($comment),$query).'</section>';
        $list=$service->moderationList($state,$number);
        $h.='<form method="get" action="/">'.Html::hidden('view','website').Html::hidden('section','comments').Html::select('comments_state','Status',self::STATES,$state).'<button class="btn btn-outline theme-secondary" type="submit">Anzeigen</button></form><p role="status">'.$list['total'].' Kommentare</p><div class="comment-review-list" tabindex="0" role="region" aria-label="Kommentare zur Prüfung">';
        foreach($list['rows']as$row){$plain=Display::text($row['body'],$row['format']);$h.='<article class="comment-message" data-comment-review="'.$e($row['id']).'"><header><strong>'.$e($row['author_name']).'</strong>'.PublicView::time($row['created_at']).'</header>'.$this->sourceLabel($row).'<p>'.$e(mb_substr($plain,0,500)).(mb_strlen($plain)>500?' …':'').'</p><div class="comment-actions"><a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comment'=>$row['id'],'comments_state'=>$state,'comments_list_page'=>$list['page']])).'">Öffnen</a>'.$this->decisions($row).'</div></article>';}
        $h.='</div>'.(!$list['rows']?'<p>Keine Kommentare in diesem Status.</p>':'').self::pager($list['total'],20,$list['page'],static fn(int $page):string=>self::url(['comments_state'=>$state,'comments_list_page'=>$page]),'Kommentarseiten');return$h.'</section>';
    }

    /** Error recovery renders the submitted draft with its original CAS; it never repeats a write. */
    public function correction(array $post,string $error):string
    {
        if(!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new DomainException('Formular neu öffnen.');
        $operation=self::scalar($post,'operation');$query=[];
        if($operation==='website-comment-configure')$query=['comments_state'=>'settings','comments_page'=>Values::id($post['comments_page']??null),'comments_stream'=>Values::stream(self::scalar($post,'comments_stream','comments'))];
        elseif(in_array($operation,['website-comment-edit','website-comment-moderate'],true)){$current=$this->service()->get(Values::id($post['comment']??null));$query=['comments_state'=>$current['state'],'comment'=>$current['id'],'comments_page'=>$current['page_id'],'comments_stream'=>$current['stream']];}
        else throw new InvalidArgumentException('Kommentaraktion prüfen.');
        self::version($post['version']??null,$operation==='website-comment-configure');
        foreach(['name','body','format','max_length']as$key)if(isset($post[$key])&&(!is_string($post[$key])||strlen($post[$key])>400000))throw new InvalidArgumentException('Gesendete Kommentareingabe prüfen.');
        $this->failedPost=$post;
        try{return'<p class="comment-notice comment-error" role="alert">'.Html::e($error).'</p>'.$this->render($query);}finally{$this->failedPost=null;}
    }

    private function settings(array $query):string
    {
        $e=Html::e(...);$pageId=self::scalar($query,'comments_page');$pages=new Pages($this->db,$this->access);
        $h='<link rel="stylesheet" href="/assets/comments.css"><section class="content native-comment-admin"><header class="comment-heading"><h2>Kommentareinstellungen</h2><a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url()).'">← Freigaben</a></header>';
        if($pageId===''){
            $search=Values::text(self::scalar($query,'q'),120,false);$number=Http::number($query['comments_list_page']??'1',50001);
            [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',false,'edit');$where='('.$policy.') AND '.PageTrash::activeSql();
            if($search!==''){$where.=" AND LOCATE(? COLLATE utf8mb4_unicode_ci,CONCAT_WS(' ',JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')),p.slug) COLLATE utf8mb4_unicode_ci)>0";$parameters[]=$search;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_pages p WHERE '.$where,$parameters)->fetchColumn();$last=max(1,(int)ceil($total/20));$number=min($number,$last);$rows=Db::run($this->db,"SELECT p.id,p.slug,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')) AS title FROM khcms_pages p WHERE ".$where.' ORDER BY title,p.id LIMIT 20 OFFSET '.(($number-1)*20),$parameters)->fetchAll();
            $h.='<form method="get" action="/">'.Html::hidden('view','website').Html::hidden('section','comments').Html::hidden('comments_state','settings').Html::input('q','Seite suchen',$search,'search','maxlength="120"').'<button class="btn btn-outline theme-secondary">Suchen</button></form><ul class="comment-page-picker">';
            foreach($rows as$page)$h.='<li><a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comments_state'=>'settings','comments_page'=>$page['id']])).'"><strong>'.$e($page['title']).'</strong><span>'.$e($page['slug']).'</span></a></li>';
            return$h.'</ul>'.self::pager($total,20,$number,static fn(int $page):string=>self::url(['comments_state'=>'settings','comments_list_page'=>$page,'q'=>$search]),'Seiten auswählen').'</section>';
        }
        $page=$pages->get(Values::id($pageId));TemplatePolicy::assertCapability($this->db,$this->access,$pageId,'edit');$choices=Bindings::choices($this->db,$this->access,$pageId);
        $h.='<h3>'.$e($page['title']).'</h3><a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comments_state'=>'settings'])).'">Andere Seite</a>';
        if(!$choices)return$h.'<p>Für diese Seite ist kein Kommentarfeld verfügbar.</p></section>';
        $stream=self::scalar($query,'comments_stream');if($stream==='')$stream=array_key_exists('comments',$choices)?'comments':array_key_first($choices);$stream=Values::stream($stream);
        if(!isset($choices[$stream]))throw new DomainException('Dieses Kommentarfeld ist für die ausgewählte Seite nicht verfügbar.');
        $config=$this->service()->configuration($pageId,$stream);
        $h.='<form method="get" action="/">'.Html::hidden('view','website').Html::hidden('section','comments').Html::hidden('comments_state','settings').Html::hidden('comments_page',$pageId).Html::select('comments_stream','Kommentarfeld',$choices,$stream).'<button class="btn btn-outline theme-secondary">Auswählen</button></form>';
        if(($this->failedPost['operation']??null)==='website-comment-configure'){$h.='<p>Deine ungespeicherte Auswahl bleibt erhalten.</p><a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comments_state'=>'settings','comments_page'=>$pageId,'comments_stream'=>$stream])).'">Aktuellen Stand öffnen</a>';$config['version']=self::version($this->failedPost['version'],true);foreach(['enabled','moderation','anonymous']as$key)$config[$key]=($this->failedPost[$key]??null)==='1';$config['max_length']=$this->failedPost['max_length']??'';}
        $h.=$this->form('website-comment-configure',['comments_page'=>$pageId,'comments_stream'=>$stream,'version'=>$config['version']]);
        foreach(['enabled'=>'Kommentare geöffnet','moderation'=>'Neue Kommentare vor Veröffentlichung prüfen','anonymous'=>'Gäste ohne Anmeldung zulassen']as$key=>$label)$h.='<label class="comment-check"><input type="checkbox" name="'.$key.'" value="1"'.($config[$key]?' checked':'').'>'.$label.'</label>';
        $h.=Html::input('max_length','Maximale Textlänge',(string)$config['max_length'],'number','min="100" max="10000" required').'<button class="btn btn-solid theme-primary">Speichern</button></form>';
        if($config['enabled']&&$page['published_revision']!==null)$h.='<a class="btn btn-outline theme-secondary" href="/_comments?id='.$e($pageId).'&amp;stream='.$e($stream).'">Kommentare auf der Website öffnen</a>';
        return$h.'</section>';
    }

    private function detail(string $id,array $query):string
    {
        $service=$this->service();$current=$service->get($id);$row=$current;$e=Html::e(...);$historical=isset($query['revision']);if($historical)$row=$service->version($id,self::version($query['revision']));
        $h='<a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comments_state'=>self::scalar($query,'comments_state','pending'),'comments_list_page'=>Http::number($query['comments_list_page']??'1',50001)])).'">← Zur Liste</a><article class="comment-message"><header><strong>'.$e($row['author_name']).'</strong><span>'.$e(self::STATES[$row['state']]).'</span>'.PublicView::time($row['created_at']).'</header>'.$this->sourceLabel($row).'<p>Gespeicherte Fassung '.$e((string)$row['version']).'</p><div class="comment-body kh-richtext">'.Display::render($row['body'],$row['format']).'</div></article>';
        if($historical)$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comment'=>$id,'comments_page'=>$current['page_id'],'comments_stream'=>$current['stream'],'comments_state'=>$current['state']])).'">Aktuelle Fassung öffnen</a>';
        else{
            $edit=$row;$failed=($this->failedPost['operation']??null)==='website-comment-edit';
            if($this->failedPost!==null){if(!$failed)$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comment'=>$id,'comments_page'=>$current['page_id'],'comments_stream'=>$current['stream'],'comments_state'=>$current['state']])).'">Aktuellen Stand öffnen</a>';if($failed){foreach(['name'=>'author_name','body'=>'body','format'=>'format']as$posted=>$key)$edit[$key]=$this->failedPost[$posted]??'';$edit['version']=self::version($this->failedPost['version']);$h.='<p>Deine ungespeicherte Korrektur · Fassung '.$e((string)$edit['version']).'</p>';}}
            $h.='<div class="comment-actions">'.($this->failedPost===null?$this->decisions($row):'').'<a class="btn btn-outline theme-secondary" href="/_comments?id='.$e($row['page_id']).'&amp;stream='.$e($row['stream']).'">Website öffnen</a></div><details class="comment-correction"'.($failed?' open':'').'><summary>Text korrigieren</summary>'.$this->form('website-comment-edit',['comment'=>$id,'comments_page'=>$current['page_id'],'comments_stream'=>$current['stream'],'version'=>$edit['version']]).Html::input('name','Anzeigename',$edit['author_name'],'text','required maxlength="100"').'<label>Kommentartext<textarea name="body" rows="6" required maxlength="10000">'.$e($edit['body']).'</textarea></label>'.Html::select('format','Textformat',['plain'=>'Einfacher Text','markdown'=>'Markdown'],$edit['format']).'<div class="comment-actions"><button class="btn btn-solid theme-primary">Korrektur speichern</button><a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comment'=>$id,'comments_page'=>$current['page_id'],'comments_stream'=>$current['stream'],'comments_state'=>$current['state']])).'">Aktuellen Stand öffnen</a></div></form></details>';
            if(($this->failedPost['operation']??null)==='website-comment-moderate')$h.=$this->form('website-comment-moderate',['comment'=>$id,'version'=>self::version($this->failedPost['version']),'comment_state'=>self::scalar($this->failedPost,'comment_state')]).'<button class="btn btn-outline theme-secondary">Ursprüngliche Entscheidung prüfen</button></form>';
        }
        $history=$service->history($id,Http::number($query['comments_history_page']??'1',100001));$h.='<details class="comment-history" id="comment-history"'.(isset($query['comments_history_page'])||$historical?' open':'').'><summary>Verlauf</summary><ol>';
        foreach($history['rows']as$revision)$h.='<li><a class="btn btn-outline theme-secondary" data-nav href="'.$e(self::url(['comment'=>$id,'comments_page'=>$current['page_id'],'comments_stream'=>$current['stream'],'comments_state'=>$current['state'],'comments_history_page'=>$history['page'],'revision'=>$revision['version']])).'#comment-history">Fassung '.$e((string)$revision['version']).' · '.$e(self::actionLabel($revision['action'])).'</a>'.PublicView::time($revision['created_at']).'</li>';
        return$h.'</ol>'.self::pager($history['total'],10,$history['page'],static fn(int $page):string=>self::url(['comment'=>$id,'comments_page'=>$current['page_id'],'comments_stream'=>$current['stream'],'comments_state'=>$current['state'],'comments_history_page'=>$page]).'#comment-history','Verlaufsseiten').'</details>';
    }

    private function decisions(array $row):string
    {
        $h='';$targets=$row['state']==='pending'?['approved'=>'Freigeben','rejected'=>'Ablehnen','spam'=>'Spam']:['pending'=>'Zurückhalten','approved'=>'Veröffentlichen','removed'=>'Ausblenden'];
        foreach($targets as$state=>$label)if($row['state']!==$state)$h.=$this->form('website-comment-moderate',['comment'=>$row['id'],'comments_page'=>$row['page_id'],'comments_stream'=>$row['stream'],'version'=>$row['version'],'comment_state'=>$state]).'<button class="btn '.($state==='approved'?'btn-solid theme-primary':'btn-outline theme-secondary').'">'.$label.'</button></form>';
        return$h;
    }
    private function form(string $operation,array $input=[]):string{$h='<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation',$operation);foreach($input as$key=>$value)$h.=Html::hidden($key,$value);return$h;}
    private function sourceLabel(array $row):string
    {
        $id=$row['page_id'];if(!isset($this->pageLabels[$id]))$this->pageLabels[$id]=Db::run($this->db,"SELECT JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.title')) FROM khcms_pages WHERE id=? AND club_id=?",[$id,$this->access->club()])->fetchColumn()?:'Seite';
        return'<p class="comment-source"><a data-nav href="/?view=website&amp;item='.Html::e($id).'">'.Html::e($this->pageLabels[$id]).'</a><span> · '.Html::e($row['stream']==='comments'?'Kommentare':$row['stream']).'</span></p>';
    }
    private static function pager(int $total,int $size,int $page,callable $url,string $label):string{return str_replace('<a ','<a data-nav ',Pager::render($total,$size,$page,$url,$label));}
    private static function actionLabel(string $action):string{return['submit'=>'Gesendet','edit'=>'Korrigiert',...self::STATES][$action]??'Gespeichert';}
    private function can(string $capability):bool{try{$this->access->require('website',$capability);return true;}catch(DomainException){return false;}}
    private static function url(array $query=[]):string{return'/?'.http_build_query($query+['view'=>'website','section'=>'comments'],'','&',PHP_QUERY_RFC3986);}
}
