<?php declare(strict_types=1);
$cases=json_decode((string)file_get_contents(__DIR__.'/../resources/handbook-routes.json'),true,512,JSON_THROW_ON_ERROR);
$cases[]=['route'=>['view'=>'members','id'=>'example'],'chapter'=>2];
$cases[]=['route'=>['view'=>'finance','tab'=>'bank','account'=>'example'],'chapter'=>8];
foreach($cases as $case){
 $_GET=$case['route'];$view=$_GET['view'];ob_start();require __DIR__.'/../views/Help/handbook-context.php';$html=ob_get_clean();
 if(!str_contains($html,'#kapitel-'.$case['chapter'].'"'))throw new RuntimeException('Incorrect help target: '.json_encode($case));
}
echo 'PASS '.count($cases)." contextual help routes\n";
