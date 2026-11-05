<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;

/** A bounded, explicitly confirmed review of server-resolved code bindings. */
final class PageFieldtypesPanel
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public function __construct(private \PDO $db,private Access $access,private string $csrf){}
    public function render(array $page):string
    {
        if(empty($page['id'])||!empty($page['trashed'])||!empty($page['archived']))return '';
        try{$changes=(new Pages($this->db,$this->access))->fieldtypeChanges($page['id']);}catch(\DomainException){return '';}
        if(!$changes)return '';$number=filter_var($_GET['fieldtypes_page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);$number=$number===false?1:$number;$total=(int)ceil(count($changes)/10);$number=min($number,$total);$slice=array_slice($changes,($number-1)*10,10);
        $submitted=($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??'')==='page-fieldtypes-review'&&($_POST['id']??'')===$page['id'];$selected=$submitted&&is_array($_POST['fieldtype_paths']??null)?array_filter($_POST['fieldtype_paths'],'is_string'):[];$ready=false;
        $h='<details class="panel fieldtype-review-panel"'.($submitted||$number>1?' open':'').'><summary>'.Html::e($this->contentFormat('Typfassungen prüfen · {count}',['count'=>count($changes)])).'</summary><form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','page-fieldtypes-review').Html::hidden('id',$page['id']).Html::hidden('version',$this->contentPosted('page-fieldtypes-review',$page['id'])&&is_string($_POST['version']??null)?$_POST['version']:$page['version']);
        foreach($slice as$change){$ready=$ready||$change['available'];$h.='<div class="fieldtype-review-row"><label class="check"><input type="checkbox" name="fieldtype_paths[]" value="'.Html::e($change['path']).'"'.(!$change['available']?' disabled':'').(in_array($change['path'],$selected,true)?' checked':'').'>'.Html::e($change['label']).('</label><dl><dt>'.Html::e($this->contentText('Gespeichert')).'</dt><dd>').Html::e($change['stored']).('</dd><dt>'.Html::e($this->contentText('Aktuell')).'</dt><dd>').Html::e($change['current']).'</dd></dl>'.(!$change['available']?('<span class="badge">'.Html::e($this->contentText('Nicht verfügbar')).'</span>'):'').'</div>';}
        if($ready)$h.=('<label class="check"><input type="checkbox" name="fieldtypes_confirm" value="1" required>'.Html::e($this->contentText('Ausgewählte Typfassungen im Entwurf übernehmen')).'</label><button type="submit">'.Html::e($this->contentText('Typfassungen übernehmen')).'</button>');
        $h.='</form><a data-nav class="btn secondary" href="/?'.Html::e(http_build_query(['view'=>'website','item'=>$page['id'],'fieldtypes_page'=>$number])).('">'.Html::e($this->contentText('Aktuellen Entwurf laden')).'</a>');if($total>1){$h.=('<nav class="pager" aria-label="'.Html::e($this->contentText('Typfassungen')).'">');foreach(['Zurück'=>$number-1,'Weiter'=>$number+1]as$label=>$target)if($target>=1&&$target<=$total)$h.='<a data-nav href="/?'.Html::e(http_build_query(['view'=>'website','item'=>$page['id'],'fieldtypes_page'=>$target])).'">'.Html::e($this->contentText($label)).'</a>';$h.='<span>'.$number.' / '.$total.'</span></nav>';}
        return $h.'</details>';
    }
}
