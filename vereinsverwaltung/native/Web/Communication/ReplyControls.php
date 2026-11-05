<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};

/** The same human draft, explicit send and status controls for both conversation types. */
final class ReplyControls extends Workspace
{
    use ConversationInterfaceText;
    public function render(?array $source,?array $reply,?string $connection,string $hint='',?array $humanState=null):string
    {
        $unchanged=$source&&(!$reply||hash_equals($reply['source_fingerprint'],$source['fingerprint']));
        $active=$source&&empty($source['_archived']);
        $hiddenComment=$source&&$source['kind']==='comment'&&($source['payload']['hidden']??null)===true;
        $canReply=!$hiddenComment||$source['channel']!=='instagram';
        $editable=$active&&$canReply&&in_array($source['kind'],['comment','message'],true)&&$connection!==null&&$unchanged&&(!$reply||(($reply['reply_slot']??'')===''&&$reply['connection_id']===$connection&&in_array($reply['status'],['draft','rejected','blocked'],true)))&&$this->can('social','edit');
        $h='<footer class="chat-composer">';
        if($hiddenComment)$h.='<p class="chat-status" data-comment-hidden>' . Html::e($this->conversationText('Kommentar ausgeblendet')) . '</p>';
        $window=true;
        if($source&&$source['kind']==='message'){
            $date=$source['payload']['created_at'];
            $window=is_string($date)&&$date>gmdate('Y-m-d H:i:s',time()-86400)&&$date<=gmdate('Y-m-d H:i:s',time()+30);
            if(!$window)$h.='<p class="chat-status">' . Html::e($this->conversationText('Antwortfenster geschlossen')) . '</p>';
        }
        if($hint!=='')$h.='<p class="chat-status">'.Html::e($hint).'</p>';
        if($reply&&!$unchanged)$h.='<p class="chat-status">' . Html::e($this->conversationText('Original geändert · erneut abgleichen')) . '</p>';
        $humanService=new \Kulturhus\Native\Communication\HumanSends($this->db,new \Kulturhus\Native\Communication\Replies($this->db,$this->box()));
        $humanInstalled=$humanService->installed();
        if($humanInstalled&&$reply&&\Kulturhus\Native\Shared\Db::run($this->db,'SELECT 1 FROM native_social_human_sends WHERE club_id=? AND reply_id=?',[$this->access->club(),$reply['id']])->fetchColumn())$editable=false;
        $direct=$humanInstalled&&$active&&$canReply&&in_array($source['kind'],['comment','message'],true)&&($source['kind']==='message'||$unchanged)&&$connection!==null&&$window&&$this->can('social','manage')&&$this->can('social','edit')&&$this->can('social','publish')&&(!$reply||!in_array($reply['status'],['sending','uncertain'],true));
        if($humanState&&$source&&$humanState['attention_id']!==$source['id'])throw new \DomainException('Menschliche Eingabe gehört zu einem anderen Gespräch.');
        if($humanState&&!empty($humanState['error']))$h.='<p role="alert">'.Html::e($this->conversationText($humanState['error'])).'</p>';
        $moderation=new ModerationControls($this->db,$this->access,$this->mediaRoot,$this->csrf);$hasComposer=false;
        if($editable||$direct||($humanState&&$active&&$connection!==null&&$this->can('social','edit'))){
            $hasComposer=true;
            $instagramMessage=$source['kind']==='message'&&$source['channel']==='instagram';
            $values=$humanState??['attention_id'=>$source['id'],'attention_version'=>$source['version'],'reply_id'=>$editable?($reply['id']??''):'','reply_version'=>$editable?($reply['version']??0):0,'reply_text'=>$editable?($reply['payload']['text']??''):'','human_key'=>bin2hex(random_bytes(16))];
            $h.=$this->start('social-reply-save');foreach(['attention_id','attention_version','reply_id','reply_version','human_key']as$key)$h.=Html::hidden($key,$values[$key]);
            $h.='<label>' . Html::e($this->conversationText('Deine Antwort')) . '<textarea class="form-control" name="reply_text" rows="2" maxlength="'.($instagramMessage?'999':'2000').'"'.($instagramMessage?' data-reply-byte-limit="1000"':'').' required placeholder="'.Html::e($this->conversationText('Antwort schreiben …')).'">'.Html::e($values['reply_text']).'</textarea></label><div class="actions">';
            if($direct)$h.='<button class="btn btn-solid theme-primary" name="human_send" value="1" data-human-send>' . Html::e($this->conversationText('Senden')) . '</button>';
            if($editable&&!$humanState)$h.='<button class="btn btn-outline theme-secondary">' . Html::e($this->conversationText('Entwurf speichern')) . '</button>';
            if($humanState)$h.='<button class="btn btn-outline theme-secondary" name="operation" value="social-reply-human-review">' . Html::e($this->conversationText('Neu prüfen')) . '</button>';
            $h.='</div>'.$moderation->render($source,true).'</form>';
        }
        if(!$hasComposer)$h.=$moderation->render($source,false);
        if(!$humanInstalled&&$active&&$canReply&&$reply&&$reply['status']==='draft'&&$reply['connection_id']===$connection&&$unchanged&&$window&&$this->can('social','publish')){
            $h.='<details class="chat-send"><summary>' . Html::e($this->conversationText('Entwurf senden')) . '</summary>'.$this->start('social-reply-send').Html::hidden('reply_id',$reply['id']).Html::hidden('reply_version',$reply['version']).'<label class="check"><input class="check" type="checkbox" name="reply_confirm" value="1" required>' . ' ' . Html::e($this->conversationText('Gespeicherte Antwort geprüft')) . '</label><button class="btn btn-solid theme-primary">' . Html::e($this->conversationText('Senden')) . '</button></form></details>';
        }
        if($reply&&in_array($reply['status'],['uncertain','sending'],true))$h.='<p class="chat-status">' . Html::e($this->conversationText('Ergebnis unklar · nicht erneut senden')) . '</p>';
        if($active&&$reply&&$reply['remote_id']&&$this->can('social','publish'))$h.=$this->start('social-reply-reconcile').Html::hidden('reply_id',$reply['id']).Html::hidden('reply_version',$reply['version']).'<button class="btn btn-outline theme-secondary">' . Html::e($this->conversationText('Ergebnis prüfen')) . '</button></form>';
        if($active&&$source['kind']==='comment')$h.='<details><summary>' . Html::e($this->conversationText('Status')) . '</summary>'.$this->start('social-attention-state').Html::hidden('attention_id',$source['id']).Html::hidden('attention_version',$source['version']).Html::select('attention_state',$this->conversationText('Status'),['new'=>$this->conversationText('Neu'),'reply_needed'=>$this->conversationText('Antwort nötig'),'done'=>$this->conversationText('Erledigt')],$source['state']).'<button class="btn btn-outline theme-secondary">' . Html::e($this->conversationText('Übernehmen')) . '</button></form></details>';
        if($active)$h.=(new Mail($this->db,$this->access,$this->mediaRoot,$this->csrf))->panel($source,$reply,$connection);
        return $h.'</footer>';
    }
    public static function status(string $state,array $messages=[]):string
    {
        return \Kulturhus\Native\Language\ConversationMessages::text(['draft'=>'Entwurf · nicht versendet','queued'=>'Wird gesendet','sending'=>'Versand begonnen','sent'=>'Gesendet','uncertain'=>'Ergebnis unklar','rejected'=>'Abgelehnt','blocked'=>'Nicht gesendet'][$state]??'Status prüfen',$messages);
    }
    public static function humanStatus(string $state,array $messages=[]):string
    {
        return in_array($state,['draft','queued'],true)?\Kulturhus\Native\Language\ConversationMessages::text('Noch nicht gesendet',$messages):self::status($state,$messages);
    }
}
