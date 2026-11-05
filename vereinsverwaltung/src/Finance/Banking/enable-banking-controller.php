<?php declare(strict_types=1);
// Loaded only after the ordinary current account identity and finance permission checks.
$enableError='';$enableClient=null;$enableData=null;$enableStatus=null;$enableBanks=[];$enableConnections=[];
$enableEnvironment=input($_POST,'bank_environment',input($_GET,'bank_environment','PRODUCTION'));
$enableAction=input($_POST,'action');
if(($canFinance&&$finance->canWrite())&&((input($_GET,'view')==='finance'&&input($_GET,'tab')==='bank')||str_starts_with($enableAction,'enable-')||isset($_GET['enable_finish']))){
    require_once __DIR__.'/EnableBanking.php';
    try{
        if(isset($_GET['enable_finish']))$enableEnvironment=str_starts_with(input($_GET,'state'),'sandbox-')?'SANDBOX':'PRODUCTION';
        $enableClient=new \Kulturhus\Verein\EnableBanking($enableEnvironment,(string)$_SESSION['actor'],\Kulturhus\Verein\EnableBanking::root());
        if(isset($_GET['enable_continue'])){
            $forward=$_SESSION['enable_forward']??null;unset($_SESSION['enable_forward']);
            if(!is_array($forward)||($forward['actor']??'')!==$_SESSION['actor']||($forward['environment']??'')!==$enableEnvironment||($forward['expires']??0)<time())throw new DomainException('Bankfreigabe bitte erneut starten.');
            $bankRedirect=$forward['url'];header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
            require dirname(__DIR__, 2).'/../views/Finance/Banking/enable-banking-forward.php';exit;
        }
        if(isset($_GET['enable_finish'])){
            $enableClient->finish(input($_GET,'state'),input($_GET,'code'));
            $_SESSION['notice']='Bankfreigabe gespeichert. Die freigegebenen Konten können jetzt einzeln gelesen werden.';
            redirectTo(['view'=>'finance','tab'=>'bank','bank_environment'=>$enableEnvironment]);
        }
        if(str_starts_with($enableAction,'enable-')){
            if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($_SESSION['csrf'],input($_POST,'csrf')))throw new DomainException('Formular abgelaufen. Bitte neu laden.');
            if(in_array($enableAction,['enable-map','enable-import','enable-review'],true)){
                $_SESSION['notice']=\Kulturhus\Verein\BankImportActions::handle($db,(string)$_SESSION['actor'],$enableAction,$_POST,$enableClient);
                redirectTo(['view'=>'finance','tab'=>'bank','bank_environment'=>'PRODUCTION','bank_uid'=>input($_POST,'bank_uid')]);
            }
            if($enableAction!=='enable-connect')throw new DomainException('Unbekannte Bankaktion.');
            $bankRedirect=$enableClient->start(input($_POST,'bank_name'));
            // A form-action 'self' policy also blocks a POST's external redirect in Chromium.
            // Complete the POST on our own origin; the next HTML page starts normal navigation.
            $_SESSION['enable_forward']=['url'=>$bankRedirect,'actor'=>$_SESSION['actor'],'environment'=>$enableEnvironment,'expires'=>time()+120];
            redirectTo(['view'=>'finance','tab'=>'bank','bank_environment'=>$enableEnvironment,'enable_continue'=>1]);
        }
        $enableStatus=$enableClient->application();
        if($enableStatus['active']??false)$enableBanks=$enableClient->banks();
        $enableConnections=$enableClient->connections();
        if(input($_GET,'bank_uid')!=='')$enableData=$enableClient->accountData(input($_GET,'bank_uid'),input($_GET,'bank_continuation'));
    }catch(Throwable $ex){$enableError=$ex instanceof DomainException?$ex->getMessage():'Bankverbindung derzeit nicht verfügbar. Bitte später erneut versuchen.';}
    if(isset($_GET['enable_finish'])||str_starts_with($enableAction,'enable-')){
        $_SESSION['notice']=$enableError;redirectTo(['view'=>'finance','tab'=>'bank','bank_environment'=>in_array($enableEnvironment,['PRODUCTION','SANDBOX'],true)?$enableEnvironment:'PRODUCTION']);
    }
}
if((str_starts_with($enableAction,'enable-')||isset($_GET['enable_finish']))&&(!$canFinance||!$finance->canWrite())){http_response_code(403);exit('Finanz-Schreibberechtigung erforderlich.');}
