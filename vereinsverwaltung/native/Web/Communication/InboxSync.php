<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use DomainException;
use Throwable;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\{Content,InboxCatalogue,SocialHub};
use Kulturhus\Native\Integration\{Cooldown,ManagerAccess};

/** Explicit provider reads keep the original conversation separate from an unsent reply. */
final class InboxSync extends Workspace
{
    use ConversationInterfaceText;

    public static function selection(array $input):array
    {
        InboxCatalogue::query($input);
        $out=[];
        foreach(['q'=>'','inbox_channel'=>'all','conversation_page'=>'1','thread'=>'','message_page'=>'1','focus_message'=>'','reply'=>'','chat_list'=>'0']as$key=>$default){
            $value=array_key_exists($key,$input)?$input[$key]:$default;
            if(!is_string($value)&&!is_int($value))throw new DomainException('Ursprüngliches Abgleichformular erneut öffnen.');
            if(in_array($key,['conversation_page','message_page'],true)){
                if(filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]])===false)throw new DomainException('Gesprächsseite prüfen.');
            }elseif(!is_string($value))throw new DomainException('Ursprüngliches Abgleichformular erneut öffnen.');
            if(in_array($key,['thread','reply'],true)&&$value!=='')Content::id($value);
            if($key==='focus_message'&&$value!=='')SocialHub::remoteId($value);
            if($key==='chat_list'&&!in_array($value,['0','1'],true))throw new DomainException('Ursprüngliches Abgleichformular erneut öffnen.');
            $out[$key]=(string)$value;
        }
        return$out;
    }

    private function identity():array
    {
        $actor=$this->access->require('social','manage');
        return['actor_id'=>$actor['id'],'club_id'=>$actor['club_id'],'authority'=>$this->access instanceof ManagerAccess?'manager':'native'];
    }

    public function form(string $channel,array $query):string
    {
        if(!in_array($channel,['facebook','instagram'],true))throw new DomainException('Nachrichtenkanal prüfen.');
        $selection=self::selection($query);$html='<form method="post" data-navigation-native data-inbox-sync target="_blank">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','social-inbox-sync').Html::hidden('channel',$channel).Html::hidden('inbox_context','1');
        foreach($this->identity()+$selection as$key=>$value)$html.=Html::hidden('inbox['.$key.']',$value);
        return$html;
    }

    public function action(array $post):array
    {
        $this->validatePost($post);$identity=$this->identity();$raw=$post['inbox']??null;
        if(($post['inbox_context']??null)!=='1'||!is_array($raw))throw new DomainException('Ursprüngliches Abgleichformular erneut öffnen.');
        $expected=array_keys($identity+self::selection([]));$keys=array_keys($raw);sort($expected);sort($keys);
        if($keys!==$expected)throw new DomainException('Ursprüngliches Abgleichformular erneut öffnen.');
        foreach($identity as$key=>$value)if(($raw[$key]??null)!==$value)throw new DomainException('Person oder Vereinszuordnung geändert.');
        $selection=self::selection($raw);$channel=$post['channel']??null;
        if(!is_string($channel)||!in_array($channel,['facebook','instagram'],true))throw new DomainException('Nachrichtenkanal prüfen.');
        $state=['selection'=>$selection];
        try{(new SocialHub($this->db,$this->box()))->syncInbox($this->access,$channel);$state['notice']='Anbieterabgleich gespeichert. Verfügbarkeit und Zeitpunkt im jeweiligen Bereich prüfen.';}
        catch(Cooldown $pause){http_response_code(429);header('Retry-After: '.$pause->seconds);$state['error']='Anbieterpause. Bitte später erneut prüfen.';$state['retry_after']=$pause->seconds;}
        catch(DomainException $error){http_response_code(422);$state['error']=$error->getMessage();}
        catch(Throwable){http_response_code(503);$state['error']='Abgleich nicht bestätigt. Die ursprüngliche Suchansicht bleibt erhalten. Anbieterstatus prüfen.';}
        return['view'=>'social','section'=>'inbox','notice'=>'','compose_state'=>['inbox_sync'=>$state],'inbox_sync_response'=>['ok'=>!isset($state['error']),'error'=>isset($state['error'])?$this->conversationText($state['error']):null,'notice'=>isset($state['notice'])?$this->conversationText($state['notice']):null,'selection'=>$selection,'retry_after'=>$state['retry_after']??null]];
    }

    public function invalid(array $raw,string $error):string
    {
        $this->identity();http_response_code(422);
        return'<section class="chat-sync-original"><h2>'.Html::e($this->conversationText('Nachrichten')).'</h2><p role="alert">'.Html::e($this->conversationText($error)).'</p><details><summary>'.Html::e($this->conversationText('Ursprüngliche Suchangaben')).'</summary><pre class="social-filter-original">'.Html::e(json_encode($raw,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)).'</pre></details><a class="btn btn-outline theme-secondary" data-nav href="/?view=social&amp;section=inbox&amp;chat_list=1">'.Html::e($this->conversationText('Filter zurücksetzen')).'</a></section>';
    }
}
