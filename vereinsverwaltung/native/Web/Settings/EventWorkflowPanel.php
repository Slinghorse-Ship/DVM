<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Settings;

use PDO;
use Kulturhus\Native\Events\Workflow;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;

final class EventWorkflowPanel
{
    public static function render(PDO $db, Access $access, string $csrf): string
    {
        $access->require('website','manage');
        $state=(new Workflow($db,$access))->get();
        return '<details class="panel" id="event-workflow"><summary>Veranstaltungen &amp; Tickets</summary><form method="post">'
            .Html::hidden('csrf',$csrf).Html::hidden('operation','event-workflow').Html::hidden('version',$state['version'])
            .Html::select('source','Veranstaltungsquelle',Workflow::SOURCES,$state['source'])
            .Html::select('tickets','Ticketverkauf',Workflow::TICKETS,$state['tickets'])
            .Html::select('payment','Zahlung für eigene Tickets',Workflow::PAYMENTS,$state['payment'])
            .'<label class="check"><input class="check" type="checkbox" name="automatic_publication" value="1"'.($state['automatic_publication']?' checked':'').'><span>Übernommene Termine automatisch auf der Website veröffentlichen</span></label>'
            .'<button class="btn btn-solid theme-primary">Veranstaltungsablauf speichern</button></form></details>';
    }
    public static function input(array $post): array
    {
        if(array_key_exists('automatic_publication',$post)&&$post['automatic_publication']!=='1')throw new \DomainException('Veröffentlichungswahl prüfen.');
        return ['source'=>$post['source']??null,'tickets'=>$post['tickets']??null,'payment'=>$post['payment']??null,'automatic_publication'=>array_key_exists('automatic_publication',$post)];
    }
}
