<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,DataModel,TemplateContexts,TemplatePolicy,CustomFields,FieldValues,Conflict};
use Kulturhus\Native\Web\Fields\TemplateSettings;
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);$access=new Access($db,'admin');$model=new DataModel($db,$access);$pages=new Pages($db,$access);$checks=0;
function check(bool $okay,string $label):void{global $checks;if(!$okay)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function rejects(callable $operation,string $class,string $label):void{try{$operation();}catch(Throwable $error){check($error instanceof $class,$label.' ('.$error::class.')');return;}throw new RuntimeException($label);}
$field=$model->saveField(['key'=>'context_name','label'=>'Global label','type'=>'text','max'=>100]);$global=$model->field('context_name');
$strict=$model->saveTemplate(['key'=>'context_strict','label'=>'Short form','renderer'=>'standard','fields'=>['context_name'],'field_contexts'=>['context_name'=>['label'=>'Local label','required'=>true,'min'=>3,'max'=>5]]]);
$plain=$model->saveTemplate(['key'=>'context_plain','label'=>'Unchanged form','renderer'=>'standard','fields'=>['context_name']]);
check($model->templateFields('context_strict')['context_name']['label']==='Local label'&&$model->templateFields('context_plain')['context_name']===$global,'same shared field has independent template label and constraints');
check($model->field('context_name')===$global&&count($model->fieldRevisions('context_name'))===1,'template context never rewrites global original or its history');
rejects(fn()=>$pages->save(['title'=>'Too short','slug'=>'context-too-short','template'=>'context_strict','custom_fields'=>['context_name'=>'ab']]),InvalidArgumentException::class,'context minimum is enforced by actual page service');
rejects(fn()=>$pages->save(['title'=>'Too long','slug'=>'context-too-long','template'=>'context_strict','custom_fields'=>['context_name'=>'abcdef']]),InvalidArgumentException::class,'context maximum is enforced by actual page service');
$original=$pages->save(['title'=>'Exact contextual original','slug'=>'context-original','template'=>'context_strict','custom_fields'=>['context_name'=>'abc']]);$original=$pages->publish($original['id'],$original['version']);$published=$pages->publicPage($original['slug']);
$strict=$model->saveTemplate(array_replace($strict,['field_contexts'=>['context_name'=>['label'=>'Private original','required'=>false,'display'=>'hidden']]]),$strict['version']);
check($pages->publicPage($original['slug'])===$published,'editing contextual model retains exact published original until deliberate page resave');
rejects(fn()=>$pages->publish($original['id'],$original['version']),Conflict::class,'changed context prevents silent publication of stale captured definitions');
$saved=$pages->save(['id'=>$original['id'],'title'=>$original['title'],'slug'=>$original['slug'],'template'=>'context_strict'],$original['version']);
check($saved['custom_fields']['context_name']==='abc'&&$saved['field_definitions']['context_name']['display']==='hidden','omitted hidden control inherits exact original through actual save');
rejects(fn()=>$pages->save(array_replace($saved,['custom_fields'=>['context_name'=>'forged']]),$saved['version']),DomainException::class,'direct forged hidden-field write cannot bypass omitted UI control');
$public=FieldValues::publicPayload($saved);check(!isset($public['custom_fields']['context_name'])&&!isset($public['field_definitions']['context_name'])&&!str_contains(FieldValues::publicText($saved),'abc'),'hidden field is absent from public values, definitions and indexing text');
$strict=$model->saveTemplate(array_replace($strict,['field_contexts'=>['context_name'=>['required'=>false,'display'=>'readonly']]]),$strict['version']);
$saved=$pages->save(['id'=>$saved['id'],'title'=>$saved['title'],'slug'=>$saved['slug'],'template'=>'context_strict'],$saved['version']);
check($saved['custom_fields']['context_name']==='abc','disabled readonly control inherits previous typed value');
rejects(fn()=>$pages->save(array_replace($saved,['custom_fields'=>['context_name'=>'different']]),$saved['version']),DomainException::class,'readonly field forbids direct writes as well');

$multi=$model->saveField(['key'=>'context_tags','label'=>'Global tags','type'=>'text','multiple'=>true,'max_items'=>4,'max'=>100]);
$set=$model->saveTemplate(['key'=>'context_multi','label'=>'Contextual collection','renderer'=>'standard','fields'=>['context_tags'],'field_contexts'=>['context_tags'=>['max'=>3]]]);
$tags=$pages->save(['title'=>'Typed collection','slug'=>'context-tags','template'=>'context_multi','custom_fields'=>['context_tags'=>['abc','xy']]]);
check($tags['custom_fields']['context_tags']===['abc','xy']&&$tags['field_definitions']['context_tags']['max_items']===4,'context scalar bounds apply within typed multiple values and preserve collection limit');
rejects(fn()=>$pages->save(array_replace($tags,['custom_fields'=>['context_tags'=>['long']]]),$tags['version']),InvalidArgumentException::class,'context bound validates each collection item');
$child=$model->saveField(['key'=>'context_child','label'=>'Child original','type'=>'text']);$repeater=$model->saveField(['key'=>'context_rows','label'=>'Rows','type'=>'repeater','fields'=>['context_child']]);
$form=$model->saveTemplate(['key'=>'context_repeater','label'=>'Nested original','renderer'=>'standard','fields'=>['context_rows']]);
$rows=$pages->save(['title'=>'Nested source','slug'=>'context-repeater','template'=>'context_repeater','custom_fields'=>['context_rows'=>[['values'=>['context_child'=>'First original']]]]]);$item=$rows['custom_fields']['context_rows'][0];
$form=$model->saveTemplate(array_replace($form,['field_contexts'=>['context_rows'=>['label'=>'Retained rows','display'=>'readonly']]]),$form['version']);
$rows=$pages->save(['id'=>$rows['id'],'title'=>$rows['title'],'slug'=>$rows['slug'],'template'=>'context_repeater'],$rows['version']);
check($rows['custom_fields']['context_rows'][0]===$item&&$rows['field_definitions']['context_rows']['item_definitions']['context_child']===$model->field('context_child'),'readonly repeater keeps item identity, version, values and original child definition');
$forged=$rows;$forged['custom_fields']['context_rows'][0]['values']['context_child']='Forged child';rejects(fn()=>$pages->save($forged,$rows['version']),DomainException::class,'nested child write cannot bypass readonly parent context');
$globalRows=$model->field('context_rows');$historyRows=$model->fieldRevisions('context_rows');
rejects(fn()=>$model->saveField(array_replace($repeater,['required'=>true]),$repeater['version']),InvalidArgumentException::class,'global required change cannot make an existing readonly context unusable');
check($model->field('context_rows')===$globalRows&&$model->fieldRevisions('context_rows')===$historyRows,'incompatible global field change rolls back definition and revision atomically');

foreach([['unknown'=>['label'=>'Invalid']],['context_name'=>['type'=>'password']],['context_name'=>['display'=>'script']],['context_name'=>['required'=>'1']]] as $context)rejects(fn()=>TemplateContexts::normalize($context,['context_name']),InvalidArgumentException::class,'context rejects foreign field, type replacement, executable display or forged boolean');
rejects(fn()=>TemplateContexts::resolve(['field_contexts'=>['context_name'=>['display'=>'hidden','required'=>true]]],['context_name'=>$global]),InvalidArgumentException::class,'hidden mandatory field without a default is refused at configuration boundary');
$dependent=CustomFields::definition(['key'=>'context_dependent','label'=>'Dependent','type'=>'text','show_when'=>['match'=>'all','conditions'=>[['field'=>'context_name','operator'=>'filled']]]]);
rejects(fn()=>TemplateContexts::resolve(['field_contexts'=>['context_name'=>['display'=>'hidden']]],['context_name'=>$global,'context_dependent'=>$dependent]),InvalidArgumentException::class,'hidden source cannot leak into visible field-condition state');
rejects(fn()=>TemplatePolicy::normalize(['publish'=>'read']),InvalidArgumentException::class,'template policy cannot weaken mandatory publication authority');
rejects(fn()=>TemplatePolicy::normalize(['handler'=>'upload.php']),InvalidArgumentException::class,'template policy is closed declarative configuration');

$post=['template_settings'=>'1','template_contexts'=>['context_name'=>['enabled'=>'1','label_mode'=>'custom','label'=>'Local form label','required'=>'0','min_mode'=>'none','max_mode'=>'custom','max'=>'12','display'=>'readonly']]];
$compiled=TemplateSettings::submitted($post,['context_name']);check($compiled['field_contexts']['context_name']===['display'=>'readonly','label'=>'Local form label','max'=>'12','min'=>null,'required'=>false],'normal labelled form compiles explicit context overrides without end-user JSON');
$html=TemplateSettings::render($strict,[$global],$model->templates());check(str_contains($html,'template_contexts[context_name][display]')&&str_contains($html,'policy_enabled')&&!str_contains($html,'textarea name="policy_json"'),'template settings render ordinary compact controls');
check(TemplateSettings::submitted([],['context_name'])===[],'older template forms preserve their existing submission contract');
check(TemplateSettings::submitted($post,[])['field_contexts']===[],'unselecting a template field also removes its obsolete local context');
echo json_encode(['checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
