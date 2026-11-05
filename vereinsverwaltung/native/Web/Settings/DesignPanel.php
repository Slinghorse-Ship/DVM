<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Settings;
use PDO;
use Kulturhus\Native\Cms\WebsiteDesign;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;

final class DesignPanel
{
    public static function render(PDO $db, Access $access, string $csrf): string
    {
        $messages=(new \Kulturhus\Native\Language\Catalog($db,$access))->contentMessages();$t=static fn(string $source):string=>\Kulturhus\Native\Language\ContentMessages::text($source,$messages);
        $access->require('website','manage');$store=new WebsiteDesign($db,$access);$state=$store->get();
        $canUse=true;try{$access->require('website','publish');}catch(\DomainException){$canUse=false;}
        $html=('<section class="panel website-design" aria-labelledby="website-design-title"><h2 id="website-design-title">' . Html::e($t('Website-Vorlagen')) . '</h2><p>' . Html::e($t('Wähle das Erscheinungsbild deines Vereins. Die Vorschau öffnet eine private Ansicht. „Vorlage verwenden“ ändert das Design der öffentlichen Website; deine Inhalte bleiben erhalten.')) . '</p><p>' . Html::e($t('Aktuell:')) . ' <strong data-current-design>').Html::e($t(WebsiteDesign::template($state['template'])['name'])).'</strong></p><div class="website-design-grid">';
        foreach(WebsiteDesign::templates() as $key=>$template){
            $e=Html::e(...);$selected=$state['template']===$key;
            $html.='<article class="website-design-card" data-design="'.$key.'"'.($selected?' data-selected':'').'><div class="website-design-sample sample-'.$key.'" aria-hidden="true"><div class="sample-nav"><i></i><span></span><span></span></div><div class="sample-hero"><div><span></span><strong></strong><strong></strong><i></i></div><b></b></div><div class="sample-cards"><i></i><i></i><i></i></div></div><h3>'.$e($t($template['name'])).'</h3><p>'.$e($t($template['description'])).'</p><div class="website-design-actions"><a class="btn btn-outline theme-secondary" href="/design-preview?template='.$key.'" target="_blank" rel="noopener">'.$e(strtr($t('{template} ansehen ↗'),['{template}'=>$t($template['name'])])).'</a>';
            if($selected)$html.=('<span class="website-design-current" role="status">' . Html::e($t('Aktuelle Vorlage')) . '</span>');
            elseif($canUse)$html.='<form method="post">'.Html::hidden('csrf',$csrf).Html::hidden('operation','website-design').Html::hidden('version',$state['version']).Html::hidden('website_template',$key).'<button class="btn btn-solid theme-primary" aria-label="'.$e(strtr($t('{template} als Website-Vorlage verwenden'),['{template}'=>$t($template['name'])])).'">'.Html::e($t('Vorlage verwenden')).'</button></form>';
            $html.='</div></article>';
        }
        $html.='</div>';
        if(!$canUse)$html.=('<p>' . Html::e($t('Zum Wechseln der öffentlichen Vorlage benötigst du Veröffentlichungsrechte.')) . '</p>');
        if($history=$store->history()){
            $html.=('<details><summary>' . Html::e($t('Letzte Designwechsel')) . '</summary><ol>');
            foreach($history as $entry)$html.=('<li>' . Html::e($t('Fassung')) . ' ').Html::e($entry['version']).' · '.Html::e($t(WebsiteDesign::template($entry['template_key'])['name'])).' · '.Html::e($entry['created_at']).' UTC</li>';
            $html.='</ol></details>';
        }
        return $html.'</section>';
    }
}
