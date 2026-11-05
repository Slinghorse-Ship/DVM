<?php declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('KULTURHUS_LOCAL_DEMO')!=='1'||getenv('VEREIN_MODE')!=='synthetic'||getenv('KULTURHUS_DISABLE_MAIL')!=='1')throw new RuntimeException('Isolated local login fixture only');
$input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
if(!preg_match('/^[a-f0-9]{32}$/D',$input['token']??'')||!in_array($input['action']??'',['create','delete'],true))throw new RuntimeException('Invalid login fixture request');
$wire=(static function(){require '/var/www/html/index.php';return $wire;})();
if($wire->config->dbHost!=='db'||!preg_match('/^kulturhus_(test|copy_[a-f0-9]{8})$/D',$wire->config->dbName))throw new RuntimeException('Wrong local database');
$name='kh-login-'.$input['token'];$user=$wire->users->get($name);
if($input['action']==='create'){
    if($user->id||!is_string($input['password']??null)||strlen($input['password'])<32)throw new RuntimeException('Fixture collision or missing password');
    $user=$wire->users->add($name);$user->pass=$input['password'];$user->email=$name.'@example.invalid';$user->addRole('guest');$user->addRole('verein-member');$user->save();$user->meta('kh_login_fixture',$input['token']);
    echo json_encode(['name'=>$name,'superuser'=>$user->isSuperuser()]);
}else{
    if(!$user->id||$user->meta('kh_login_fixture')!==$input['token']||$user->isSuperuser())throw new RuntimeException('Refusing to remove unrelated user');
    $wire->users->delete($user);echo json_encode(['removed'=>true]);
}
