<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();\Kulturhus\Verein\Database::migrate($db);
$result=['files'=>0,'prepared'=>0,'dynamic_fragments_skipped'=>0,'errors'=>[]];
foreach(['src','views','public'] as $dir)foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $file){
 if($file->getExtension()!=='php')continue;++$result['files'];$tokens=token_get_all(file_get_contents($file->getPathname()));
 foreach($tokens as $i=>$token){if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING)continue;
  $sql=substr($token[1],1,-1);$sql=$token[1][0]==="'"?str_replace(["\\'","\\\\"],["'","\\"],$sql):stripcslashes($sql);
  if(!preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\s/i',$sql))continue;
  $before=$i-1;$after=$i+1;foreach([&$before,&$after] as $side=>$index){unset($index);$step=$side===0?-1:1;if($side===0){while(isset($tokens[$before])&&is_array($tokens[$before])&&in_array($tokens[$before][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$before+=$step;}else{while(isset($tokens[$after])&&is_array($tokens[$after])&&in_array($tokens[$after][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$after+=$step;}}
  if(($tokens[$before]??null)==='.'||($tokens[$after]??null)==='.'){$result['dynamic_fragments_skipped']++;continue;}
  // Native server PREPARE checks grammar/tables/columns; never execute these statements.
  try{$q=$db->prepare($sql);$q=null;++$result['prepared'];}catch(Throwable $e){$result['errors'][]=['file'=>basename($file->getPathname()),'line'=>$token[2],'error'=>$e->getMessage(),'sql'=>$sql];}
 }
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
if($result['errors'])throw new RuntimeException('Native SQL preparation errors');
