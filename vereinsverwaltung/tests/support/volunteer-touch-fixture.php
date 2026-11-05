<?php declare(strict_types=1);
ob_start();require __DIR__.'/volunteer-general-fixture.php';ob_end_clean();
// The real select is enhanced only at ten options; a two-member fixture misses it.
for($i=1;$i<=29;$i++){
 $touchName='Synthetic Touch Member '.sprintf('%02d',$i);
 $touchMember=$registry->createMemberContact('person',$touchName,'touch'.$i.'@example.invalid',"Testweg 4\n12345 Musterort",$details,['number'=>'SYN-TOUCH-'.$i,'status'=>'aktiv','start'=>'2020-01-01','family'=>'new','family_name'=>'Synthetic touch family '.$i],false);
}
$fixture=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
$fixture['touch']=['name'=>$touchName,'id'=>$touchMember];
file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo $file,"\n";
