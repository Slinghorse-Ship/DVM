<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use Kulturhus\Native\Web\{Workspace, Html};
use Kulturhus\Native\Communication\{Replies as ReplyService, AttentionCatalogue,ConversationTimeline};

/** Human-written replies in a bounded conversation view. */
final class Replies extends Workspace
{
    use ConversationInterfaceText;
    public function manager(?array $humanState=null): string
    {
        $this->access->require('social', 'manage');
        $service = new ReplyService($this->db, $this->box());
        $id = is_string($_GET['reply'] ?? null) ? $_GET['reply'] : '';
        $attention = is_string($_GET['attention'] ?? null) ? $_GET['attention'] : '';
        $original=$_GET['original']??'';if(!is_string($original))throw new \DomainException('Ursprüngliche Fassung prüfen.');
        $catalogue=new AttentionCatalogue($this->db,$this->box());
        $result=$catalogue->listing($this->access,AttentionCatalogue::query($_GET));$rows=$result['rows'];
        $state=$result['state'];
        $reply = $id !== '' ? $service->get($this->access, $id) : null;
        if($reply&&($reply['payload']['kind']??'comment')!=='comment')throw new \DomainException('Nachrichtenantwort im Nachrichtengespräch öffnen.');
        if ($reply) $attention = $reply['attention_id'];
        if ($attention === '' && $rows) {
            $attention = $rows[0]['id'];
            if($rows[0]['reply_id']!=='')$reply=$service->get($this->access,$rows[0]['reply_id']);
        }
        $source = null; $connection = null; $hint = '';
        if ($attention !== '') {
            try { $source = $catalogue->attention($this->access, $attention,$original); }
            catch (\DomainException $error) { if (!$reply||$original!=='') throw $error; }
        }
        if($original!==''&&$reply&&!hash_equals($reply['source_fingerprint'],$source['fingerprint']))throw new \DomainException('Antwort gehört nicht zu dieser ursprünglichen Fassung.');
        if ($source && $source['kind'] === 'comment' && empty($source['_archived'])) {
            try { $connection = $service->connection($this->access, $attention); }
            catch (\DomainException) { $hint = $this->conversationText('Verbindung oder Original erneut abgleichen.'); }
        }
        if(!empty($source['_archived']))$hint=$this->conversationText('Original archiviert');
        if($source&&$source['kind']==='message')throw new \DomainException('Nachricht im Nachrichtengespräch öffnen.');
        $historyConnection=$connection??($source?$service->retainedConnection($this->access,$source):null);
        if (!$reply && $source && $historyConnection) $reply=$service->forSource($this->access,$source,$historyConnection);
        $list = '';
        foreach ($rows as $row) {
            $query=['view'=>'social','section'=>'review','attention'=>$row['id']]+$this->context();
            if($row['reply_id']!=='')$query['reply']=$row['reply_id'];
            $list .= ChatLayout::choice('/?' . http_build_query($query), $row['name'], $row['text'], $row['channel'], $row['id'] === $attention, self::state($row['state'],$this->conversationMessages()), $row['kind'],$this->conversationMessages(),$this->originLabel($row['origin']??null,$row['title']));
        }
        if ($list === '') $list = '<p class="chat-empty">' . Html::e($this->conversationText('Keine Gespräche')) . '</p>';
        $thread = ($source || $reply) ? $this->thread($source, $reply, $connection, $hint,$humanState) : '<p class="chat-empty">' . Html::e($this->conversationText('Keine Kommentare')) . '</p>';
        $tools = (new Review($this->db,$this->access,$this->mediaRoot,$this->csrf))->tools();
        $tools .= (new Mail($this->db,$this->access,$this->mediaRoot,$this->csrf))->tools();
        $pagination='<div class="chat-catalogue-controls"><details class="chat-catalogue-filters"><summary>' . Html::e($this->conversationText('Filter')) . '</summary><form method="get">'.Html::hidden('view','social').Html::hidden('section','review').Html::hidden('chat_list','1').Html::hidden('q',$result['q']).Html::select('review_channel',$this->conversationText('Plattform'),['all'=>$this->conversationText('Alle'),'facebook'=>'Facebook','instagram'=>'Instagram','stadtlandfunk'=>'StadtLand.Funk'],$result['channel']).Html::select('state',$this->conversationText('Status'),['all'=>$this->conversationText('Alle'),'open'=>$this->conversationText('Offen'),'new'=>$this->conversationText('Neu'),'reply_needed'=>$this->conversationText('Antwort nötig'),'done'=>$this->conversationText('Erledigt')],$state).Html::select('review_kind',$this->conversationText('Art'),['all'=>$this->conversationText('Alle'),'comment'=>$this->conversationText('Kommentare'),'reaction'=>$this->conversationText('Reaktionen')],$result['kind']).'<button class="btn btn-outline theme-secondary">' . Html::e($this->conversationText('Anzeigen')) . '</button></form></details><nav aria-label="' . Html::e($this->conversationText('Gesprächsseiten')) . '"><span>'.Html::e($this->conversationText('{count} Gespräche · {page}/{pages}',['count'=>$result['total'],'page'=>$result['page'],'pages'=>$result['pages']])).'</span>';
        foreach([-1=>$this->conversationText('Vorherige Gespräche'),1=>$this->conversationText('Weitere Gespräche')]as$direction=>$label){$page=$result['page']+$direction;if($page>=1&&$page<=$result['pages'])$pagination.='<a class="btn btn-outline theme-secondary" data-nav href="/?'.Html::e(http_build_query(['view'=>'social','section'=>'review','chat_list'=>'1','review_page'=>$page]+array_diff_key($this->context(),['review_page'=>true]))).'">'.Html::e($label).'</a>';}
        return ChatLayout::frame($list, $thread, $tools, 'review', isset($_GET['attention']) || $id !== '',$pagination.'</nav>'.ChatLayout::pagePicker('review',$this->context(),$result['page'],$result['pages'],$this->conversationMessages()).'</div>',$this->conversationMessages());
    }

