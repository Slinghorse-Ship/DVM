<?php declare(strict_types=1);
if(getenv('VEREIN_MODE')!=='synthetic')throw new RuntimeException('Synthetic environment required');
$root='/var/www/html';require $root.'/vendor/autoload.php';require $root.'/wire/core/ProcessWire.php';
$wire=new \ProcessWire\ProcessWire(\ProcessWire\ProcessWire::buildConfig($root));
$user=$wire->users->get('verein-local-manager');if(!$user->id)throw new RuntimeException('Local manager missing');$wire->users->setCurrentUser($user);
$db=$wire->database;$before=$db->query("SELECT data FROM modules WHERE class='ProcessKulturhusDashboard'")->fetchColumn();
$service=new \Kulturhus\EditorialIntegration($wire);$read=$service->read('navigation');
$data=$read['menu'];$data['program_auto']='0';$data['items']['program']['label']='Programm 2027';
$db->beginTransaction();
try{
    $service->write('navigation/save',$data,$read['version']);
    $after=$service->read('navigation');
    if($after['menu']['items']['program']['label']!=='Programm 2027')throw new RuntimeException('Save/read failed');
    if((new \Kulturhus\SiteNavigation($wire))->publicLinks(2026)[0]['label']!=='Programm 2027')throw new RuntimeException('Public renderer failed');
    $original=json_decode($before,true);$current=json_decode($db->query("SELECT data FROM modules WHERE class='ProcessKulturhusDashboard'")->fetchColumn(),true);unset($original['public_navigation_v1'],$current['public_navigation_v1']);
    if($original!==$current)throw new RuntimeException('Unrelated settings changed');
    $conflict=false;try{$service->write('navigation/save',$data,$read['version']);}catch(DomainException){$conflict=true;}
    if(!$conflict)throw new RuntimeException('Stale version accepted');
    $wire->users->setCurrentUser($wire->users->get('guest'));$denied=false;
    try{(new \Kulturhus\SiteNavigation($wire))->save($data,$after['version']);}catch(\ProcessWire\WirePermissionException){$denied=true;}
    if(!$denied)throw new RuntimeException('Guest write accepted');
    echo "PASS save/read, public renderer, unrelated settings, stale version, guest denial\n";
}finally{$db->rollBack();}
if($before!==$db->query("SELECT data FROM modules WHERE class='ProcessKulturhusDashboard'")->fetchColumn())throw new RuntimeException('Rollback failed');
echo "PASS original module configuration preserved byte for byte\n";
