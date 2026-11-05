<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Workspace,Html};
final class Review extends Workspace
{
    use ConversationInterfaceText;
    public function manager(?array $humanState=null): string
    {
        return (new Replies($this->db,$this->access,$this->mediaRoot,$this->csrf))->manager($humanState);
    }
    public function tools(): string
    {
        $this->access->require('social','manage');
        return '<a class="btn btn-outline theme-secondary social-check-plans" data-check-plan-open data-save-original data-navigation-native target="_blank" rel="noopener" href="/?view=social&amp;section=sync">'.Html::e($this->conversationText('Abrufzeiten')).'</a>';
    }
}
