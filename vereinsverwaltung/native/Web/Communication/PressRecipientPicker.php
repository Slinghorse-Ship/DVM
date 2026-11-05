<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use Kulturhus\Native\Communication\PressRecipientCatalogue;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;
use PDO;

final class PressRecipientPicker
{
    public static function render(PDO $db,Access $access,array $state,bool $open=false): string
    {
        $messages=(new \Kulturhus\Native\Language\Catalog($db,$access))->contentMessages();$t=static fn(string $source):string=>\Kulturhus\Native\Language\ContentMessages::text($source,$messages);
        $page=PressRecipientCatalogue::page($db,$access,$state);$e=Html::e(...);$count=count($page['recipient_ids']);
        $button=static fn(string $value,string $label,bool $disabled=false):string=>'<button class="btn btn-outline theme-secondary" type="submit" name="press_browse" value="'.$value.'" formnovalidate'.($disabled?' disabled':'').'>'.Html::e($t($label)).'</button>';
        $h='<details data-press-picker class="press-recipient-picker"'.($open?' open':'').'><summary>'.Html::e($t('Presseempfänger')).' <span class="badge" data-press-count>'.Html::e(strtr($t('{count} ausgewählt'),['{count}'=>(string)$count])).'</span></summary><div class="press-recipient-content">';
        $h.=Html::hidden('press_picker_present','1').Html::hidden('press_mode',$page['mode']).Html::hidden('press_page',$page['page']);
        foreach($page['recipient_ids']as$id)$h.=Html::hidden('recipient_ids[]',$id);
        $h.='<div class="press-recipient-modes" role="group" aria-label="'.Html::e($t('Empfänger anzeigen')).'">'.$button('active','Aktive Kontakte',$page['mode']==='active').$button('selected',$t('Ausgewählt ·').' '.$count,$page['mode']==='selected').'</div>';
        $h.='<div class="press-recipient-search" data-dirty-ignore>'.Html::input('press_query',$t('Name, E-Mail oder Redaktion'),$page['search'],'search','maxlength="200" autocomplete="off"').$button('search','Suchen').'</div>';
        $h.='<p class="press-recipient-status" role="status" tabindex="-1" data-press-result>'.$page['total'].' '.Html::e($page['mode']==='selected'?$t('ausgewählte Kontakte'):$t('Treffer')).'</p><div class="press-recipient-options">';
        foreach($page['rows']as$row){
            $input='press-contact-'.$row['id'];
            $h.=Html::hidden('press_visible[]',$row['id']).'<article class="press-recipient-option"><input class="check" id="'.$input.'" type="checkbox" name="press_choice[]" value="'.$e($row['id']).'"'.(in_array($row['id'],$page['recipient_ids'],true)?' checked':'').'><span><label class="press-recipient-name" for="'.$input.'">'.$e($row['name']).'</label><details class="press-recipient-contact" name="press-contact-details"><summary>'.$e($row['outlet']?:$t('Kontaktdetails')).'</summary><small>'.$e($row['email']).'</small></details>'.(!(bool)$row['active']?'<span class="badge">'.Html::e($t('Inaktiv')).'</span>':'').'</span></article>';
        }
        if(!$page['rows'])$h.='<p>'.Html::e($t('Keine Kontakte gefunden.')).'</p>';
        $h.='</div><nav class="press-recipient-pages" aria-label="'.Html::e($t('Empfängerseiten')).'">'.$button('previous','Zurück',$page['page']<=1).'<span>'.Html::e($t('Seite')).' '.$page['page'].' '.Html::e($t('von')).' '.$page['pages'].'</span>'.$button('next','Weiter',$page['page']>=$page['pages']).'</nav><p role="alert" tabindex="-1" data-press-error hidden></p></div></details>';
        return $h;
    }
}
