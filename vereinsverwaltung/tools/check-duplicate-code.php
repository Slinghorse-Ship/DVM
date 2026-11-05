<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only.');
$root=dirname(__DIR__,2);$groups=[];$files=0;$fileGroups=[];
foreach(['vereinsverwaltung/src','vereinsverwaltung/native','processwire/site/src','banking'] as $area){
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$area,FilesystemIterator::SKIP_DOTS)) as $file){
  $relative=substr($file->getPathname(),strlen($root)+1);if($file->getExtension()!=='php'||preg_match('~/(vendor|runtime|tests)/~',$relative))continue;$files++;
  $bytes=file_get_contents($file->getPathname());if(strlen($bytes)>200)$fileGroups[hash('sha256',$bytes)][]=$relative;
  $tokens=token_get_all($bytes);$length=count($tokens);
  for($i=0;$i<$length;$i++){
   if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;$line=$tokens[$i][2];$name=null;$j=$i+1;
   for(;$j<$length;$j++){if(is_array($tokens[$j])&&$tokens[$j][0]===T_STRING){$name=$tokens[$j][1];break;}if($tokens[$j]==='(')break;}
   if($name===null)continue;
   for(;$j<$length&&$tokens[$j]!=='{'&&$tokens[$j]!==';';$j++);if($j===$length||$tokens[$j]!== '{')continue;
   $depth=1;$body='';for($j++;$j<$length&&$depth>0;$j++){$t=$tokens[$j];if($t==='{')$depth++;if($t==='}')$depth--;if($depth===0)break;if(is_array($t)){if(in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))$depth++;if(in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;$body.=$t[1];}else $body.=$t;}
   if(strlen($body)>=140)$groups[hash('sha256',$body)][]=['path'=>$relative,'line'=>$line,'method'=>$name,'bytes'=>strlen($body)];
  }
 }
}
$dupes=array_values(array_filter($groups,fn($g)=>count($g)>1));usort($dupes,fn($a,$b)=>($b[0]['bytes']*(count($b)-1))<=>($a[0]['bytes']*(count($a)-1)));
echo json_encode(['files'=>$files,'exact_file_groups'=>array_values(array_filter($fileGroups,fn($g)=>count($g)>1)),'exact_method_groups'=>count($dupes),'methods'=>$dupes],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
