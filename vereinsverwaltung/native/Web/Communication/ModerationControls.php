<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\CommentModeration;

/** Same submitted original and human reply fields in script and ordinary HTML forms. */
final class ModerationControls extends Workspace
{
    use ConversationInterfaceText;
    public function render(?array $source,bool $inside):string
    {
        if(!$source||!empty($source['_archived'])||$source['kind']!=='comment'||!in_array($source['channel'],['facebook','instagram'],true)||!$this->can('social','manage')||!$this->can('social','edit')||!$this->can('social','publish'))return'';
        $service=new CommentModeration($this->db,$this->box());if(!$service->installed())return'';$latest=$service->latest($this->access,$source['id']);
        $pending=$latest&&in_array($latest['status'],['pending','sending','uncertain'],true);$h='<div class="chat-moderation" data-comment-moderation>';
        if($latest)$h.='<p class="chat-status" data-moderation-status>'.Html::e(self::label($latest,$this->conversationMessages())).'</p>';
        if(!$inside)$h.=$this->start($pending?'social-comment-moderation-check':'social-comment-moderate').Html::hidden('attention_id',$source['id']).Html::hidden('attention_version',$source['version']);
        if($pending){
            $h.=Html::hidden('moderation_id',$latest['id']).Html::hidden('moderation_close_version',$latest['version']);
            if(in_array($latest['status'],['sending','uncertain'],true))$h.='<button class="btn btn-outline theme-secondary" name="operation" value="social-comment-moderation-check" formnovalidate>' . Html::e($this->conversationText('Ergebnis prüfen')) . '</button>';
            else $h.=Html::hidden('moderation_key',$latest['id']).Html::hidden('moderation_hidden',(int)$latest['desired_hidden']).Html::hidden('moderation_version',$service->original($this->access,$latest['id'])['version']).'<button class="btn btn-outline theme-secondary" name="operation" value="social-comment-moderate" formnovalidate>' . Html::e($this->conversationText('Vorgang fortsetzen')) . '</button>';
            $h.='<details><summary>' . Html::e($this->conversationText('Vorgang schließen')) . '</summary>'.($latest['status']!=='pending'?'<label class="check"><input class="check" type="checkbox" name="moderation_close_confirm" value="1">' . ' ' . Html::e($this->conversationText('Beim Anbieter geprüft')) . '</label>':'').'<button class="btn btn-outline theme-secondary" name="operation" value="social-comment-moderation-close" formnovalidate>'.Html::e(($latest['status']==='pending'?$this->conversationText('Vorgang abbrechen'):$this->conversationText('Unbestätigt schließen'))).'</button></details>';
        }else{
            $hidden=($source['payload']['hidden']??false)===true;$h.=Html::hidden('moderation_key',bin2hex(random_bytes(16))).Html::hidden('moderation_version',$source['version']).Html::hidden('moderation_hidden',$hidden?'0':'1').'<button class="btn btn-outline theme-secondary" name="operation" value="social-comment-moderate" formnovalidate>'.Html::e(($hidden?$this->conversationText('Kommentar einblenden'):$this->conversationText('Kommentar ausblenden'))).'</button>';
        }
        return$h.(!$inside?'</form>':'').'</div>';
    }
    public static function label(array $row,array $messages=[]):string
    {return \Kulturhus\Native\Language\ConversationMessages::text(match($row['status']){'confirmed'=>(bool)$row['desired_hidden']?'Ausgeblendet':'Eingeblendet','pending'=>'Moderation vorbereitet','sending','uncertain'=>'Ergebnis unklar · prüfen','closed'=>'Nicht bestätigt · geschlossen','rejected'=>'Nicht moderiert · API-Rechte prüfen',default=>'Nicht moderiert · Original prüfen'},$messages);}
}
