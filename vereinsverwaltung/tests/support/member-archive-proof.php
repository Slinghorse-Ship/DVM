<?php declare(strict_types=1);
/** Fixture-only deliberate second archive cycle; never included by production. */
require_once dirname(__DIR__,2).'/src/autoload.php';
function ownMemberArchiveProof(array $fixture):void
{
    $cfg=\Kulturhus\Native\TestSupport::config();$db=\Kulturhus\Native\TestSupport::db();
    if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-member-archive-interface'||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$cfg['database']??'')||$db->query('SELECT DATABASE()')->fetchColumn()!==$cfg['database'])throw new RuntimeException('Owned archive proof required.');
    $id=$_POST['person']??null;$case=null;foreach($fixture['data']['cases']as$row)if($row['person']===$id)$case=$row;
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||$case===null){http_response_code(422);exit;}
    $archive=new \Kulturhus\Verein\ContactArchive($db,'own-list-admin',\Kulturhus\Verein\ContactArchive::serverKey());
    $registry=new \Kulturhus\Verein\Registry($db,'own-list-admin');$original=$archive->archived($id);
    if($original['version']!==2||$original['fingerprint']!==$case['fingerprint'])throw new RuntimeException('Exactly one deliberate owned archive cycle required.');
    $archive->restore($id,['version'=>2,'fingerprint'=>$case['fingerprint'],'name'=>$case['name']]);
    $profile=$registry->contact($id);$details=array_intersect_key($profile,array_flip(['phone','birthday','notes','honorary','legacy_member_number']));$details['notes']=$case['current_note'];
    $registry->saveContact($id,3,$profile['kind'],$case['current_name'],$profile['email'],$profile['address'],$details);
    $archive->archive($id,4,$case['current_name'],'Own deliberately reviewed new archive cycle','');$current=$archive->archived($id);
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
    echo json_encode(['person'=>$id,'version'=>$current['version'],'fingerprint'=>$current['fingerprint'],'name'=>$current['name']],JSON_THROW_ON_ERROR);
}
