<?php declare(strict_types=1);
if($view!=='finance'||$tab!=='book-values'||!$canFinance||!$workspaceAllowed)return;
$assetBookSourceState=\Kulturhus\Verein\Web\AssetBookSource::prepare($db,(string)$_SESSION['actor'],$_GET,$_POST,$_SERVER['REQUEST_METHOD']??'GET',(string)((int)date('Y')-1));
if(!in_array($assetBookSourceState['state'],['ready','picker'],true)){
 $assetBookSourceState['command_error']=$error;$error='';
 if($assetBookSourceState['state']==='blocked'){$canFinance=false;$workspaceAllowed=false;$error=$assetBookSourceState['message'];http_response_code(403);}
 else http_response_code(422);
}
