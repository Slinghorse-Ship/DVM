<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Cms\EventDetails;
use Kulturhus\Native\Events\Workflow;
use Kulturhus\Native\Shared\Db;

final class FacebookLinkPanel extends Workspace
{
    public function render(array $page):string
    {
        if(!$this->can('website','edit')||!EventDetails::isEvent($page)||(new Workflow($this->db,$this->access))->get()['source']!=='facebook')return '';
        $source=Db::run($this->db,"SELECT external_id FROM native_event_sources WHERE club_id=? AND page_id=? AND source='facebook'",[$this->access->club(),$page['id']])->fetchColumn();
        return '<details class="panel" id="facebook-event-link"><summary>Facebook-Veranstaltung zuordnen</summary>'
            .$this->start('facebook-event-link').Html::hidden('view','events').Html::hidden('id',$page['id']).Html::hidden('version',$page['version'])
            .Html::input('facebook_event_id','Facebook-Veranstaltungs-ID',is_string($source)?$source:'','text','required inputmode="numeric" pattern="[0-9]{1,40}" maxlength="40"')
            .'<label class="check"><input class="check" type="checkbox" name="link_confirm" value="1" required><span>Facebook-Angaben beim nächsten Abgleich für diesen Website-Termin übernehmen</span></label>'
            .'<button class="btn btn-solid theme-primary">Veranstaltung zuordnen</button></form></details>';
    }
}
