<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use DomainException;
use Kulturhus\Native\Web\{Html,Workspace};
use Kulturhus\Native\Communication\ImageHistory;

final class ImageHistoryPanel extends Workspace
{
    public function render(array $item): string
    {
        $anchor=$_GET['image_anchor']??'';
        if(!is_string($anchor))throw new DomainException('Bildverlauf prüfen.');
        $page=(new ImageHistory($this->db,$this->access))->page($item['id'],$_GET['image_page']??1,$anchor,$_GET['image_total']??null);
        $open=isset($_GET['image_page'])||in_array($_POST['operation']??'', ['social-render','social-canvas-save'],true);
        $h='<section class="panel social-image-history" id="social-images" tabindex="-1"><h2>Bildfassungen <span class="badge">'.$page['total'].'</span></h2>';
        if($this->can('social','edit'))$h.=$this->start('social-render').Html::hidden('id',$item['id']).'<button class="btn btn-solid theme-primary">'.($item['kind']==='carousel'?'Karussell-Bilder erzeugen':'Feed-/Storybilder dieser Fassung erzeugen').'</button></form>';
        if(!$page['total'])return $h.'</section>';
        if($page['reset'])$h.='<p role="status">Neue Bildfassungen. Verlauf aktualisiert.</p>';
        $h.='<details class="social-image-pages"'.($open?' open':'').'><summary>Gespeicherte Bilder</summary>'.$this->pager($item['id'],$page).'<div class="social-image-grid">';
        foreach($page['rows']as$row){$url='/social-image/'.$row['id'];$kind=$row['kind']==='carousel'?'Karussell · Bild '.((int)$row['slide']+1):($row['kind']==='story'?'Story · Folie '.((int)$row['slide']+1):'Beitrag');
            $h.='<figure><a href="'.$url.'" target="_blank" rel="noopener"><img src="'.$url.'/thumb" data-original="'.$url.'" loading="lazy" decoding="async" width="320" height="'.($row['kind']==='story'?569:400).'" alt="'.Html::e('Fassung '.$row['item_version'].' · '.$kind).'"></a><figcaption><strong>Fassung '.Html::e($row['item_version']).'</strong><span>'.Html::e($kind).'</span><time>'.Html::e($row['created_at']).' UTC</time><a class="btn btn-outline theme-secondary" href="'.$url.'" download>Bild herunterladen</a></figcaption></figure>';
        }
        return $h.'</div>'.$this->pager($item['id'],$page).'</details></section>';
    }

    private function pager(string $item,array $page): string
    {
        $h='<nav class="social-image-pager" aria-label="Gespeicherte Bildseiten"><span>'.$page['total'].' Bilder · '.$page['page'].'/'.$page['pages'].'</span>';
        foreach([-1=>'Vorherige Bilder',1=>'Weitere Bilder']as$direction=>$label){$target=$page['page']+$direction;if($target>=1&&$target<=$page['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$item,'image_page'=>$target,'image_anchor'=>$page['anchor'],'image_total'=>$page['total']]).'#social-images').'">'.$label.'</a>';}
        if($page['pages']>1)$h.='<form method="get" action="/#social-images">'.Html::hidden('view','social').Html::hidden('section','compose').Html::hidden('item',$item).Html::hidden('image_anchor',$page['anchor']).Html::hidden('image_total',$page['total']).Html::input('image_page','Bildseite',$page['page'],'number','required min="1" max="'.$page['pages'].'"').'<button class="btn btn-outline theme-secondary">Bildseite öffnen</button></form>';
        return $h.'</nav>';
    }
}
