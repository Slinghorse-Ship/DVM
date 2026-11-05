<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\InboxHistory;

/** Previous native originals use the same bounded chat, without reply or mail actions. */
final class InboxHistoryPanel extends Workspace
{
    use ConversationInterfaceText;
    public function render(string $thread,array $context):string
    {
        $history=(new InboxHistory($this->db,$this->box()))->view($this->access,$thread,$_GET);
        $base=['view'=>'social','section'=>'inbox','thread'=>$thread,'inbox_history'=>'1']+$context;
        $url=static fn(array $args):string=>'/?'.http_build_query($args+$base);
        $h='<header class="chat-person" data-inbox-history><div><h3 data-original-content>'.Html::e(trim($history['selected']['name']??'')?:$this->conversationText('Frühere Nachrichten')).'</h3>'.ChatLayout::identity('message',$history['channel'],$this->conversationMessages()).'<p class="chat-status">'.Html::e(($history['kind']==='connections'?$this->conversationText('Früherer Kontoverlauf'):$this->conversationText('Frühere Nachrichtenversion'))).' · '.Html::e($this->conversationText('Lesen')).'</p></div></header><div class="chat-catalogue-controls">';
        $h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'inbox','thread'=>$thread]+$context)).'">' . Html::e($this->conversationText('Zum aktuellen Chat')) . '</a><nav aria-label="' . Html::e($this->conversationText('Nachrichtenhistorie')) . '">';
        foreach(['versions'=>$this->conversationText('Nachrichtenversionen'),'connections'=>$this->conversationText('Kontoverläufe')]as$kind=>$label){
            $args=['inbox_history_kind'=>$kind];if($kind==='versions'&&$history['selected_connection']!=='')$args['inbox_connection']=$history['selected_connection'];
            $h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($url($args)).'"'.($history['kind']===$kind?' aria-current="page"':'').'>'.Html::e($label).'</a>';
        }
        $h.='</nav><details class="chat-plan-menu"><summary>'.Html::e($this->conversationText('{count} Fassungen · {page}/{pages}',['count'=>$history['total'],'page'=>$history['page'],'pages'=>$history['pages']])).'</summary><nav class="chat-history-choices" aria-label="' . Html::e($this->conversationText('Frühere Fassungen')) . '">';
        foreach($history['rows']as$row){
            $args=['inbox_history_kind'=>$history['kind'],'inbox_history_page'=>$history['page']];
            if($history['kind']==='connections')$args['inbox_connection']=$row['id'];else{$args['inbox_connection']=$history['connection'];$args['inbox_version']=$row['id'];}
            $h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($url($args)).'"'.(($history['selected']['id']??'')===$row['id']?' aria-current="page"':'').'><span>'.Html::e($this->conversationText($history['kind']==='connections'?'Kontoverlauf {number}':'Fassung {number}',['number'=>$row['number']])).' · '.Html::e($this->time($row['date'])).'</span><small data-original-content>'.Html::e(mb_substr($row['text'],0,90)).'</small></a>';
        }
        if(!$history['rows'])$h.='<p class="chat-empty">' . Html::e($this->conversationText('Keine früheren Fassungen')) . '</p>';
        $h.='</nav></details>';
        foreach([-1=>$this->conversationText('Vorherige Fassungen'),1=>$this->conversationText('Weitere Fassungen')]as$direction=>$label){$page=$history['page']+$direction;if($page>=1&&$page<=$history['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($url(['inbox_history_kind'=>$history['kind'],'inbox_connection'=>$history['connection'],'inbox_history_page'=>$page])).'">'.Html::e($label).'</a>';}
        $timeline=$history['timeline'];$h.='</div><div class="chat-messages" tabindex="0" aria-label="' . Html::e($this->conversationText('Früherer Nachrichtenverlauf')) . '">';
        $pageLink=static fn(int $page):string=>$url(['inbox_history_kind'=>$history['kind'],'inbox_connection'=>$history['selected_connection'],'inbox_version'=>$history['version'],'inbox_history_page'=>$history['page'],'message_page'=>$page]);
        if($timeline['older'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($pageLink($timeline['page']+1)).'">' . Html::e($this->conversationText('Ältere Nachrichten')) . '</a>';
        foreach($timeline['messages']as$message)$h.=ChatLayout::bubble($message['incoming']?($message['from_name']?:$this->conversationText('Gespräch')):$this->conversationText('Ihr'),$message['text'],$this->time($message['created_at']),!$message['incoming'],isset($message['mail_status'])?ReplyControls::status($message['mail_status'],$this->conversationMessages()).' · '.$this->conversationText('E-Mail'):'',utc:$message['created_at']);
        if($timeline['newer'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($pageLink($timeline['page']-1)).'">' . Html::e($this->conversationText('Neuere Nachrichten')) . '</a>';
        return $h.'</div>';
    }
    private function time(string $utc):string
    {
        return $utc===''?'':$this->conversationDate($utc);
    }
}
