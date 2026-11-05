<?php declare(strict_types=1);
use Kulturhus\Native\Api\{Context,Engine};
use Kulturhus\Native\Cms\{DataModel,Pages};
use Kulturhus\Native\CmsTransfer\{Content,Exporter,Package};
use Kulturhus\Native\Shared\Db;

function ownCompositionTransferBaseline(PDO $db,Context $context,string $root):array
{
    $target=$root.'/api-native-model-before.zip';$export=(new Exporter($db,$context->access,$root.'/media',$root))->export([],$target,false);$packet=Package::read($target,$root);
    try{compositionCheck($export['templates']>=4&&$export['pages']===0&&$packet['content']['pages']===[],'ordinary native model export stays a valid data-only package before any local provider binding');return ['target'=>$target,'sha256'=>hash_file('sha256',$target),'content'=>$packet['content']];}finally{Package::cleanup($packet);}
}
function ownCompositionBindingRefusal(callable $run,string $label):void
{
    try{$run();}catch(InvalidArgumentException $error){compositionCheck($error->getMessage()==='Lokale Typ- oder Rendererbindungen können nicht als natives CMS-Paket übertragen werden.',$label);return;}throw new RuntimeException('FAIL '.$label);
}
function ownCompositionTransferBound(PDO $db,Context $context,string $root,Engine $engine,callable $key,array $field,array $template,array $page,array $baseline):void
{
    $control=$engine->dispatch('POST','/api/v1/fields',[],['version'=>0,'data'=>['key'=>'api_control_only','label'=>'Own portable input control boundary','type'=>'text','inputfield'=>$field['inputfield']]],$key())['data'];
    $rows=$engine->dispatch('POST','/api/v1/fields',[],['version'=>0,'data'=>['key'=>'api_provider_rows','label'=>'Own nested provider rows','type'=>'repeater','fields'=>['api_provider_text']]],$key())['data'];
    $engine->dispatch('POST','/api/v1/templates',[],['version'=>0,'data'=>['key'=>'api_nested_template','label'=>'Own nested native template','renderer'=>'standard','fields'=>['api_provider_rows']]],$key());
    $nested=$engine->dispatch('POST','/api/v1/pages',[],['version'=>0,'data'=>['title'=>'Own nested provider original','slug'=>'own-api-nested-provider','template'=>'api_nested_template','custom_fields'=>['api_provider_rows'=>[['values'=>['api_provider_text'=>'Own nested approved value']]]]]],$key())['data'];
    $pages=new Pages($db,$context->access);$captured=$pages->get($page['id']);$nested=$pages->get($nested['id']);$normalizations=$GLOBALS['own_api_normalizations']??0;compositionCheck($normalizations>0,'the actually registered provider executed before immutable export-refusal counter checks');
    ownCompositionBindingRefusal(fn()=>Content::field($field),'portable field rejects an actual registered fieldtype before dropping its native identity');
    ownCompositionBindingRefusal(fn()=>Content::field($control),'portable field rejects an actual registered inputfield even without a fieldtype');
    ownCompositionBindingRefusal(fn()=>Content::field($rows),'portable repeater definition checks actual resolved child provider identities recursively');
    ownCompositionBindingRefusal(fn()=>Content::template($template),'portable template rejects an actual registered frontend renderer before selecting its portable properties');
    ownCompositionBindingRefusal(fn()=>Content::page($captured),'captured original page cannot silently discard its local renderer and type contracts');
    ownCompositionBindingRefusal(fn()=>Content::page($nested),'saved native repeater page rejects captured nested local definitions before touching its values');
    $exporter=new Exporter($db,$context->access,$root.'/media',$root);$target=$root.'/api-bound-field-refused.zip';$before=glob($root.'/*.zip');
    ownCompositionBindingRefusal(fn()=>$exporter->export([$captured['id']=>$captured['version']],$target,false),'actual complete package export refuses local field bindings before reserving any target');
    ownCompositionBindingRefusal(fn()=>$exporter->export([],$baseline['target'],false),'actual refused export leaves an already occupied original native package untouched');
    $rendererTarget=$root.'/api-bound-renderer-refused.zip';$db->beginTransaction();try{
        $model=new DataModel($db,$context->access);$model->saveField(array_replace($model->field('api_provider_text'),['fieldtype'=>null,'inputfield'=>null]),1);$model->saveField(array_replace($model->field('api_control_only'),['inputfield'=>null]),1);
        ownCompositionBindingRefusal(fn()=>$exporter->export([],$rendererTarget,false),'actual model-only package export also refuses the registered renderer when every current field is native');
    }finally{$db->rollBack();}
    compositionCheck(!file_exists($target)&&!file_exists($rendererTarget)&&glob($root.'/*.zip')===$before&&hash_file('sha256',$baseline['target'])===$baseline['sha256'],'rejected local contracts create no partial package and preserve original target bytes');
    compositionCheck(($GLOBALS['own_api_normalizations']??0)===$normalizations&&$pages->get($captured['id'])===$captured&&$pages->get($nested['id'])===$nested,'export refusal preserves exact original pages and executes no provider normalization callback');
    $untrusted=$baseline['content'];$untrusted['fields']=[array_intersect_key($field,array_flip(Content::FIELD_KEYS))+['fieldtype'=>$field['fieldtype']]];compositionReject(fn()=>Content::validate($untrusted),'closed imported content continues to reject local binding properties instead of importing host code identities');
}
function ownCompositionTransferNativeAgain(PDO $db,Context $context,string $root,Engine $engine,callable $key,array $baseline):void
{
    $engine->dispatch('PUT','/api/v1/fields/api_control_only',[],['version'=>1,'data'=>['inputfield'=>null]],$key());$target=$root.'/api-native-model-after.zip';$count=$GLOBALS['own_api_normalizations']??0;$export=(new Exporter($db,$context->access,$root.'/media',$root))->export([],$target,false);$packet=Package::read($target,$root);
    try{
        compositionCheck($export['pages']===0&&$packet['content']['pages']===[]&&count($packet['content']['fields'])>=3&&($GLOBALS['own_api_normalizations']??0)===$count,'deliberately native current model still exports normally without reevaluating historical original provider values');
        compositionCheck(hash_file('sha256',$baseline['target'])===$baseline['sha256'],'later native export also preserves the first original native package byte for byte');
    }finally{Package::cleanup($packet);}
}
