<?php declare(strict_types=1);
/** Actual v1 schema requests: private labels, nested schema and condition literals stay private. */
function ownApiModelPolicyProof(\Kulturhus\Native\Api\Engine $admin,\Kulturhus\Native\Api\Engine $reader,Closure $check):void
{
    $key=static fn():string=>bin2hex(random_bytes(16));
    $create=static fn(string $resource,array $data):array=>$admin->dispatch('POST','/api/v1/'.$resource,[],['version'=>0,'data'=>$data],$key());
    $create('fields',['key'=>'api_model_private','label'=>'Own secret schema label','type'=>'text','access'=>['read'=>'manage','edit'=>'manage']]);
    $create('fields',['key'=>'api_model_public','label'=>'Own public schema label','type'=>'text']);
    $create('fields',['key'=>'api_model_condition','label'=>'Own condition field','type'=>'text','show_when'=>['match'=>'all','conditions'=>[['field'=>'api_model_private','operator'=>'eq','value'=>'Own secret comparison literal']]]]);
    $create('fields',['key'=>'api_model_rows','label'=>'Own schema collection','type'=>'repeater','fields'=>['api_model_private','api_model_public']]);
    $create('templates',['key'=>'api_model_template','label'=>'Own schema template','renderer'=>'standard','fields'=>['api_model_private','api_model_public','api_model_condition','api_model_rows'],'field_contexts'=>['api_model_private'=>['label'=>'Own secret context label']],'groups'=>[['key'=>'private_group','label'=>'Own secret group label','fields'=>['api_model_private']]]]);
    try{$reader->dispatch('GET','/api/v1/fields/api_model_private');throw new RuntimeException('Protected schema was readable');}catch(\Kulturhus\Native\Api\Problem $error){$check($error->status===404,'individual private schema is unavailable even when its original key is known');}
    $visible=$reader->dispatch('GET','/api/v1/fields',['limit'=>'50']);$all=$admin->dispatch('GET','/api/v1/fields',['limit'=>'50']);
    $check($all['meta']['total']===$visible['meta']['total']+1&&!str_contains(json_encode($visible),'Own secret schema label'),'schema count and page selection exclude private roots before pagination');
    $seen=[];for($page=1;$page<=$visible['meta']['total'];$page++){$one=$reader->dispatch('GET','/api/v1/fields',['limit'=>'1','page'=>(string)$page]);$check(count($one['data'])===1&&$one['meta']['total']===$visible['meta']['total'],'every bounded schema window is counted after current read permission');$seen[]=$one['data'][0]['key'];}
    $check(count(array_unique($seen))===$visible['meta']['total']&&!in_array('api_model_private',$seen,true),'every permitted schema original remains reachable once without a private-key slot');
    $rows=$reader->dispatch('GET','/api/v1/fields/api_model_rows')['data'];
    $check($rows['fields']===['api_model_public']&&array_keys($rows['item_definitions'])===['api_model_public'],'resolved repeater schema removes private original child labels and keys');
    $condition=$reader->dispatch('GET','/api/v1/fields/api_model_condition')['data'];
    $check(!isset($condition['show_when'])&&!str_contains(json_encode($visible),'Own secret comparison literal'),'readable field metadata never exposes a comparison literal against a private source');
    $template=$reader->dispatch('GET','/api/v1/templates/api_model_template')['data'];
    $check(!in_array('api_model_private',$template['fields'],true)&&!isset($template['field_contexts']['api_model_private'])&&!str_contains(json_encode($template),'Own secret'),'template metadata removes private field contexts and now-empty private groups');
    $password=$reader->dispatch('GET','/api/v1/fields/api_password')['data'];
    $check($password['type']==='password'&&!isset($password['default']),'permitted password schema remains discoverable without any password value or default');
    $body=['version'=>0,'data'=>['key'=>'api_model_replayed','label'=>'Own formerly readable schema label','type'=>'text']];$request=$key();$admin->dispatch('POST','/api/v1/fields',[],$body,$request);
    $admin->dispatch('PUT','/api/v1/fields/api_model_replayed',[],['version'=>1,'data'=>['access'=>['read'=>'manage','edit'=>'manage']]],$key());
    $check($admin->dispatch('POST','/api/v1/fields',[],$body,$request)['replayed']===true,'schema mutation replay keeps its exact committed operation identity and current field projection');
}
