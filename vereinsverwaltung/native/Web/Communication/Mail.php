<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\Mail\{Settings,Bridge};

/** Optional settings and collapsed per-conversation mail controls. */
final class Mail extends Workspace
{
    use ConversationInterfaceText;
    public function manager():string
    {
        $this->access->require('website','manage');$state=(new Settings($this->db,$this->box()))->state($this->access);$data=$state['data'];
        if(($_POST['operation']??null)==='social-mail-settings'&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf'])&&is_array($_POST['mail']??null)){
            foreach(['target','reply_to','imap_host','imap_user','folder','since']as$key)if(is_string($_POST['mail'][$key]??null)&&strlen($_POST['mail'][$key])<=254)$data[$key]=$_POST['mail'][$key];
            foreach(['enabled','receive_enabled','auto_send']as$key)$data[$key]=($_POST['mail'][$key]??null)==='1';
            if(is_string($_POST['mail_version']??null)&&ctype_digit($_POST['mail_version']))$state['version']=(int)$_POST['mail_version'];
        }
        $h='<a class="btn btn-outline theme-secondary" data-nav href="/?view=social&amp;section=review">' . Html::e($this->conversationText('← Gespräche')) . '</a><section class="card conversation-mail-settings"><h2>' . Html::e($this->conversationText('Mailmodul')) . '</h2>'.$this->start('social-mail-settings').Html::hidden('mail_version',$state['version']);
        $h.='<label class="check"><input class="check" type="checkbox" name="mail[enabled]" value="1"'.($data['enabled']?' checked':'').'>' . ' ' . Html::e($this->conversationText('Mailmodul aktivieren')) . '</label><div class="form-grid">';
        foreach(['target'=>$this->conversationText('Zieladresse'),'reply_to'=>$this->conversationText('Antwortpostfach')]as$key=>$label)$h.=Html::input('mail['.$key.']',$label,$data[$key],'email');
        $h.='</div><label class="check"><input class="check" type="checkbox" name="mail[auto_send]" value="1"'.($data['auto_send']?' checked':'').'>' . ' ' . Html::e($this->conversationText('Mailantworten direkt im zugehörigen Gespräch senden')) . '</label><details class="settings-panel"><summary>' . Html::e($this->conversationText('Antwortabruf')) . '</summary><label class="check"><input class="check" type="checkbox" name="mail[receive_enabled]" value="1"'.($data['receive_enabled']?' checked':'').'>' . ' ' . Html::e($this->conversationText('Antworten per IMAP abrufen')) . '</label><div class="form-grid">';
        foreach(['imap_host'=>$this->conversationText('IMAP-Server · TLS 993'),'imap_user'=>$this->conversationText('Postfach-Benutzername'),'folder'=>$this->conversationText('Postfachordner'),'since'=>$this->conversationText('Abrufen ab')]as$key=>$label)$h.=Html::input('mail['.$key.']',$label,$data[$key],$key==='since'?'date':'text');
        $h.='<label>' . Html::e($this->conversationText('Postfachpasswort')) . '<input class="form-control" type="password" name="mail[imap_password]" autocomplete="new-password" maxlength="1000" value=""><small>'.Html::e(($state['password_set']?$this->conversationText('Gespeichert · leer lassen zum Behalten'):$this->conversationText('Noch nicht gespeichert'))).'</small></label><label class="check"><input class="check" type="checkbox" name="mail[clear_password]" value="1">' . ' ' . Html::e($this->conversationText('Passwort entfernen')) . '</label></div><p>'.Html::e(($state['imap_available']?$this->conversationText('IMAP verfügbar'):$this->conversationText('IMAP-Erweiterung auf diesem Server fehlt'))).'</p></details><p>' . Html::e($this->conversationText('Versand über das konfigurierte Postfach für Vereinsnachrichten.')) . '</p><button class="btn btn-solid theme-primary">' . Html::e($this->conversationText('Einstellungen speichern')) . '</button></form></section>';
        return $h.'<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="/?view=social&amp;section=mail">'.Html::e($this->conversationText('Gespeicherte Einstellungen prüfen')).'</a>';
    }
    public function panel(array $source,?array $reply,?string $connection):string
    {
        if(!in_array($source['kind'],['comment','message'],true))return '';
        $state=(new Settings($this->db,$this->box()))->state($this->access);
        if(!$state['data']['enabled'])return '';
        $service=new Bridge($this->db,$this->box());$history=$service->history($this->access,$source);$prepared=null;
        $h='<details class="chat-mail"><summary>' . Html::e($this->conversationText('E-Mail')).(array_sum(array_map(static fn($row)=>count($row['answers']),$history))?' · '.Html::e($this->conversationText('Antworten vorhanden')):'').'</summary><div class="chat-mail-panel">';
        foreach($history as$row){
            if($row['attention_id']===$source['id']&&$row['source_fingerprint']===$source['fingerprint']&&$row['connection_id']===$connection&&$row['binding']===$state['binding']&&in_array($row['status'],['prepared','blocked'],true))$prepared=$row;
            $status=['prepared'=>$this->conversationText('Vorbereitet · nicht versendet'),'sending'=>$this->conversationText('Versand begonnen'),'accepted'=>$this->conversationText('Vom Mailserver angenommen'),'uncertain'=>$this->conversationText('Ergebnis unklar · nicht erneut senden'),'blocked'=>$this->conversationText('Versand gestoppt')][$row['status']]??$this->conversationText('Status prüfen');
            $h.='<p class="chat-status">'.Html::e($this->conversationText('E-Mail')).' · '.Html::e($row['payload']['target']).' · '.Html::e($status).'</p>';
            foreach($row['answers']as$answer){
                $h.='<details class="chat-mail-answer"><summary>'.Html::e($this->conversationText('E-Mail-Antwort')).' · '.Html::e($this->conversationDate($answer['created_at'])).'</summary>'.ChatLayout::bubble($answer['payload']['from'],$answer['payload']['text'],$this->conversationDate($answer['created_at']),false,$this->conversationText('E-Mail'),utc:$answer['created_at']);
                $unchanged=$row['attention_id']===$source['id']&&$row['source_fingerprint']===$source['fingerprint']&&$connection===$row['connection_id']&&$row['binding']===$state['binding'];
                if($answer['delivery']){
                    $delivery=$answer['delivery'];$status=['pending'=>$this->conversationText('Wird gesendet'),'ready'=>$this->conversationText('Wird gesendet'),'sent'=>$this->conversationText('Gesendet'),'blocked'=>$this->conversationText('Nicht gesendet · Rechte, Original oder Antwortfenster prüfen'),'uncertain'=>$this->conversationText('Ergebnis unklar · nicht erneut senden'),'rejected'=>$this->conversationText('Vom Anbieter abgelehnt')][$delivery['state']]??$this->conversationText('Status prüfen');
                    if($delivery['reply_id']){
                        $social=(new \Kulturhus\Native\Communication\Replies($this->db,$this->box()))->get($this->access,$delivery['reply_id']);
                        if($social['status']==='sent')$status=$this->conversationText('Gesendet');
                        elseif(in_array($social['status'],['sending','uncertain'],true))$status=$this->conversationText('Ergebnis unklar · nicht erneut senden');
                    }
                    $h.='<p class="chat-status">'.Html::e($status).'</p>';
                }elseif(!$state['data']['auto_send']&&$unchanged&&(!$reply||in_array($reply['status'],['draft','rejected','blocked'],true))&&$this->can('social','edit')){
                    $h.=$this->start('social-mail-use').Html::hidden('mail_answer',$answer['id']).Html::hidden('attention_id',$source['id']).Html::hidden('attention_version',$source['version']).Html::hidden('reply_id',$reply['id']??'').Html::hidden('reply_version',$reply['version']??0).'<label>' . Html::e($this->conversationText('Antwortentwurf')) . '<textarea class="form-control" name="reply_text" rows="3" maxlength="2000" required>'.Html::e($answer['payload']['text']).'</textarea></label><label class="check"><input class="check" type="checkbox" name="mail_confirm" value="1" required>' . ' ' . Html::e($this->conversationText($reply?'Als Antwortentwurf übernehmen und bisherigen Entwurf ersetzen':'Als Antwortentwurf übernehmen')).'</label><button class="btn btn-outline theme-secondary">' . Html::e($this->conversationText('Übernehmen')) . '</button></form>';
                }else $h.='<p class="chat-status">' . Html::e($this->conversationText('Frühere Original- oder Mailfassung')) . '</p>';
                $h.='</details>';
            }
        }
        $already=array_filter($history,static fn($row)=>$row['attention_id']===$source['id']&&$row['source_fingerprint']===$source['fingerprint']&&$row['connection_id']===$connection&&$row['binding']===$state['binding']);
        if($connection!==null&&$this->can('social','edit')&&(!$already||$prepared))$h.=$this->start('social-mail-prepare').Html::hidden('attention_id',$source['id']).Html::hidden('attention_version',$source['version']).Html::hidden('mail_id',$prepared['id']??'').Html::hidden('mail_version',$prepared['version']??0).'<p>'.Html::e($this->conversationText('An {address}',['address'=>$state['data']['target']])).'</p><label>' . Html::e($this->conversationText('Eigene Notiz · optional')) . '<textarea class="form-control" name="mail_note" rows="2" maxlength="2000">'.Html::e($prepared['payload']['note']??'').'</textarea></label><button class="btn btn-outline theme-secondary">' . Html::e($this->conversationText('Mail vorbereiten')) . '</button></form>';
        if($prepared&&$prepared['status']==='prepared'&&$this->can('social','publish'))$h.=$this->start('social-mail-send').Html::hidden('mail_id',$prepared['id']).Html::hidden('mail_version',$prepared['version']).'<label class="check"><input class="check" type="checkbox" name="mail_confirm" value="1" required> '.Html::e($this->conversationText('Original und Notiz an {address} senden',['address'=>$prepared['payload']['target']])).'</label><button class="btn btn-solid theme-primary">' . Html::e($this->conversationText('Mail senden')) . '</button></form>';
        if($state['data']['receive_enabled'])$h.=$this->start('social-mail-fetch').Html::hidden('attention_id',$source['id']).'<button class="btn btn-outline theme-secondary">' . Html::e($this->conversationText('Mailantworten abrufen')) . '</button></form>';
        return $h.'</div></details>';
    }
    public function tools():string
    {
        return $this->can('website','manage')?'<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="/?view=social&amp;section=mail">' . Html::e($this->conversationText('Mailmodul')) . '</a>':'';
    }
}
