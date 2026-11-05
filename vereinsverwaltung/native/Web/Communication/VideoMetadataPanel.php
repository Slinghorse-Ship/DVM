<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Language\{Catalog,VideoMetadataMessages};

/** Original public details and newly typed proof; saved private proof is never preloaded. */
final class VideoMetadataPanel extends Workspace
{
    public function render(array $item,array $row,array $metadata):string
    {
        $messages=(new Catalog($this->db,$this->access))->messages();
        return '<details class="social-video-metadata" name="video-metadata-'.Html::e($item['id']).'"><summary>'.Html::e(VideoMetadataMessages::text('Videoangaben',$messages)).'</summary>'.VideoMetadataForm::render($item,$row,$metadata,$this->csrf,$messages,null,true,$this->can('social','edit'),$this->can('social','manage')).'</details>';
    }
}
