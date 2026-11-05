<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\VideoQuota;

final class VideoQuotaPanel extends Workspace
{
    public function render(array $item): string
    {
        if(!VideoQuota::installed($this->db))return (new VideoStoragePanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item).(new VideoReusePanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item);
        $state=(new VideoQuota($this->db,$this->access))->get();
        $messages=(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();
        $title=Html::e(\Kulturhus\Native\Language\VideoPolicyMessages::text('Videospeicher',$messages));
        $h='<details class="social-video-quota"'.(($_GET['video_storage_check']??null)==='1'||($_GET['video_recovery_check']??null)==='1'||($_GET['video_use_check']??null)==='1'||in_array($_POST['operation']??null,['social-video-recover','social-video-reuse'],true)?' open':'').'><summary>'.$title.'</summary>'.VideoPolicyForm::usage($state,$messages);
        if($this->can('social','manage'))$h.=VideoPolicyForm::render($item,'quota',$state,$this->csrf,$messages);
        return $h.(new VideoStoragePanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item).(new VideoRecoveryPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item).(new VideoReusePanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($item).'</details>';
    }
}
