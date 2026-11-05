<?php declare(strict_types=1);
/** Copied to an owned private CI registry; never registered by production code. */
final class OwnInterfaceExtension implements \Kulturhus\Native\Extensions\Plugin
{
 public function __construct(private string $id){}
 public function definition():\Kulturhus\Native\Extensions\Definition{return new \Kulturhus\Native\Extensions\Definition($this->id,'Owned original '.$this->id,'1.0.0',[],[
  'text'=>['type'=>'text','label'=>'Owned original text','max'=>200,'default'=>'Owned default text'],
  'count'=>['type'=>'integer','label'=>'Owned original count','min'=>0,'max'=>200,'default'=>5],
  'option'=>['type'=>'boolean','label'=>'Owned original switch','default'=>false],
  'style'=>['type'=>'select','label'=>'Owned original style','options'=>['alpha'=>'Owned alpha','beta'=>'Owned beta'],'default'=>'alpha'],
 ]);}
 public function install(\Kulturhus\Native\Extensions\Context $context):void{$context->put('retained-value','Owned original KV '.$this->id);}
 public function upgrade(\Kulturhus\Native\Extensions\Context $context,string $previousVersion):void{}
 public function uninstall(\Kulturhus\Native\Extensions\Context $context):void{$context->delete('retained-value');}
 public function initialize(\Kulturhus\Native\Extensions\HookScope $hooks,\Kulturhus\Native\Extensions\UrlScope $urls,\Kulturhus\Native\Extensions\Context $context):void{}
}
