<?php declare(strict_types=1);
/** Actual successful controls, including controls associated with the complete main form. */
function ownCompleteBalanceFormPost(string $html,string $action):array
{
 $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
 try{
  $dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);$xp=new DOMXPath($dom);$forms=$xp->query('//form[@id="balance-local-main"]');
  if($forms->length!==1)throw new RuntimeException('Exactly one actual complete balance form required');
  $pairs=[];
  foreach($xp->query('//input[@name]|//select[@name]|//textarea[@name]')as $node){
   $inside=$xp->query('ancestor::form[@id="balance-local-main"]',$node)->length>0;
   if(!$inside&&$node->getAttribute('form')!=='balance-local-main')continue;
   if($node->hasAttribute('disabled')||$xp->query('ancestor::fieldset[@disabled]',$node)->length)continue;
   $type=$node->getAttribute('type');if(in_array($type,['file','button','submit'],true))continue;
   if(in_array($type,['checkbox','radio'],true)&&!$node->hasAttribute('checked'))continue;
   $name=$node->getAttribute('name');$value=$node->tagName==='textarea'?$node->textContent:$node->getAttribute('value');
   if($node->tagName==='select'){
    $selected=$xp->query('.//option[@selected]',$node);if($selected->length===0)$selected=$xp->query('.//option[1]',$node);$value=$selected->item(0)?->getAttribute('value')??'';
   }
   $pairs[]=urlencode($name).'='.urlencode($value);
  }
  $pairs[]='action='.urlencode($action);parse_str(implode('&',$pairs),$post);return $post;
 }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
}
