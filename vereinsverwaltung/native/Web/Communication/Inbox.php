<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\{InboxCatalogue,ConversationTimeline,Replies as ReplyService,MessageReplyProvider};

/** One real stored conversation at a time, with bounded message history. */
final class Inbox extends Workspace
{
    use ConversationInterfaceText;
    private array $query=[];
    public function manager(?array $humanState=null,?array $syncState=null): string
    {
        $this->access->require('social','manage');$this->query=$syncState['selection']??$_GET;$query=$this->query;$sync=new InboxSync($this->db,$this->access,$this->mediaRoot,$this->csrf);
        try{InboxSync::selection($query);}catch(\DomainException $error){return$sync->invalid($query,$error->getMessage());}
        $catalogue=new InboxCatalogue($this->db,$this->box());$result=$catalogue->listing($this->access,InboxCatalogue::query($query));$rows=$result['rows'];
        $id=is_string($query['thread']??null)?$query['thread']:($rows[0]['id']??'');
        $selected=null;$list='';
        foreach($rows as$row){
            $hit=array_intersect_key($row,array_flip(['message_page','focus_message']));
            $list.=ChatLayout::choice('/?'.http_build_query(['view'=>'social','section'=>'inbox','thread'=>$row['id']]+$hit+$this->context()),$row['name'],$row['text'],$row['channel'],$row['id']===$id,$this->conversationDate($row['last_incoming']),'message',$this->conversationMessages());
        }
        $historical=($query['inbox_history']??'')==='1';
        if($id!==''&&!$historical)try{$selected=$catalogue->conversation($this->access,$id);}catch(\DomainException $error){if($syncState!==null)return$sync->invalid($query,$error->getMessage());throw$error;}
        $tools='';
        if(!$historical){
        $tools='<details class="chat-plan-menu"><summary>' . Html::e($this->conversationText('Abgleichen')) . '</summary><div class="chat-plan-panel">';
        foreach(['facebook'=>'Facebook','instagram'=>'Instagram']as$channel=>$label)$tools.=$sync->form($channel,['thread'=>$id]+$query).'<button class="btn btn-outline theme-secondary">'.Html::e($this->conversationText('{platform} abgleichen',['platform'=>$label])).'</button></form>';
        $tools.='</div></details>'.(new Mail($this->db,$this->access,$this->mediaRoot,$this->csrf))->tools();
        if($this->can('social','manage'))$tools.=(new Review($this->db,$this->access,$this->mediaRoot,$this->csrf))->tools();
        }
        $thread='<p class="chat-empty">' . Html::e($this->conversationText('Keine Nachrichten')) . '</p>';
        if($historical&&$id!==''){
            $thread=(new InboxHistoryPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($id,$this->context());
            $selected=['id'=>$id];
        }elseif($selected){
            $service=new ReplyService($this->db,$this->box());$source=null;$reply=null;$connection=null;$hint='';
            try{$source=$service->messageOriginal($this->access,$id);if(empty($source['_archived']))$connection=$service->connection($this->access,$source['id']);else$hint=$this->conversationText('Original archiviert');}
            catch(\DomainException){$hint=$this->conversationText('Gespräch erneut abgleichen.');}
            $historyConnection=$connection??($source?$service->retainedConnection($this->access,$source):null);
            $replyId=is_string($query['reply']??null)?$query['reply']:'';
            if($replyId!==''){
                $reply=$service->get($this->access,$replyId);
                if(($reply['payload']['kind']??'comment')!=='message'||MessageReplyProvider::threadId($this->access->club(),$reply['channel'],$reply['parent_id'])!==$id)throw new \DomainException('Antwort gehört nicht zu diesem Gespräch.');
            }elseif($source&&$historyConnection)$reply=$service->forSource($this->access,$source,$historyConnection);
            $focus=$query['focus_message']??'';if(!is_string($focus)||strlen($focus)>205)throw new \DomainException('Nachrichtensuchtreffer prüfen.');
            $timelineService=new ConversationTimeline($service);$page=$focus!==''?$timelineService->pageContaining($this->access,$selected['messages'],$source,$historyConnection,$focus):($query['message_page']??1);
            $timeline=$timelineService->page($this->access,$selected['messages'],$source,$historyConnection,$page);$page=$timeline['page'];
            $label=$selected['channel']==='facebook'?'Facebook':'Instagram';
            $historyLink='';
            if(!empty($selected['payload']['message_versions'])||!empty($selected['payload']['connection_history']))$historyLink='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'inbox','thread'=>$id,'inbox_history'=>'1','inbox_history_kind'=>!empty($selected['payload']['message_versions'])?'versions':'connections']+$this->context())).'">' . Html::e($this->conversationText('Frühere Fassungen')) . '</a>';
            $thread='<header class="chat-person"><div><h3 data-original-content>'.Html::e($selected['name']).'</h3>'.ChatLayout::identity('message',$selected['channel'],$this->conversationMessages()).'</div>'.$historyLink.'</header><div class="chat-messages"'.($page===1?' data-chat-latest':'').' tabindex="0" aria-label="'.Html::e($this->conversationText('Nachrichtenverlauf')).'">';
            if($timeline['older'])$thread.=$this->pageLink($id,$page+1,$this->conversationText('Ältere Nachrichten'));
            foreach($timeline['messages']as$message)$thread.=ChatLayout::bubble($message['incoming']?($message['from_name']?:$selected['name']):$this->conversationText('Ihr'),$message['text'],$this->conversationDate($message['created_at']),!$message['incoming'],isset($message['mail_status'])?ReplyControls::status($message['mail_status'],$this->conversationMessages()).' · '.$this->conversationText('E-Mail'):(isset($message['human_status'])?ReplyControls::humanStatus($message['human_status'],$this->conversationMessages()):($message['status']??'')),$message['id']===$focus,$result['q'],utc:$message['created_at']);
            if($reply&&$page===1&&!in_array($reply['remote_id'],array_column($selected['messages'],'id'),true)&&!in_array($reply['id'],array_column($timeline['messages'],'local_reply_id'),true))$thread.='<div class="social-reply-preview">'.ChatLayout::bubble($this->conversationText('Ihr'),$reply['payload']['text'],$this->conversationDate($reply['updated_at']),true,ReplyControls::status($reply['status'],$this->conversationMessages()),utc:$reply['updated_at']).'</div>';
            if($timeline['newer'])$thread.=$this->pageLink($id,$page-1,$this->conversationText('Neuere Nachrichten'));
            $thread.='</div>'.(new ReplyControls($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($source,$reply,$connection,$hint,$humanState);
            if((!$source||!$connection)&&empty($source['_archived']))$thread.='<footer class="chat-composer"><a class="btn btn-solid theme-primary" target="_blank" rel="noopener noreferrer" href="'.($selected['channel']==='facebook'?'https://www.facebook.com/messages/':'https://www.instagram.com/direct/inbox/').'">'.Html::e($this->conversationText('Bei {platform} antworten ↗',['platform'=>$label])).'</a></footer>';
        }
        $pagination='<div class="chat-catalogue-controls"><form method="get">'.Html::hidden('view','social').Html::hidden('section','inbox').Html::hidden('chat_list','1').Html::hidden('q',$result['q']).Html::select('inbox_channel',$this->conversationText('Plattform'),['all'=>$this->conversationText('Alle'),'facebook'=>'Facebook','instagram'=>'Instagram'],$result['channel']).'<button class="btn btn-outline theme-secondary">' . Html::e($this->conversationText('Anzeigen')) . '</button></form><nav aria-label="' . Html::e($this->conversationText('Gesprächsseiten')) . '"><span>'.Html::e($this->conversationText('{count} Gespräche · {page}/{pages}',['count'=>$result['total'],'page'=>$result['page'],'pages'=>$result['pages']])).'</span>';
        foreach([-1=>$this->conversationText('Vorherige Gespräche'),1=>$this->conversationText('Weitere Gespräche')]as$direction=>$label){$page=$result['page']+$direction;if($page>=1&&$page<=$result['pages'])$pagination.='<a class="btn btn-outline theme-secondary" data-nav href="/?'.Html::e(http_build_query(['view'=>'social','section'=>'inbox','chat_list'=>'1','conversation_page'=>$page,'inbox_channel'=>$result['channel'],'q'=>$result['q']])).'">'.Html::e($label).'</a>';}
        $notice=isset($syncState['error'])?'<p role="alert" data-inbox-sync-result>'.Html::e($this->conversationText($syncState['error'])).'</p>':(isset($syncState['notice'])?'<p role="status" data-inbox-sync-result>'.Html::e($this->conversationText($syncState['notice'])).'</p>':'');
        return $notice.ChatLayout::frame($list?:'<p class="chat-empty">' . Html::e($this->conversationText('Keine Gespräche')) . '</p>',$thread,$tools,'inbox',$selected !== null && isset($query['thread']),$pagination.'</nav>'.ChatLayout::pagePicker('inbox',$this->context(),$result['page'],$result['pages'],$this->conversationMessages()).'</div>',$this->conversationMessages(),$query);
    }
    private function pageLink(string $id,int $page,string $label):string
    {
        return '<a class="btn btn-outline theme-secondary" data-nav href="/?'.Html::e(http_build_query(['view'=>'social','section'=>'inbox','thread'=>$id,'message_page'=>$page]+$this->context())).'">'.Html::e($label).'</a>';
    }
    private function context():array
    {
        return ['conversation_page'=>$this->query['conversation_page']??1,'inbox_channel'=>$this->query['inbox_channel']??'all','q'=>$this->query['q']??''];
    }
}
