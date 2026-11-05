<?php declare(strict_types=1);
// $db is the existing manager PDO. Authentication never reads a posted actor/role.
try {
    $nativeAuth=\Kulturhus\Native\Auth\Runtime::service($db);
    $nativeBridge=\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,$nativeAuth,is_string($_SESSION['auth_token']??null)?$_SESSION['auth_token']:'');
    $resolvedActor=$nativeBridge->managerActor();
    if(isset($_SESSION['actor'])&&$_SESSION['actor']!==$resolvedActor){
        $authenticatedToken=$_SESSION['auth_token'];session_regenerate_id(true);
        $_SESSION=['auth_token'=>$authenticatedToken,'csrf'=>bin2hex(random_bytes(32))];unset($authenticatedToken);
    }
    $_SESSION['actor']=$resolvedActor;$nativeAccess=$nativeBridge->access();
    $nativeMediaRoot=(string)getenv('KH_NATIVE_MANAGER_MEDIA_ROOT');
    if($nativeMediaRoot===''||!is_dir($nativeMediaRoot))throw new RuntimeException('Privater Medienspeicher fehlt.');
    $nativeEmbedded=new \Kulturhus\Native\Integration\EmbeddedManager(new \Kulturhus\Native\Web\Manager($db,$nativeAccess,$nativeMediaRoot,$_SESSION['csrf'],false,true),$nativeAccess);
} catch(DomainException $error) {
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&($_GET['view']??'')==='portal'&&($_GET['tab']??'')==='signatures'&&is_string($_GET['wf_sign']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$_GET['wf_sign']))$_SESSION['workflow_login_signature']=$_GET['wf_sign'];
    unset($_SESSION['auth_token'],$_SESSION['actor']);header('Location: /login/',true,303);exit;
}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&in_array($_POST['action']??'', ['persona','switch-role','return-to-demo'],true)){
    http_response_code(403);exit('Angemeldete Identitäten können nicht als Testrolle gewechselt werden.');
}
if(isset($_GET['native_endpoint'])){
    \Kulturhus\Native\Integration\Endpoints::serve($db,$nativeAccess,$nativeMediaRoot,$_SESSION['csrf'],$_GET);exit;
}
