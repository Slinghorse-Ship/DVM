<?php declare(strict_types=1);
/** Owned local forms only; no provider connection or publishing endpoint is exposed. */
function ownSocialPhotoBrowser(PDO $db):array
{
    $cfg=Kulturhus\Native\TestSupport::config();
    if(($cfg['label']??'')!=='native-social-photo-upload'||($cfg['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Own social browser context required.');
    $root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];mkdir($root,0700);
    foreach(['web','media','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    foreach(['auth','private']as$name){file_put_contents($root.'/'.$name.'.key',random_bytes(32));chmod($root.'/'.$name.'.key',0600);}
    putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
    $nativeClub=(new Kulturhus\Native\Shared\Access($db,'admin'))->club();
    Kulturhus\Native\Shared\Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',['own-studio-native-en',$nativeClub,'Own native English editor']);
    $db->exec("INSERT INTO native_grants(actor_id,module,capability) SELECT 'own-studio-native-en',module,capability FROM native_grants WHERE actor_id='admin'");
    $actors=['de'=>'own-studio-browser-de','en'=>'own-studio-browser-en'];$tokens=[];$managerIds=[];
    Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs(id,name) VALUES('own-studio-browser','Own image studio browser')");
    $authority=null;
    foreach($actors as$locale=>$actor){
        Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,'own-studio-browser','member',1)",[$actor]);
        $bridge=new Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null);$access=$bridge->access();$authority??=$access;
        $settings=new Kulturhus\Verein\Settings($db,$actor);if($locale==='de')$settings->save('club',array_replace(Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Own image studio browser','street'=>'Own fixture street','postcode'=>'00000','city'=>'Own fixture city']),0);
        $contact=(new Kulturhus\Verein\Registry($db,$actor))->saveContact(null,0,'person','Own original editor '.$locale,'own-studio-'.$locale.'@example.invalid','Own original fixture address');
        Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$actor]);
        $access=$bridge->access();(new Kulturhus\Native\Language\Catalog($db,$access))->select($locale);$managerIds[$locale]=$access->user()['id'];
        $auth=Kulturhus\Native\Auth\Runtime::service($db);$email='own-studio-save-'.$locale.'@example.invalid';$invitation=$auth->createInvitation($authority,$bridge->nativeActorId(),$email);$auth->acceptInvitation($invitation['token'],'Own image studio browser password 2026!');$tokens[$locale]=$auth->passwordLogin($email,'Own image studio browser password 2026!','own-studio-save-'.$locale)['session_token'];
    }
    $nativeActors=['de'=>'admin','en'=>'own-studio-native-en'];
    foreach($nativeActors as$locale=>$actor)(new Kulturhus\Native\Language\Catalog($db,new Kulturhus\Native\Shared\Access($db,$actor)))->select($locale);
    Kulturhus\Verein\Database::ensureContributionRates($db);
    $pattern=imagecreatetruecolor(64,64);imagefill($pattern,0,0,imagecolorallocate($pattern,24,112,92));imagefilledrectangle($pattern,8,8,38,38,imagecolorallocate($pattern,220,80,35));imagepng($pattern,$root.'/source.png');imagedestroy($pattern);
    $byClub=[];$items=[];$initial=[];
    foreach(['native'=>$nativeActors,'integrated'=>$managerIds]as$mode=>$editors)foreach($editors as$locale=>$actor){
        $access=new Kulturhus\Native\Shared\Access($db,$actor);$club=$access->club();
        if(!isset($byClub[$club])){
            $media=new Kulturhus\Native\Cms\Media($db,$access,$root.'/media');$photo=$media->upload($root.'/source.png','Own source.png','Ebenen');foreach(['thumb','medium','large']as$size)$media->variant($photo['id'],$size);
            (new Kulturhus\Native\Cms\MediaMetadata($db,$access,Kulturhus\Native\Shared\SecretBox::fromFile($root.'/private.key')))->save($photo['id'],['alt'=>'Ebenen','credit'=>'0 Own original photographer','source_name'=>'Own original archive','license_name'=>'Own original permission','rights_state'=>'cleared','consent_state'=>'not_required','channels'=>['website','social','newsletter','press']],0,'Own private proof never exported');
            $profile=Kulturhus\Native\Integration\ManagerClub::profile($db,$club);$identityData=['name'=>'0 Own original studio club','origin'=>'https://example.invalid','logo_id'=>$photo['id'],'address'=>'Own original club address'];if($profile!==null)$identityData['club_version']=$profile['version'];$oldIdentity=Kulturhus\Native\Shared\Identity::forClub($db,$club);$identity=(new Kulturhus\Native\Shared\Identity($db,$access))->save($identityData,$oldIdentity['version']);
            $byClub[$club]=['photo'=>$photo['id'],'identity'=>$identity];
        }
        foreach(['post'=>1350,'story'=>1920]as$kind=>$height){
            $work=new Kulturhus\Native\Communication\Workspace($db,$access,'social',$root.'/media');
            $item=$work->save(['title'=>'0 Original Bildstudio <literal>','summary'=>'0 Original Ebenen','body'=>'0 Own original body','kind'=>$kind,'story'=>$kind==='story'?[['title'=>'0 Original Folie','text'=>'0 Original Folientext','media_ref'=>'']]:[]]);
            $doc=['schema'=>1,'width'=>1080,'height'=>$height,'background'=>'#163f33','layers'=>[
                ['id'=>'own-photo','type'=>'image','media_ref'=>$byClub[$club]['photo'],'x'=>80,'y'=>400,'width'=>900,'height'=>600,'rotation'=>0,'opacity'=>1],
                ['id'=>'own-text','type'=>'text','text'=>'Bildstudio','font'=>'Georgia','font_size'=>60,'color'=>'#ffffff','x'=>80,'y'=>80,'width'=>900,'height'=>180,'rotation'=>0,'opacity'=>1],
            ]];$doc=Kulturhus\Native\Communication\CanvasDocument::validate($doc,$kind);
            $raster=imagecreatetruecolor(1080,$height);imagefill($raster,0,0,imagecolorallocate($raster,22,63,51));imagefilledrectangle($raster,200,200,600,600,imagecolorallocate($raster,220,80,35));imagepng($raster,$root.'/canvas.png');imagedestroy($raster);
            $image=(new Kulturhus\Native\Communication\Images($db,$access,$root.'/media'))->saveCanvas($item['id'],1,$byClub[$club]['identity']['version'],0,$doc,$root.'/canvas.png');
            $items[$mode][$locale][$kind]=$item['id'];$initial[$item['id']]=$doc;
        }
    }
    $language=new Kulturhus\Native\Language\Settings($db,$authority);$configuration=['source'=>'de','languages'=>[['code'=>'de','label'=>'Deutsch','enabled'=>true,'fallback'=>null,'messages'=>[]],['code'=>'en','label'=>'English','enabled'=>true,'fallback'=>'de','messages'=>[Kulturhus\Native\Language\ImageStudioMessages::key('Bildstudio')=>'<Own Image Studio>']]]];$language->save($configuration,0);
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $data=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'native_actors'=>$nativeActors,'manager_actors'=>$actors,'manager_ids'=>$managerIds,'tokens'=>$tokens,'items'=>$items,'initial_documents'=>$initial];
    $file=$root.'/fixture.json';file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_SOCIAL_PHOTO_FIXTURE='.$file);
    $log=fopen($root.'/http.log','ab');$process=proc_open([...Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/social-photo-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own social server unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return ['process'=>$process,'log'=>$log,'root'=>$root,'input'=>$data];
}
