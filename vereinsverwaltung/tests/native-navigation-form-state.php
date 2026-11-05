<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\Web\NavigationFormState as State;
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$reject=static function(array $input,string $label)use($check):void{try{State::restore($input);}catch(InvalidArgumentException){$check(true,$label);return;}throw new RuntimeException('Accepted '.$label);};
$id=str_repeat('a',32);$post=['operation'=>'navigation-save','version'=>'3','navigation_seen_version'=>'5','navigation_actor'=>'own-actor','navigation_club'=>'own-club','items'=>[$id=>['label'=>'Eigener & Originalname ä🙂','position'=>'ungültig','visible'=>'1']],'automatic_program_year'=>'on'];
$state=State::restore($post);$check($state['version']===3&&$state['seen_version']===5&&$state['items'][$id]['position']==='ungültig'&&$state['items'][$id]['label']===$post['items'][$id]['label'],'own Unicode/input and old/current CAS are separate');
$check($state['items'][$id]['visible']&&$state['automatic_program_year'],'strict checkbox on/1 accepted');
$plain=$post;unset($plain['automatic_program_year'],$plain['items'][$id]['visible']);$check(!State::restore($plain)['items'][$id]['visible']&&!State::restore($plain)['automatic_program_year'],'unchecked values retained');
foreach(['version'=>['0','4294967295'],'navigation_seen_version'=>['0','4294967295']]as$key=>$values)foreach($values as$value)$check(State::restore(array_replace($post,[$key=>$value]))[$key==='version'?'version':'seen_version']===(int)$value,'valid bounded CAS '.$key.' '.$value);
foreach(['-1','01','1e3','4294967296',[],true,1.5,null]as$value)$reject(array_replace($post,['version'=>$value]),'malformed/oversize CAS');
foreach(['navigation_actor','navigation_club']as$key)foreach(['',str_repeat('x',65),[],null,"a\0b"]as$value)$reject(array_replace($post,[$key=>$value]),'bounded identity '.$key);
foreach(['compare','resume']as$value)$check(State::restore($post+['navigation_browse'=>$value])['browse']===$value,'closed explicit browse '.$value);
foreach(['write','',[]]as$value)if($value!=='')$reject($post+['navigation_browse'=>$value],'unknown/nested browse');
foreach([['label'=>[]],['position'=>[]],['visible'=>[]],['extra'=>'x'],['label'=>str_repeat('ä',501)],['position'=>str_repeat('1',33)],['label'=>"x\0y"],['label'=>"\xff"]]as$change){$bad=$post;$bad['items'][$id]=array_replace($bad['items'][$id],$change);$reject($bad,'bounded closed row');}
$bad=$post;$bad['items']=['foreign-id'=>$post['items'][$id]];$reject($bad,'malformed page identifier');
$bad=$post;$bad['items']=array_fill(0,1001,$post['items'][$id]);$reject($bad,'item count bounded');
$check(State::submitted($post)&&!State::submitted(['operation'=>'page-save']),'closed operation dispatch');
echo json_encode(['suite'=>'native-navigation-form-state','passed'=>$checks],JSON_THROW_ON_ERROR).PHP_EOL;
