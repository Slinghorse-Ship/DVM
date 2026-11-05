<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\{VideoRemoval,VideoRetention};
final class VideoRetentionPanel extends Workspace
{
    public function render(array $item):string
    {
        if(!$this->can('social','manage')||!VideoRemoval::installed($this->db))return '';
        $rule=(new VideoRetention($this->db,$this->access))->get();
        $messages=(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();
        return '<details class="social-video-retention"><summary>'.Html::e(\Kulturhus\Native\Language\VideoPolicyMessages::text('Videoaufbewahrung',$messages)).'</summary>'.VideoPolicyForm::render($item,'retention',$rule,$this->csrf,$messages).'</details>';
    }
}
