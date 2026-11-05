<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use PDO;
use InvalidArgumentException;
use Kulturhus\Native\Cms\ContentSections;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\{Html,ReferencePicker,RichTextEditor};

/** Normal forms provide one extra section per save, including without JavaScript. */
final class ContentSectionsPanel
{
    public function __construct(private PDO $db,private Access $access,private string $csrf){}
    public function render(array $page,bool $embedded=false):string
    {
        if((!$embedded&&empty($page['id']))||!empty($page['archived'])||!empty($page['trashed'])||($page['template_definition']['renderer']??$page['template'])!=='standard')return '';
        try{$this->access->require('website','edit');}catch(\DomainException){return '';}
        $sections=$page['content_sections']??[];$version=$page['version'];
        $submitted=http_response_code()>=400&&($_SERVER['REQUEST_METHOD']??'')==='POST'&&in_array($_POST['operation']??'',['page-sections-save',...($embedded?['page-save']:[])],true)&&($_POST['id']??'')===$page['id']&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf'])&&is_string($_POST['version']??null)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$_POST['version'])&&(!$embedded||(string)$page['version']===$_POST['version']);
        if($submitted){try{$sections=self::fromPost($_POST,false);if(is_string($_POST['version']??null)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$_POST['version']))$version=$_POST['version'];}catch(\DomainException|InvalidArgumentException){}}
        $h='<details id="page-content-sections" tabindex="-1" class="panel content-sections-panel"'.($submitted?' open':'').'><summary>Inhaltsabschnitte gestalten</summary>'.($embedded?Html::hidden('sections_present','1').'<p>Abschnitte erscheinen sofort in der Livevorschau und werden mit dem Seitenentwurf gespeichert.</p>':'<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','page-sections-save').Html::hidden('id',$page['id']).Html::hidden('version',$version));
        if(count($sections)<ContentSections::LIMIT)$sections[]=['id'=>bin2hex(random_bytes(16)),'layout'=>'text'];
        $h.=Html::hidden('section_row_count',(string)count($sections));
        foreach($sections as $index=>$row){
            $new=!isset($row['heading']);$name='section_rows['.$index.']';
            $label=$new?'+ Hinzufügen':($index+1).' · '.(($row['heading']??'')!==''?$row['heading']:'Ohne Überschrift');
            $h.='<details class="content-section-card"'.($submitted?' open':'').'><summary><span>'.Html::e($label).'</span></summary><fieldset data-content-section><legend class="sr-only">'.($new?'Neuen Abschnitt hinzufügen':'Abschnitt '.($index+1)).'</legend>'.Html::hidden($name.'[id]',$row['id']).($new?Html::hidden($name.'[unused_slot]','1'):'').'<div class="fields">'.Html::input($name.'[position]','Reihenfolge',($index+1)*10,'number','required min="0" max="100000" step="1"').Html::input($name.'[heading]','Überschrift',$row['heading']??'','text','maxlength="200"').Html::select($name.'[layout]','Anordnung',ContentSections::LAYOUTS,$row['layout']).'</div>'.RichTextEditor::render($name.'[body_html]',$row['body_html']??'','Abschnittstext').'<label for="section-image-'.$index.'">Bild</label>'.(new ReferencePicker($this->db,$this->access))->render('media',$name.'[media_id]','section-image-'.$index,$row['media_id']??'',false,'','','Ohne Bild').'<div class="fields">';
            foreach(['alt'=>'Bildbeschreibung','caption'=>'Bildunterschrift','credit'=>'Fotocredit','button_label'=>'Schaltflächentext','button_url'=>'Link (HTTPS, Seitenpfad oder Anker)']as $key=>$label)$h.=Html::input($name.'['.$key.']',$label,$row[$key]??'','text','maxlength="'.(['alt'=>500,'caption'=>500,'credit'=>300,'button_label'=>100,'button_url'=>2000][$key]).'"');
            $h.='</div>'.ContentSectionCrop::form($name,$row).($new?'':'<label class="check"><input class="check" type="checkbox" name="'.$name.'[remove]" value="1"> Diesen Abschnitt im Entwurf entfernen</label>').'</fieldset></details>';
        }
        return $h.($embedded?'':'<noscript><p class="hint">Andere ungespeicherte Eingaben zuerst sichern.</p></noscript><button class="btn btn-solid theme-primary">Abschnitte als Entwurf speichern</button></form><details><summary>Gespeicherte Vorschau</summary><iframe title="Gespeicherte Inhaltsabschnitte" sandbox="allow-same-origin" loading="lazy" src="/sections-preview?item='.Html::e($page['id']).'" style="width:100%;min-height:32rem;border:0"></iframe></details>').'</details>';
    }
    public static function fromPost(array $post,bool $normalize=true):array
    {
        $rows=$post['section_rows']??null;
        $count=$post['section_row_count']??null;
        if(!is_string($count)||!ctype_digit($count)||!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>ContentSections::LIMIT||(int)$count!==count($rows))throw new InvalidArgumentException('Vollständige Abschnittsliste prüfen.');
        $result=[];$identities=[];
        foreach($rows as $index=>$row){
            $required=['id','position','heading','body_html','media_id','alt','caption','credit','layout','button_label','button_url'];
            if(!is_array($row)||array_diff(array_keys($row),[...$required,'remove','unused_slot','aspect','focal_x','focal_y'])||array_diff($required,array_keys($row))||isset($row['unused_slot'])&&$row['unused_slot']!=='1')throw new InvalidArgumentException('Vollständige Abschnittsfelder prüfen.');
            foreach($row as $value)if(!is_string($value)||!preg_match('//u',$value))throw new InvalidArgumentException('Abschnittsfelder müssen UTF-8-Text sein.');
            $id=$row['id']??'';$position=filter_var($row['position']??null,FILTER_VALIDATE_INT);
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||isset($identities[$id])||$position===false||$position===null||$position<0||$position>100000||isset($row['remove'])&&$row['remove']!=='1')throw new InvalidArgumentException('Abschnittskennung, Reihenfolge und Entfernen prüfen.');$identities[$id]=true;
            if(isset($row['remove']))continue;
            $unused=isset($row['unused_slot']);unset($row['position'],$row['remove'],$row['unused_slot']);
            $empty=true;foreach(['heading','body_html','media_id','alt','caption','credit','button_label','button_url']as $key)if(trim($row[$key]??'')!=='')$empty=false;
            $defaultCrop=!array_intersect(array_keys($row),['aspect','focal_x','focal_y'])||(($row['aspect']??null)==='original'&&($row['focal_x']??null)==='50'&&($row['focal_y']??null)==='50');
            if($unused&&$empty&&($row['layout']??'text')==='text'&&$defaultCrop)continue;
            $row['media_id']=($row['media_id']??'')===''?null:$row['media_id'];$result[]=['position'=>$position,'index'=>$index,'value'=>$row];
        }
        usort($result,static fn($a,$b)=>[$a['position'],$a['index']]<=>[$b['position'],$b['index']]);
        $values=array_column($result,'value');
        if(!$normalize){foreach($values as &$row){if($row['media_id']!==null&&!preg_match('/\A[a-f0-9]{32}\z/D',$row['media_id']))throw new InvalidArgumentException('Bildkennung prüfen.');$row['body_html']=\Kulturhus\Native\Shared\RichText::sanitize($row['body_html']);}unset($row);return $values;}
        return ContentSections::normalize($values);
    }
}
