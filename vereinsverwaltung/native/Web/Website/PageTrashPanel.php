<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\{PageTrash, PageReferences};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;
use PDO;

final class PageTrashPanel
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public function __construct(private PDO $db, private Access $access, private string $csrf) {}

    public function render(array $page): string
    {
        $trash = $page['trashed'];
        $h = '<section class="panel page-trash-panel" data-trash-page="'.Html::e($page['id']).'"><h2>'.Html::e($this->contentText($trash?'Im Papierkorb':'Papierkorb')).'</h2>';
        if ($trash) $h .= '<p>'.Html::e($page['title']).' · /'.Html::e($page['slug']).('</p><p>'.Html::e($this->contentText('Inhalte, Bilder, Kennung und frühere Versionen bleiben erhalten. Zurückholen veröffentlicht die Seite nicht.')).'').($page['archived']?' '.Html::e($this->contentText('Der frühere Archivstatus bleibt erhalten.')):'').'</p>';
        else $h .= ('<p>'.Html::e($this->contentText('Die gespeicherte Seite offline nehmen und aus der aktiven Redaktion entfernen. Originale, Versionen, Adressen und Bilder bleiben zum Zurückholen erhalten. Ungespeicherte Inhalte vorher sichern.')).'</p>');
        if(is_string($_POST['operation']??null)&&in_array($_POST['operation'],['page-trash','page-trash-restore','page-purge'],true)&&$this->contentPosted($_POST['operation'],$page['id'])&&is_string($_POST['version']??null)&&(string)$_POST['version']!==(string)$page['version'])$h.='<p role="alert">'.Html::e($this->contentText('Die Seite wurde inzwischen geändert. Bitte neu laden.')).' <a data-nav href="'.Html::e('/?'.http_build_query(['view'=>'website','item'=>$page['id']])).'">'.Html::e($this->contentText('Aktuellen Stand öffnen')).'</a></p>';
        $allowed = true;
        try { $this->access->require('website',$trash?'edit':'publish'); \Kulturhus\Native\Cms\TemplatePolicy::assertCapability($this->db,$this->access,$page['id'],$trash?'edit':'publish'); }
        catch (\DomainException) { $allowed=false; }
        if (!$trash) {
            $references = (new PageReferences($this->db,$this->access))->incoming($page['id']);
            if ($references) {
                $h .= ('<p>'.Html::e($this->contentText('Vor dem Verschieben diese Unterseiten oder Verknüpfungen bearbeiten:')).'</p><ul>');
                foreach (array_slice($references,0,25) as $reference) $h .= !empty($reference['restricted'])?('<li>'.Html::e($this->contentText('Geschützter Verweis')).'</li>'):'<li><a data-nav href="/?view=website&amp;item='.Html::e($reference['id']).'">'.Html::e($reference['title']).'</a> · '.Html::e($this->contentText($reference['basis']==='draft'?'gespeicherter Entwurf':'veröffentlichtes Original')).'</li>';
                $h .= '</ul>';if(count($references)>25)$h.=('<p>'.Html::e($this->contentText('Weitere Verweise vorhanden.')).'</p>');
                $allowed=false;
            }
        }
        if ($allowed) {
            $version=$page['version'];
            if (is_string($_POST['operation']??null) && in_array($_POST['operation'],['page-trash','page-trash-restore'],true) && $this->contentPosted($_POST['operation'],$page['id']) && is_string($_POST['version']??null) && preg_match('/\A[1-9][0-9]{0,9}\z/D',$_POST['version'])) $version=$_POST['version'];
            $h .= '<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation',$trash?'page-trash-restore':'page-trash').Html::hidden('id',$page['id']).Html::hidden('version',$version);
            if (!$trash) $h .= ('<label class="check"><input class="check" type="checkbox" name="trash_confirm" value="1" required>'.' '.Html::e($this->contentText('Gespeicherte Seite offline nehmen und in den Papierkorb verschieben')).'</label>');
            $h .= '<button class="btn btn-solid theme-'.($trash?'primary':'danger').'">'.Html::e($this->contentText($trash?'Privat zurückholen':'In den Papierkorb verschieben')).'</button></form>';
        }
        if($trash){
            try{(new \Kulturhus\Native\Cms\PagePurge($this->db,$this->access))->assertRemovalRights($page['id']);$purgeAllowed=true;}catch(\DomainException){$purgeAllowed=false;}
            if($purgeAllowed){
                $h.=('<details class="page-purge"><summary>'.Html::e($this->contentText('Endgültig löschen')).'</summary><p>'.Html::e($this->contentText('Entfernt Seiteninhalt und Versionen dauerhaft. Verknüpfte Originale verhindern die Löschung. Bilder bleiben erhalten.')).'</p><form method="post">').Html::hidden('csrf',$this->csrf).Html::hidden('operation','page-purge').Html::hidden('id',$page['id']).Html::hidden('version',$this->contentPosted('page-purge',$page['id'])&&is_string($_POST['version']??null)?$_POST['version']:$page['version']).(\Kulturhus\Native\Comments\PageRemoval::present($this->db,$this->access->club(),$page['id'])?('<label class="check"><input class="check" type="checkbox" name="purge_comments_confirm" value="1" required>'.' '.Html::e($this->contentText('Zugehörige Kommentare und ihren Verlauf dauerhaft löschen')).'</label>'):'').('<label class="check"><input class="check" type="checkbox" name="purge_confirm" value="1" required>'.' '.Html::e($this->contentText('Diese gespeicherte Seite endgültig löschen')).'</label><button class="btn btn-solid theme-danger">'.Html::e($this->contentText('Seite endgültig löschen')).'</button></form></details>');
            }
        }
        return $h.('<p><a data-nav href="/?view=website&amp;section=trash">'.Html::e($this->contentText('Papierkorb öffnen')).'</a></p></section>');
    }

    public function listing(array $query): string
    {
        $page = $query['page']??'1';
        if (!is_string($page) || !preg_match('/\A[1-9][0-9]{0,5}\z/D',$page)) throw new \InvalidArgumentException('Gültige Papierkorbseite angeben.');
        $data=(new PageTrash($this->db,$this->access))->listing((int)$page);
        $h=('<div class="page-trash-list"><h2>'.Html::e($this->contentText('Papierkorb')).'</h2><p>'.Html::e($this->contentText('Seiten, Beiträge, Veranstaltungen und Alben zurückholen. Frühere Veröffentlichungen bleiben offline, bis sie erneut geprüft und ausdrücklich veröffentlicht werden.')).'</p><p role="status">').Html::e($this->contentFormat('{count} Inhalte · Seite {page} von {pages}',['count'=>$data['total'],'page'=>$data['page'],'pages'=>$data['last']])).'</p><ul class="list-group">';
        foreach($data['rows'] as $row) $h.='<li class="list-group-item" data-trash-row="'.Html::e($row['id']).'"><a data-nav href="/?view=website&amp;item='.Html::e($row['id']).'">'.Html::e($row['title']).'</a> <small>/'.Html::e($row['slug']).'</small></li>';
        $h.='</ul>';if(!$data['rows'])$h.=('<p>'.Html::e($this->contentText('Der Papierkorb ist leer.')).'</p>');
        if($data['last']>1){$h.=('<nav aria-label="'.Html::e($this->contentText('Papierkorbseiten')).'">');foreach(['Vorherige Seite'=>$data['page']-1,'Nächste Seite'=>$data['page']+1]as $label=>$target)if($target>=1&&$target<=$data['last'])$h.='<a data-nav href="/?view=website&amp;section=trash&amp;page='.$target.'">'.Html::e($this->contentText($label)).'</a> ';$h.='</nav>';}
        return $h.('<p><a data-nav href="/?view=website">'.Html::e($this->contentText('Zur aktiven Redaktion')).'</a> · <a data-nav href="/?view=website&amp;section=bulk&amp;direction=restore">'.Html::e($this->contentText('Stapelweise privat zurückholen')).'</a></p></div>');
    }
}