    private function thread(?array $source, ?array $reply, ?string $connection, string $hint,?array $humanState=null): string
    {
        $original = $reply['payload']['original'] ?? $source['payload'];
        $channel = $reply['channel'] ?? $source['channel'];
        $parent = $reply['parent_id'] ?? $source['parent_id'];
        $origin=$source?(new AttentionCatalogue($this->db,$this->box()))->origin($this->access,$source):null;
        $h = '<div class="social-reply-layout"><header class="chat-person"><div><h3 data-original-content>' . Html::e($original['name'] ?: $this->conversationText('Kommentar')) . '</h3>' . ChatLayout::identity($source['kind'] ?? 'comment', $channel,$this->conversationMessages()) . '</div>';
        if ($channel === 'stadtlandfunk') $h .= '<a class="btn btn-outline theme-secondary" target="_blank" rel="noopener noreferrer" href="https://www.stadtlandfunk.app/post-detail?' . Html::e(http_build_query(['id'=>$parent])) . '">' . Html::e($this->conversationText('Original ↗')) . '</a>';
        $question=['id'=>$original['remote_id']??'original-question','text'=>$original['text'],'incoming'=>true,'from_name'=>$original['name']?:$this->conversationText('Kommentar'),'created_at'=>$original['created_at']];
        $service=new ReplyService($this->db,$this->box());$historyConnection=$connection??($reply['connection_id']??($source?$service->retainedConnection($this->access,$source):null));
        $timeline=(new ConversationTimeline($service))->page($this->access,[$question],$source,$historyConnection,$_GET['message_page']??1);
        $h .= '</header>'.$this->originPanel($origin,$original['title']).'<div class="chat-messages"'.($timeline['page']===1?' data-chat-latest':'').' tabindex="0" aria-label="'.Html::e($this->conversationText('Gesprächsverlauf')).'">';

        $h.=(new OriginalHistory($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($source['id']??$reply['attention_id'],$this->context());
        if($timeline['older'])$h.=$this->pageLink($source,$reply,$timeline['page']+1,$this->conversationText('Ältere Nachrichten'));
        foreach($timeline['messages']as$message)$h.=ChatLayout::bubble($message['incoming']?$message['from_name']:$this->conversationText('Ihr'),$message['text'],$message['created_at']!==''?$this->conversationDate($message['created_at']):'',!$message['incoming'],isset($message['mail_status'])?ReplyControls::status($message['mail_status'],$this->conversationMessages()).' · '.$this->conversationText('E-Mail'):(isset($message['human_status'])?ReplyControls::humanStatus($message['human_status'],$this->conversationMessages()):''),utc:$message['created_at']);
        if ($reply&&($reply['reply_slot']??'')===''&&$timeline['page']===1&&!in_array($reply['id'],array_column($timeline['messages'],'local_reply_id'),true)) $h .= '<div class="social-reply-preview">' . ChatLayout::bubble($this->conversationText('Ihr'),$reply['payload']['text'],$this->conversationDate($reply['updated_at']),true,self::status($reply['status'],$this->conversationMessages()),utc:$reply['updated_at']) . '</div>';
        if($timeline['newer'])$h.=$this->pageLink($source,$reply,$timeline['page']-1,$this->conversationText('Neuere Nachrichten'));
        return $h . '</div>' . (new ReplyControls($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($source,$reply,$connection,$hint,$humanState) . '</div>';
    }

    private function originLabel(?array $origin,string $fallback=''):string
    {
        if(!$origin)return $fallback!==''?$this->conversationText('Bezug').': '.mb_substr($fallback,0,160):$this->conversationText('Bezug nicht mehr verfügbar');
        $kind=['post'=>'Beitrag','event'=>'Veranstaltung','story'=>'Story','reel'=>'Reel','video'=>'Video','carousel'=>'Karussell'][$origin['kind']]??'Beitrag';
        return $this->conversationText($kind).': '.mb_substr($origin['text']?:$this->conversationText('Ohne Anbieterbeschreibung'),0,160);
    }
    private function originPanel(?array $origin,string $fallback):string
    {
        $h='<section class="chat-context social-comment-origin" aria-label="'.Html::e($this->conversationText('Bezug')).'"><strong data-original-content>'.Html::e($this->originLabel($origin,$fallback)).'</strong>';
        if($origin&&$origin['url']!=='')$h.='<a class="btn btn-outline theme-secondary" target="_blank" rel="noopener noreferrer" href="'.Html::e($origin['url']).'">'.Html::e($this->conversationText('Original öffnen ↗')).'</a>';
        return $h.'</section>';
    }

    private function pageLink(?array $source,?array $reply,int $page,string $label):string
    {
        $query=['view'=>'social','section'=>'review','attention'=>$source['id']??$reply['attention_id'],'message_page'=>$page]+$this->context();
        if($reply&&is_string($_GET['reply']??null))$query['reply']=$reply['id'];
        foreach(['original','original_page']as$key)if(is_string($_GET[$key]??null))$query[$key]=$_GET[$key];
        return '<a class="btn btn-outline theme-secondary" data-nav href="/?'.Html::e(http_build_query($query)).'">'.Html::e($label).'</a>';
    }

    private function context():array
    {
        return ['review_page'=>$_GET['review_page']??1,'review_channel'=>$_GET['review_channel']??'all','review_kind'=>$_GET['review_kind']??'all','state'=>$_GET['state']??'all','q'=>$_GET['q']??''];
    }

    private static function channel(string $channel): string { return ['facebook'=>'Facebook','instagram'=>'Instagram','stadtlandfunk'=>'StadtLand.Funk'][$channel] ?? 'Kanal'; }
    private static function state(string $state,array $messages=[]): string { return \Kulturhus\Native\Language\ConversationMessages::text(['new'=>'Neu','reply_needed'=>'Antwort nötig','done'=>'Erledigt','saved'=>'Gespeichert','archived'=>'Archiviert'][$state] ?? '',$messages); }
    private static function status(string $state,array $messages=[]): string { return \Kulturhus\Native\Language\ConversationMessages::text(['draft'=>'Entwurf · nicht versendet','sending'=>'Versand begonnen','sent'=>'Gesendet','uncertain'=>'Ergebnis unklar','rejected'=>'Abgelehnt','blocked'=>'Gestoppt'][$state] ?? 'Status prüfen',$messages); }
}
