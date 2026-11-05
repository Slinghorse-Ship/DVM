<?php declare(strict_types=1);
require __DIR__.'/member-profile-environment.php';
$fixture=json_decode((string)file_get_contents($argv[1]??''),true,32,JSON_THROW_ON_ERROR);
if(!str_starts_with($fixture['club']??'','member-profile-'))throw new RuntimeException('Synthetic fixture required.');
$db=\Kulturhus\Verein\Tests\MySqlFixture::existing($memberTestDatabase);$db->exec('START TRANSACTION READ ONLY');
$out=[];foreach(['admin'=>$fixture['actor'],'own'=>$fixture['club'].'-reader'] as $role=>$actor)$out[$role]=(new \Kulturhus\Verein\Settings($db,$actor))->section('account');
echo json_encode($out,JSON_THROW_ON_ERROR);
