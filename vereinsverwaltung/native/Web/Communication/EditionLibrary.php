<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Communication\{EditionCatalogue as Catalogue,Content};
use Kulturhus\Native\Web\{Workspace,Html};

final class EditionLibrary extends Workspace
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public function render(string $module,string $id): string
    {
        $p=Catalogue::page($this->db,$this->access,$module,$_GET);$e=Html::e(...);$base=['view'=>$module,'section'=>'compose','edition_query'=>$p['query'],'edition_state'=>$p['state'],'edition_page'=>$p['page'],'edition_open'=>'1'];
        $h='<section class="edition-library"><details class="draft-library edition-list"'.(($_GET['edition_open']??'')==='1'?' open':'').'><summary>'.Html::e($this->contentText($module==='press'?'Pressemitteilungen':'Newsletter-Ausgaben')).' · '.$p['total'].'</summary><form method="get">'.Html::hidden('view',$module).Html::hidden('section','compose').Html::hidden('item',$id).Html::hidden('edition_open','1').'<div class="edition-filter">'.Html::input('edition_query',$this->contentText('Titel / Betreff'),$p['query'],'search','maxlength="200"').Html::select('edition_state',$this->contentText('Status'),$this->contentOptions(Catalogue::STATES),$p['state']).('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Suchen')) . '</button></div></form><p role="status">' . Html::e($this->contentText('Seite')) . ' ').$p['page'].' '.Html::e($this->contentText('von')).' '.$p['pages'].'</p><div class="edition-cards">';
        foreach($p['rows']as$r)$h.='<a class="item edition-card" data-nav href="/?'.$e(http_build_query($base+['item'=>$r['id']])).'"'.($r['id']===$id?' aria-current="page"':'').'><strong>'.$e($r['title']).'</strong><span>'.$e($this->contentText(Catalogue::STATES[$r['status']]??$this->contentText('Stand prüfen'))).' · '.Html::e($this->contentText('Fassung')).' '.(int)$r['version'].'</span><time>'.$this->displayTime($r['updated_at']).'</time></a>';
        if(!$p['rows'])$h.=('<p>' . Html::e($this->contentText('Keine passenden Ausgaben.')) . '</p>');$h.='</div>'.$this->paging($base,$p,'edition_page').'</details>';
        if($this->can($module,'edit'))$h.='<a class="btn btn-outline theme-secondary" data-nav href="/?view='.$e($module).('&amp;section=compose">' . Html::e($this->contentText('+ Neu anlegen')) . '</a>');
        return$h.'</section>';
    }
    public function history(string $module,string $id): string
    {
        $p=Catalogue::history($this->db,$this->access,$module,$id,$_GET['edition_history_page']??1);$e=Html::e(...);$base=['view'=>$module,'section'=>'compose','item'=>$id,'edition_history_open'=>'1'];
        foreach(['edition_query','edition_state','edition_page','edition_open']as$key)if(isset($_GET[$key])&&is_string($_GET[$key]))$base[$key]=$_GET[$key];
        $h='<details class="edition-history"'.(($_GET['edition_history_open']??'')==='1'?' open':'').('><summary>' . Html::e($this->contentText('Versionen ·')) . ' ').$p['total'].('</summary><p>' . Html::e($this->contentText('Seite')) . ' ').$p['page'].' '.Html::e($this->contentText('von')).' '.$p['pages'].'</p>';
        foreach($p['rows']as$r)$h.='<p><a class="btn btn-outline theme-secondary" href="/preview?'.$e(http_build_query(['module'=>$module,'item'=>$id,'revision'=>(int)$r['version']])).('" target="_blank" rel="noopener">' . Html::e($this->contentText('Fassung')) . ' ').(int)$r['version'].' · '.$this->displayTime($r['created_at']).'</a></p>';
        return$h.$this->paging($base,$p,'edition_history_page').'</details>';
    }
    private function paging(array $base,array $p,string $key): string
    {
        $h='<nav class="edition-pages" aria-label="'.($key==='edition_page'?'Ausgabenseiten':'Fassungsseiten').'">';foreach(['Zurück'=>-1,'Weiter'=>1]as$label=>$delta){$page=$p['page']+$delta;if($page>0&&$page<=$p['pages'])$h.='<a class="btn btn-outline theme-primary" data-nav href="/?'.Html::e(http_build_query(array_replace($base,[$key=>$page]))).'">'.Html::e($this->contentText($label)).'</a>';}
        return$h.'</nav>';
    }
}
