<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\{ContentLists,SelectorGrammar};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;
use PDO;

/** One bounded result page; query controls and saved views stay collapsed until requested. */
final class ContentListPanel
{
    private const OPS=['eq'=>'ist','ne'=>'ist nicht','contains_ci'=>'enthält','starts_with_ci'=>'beginnt mit','gt'=>'größer als','gte'=>'mindestens','lt'=>'kleiner als','lte'=>'höchstens','empty'=>'ist leer','filled'=>'ist ausgefüllt'];
    public function __construct(private PDO $db,private Access $access,private string $csrf){}
    private static function scalar(array $data,string $name,string $default=''):string{if(!is_string($data[$name]??$default))throw new \InvalidArgumentException('Listeneingabe prüfen.');return $data[$name]??$default;}
    public function config(array $data):array
    {
        $columns=$data['columns']??['title','slug','status'];$mode=self::scalar($data,'filter_mode','rules');if(!in_array($mode,['rules','expression'],true))throw new \InvalidArgumentException('Filtermodus auswählen.');$rules=$data['rules']??[];$junction=self::scalar($data,'junction','all');
        if(!in_array($junction,['all','any'],true)||!is_array($rules)||count($rules)>20)throw new \InvalidArgumentException('Filter prüfen.');$selectors=[];
        foreach($rules as$rule){if(!is_array($rule)||array_diff(array_keys($rule),['field','op','value']))throw new \InvalidArgumentException('Filterbedingung prüfen.');$field=self::scalar($rule,'field');if($field==='')continue;$op=self::scalar($rule,'op','eq');if(!isset(self::OPS[$op]))throw new \InvalidArgumentException('Filteroperator prüfen.');$selectors[]=['field'=>$field,'op'=>in_array($op,['empty','filled'],true)?($op==='empty'?'eq':'ne'):$op,'value'=>in_array($op,['empty','filled'],true)?null:self::scalar($rule,'value')];}
        $sort=self::scalar($data,'sort','title');$direction=self::scalar($data,'direction','asc');
        $aggregate=self::scalar($data,'aggregate');$order=['field'=>$sort,'direction'=>$direction];if($aggregate!=='')$order['aggregate']=$aggregate;
        $filter=$mode==='expression'?['expression'=>self::scalar($data,'selector_text')]:['selectors'=>$selectors?[[$junction=>$selectors]]:[]];
        return(new ContentLists($this->db,$this->access))->normalize(['columns'=>$columns,...$filter,'order'=>[$order]]);
    }
    public function action(array $post):array
    {
        if(!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new \DomainException('Formular abgelaufen. Bitte neu laden.');
        $service=new ContentLists($this->db,$this->access);$operation=self::scalar($post,'operation');
        $raw=self::scalar($post,'list_config');if(strlen($raw)>16384)throw new \InvalidArgumentException('Suchansicht zu groß.');$config=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($config))throw new \InvalidArgumentException('Suchansicht prüfen.');
        if($operation==='content-list-batch'){
            if(($post['confirm']??null)!=='1')throw new \InvalidArgumentException('Vorbereitung der Originalauswahl bestätigen.');$selected=$post['selected']??[];if(!is_array($selected))throw new \InvalidArgumentException('Originalauswahl prüfen.');$job=$service->batch($config,$selected);return['view'=>'website','section'=>'bulk','batch'=>$job['id'],'notice'=>'Originalauswahl vorbereitet.'];
        }
        if(!in_array($operation,['content-list-save','content-list-remove'],true))throw new \InvalidArgumentException('Listenaktion prüfen.');$id=self::scalar($post,'list_id');$expected=self::scalar($post,'list_version','0');if(!preg_match('/^(?:0|[1-9][0-9]{0,8})$/D',$expected))throw new \InvalidArgumentException('Ansichtsversion prüfen.');
        if($operation==='content-list-remove'&&($post['confirm']??null)!=='1')throw new \InvalidArgumentException('Entfernen der eigenen Suchansicht bestätigen.');
        $result=$service->save($id!==''?$id:null,(int)$expected,self::scalar($post,'list_name'),$config,$operation==='content-list-remove');
        return['view'=>'website','section'=>'lister','list_id'=>$operation==='content-list-remove'?'':$result['id'],'notice'=>$operation==='content-list-remove'?'Suchansicht entfernt.':'Eigene Suchansicht gespeichert.'];
    }
    public function render(array $query):string
    {
        $service=new ContentLists($this->db,$this->access);$id=self::scalar($query,'list_id');$preset=$id!==''?$service->get($id):null;
        $validationError='';$number=self::scalar($query,'list_page','1');if(!preg_match('/^[1-9][0-9]{0,5}$/D',$number))throw new \InvalidArgumentException('Listenseite prüfen.');
        try{$config=isset($query['list_apply'])?$this->config($query):($preset['config']??$service->normalize([]));$data=$service->listing($config,(int)$number,10);}
        catch(\InvalidArgumentException $error){$validationError=$error->getMessage();$config=$service->normalize([]);$data=['rows'=>[],'total'=>0,'page'=>1,'last'=>1];}
        $labels=$service->fields();$filterLabels=$service->filterFields();$e=Html::e(...);$href=static function(int $page)use($query):string{$args=$query;$args['view']='website';$args['section']='lister';$args['list_page']=$page;return '/?'.http_build_query($args);};
        $h='<div class="content-lister"><a class="back-link" data-nav href="/?view=website">← Zur Seitenübersicht</a><h2>Inhaltsliste</h2><details class="panel"><summary>Meine Suchansichten</summary><ul>';
        foreach($service->presets()as$row)$h.='<li><a data-nav href="/?view=website&amp;section=lister&amp;list_id='.$e($row['id']).'">'.$e($row['name']).'</a></li>';
        $h.='</ul><a data-nav href="/?view=website&amp;section=lister">Neue Ansicht</a></details><details class="panel"'.(isset($query['list_apply'])?' open':'').'><summary>Spalten &amp; Filter</summary><form method="get" action="/">'.Html::hidden('view','website').Html::hidden('section','lister').Html::hidden('list_apply','1').($id!==''?Html::hidden('list_id',$id):'').'<fieldset><legend>Spalten auswählen · bis zu acht</legend>';
        $displayColumns=isset($query['list_apply'])&&is_array($query['columns']??null)?$query['columns']:$config['columns'];
        foreach($labels as$field=>$label)$h.='<label class="setting-check"><input class="check" type="checkbox" name="columns[]" value="'.$e($field).'"'.(in_array($field,$displayColumns,true)?' checked':'').'> '.$e($label).'</label>';
        $h.='</fieldset>';if($validationError!=='')$h.='<p role="alert">'.$e($validationError).'</p>';
        $simpleTerms=$config['selectors'];if(count($simpleTerms)===1&&(isset($simpleTerms[0]['any'])||isset($simpleTerms[0]['all'])))$simpleTerms=$simpleTerms[0]['any']??$simpleTerms[0]['all'];$simple=true;foreach($simpleTerms as $term)if(!isset($term['field'],$term['op'])||array_diff(array_keys($term),['field','op','value']))$simple=false;
        $mode=isset($query['list_apply'])?self::scalar($query,'filter_mode','rules'):($simple?'rules':'expression');
        $source=isset($query['list_apply'])?self::scalar($query,'selector_text'):SelectorGrammar::format($config['selectors']);
        $h.='<div data-content-filter-modes>'.Html::select('filter_mode','Suchmöglichkeiten',['rules'=>'Einfacher Filter','expression'=>'Erweiterte Suche'],$mode).'<details data-filter-expression'.($mode==='expression'?' open':'').'><summary>Erweiterte Suche mit Suchausdruck</summary><label for="content-selector">Suchausdruck</label><textarea id="content-selector" class="form-control" name="selector_text" rows="3" maxlength="16384" spellcheck="false" aria-describedby="selector-example">'.$e($source).'</textarea><small id="selector-example">Beispiel: title contains_ci &quot;Sommer&quot; AND (status = &quot;draft&quot; OR event_day &gt;= &quot;2026-10-15&quot;)</small></details><div data-filter-rules>';$terms=$config['selectors'];$junction='all';if(count($terms)===1&&isset($terms[0]['any'])){$junction='any';$terms=$terms[0]['any'];}elseif(count($terms)===1&&isset($terms[0]['all']))$terms=$terms[0]['all'];
        if(isset($query['list_apply'])&&isset($query['rules'])&&is_array($query['rules'])){$terms=$query['rules'];$junction=self::scalar($query,'junction','all');}
        $h.=Html::select('junction','Filter verbinden',['all'=>'Alle Bedingungen','any'=>'Mindestens eine Bedingung'],$junction);
        $rowCount=max(3,min(20,count($terms)+1));foreach(range(0,$rowCount-1)as$i){$term=$terms[$i]??[];$h.='<fieldset><legend>Filter '.($i+1).'</legend>'.Html::select('rules['.$i.'][field]','Feld',[''=>'Kein Filter',...$filterLabels],$term['field']??'').Html::select('rules['.$i.'][op]','Vergleich',self::OPS,$term['op']??'eq').Html::input('rules['.$i.'][value]','Wert',is_scalar($term['value']??null)?(string)$term['value']:'','text','maxlength="2000"').'</fieldset>';}
        $h.='</div></div>'.Html::select('sort','Sortieren nach',$filterLabels,isset($query['list_apply'])?self::scalar($query,'sort','title'):($config['order'][0]['field']??'title')).Html::select('direction','Richtung',['asc'=>'Aufsteigend','desc'=>'Absteigend'],isset($query['list_apply'])?self::scalar($query,'direction','asc'):($config['order'][0]['direction']??'asc')).Html::select('aggregate','Mehrfachwerte sortieren',[''=>'Einzelwert','min'=>'Kleinster Wert','max'=>'Größter Wert','count'=>'Anzahl'],isset($query['list_apply'])?self::scalar($query,'aggregate'):($config['order'][0]['aggregate']??'')).'<button class="btn btn-solid theme-primary">Liste anzeigen</button></form></details>';
        $json=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$form='<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('list_config',$json);
        $h.='<details class="panel"><summary>Diese Suchansicht speichern</summary>'.($validationError!==''?'<p>Suchausdruck zuerst korrigieren.</p>':'').$form.Html::hidden('operation','content-list-save').Html::hidden('list_id',$id).Html::hidden('list_version',$preset['version']??0).Html::input('list_name','Name',$preset['name']??'','text','required maxlength="80"').'<button class="btn btn-solid theme-primary"'.($validationError!==''?' disabled':'').'>Speichern</button></form>';
        if($preset)$h.=$form.Html::hidden('operation','content-list-remove').Html::hidden('list_id',$id).Html::hidden('list_version',$preset['version']).Html::hidden('list_name',$preset['name']).'<label><input class="check" type="checkbox" name="confirm" value="1" required> Eigene Suchansicht entfernen</label><button class="btn btn-outline theme-secondary">Entfernen</button></form>';
        $h.='</details><p role="status">'.$data['total'].' Inhalte · Seite '.$data['page'].' von '.$data['last'].'</p>';
        try{$this->access->require('website','publish');$bulk=true;}catch(\DomainException){$bulk=false;}
        if($validationError!=='')$bulk=false;
        if($bulk)$h.=$form.Html::hidden('operation','content-list-batch');
        foreach($data['rows']as$row){$h.='<article class="content-list-result" data-content-list-row="'.$e($row['id']).'">';if($bulk)$h.='<label><input class="check" type="checkbox" name="selected[]" value="'.$e($row['id'].':'.$row['version']).'"> Auswählen<span class="visually-hidden">: '.$e((string)($row['c0']??'')).'</span></label>';
            $h.='<dl>';foreach($config['columns']as$i=>$column){$value=$row['c'.$i]??'';if($column==='status')$value=['draft'=>'Entwurf','published'=>'Veröffentlicht','unpublished_changes'=>'Neuer Entwurf'][$value]??$value;$h.='<dt>'.$e($labels[$column]).'</dt><dd>'.($i===0?'<a data-nav href="/?view=website&amp;item='.$e($row['id']).'">'.$e(trim((string)$value)!==''?mb_strimwidth((string)$value,0,500,'…','UTF-8'):'Inhalt öffnen').'</a>':$e(mb_strimwidth((string)$value,0,500,'…','UTF-8'))).'</dd>';} $h.='</dl></article>';}
        if($bulk)$h.='<label><input class="check" type="checkbox" name="confirm" value="1" required> Ausgewählte Originale als Papierkorbstapel vorbereiten</label><button class="btn btn-solid theme-primary">Stapel vorbereiten</button></form>';
        $h.='<nav aria-label="Inhaltslistenseiten">';if($data['page']>1)$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.$e($href($data['page']-1)).'">Zurück</a> ';if($data['page']<$data['last'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.$e($href($data['page']+1)).'">Weiter</a>';
        $h.='</nav><form method="get" action="/">';foreach($query as$key=>$value){if(!in_array($key,['list_page','view','section'],true))$h.=self::hiddenQuery($key,$value);}return $h.Html::hidden('view','website').Html::hidden('section','lister').Html::input('list_page','Seite öffnen',(string)$data['page'],'number','min="1" max="'.$data['last'].'" required').'<button class="btn btn-outline theme-secondary">Öffnen</button></form></div>';
    }
    private static function hiddenQuery(string $key,mixed $value,int $depth=0):string
    {
        if($depth>4)throw new \InvalidArgumentException('Suchansicht prüfen.');if(!is_array($value))return is_scalar($value)?Html::hidden($key,(string)$value):'';$h='';foreach($value as$k=>$v)$h.=self::hiddenQuery($key.'['.$k.']',$v,$depth+1);return$h;
    }
}
