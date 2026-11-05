<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Lossless display of submitted data; authentication material is never reflected. */
final class OriginalInput
{
 public static function json(array $query, #[\SensitiveParameter] array $post):string
 {
  $encode=static function(mixed $value)use(&$encode):array{
   if(is_string($value))return mb_check_encoding($value,'UTF-8')?['type'=>'text','value'=>$value]:['type'=>'bytes','base64'=>base64_encode($value)];
   if(is_array($value)){
    $entries=[];
    foreach($value as$key=>$item){
     if(is_string($key)&&$key!=='clear_password'&&preg_match('/csrf|password|secret|token|factor_code|recovery_code/i',$key))continue;
     $entries[]=['key'=>$encode($key),'value'=>$encode($item)];
    }
    return['type'=>'array','entries'=>$entries];
   }
   return['type'=>get_debug_type($value),'value'=>is_scalar($value)||$value===null?$value:null];
  };
  return json_encode(['query'=>$encode($query),'form'=>$encode($post)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }
}
