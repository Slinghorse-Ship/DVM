<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\OriginalArchive;

/** Only one chosen exact original is opened; the disclosure lists bounded metadata. */
final class OriginalHistory extends Workspace
{
    use ConversationInterfaceText;
    public function render(string $attention,array $context):string
    {
        $history=(new OriginalArchive($this->db,$this->box()))->history($this->access,'attention',$attention,$_GET['original_page']??1);
        if(!$history['total'])return '';
        $base=['view'=>'social','section'=>'review','attention'=>$attention]+$context;
        $h='<details class="chat-context chat-original-history"><summary>'.Html::e($this->conversationText('Frühere Fassungen ({count})',['count'=>$history['total']])).'</summary><div class="chat-original-list"><a class="btn btn-outline theme-secondary" data-nav href="/?'.Html::e(http_build_query($base)).'">' . Html::e($this->conversationText('Gespräch öffnen')) . '</a>';
        $reasons=['changed'=>$this->conversationText('Änderung'),'removed'=>$this->conversationText('Entfernt'),'expired'=>$this->conversationText('Früherer Eingang'),'disconnected'=>$this->conversationText('Frühere Verbindung'),'reviewed'=>$this->conversationText('Bearbeitungsstand')];
        foreach($history['rows']as$index=>$row){$position=$history['total']-($history['page']-1)*25-$index;$h.='<a class="btn btn-outline theme-secondary" data-nav'.(($_GET['original']??'')===$row['id']?' aria-current="page"':'').' href="/?'.Html::e(http_build_query($base+['original'=>$row['id'],'original_page'=>$history['page']])).'">'.Html::e($this->conversationText('Fassung {number}',['number'=>$position]).' · '.($reasons[$row['reason']]??$this->conversationText('Stand')).' · '.$this->conversationDate($row['archived_at'])).'</a>';}
        $h.='</div><nav class="chat-original-pages" aria-label="' . Html::e($this->conversationText('Frühere Fassungsseiten')) . '"><span>'.$history['page'].'/'.$history['pages'].'</span>';
        foreach([-1=>$this->conversationText('Frühere Fassungsseite'),1=>$this->conversationText('Weitere Fassungen')]as$direction=>$label){$page=$history['page']+$direction;if($page>=1&&$page<=$history['pages']){$query=$base+['original_page'=>$page];if(is_string($_GET['original']??null))$query['original']=$_GET['original'];$h.='<a class="btn btn-outline theme-secondary" data-nav href="/?'.Html::e(http_build_query($query)).'">'.Html::e($label).'</a>';}}
        return $h.'</nav></details>';
    }
}
