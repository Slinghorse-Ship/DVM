<?php declare(strict_types=1);
if($view==='handbook')return;
$helpFallback=['dashboard'=>1,'members'=>2,'contacts-extra'=>2,'members-extra'=>2,'finance'=>7,'donations'=>10,'documents'=>11,'calendar'=>13,'minutes'=>13,'gema'=>14,'inventory'=>15,'material'=>15,'messages'=>16,'chat'=>16,'website'=>17,'settings'=>19,'free'=>20,'portal'=>2];
$helpChapter=$helpFallback[$view]??20;$helpScore=0;$helpQuery=array_replace($_GET,['view'=>$view]);
$helpRoutes=json_decode((string)file_get_contents(__DIR__.'/../../resources/handbook-routes.json'),true,512,JSON_THROW_ON_ERROR);
foreach($helpRoutes as $helpRoute){
 $matches=true;foreach($helpRoute['route'] as $key=>$value){if(!isset($helpQuery[$key])||!is_scalar($helpQuery[$key])||(string)$helpQuery[$key] !== (string)$value){$matches=false;break;}}
 if($matches&&count($helpRoute['route'])>$helpScore){$helpScore=count($helpRoute['route']);$helpChapter=(int)$helpRoute['chapter'];}
}
?>
<p class="context-help"><a href="?view=handbook#kapitel-<?= $helpChapter ?>" target="_blank" rel="noopener">Schrittanleitung zu dieser Seite ↗<span class="sr-only"> (neuer Tab)</span></a></p>
