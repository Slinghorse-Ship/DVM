<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Settings;

use Kulturhus\Native\Cms\NewsPolicy;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;
use PDO;

final class NewsPolicyPanel
{
    public static function choice(array $post): bool
    {
        if (($post['news_policy_present']??null)!=='1' || (array_key_exists('news_four_eyes',$post) && $post['news_four_eyes']!=='1')) throw new \DomainException('Freigabewahl ungültig. Bitte Formular neu öffnen.');
        return array_key_exists('news_four_eyes',$post);
    }

    public static function render(PDO $db, Access $access, string $csrf): string
    {
        $access->require('website','manage'); $store=new NewsPolicy($db,$access); $state=$store->get();
        $html='<section class="panel news-policy-panel" aria-labelledby="news-policy-heading"><h2 id="news-policy-heading">Freigabe für Beiträge</h2><form method="post">'.Html::hidden('csrf',$csrf).Html::hidden('operation','news-policy').Html::hidden('version',$state['version']).Html::hidden('news_policy_present','1').'<label class="check"><input class="check" type="checkbox" name="news_four_eyes" value="1"'.($state['enabled']?' checked':'').'> Vier-Augen-Freigabe verlangen</label><p class="help">Eine andere Person mit Veröffentlichungsrecht prüft die gespeicherte Fassung vor der Veröffentlichung.</p><button class="btn btn-solid theme-primary">Freigaberegel speichern</button></form>';
        if ($history=$store->history()) {
            $html.='<details><summary>Letzte Änderungen</summary><ol>';
            foreach ($history as $entry) $html.='<li>Fassung '.Html::e($entry['version']).': '.((int)$entry['four_eyes']===1?'Vier-Augen-Freigabe erforderlich':'Vier-Augen-Freigabe optional').' · '.Html::e($entry['created_at']).' UTC</li>';
            $html.='</ol></details>';
        }
        return $html.'</section>';
    }
}
