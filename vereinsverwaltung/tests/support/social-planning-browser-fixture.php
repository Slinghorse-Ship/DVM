<?php declare(strict_types=1);
/** Owned local forms only; no provider connection or publishing endpoint is exposed. */
function ownSocialPlanningFixture(PDO $db):array
{
    $cfg=Kulturhus\Native\TestSupport::config();
    if(!in_array($cfg['label']??'',['native-social-planning-interface','native-event-preparations'],true)||($cfg['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Own planning context required.');
    $root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];mkdir($root,0700);
    foreach(['web','media','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    foreach(['auth','private']as$name){file_put_contents($root.'/'.$name.'.key',random_bytes(32));chmod($root.'/'.$name.'.key',0600);}
    putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
    $nativeClub=(new Kulturhus\Native\Shared\Access($db,'admin'))->club();
    Kulturhus\Native\Shared\Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',['own-planning-native-en',$nativeClub,'Own native English editor']);
    $db->exec("INSERT INTO native_grants(actor_id,module,capability) SELECT 'own-planning-native-en',module,capability FROM native_grants WHERE actor_id='admin'");
    $actors=['de'=>'own-planning-browser-de','en'=>'own-planning-browser-en'];$tokens=[];$managerIds=[];
    Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs(id,name) VALUES('own-planning-browser','Own planning browser')");
    $authority=null;
    foreach($actors as$locale=>$actor){
        Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,'own-planning-browser','member',1)",[$actor]);
        $bridge=new Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null);$access=$bridge->access();$authority??=$access;
        $settings=new Kulturhus\Verein\Settings($db,$actor);if($locale==='de')$settings->save('club',array_replace(Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Own planning browser','street'=>'Own fixture street','postcode'=>'00000','city'=>'Own fixture city']),0);
        $contact=(new Kulturhus\Verein\Registry($db,$actor))->saveContact(null,0,'person','Own original editor '.$locale,'own-planning-'.$locale.'@example.invalid','Own original fixture address');
        Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$actor]);
        $access=$bridge->access();(new Kulturhus\Native\Language\Catalog($db,$access))->select($locale);$managerIds[$locale]=$access->user()['id'];
        $auth=Kulturhus\Native\Auth\Runtime::service($db);$email='own-planning-save-'.$locale.'@example.invalid';$invitation=$auth->createInvitation($authority,$bridge->nativeActorId(),$email);$auth->acceptInvitation($invitation['token'],'Own planning browser password 2026!');$tokens[$locale]=$auth->passwordLogin($email,'Own planning browser password 2026!','own-planning-save-'.$locale)['session_token'];
    }
    $nativeActors=['de'=>'admin','en'=>'own-planning-native-en'];
    foreach($nativeActors as$locale=>$actor)(new Kulturhus\Native\Language\Catalog($db,new Kulturhus\Native\Shared\Access($db,$actor)))->select($locale);
    Kulturhus\Verein\Database::ensureContributionRates($db);
    $pattern=imagecreatetruecolor(64,64);imagefill($pattern,0,0,imagecolorallocate($pattern,24,112,92));imagepng($pattern,$root.'/source.png');imagedestroy($pattern);
    $box=Kulturhus\Native\Shared\SecretBox::fromFile($root.'/private.key');$outbox=new Kulturhus\Native\Delivery\Outbox($db,$box);$items=[];$plans=[];$clubs=[];$history=[];
    foreach(['native'=>$nativeActors,'integrated'=>$managerIds]as$mode=>$editors)foreach($editors as$locale=>$actor){
        $access=new Kulturhus\Native\Shared\Access($db,$actor);$club=$access->club();
        if(!isset($clubs[$club])){
            $media=new Kulturhus\Native\Cms\Media($db,$access,$root.'/media');$photo=$media->upload($root.'/source.png','Own original planning photo.png','Own original planning photo');
            foreach(['thumb','medium','large']as$size)$media->variant($photo['id'],$size);
            $profile=Kulturhus\Native\Integration\ManagerClub::profile($db,$club);$identity=['name'=>'Own original planning club','origin'=>'https://planning.example.invalid'];if($profile!==null)$identity['club_version']=$profile['version'];$prior=Kulturhus\Native\Shared\Identity::forClub($db,$club);(new Kulturhus\Native\Shared\Identity($db,$access))->save($identity,$prior['version']);
            $clubs[$club]=true;
        }
        foreach(['social','newsletter']as$module)foreach(['js','nojs']as$enhancement){
            $work=new Kulturhus\Native\Communication\Workspace($db,$access,$module,$root.'/media');$item=$work->save(['title'=>'Pausieren <literal> ä🙂','body'=>'Own unchanged original editorial content','channels'=>$module==='social'?['facebook']:[]]);
            if($module==='social')(new Kulturhus\Native\Communication\Images($db,$access,$root.'/media'))->render($item['id']);
            $approved=$work->approve($item['id'],$item['version']);$plan=$work->planAutomation($item['id'],'2026-11-18T07:00:00Z',$approved['version']);
            $items[$mode][$locale][$module][$enhancement]=$item['id'];$plans[$mode][$locale][$module][$enhancement]=$plan['id'];
            if(!isset($history[$club][$module])){
                $historyItem=$work->save(['title'=>'Pausieren <literal> ä🙂','body'=>'Own original synthetic history body','channels'=>$module==='social'?['facebook']:[]]);$approval=['item'=>$historyItem['id'],'version'=>$historyItem['version'],'actor'=>$actor,'authority'=>'native'];$jobs=[];
                for($i=0;$i<($module==='social'?35:13);$i++){
                    $payload=$module==='social'?['channel'=>$i%2?'instagram':'facebook','kind'=>'post','approval'=>$approval]:['to'=>'own-recipient@example.invalid','subject'=>'Pausieren <literal> ä🙂','text'=>'Own original message ä🙂','approval'=>$approval];
                    $job=$outbox->enqueue($club,$module==='social'?'meta':'campaign','own-planning-'.$module.'-'.$i,$payload,'2026-11-'.sprintf('%02d',3+$i%24).' 10:00:00',$access);$jobs[]=$job;
                    if($i%4===1)Kulturhus\Native\Shared\Db::run($db,"UPDATE native_delivery_jobs SET status='sent',completed_at=due_at WHERE id=?",[$job]);
                    if($i%4===2)Kulturhus\Native\Shared\Db::run($db,"UPDATE native_delivery_jobs SET status='uncertain',reason=? WHERE id=?",[$module==='social'?'own-private-reason':'newsletter_interrupted_review',$job]);
                }
                if($module==='social')$jobs[]=$outbox->enqueue($club,'meta','own-planning-missing-original',['channel'=>'facebook'],'2026-11-29 10:00:00',$access);
                $history[$club][$module]=['jobs'=>$jobs,'original'=>$historyItem['id'],'version'=>$historyItem['version']];
                $work->save(['id'=>$historyItem['id'],'title'=>'Own later unapproved title','body'=>'Own later unapproved body','channels'=>$module==='social'?['facebook']:[]],$historyItem['version']);
            }
        }
    }
    $language=new Kulturhus\Native\Language\Settings($db,$authority);$language->save(['source'=>'de','languages'=>[['code'=>'de','label'=>'Deutsch','enabled'=>true,'fallback'=>null,'messages'=>[]],['code'=>'en','label'=>'English','enabled'=>true,'fallback'=>'de','messages'=>[Kulturhus\Native\Language\SocialPlanningMessages::key('Monat')=>'<Own Month>']]]],0);
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $data=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'native_actors'=>$nativeActors,'manager_actors'=>$actors,'manager_ids'=>$managerIds,'tokens'=>$tokens,'items'=>$items,'plans'=>$plans,'history'=>$history];
    $file=$root.'/fixture.json';file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_SOCIAL_PLANNING_FIXTURE='.$file);
    return['root'=>$root,'input'=>$data];
}
