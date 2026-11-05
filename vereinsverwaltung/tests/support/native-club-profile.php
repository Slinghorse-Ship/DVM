<?php declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/autoload.php';

/** Only callers that already verified their own synthetic fixture may use this helper. */
function prepareSyntheticClubProfile(PDO $db,\Kulturhus\Native\Integration\ManagerAccess $access): int
{
    $settings=new \Kulturhus\Verein\Settings($db,$access->managerActor());
    $saved=$settings->section('club');
    if($saved['version']===0){
        $settings->save('club',array_replace(\Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Synthetischer Testverein','street'=>'Testweg 1','postcode'=>'','city'=>'']),0);
        return 1;
    }
    return $saved['version'];
}
