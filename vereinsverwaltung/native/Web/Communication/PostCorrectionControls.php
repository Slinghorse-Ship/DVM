<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\PostCorrections;

final class PostCorrectionControls extends Workspace
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    use ConversationInterfaceText;
    private function text(string $source):string{return Html::e($this->conversationText($this->contentText($source)));}
    public static function label(array $row):string
    {return match($row['status']){'confirmed'=>'Facebook bestätigt','pending'=>'Vorbereitet','sending','uncertain'=>'Ergebnis unklar','rejected'=>'Von Facebook abgewiesen','blocked'=>'Original prüfen','closed'=>'Unbestätigt geschlossen',default=>'Prüfen'};}
    public function render(array $object,?array $state=null):string
    {
        if($object['channel']!=='facebook'||$object['kind']!=='post'||!preg_match('/\A[0-9]+_[0-9]+\z/D',$object['remote_id'])||!$this->can('social','edit')||!$this->can('social','publish'))return'';
        $service=new PostCorrections($this->db,$this->box());if(!$service->installed())return'';
        try{$source=$service->source($this->access,$object['id']);$latest=$service->latest($this->access,$object['id']);}catch(\DomainException){return'';}
        $local=$state&&($state['object_id']??null)===$object['id']?$state:null;$active=$latest&&in_array($latest['status'],['pending','sending','uncertain'],true);
        $h='<details class="social-post-correction" data-post-correction="'.Html::e($object['id']).'"'.($local||$active?' open':'').'><summary>'.$this->text('Text korrigieren').'</summary>';
        if($latest)$h.='<p role="status" data-post-correction-status>'.$this->text(self::label($latest)).'</p>';
        if($local&&isset($local['error']))$h.='<p role="alert">'.$this->text($local['error']).'</p>';
        if($active){
            $h.='<details><summary>'.$this->text('Vorgesehener Text').'</summary><p class="social-caption-full">'.nl2br(Html::e($latest['original']['text'])).'</p></details>';
            $h.=$this->start('social-post-correction-check').Html::hidden('object_id',$object['id']).Html::hidden('correction_id',$latest['id']);
            if($latest['status']==='pending')$h.=Html::hidden('correction_fingerprint',$latest['original']['source']['fingerprint']).Html::hidden('correction_key',$latest['id']).Html::hidden('correction_text',$latest['original']['text']).Html::hidden('correction_confirm','1').'<button class="btn btn-outline theme-secondary" name="operation" value="social-post-correct">'.$this->text('Korrektur fortsetzen').'</button>';
            else $h.='<button class="btn btn-outline theme-secondary">'.$this->text('Ergebnis prüfen').'</button>';
            $h.='</form>'.$this->start('social-post-correction-close').Html::hidden('object_id',$object['id']).Html::hidden('correction_id',$latest['id']).Html::hidden('correction_version',$latest['version']).'<details><summary>'.$this->text('Vorgang schließen').'</summary>';
            if($latest['status']!=='pending')$h.='<label class="check"><input class="check" type="checkbox" name="correction_checked" value="1" required> '.$this->text('Beim Anbieter geprüft').'</label>';
            return$h.'<button class="btn btn-outline theme-secondary">'.$this->text($latest['status']==='pending'?'Korrektur abbrechen':'Unbestätigt schließen').'</button></details></form></details>';
        }
        $text=$local['correction_text']??$object['caption'];$fingerprint=$local['correction_fingerprint']??$source['fingerprint'];$key=$local['correction_key']??bin2hex(random_bytes(16));
        return$h.$this->start('social-post-correct').Html::hidden('object_id',$object['id']).Html::hidden('correction_fingerprint',$fingerprint).Html::hidden('correction_key',$key).'<label>'.$this->text('Beitragstext').'<textarea class="form-control" name="correction_text" maxlength="60000" required>'.Html::e($text).'</textarea></label><label class="check"><input class="check" type="checkbox" name="correction_confirm" value="1" required> '.$this->text('Facebook-Text ersetzen').'</label><button class="btn btn-solid theme-primary">'.$this->text('Text ändern').'</button>'.($local&&isset($local['error'])?'<button class="btn btn-outline theme-secondary" name="correction_reload" value="1" formnovalidate>'.$this->text('Originalstand übernehmen').'</button>':'').'</form></details>';
    }
}
