<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use DomainException;
use Kulturhus\Verein\BalanceFilingActions;

/** One existing complete specialist form at a time; no accounting or transmission dispatch. */
final class BalanceFilingSpecialists
{
    public const TASK='balance_local_task';
    public const PAGE='balance_local_page';
    private static function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function href(array $query):string{return '?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);}
    public static function listing(array $query):array
    {
        $route=BalanceFilingForm::source($query);unset($route[self::TASK],$route[self::PAGE]);return $route;
    }
    private static function title(string $action,bool $remove,callable $text):string
    {
        return $text(match($action){
            'tax-filing-balance-revision'=>'Abgabeart bearbeiten',
            'tax-filing-balance-adjust'=>'Steuerliche Wertänderung erfassen',
            'tax-filing-balance-asset'=>$remove?'Abweichenden Steuerplan entfernen':'Abweichende Abschreibung bearbeiten',
            'tax-filing-balance-capital'=>'Kapitalbewegungen zuordnen',
            'tax-filing-balance-carry'=>'Bestätigten Vorjahresstand übernehmen',
            'tax-filing-balance-partnership'=>'Beteiligungsnachweis bearbeiten',
            'tax-filing-balance-own-intangible'=>'Entwicklungsnachweis bearbeiten',
            'tax-filing-balance-pool-original'=>'Sammelpostennachweis bearbeiten',
            'tax-filing-balance-pension-offsets'=>'Pensionsnachweis bearbeiten',
            'tax-filing-balance-financial-reserve'=>'Übernommene Rücklage als Quellenentwurf erfassen',
            'tax-filing-balance-financial-reserve-withdraw'=>'Rücklagen-Quellenentwurf zurücknehmen',
            'tax-filing-balance-equalization'=>'Ausgleichsposten belegen',
            'tax-filing-balance-basis-6b'=>'Ursprüngliche Wahl nach § 6b bearbeiten',
            'tax-filing-balance-basis-7g'=>'Anschaffungskostenkürzung nach § 7g bearbeiten',
            'tax-filing-balance-special-7b'=>'Sonderabschreibung nach § 7b bearbeiten',
            'tax-filing-balance-special-7g'=>'Sonderabschreibung nach § 7g bearbeiten',
            default=>'Weitere Steuerangaben bearbeiten',
        });
    }
    private static function readOnlyFragments(DOMDocument $dom,DOMXPath $xp,DOMElement $root,?DOMNode $excluded=null):string
    {
        $out='';
        foreach($xp->query('.//*[@data-balance-specialist-readonly]',$root)as $node){
            if($xp->query('.//form|.//input|.//select|.//textarea|.//button|.//script',$node)->length)
                throw new \LogicException('Readonly balance figures must not contain interactive controls.');
            if($excluded!==null)for($parent=$node;$parent!==null;$parent=$parent->parentNode)
                if($parent->isSameNode($excluded))continue 2;
            $fragment=$dom->saveHTML($node);
            if($node->tagName!=='details'){
                $title=$node->getAttribute('data-balance-readonly-title');
                if($title==='')throw new \LogicException('Readonly balance figures require their own title.');
                $fragment='<details class="card" data-balance-readonly-summary><summary>'.self::e($title).'</summary>'.$fragment.'</details>';
            }
            $out.=$fragment;
        }
        return $out;
    }
    public static function render(string $html,array $query,array $post,callable $text):string
    {
        if(($query['balance_local_details']??null)!=='1')return $html;
        // A previously opened complete form keeps its original recovery route.
        if(BalanceFilingForm::requested($post)&&!array_key_exists(self::TASK,$query))return $html;
        $selected=$query[self::TASK]??'';
        if(!is_string($selected)||($selected!==''&&!preg_match('/\A[a-f0-9]{24}\z/D',$selected)))throw new DomainException('Ursprüngliche Fachauswahl vollständig prüfen.',422);
        $page=$query[self::PAGE]??'1';
        if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,5}\z/D',$page))throw new DomainException('Seite der Fachauswahl vollständig prüfen.',422);
        $dom=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
        try{
            $dom->loadHTML('<?xml encoding="UTF-8"><div id="balance-specialist-original">'.$html.'</div>',LIBXML_NONET);
            $xp=new DOMXPath($dom);$root=$xp->query('//*[@id="balance-specialist-original"]')->item(0);$tasks=[];
            foreach($xp->query('.//form',$root)as $form){
                $action=$xp->query('.//input[@name="action"]',$form)->item(0)?->getAttribute('value');
                if(!in_array($action,BalanceFilingActions::ACTIONS,true)||$action==='tax-filing-validate')continue;
                if(!$xp->query('.//button[not(@disabled) and not(ancestor::fieldset[@disabled])]',$form)->length)continue;
                $identity=[];
                foreach($xp->query('.//input[@type="hidden"][@name]',$form)as $node)if(!in_array($node->getAttribute('name'),['csrf','version'],true))$identity[$node->getAttribute('name')]=$node->getAttribute('value');
                ksort($identity,SORT_STRING);$key=substr(hash('sha256',\Kulturhus\Verein\BalanceFilingCommands::json([$action,$identity])),0,24);
                if(isset($tasks[$key]))throw new DomainException('Gleiche Fachaufträge zuerst anhand ihrer ursprünglichen Auswahl prüfen lassen.',503);
                $trail=[];$container=null;
                for($parent=$form->parentNode;$parent instanceof DOMElement&&!$parent->isSameNode($root);$parent=$parent->parentNode){
                    if(in_array($parent->tagName,['details','section'],true)&&$container===null)$container=$parent;
                    if($parent->tagName==='details'){
                        $summary=$xp->query('./summary',$parent)->item(0);
                        if($summary!==null)$trail[]=trim($summary->textContent);
                    }
                }
                $tasks[$key]=['form'=>$form,'container'=>$container??$form,'title'=>self::title($action,($identity['remove_plan']??'')==='1',$text),'trail'=>array_reverse($trail)];
            }
            if(!$tasks){if($selected!=='')throw new DomainException('Diese Fachauswahl wird für den aktuellen Stand nicht angeboten.',404);return $html;}
            $readOnly=self::readOnlyFragments($dom,$xp,$root);
            $introNode=$xp->query('./section[1]',$root)->item(0);$intro='';
            if($introNode instanceof DOMElement){
                $copy=$introNode->cloneNode(true);
                foreach(iterator_to_array($copy->getElementsByTagName('form'))as$oldForm)$oldForm->parentNode->removeChild($oldForm);
                $intro=$dom->saveHTML($copy);
            }
            $base=self::listing($query);$main=$base;unset($main['balance_local_details']);
            $back='<a class="btn btn-outline" href="'.self::e(self::href($main)).'">'.self::e($text('Zurück zu Angaben und XML-Vorbereitung')).'</a>';
            if($selected===''){
                $pages=max(1,(int)ceil(count($tasks)/6));$number=min($pages,(int)$page);$out=$back.$intro.'<section class="card" data-balance-specialist-menu><h2>'.self::e($text('Weitere Steuerwerte und Nachweise')).'</h2><p>'.self::e($text('Einen Fachauftrag auswählen. Jede Bearbeitung hat ein eigenes vollständiges Formular und eine eigene Bestätigung.')).'</p>';
                foreach(array_slice($tasks,($number-1)*6,6,true)as $key=>$task){
                    $out.='<article class="card"><h3>'.self::e($task['title']).'</h3>';
                    if($task['trail'])$out.='<p>'.self::e(implode(' · ',$task['trail'])).'</p>';
                    $out.='<a class="btn btn-outline" data-balance-specialist-open href="'.self::e(self::href($base+[self::TASK=>$key])).'">'.self::e($text('Diesen Fachauftrag öffnen')).'</a></article>';
                }
                $out.='<nav aria-label="'.self::e($text('Seiten der Fachauswahl')).'"><p>'.self::e($text('Seite')).' '.$number.' / '.$pages.'</p>';
                if($number>1)$out.='<a class="btn btn-outline" href="'.self::e(self::href($base+[self::PAGE=>(string)($number-1)])).'">'.self::e($text('Vorherige Seite')).'</a>';
                if($number<$pages)$out.='<a class="btn btn-outline" href="'.self::e(self::href($base+[self::PAGE=>(string)($number+1)])).'">'.self::e($text('Nächste Seite')).'</a>';
                return $out.'</nav></section>'.$readOnly;
            }
            if(!isset($tasks[$selected]))throw new DomainException('Diese Fachauswahl wird für den aktuellen Stand nicht angeboten.',404);
            $task=$tasks[$selected];$form=$task['form'];$container=$task['container'];
            $id='balance-local-specialist';$form->setAttribute('id',$id);$form->setAttribute('data-balance-specialist-form','');
            $extra=$dom->createElement('div');$extra->setAttribute('class','balance-specialist-navigation');
            $button=$dom->createElement('button');$button->setAttribute('type','submit');$button->setAttribute('name','action');$button->setAttribute('value','balance-local-specialist-back');$button->setAttribute('class','btn btn-outline');$button->textContent=$text('Zur Fachauswahl');$extra->appendChild($button);
            $discard=$dom->createElement('details');$summary=$dom->createElement('summary');$summary->textContent=$text('Eigene ungespeicherte Angaben bewusst verwerfen');$discard->appendChild($summary);
            $label=$dom->createElement('label');$label->setAttribute('class','check');$check=$dom->createElement('input');$check->setAttribute('type','checkbox');$check->setAttribute('name','balance_local_discard_confirmed');$check->setAttribute('value','1');$label->appendChild($check);$label->appendChild($dom->createTextNode($text('Nur die Eingaben dieses Formulars verwerfen; gespeicherte Originale bleiben erhalten.')));$discard->appendChild($label);
            $button=$dom->createElement('button');$button->setAttribute('type','submit');$button->setAttribute('name','action');$button->setAttribute('value','balance-local-specialist-discard');$button->setAttribute('class','btn btn-outline');$button->textContent=$text('Bestätigte Eingaben verwerfen und zur Fachauswahl');$discard->appendChild($button);$extra->appendChild($discard);$form->appendChild($extra);
            foreach(iterator_to_array($xp->query('.//form',$container))as $other)if(!$other->isSameNode($form))$other->parentNode->removeChild($other);
            if($container instanceof DOMElement&&$container->tagName==='details')$container->setAttribute('open','');
            if($introNode instanceof DOMElement)foreach($xp->query('.//form',$introNode)as$introForm)if($introForm->isSameNode($form)){$intro='';break;}
            $out=$intro.'<section data-balance-specialist><h2>'.self::e($task['title']).'</h2>';
            if($task['trail'])$out.='<p>'.self::e(implode(' · ',$task['trail'])).'</p>';
            $out.='<p>'.self::e($text('Speichern bestätigt nur diesen vollständigen Fachauftrag. Der Wechsel zur Fachauswahl übernimmt keine ungespeicherten Änderungen.')).'</p>';
            return $out.$dom->saveHTML($container).'</section>'.self::readOnlyFragments($dom,$xp,$root,$container);
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
