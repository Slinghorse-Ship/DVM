<?php declare(strict_types=1);
require __DIR__.'/../src/Infrastructure/Runtime/LocalRuntime.php';
require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\LocalRuntime;
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
$oldMode=getenv('VEREIN_MODE');$oldShared=getenv('KULTURHUS_LOCAL_DEMO');$oldDatabase=getenv('KULTURHUS_DEMO_DATABASE');
try{
    putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_LOCAL_DEMO=1');
    $request=['HTTP_HOST'=>'127.0.0.1:8092','REMOTE_ADDR'=>'172.20.0.3','REQUEST_URI'=>'/vereinsverwaltung/?view=website'];
    check(LocalRuntime::acceptsRequest('apache2handler',$request),'isolated shared Apache accepts mounted club application');
    check(LocalRuntime::basePath()==='/vereinsverwaltung/','shared cookie and route path');
    check(url(['view'=>'members'])==='/vereinsverwaltung/?view=members','redirect preserves application mount');
    check(!LocalRuntime::acceptsRequest('cli-server',$request),'shared flag does not enable arbitrary standalone server');
    foreach(['www.kulturhus.de','127.0.0.1:8091','127.0.0.1:8092.evil.invalid'] as $host){
        check(!LocalRuntime::acceptsRequest('apache2handler',['HTTP_HOST'=>$host]+array_diff_key($request,['HTTP_HOST'=>true])),'unexpected shared host rejected');
    }
    check(!LocalRuntime::acceptsRequest('apache2handler',['REQUEST_URI'=>'/processwire/']+array_diff_key($request,['REQUEST_URI'=>true])),'shared request must target club mount');
    putenv('VEREIN_MODE=production');check(!LocalRuntime::acceptsRequest('apache2handler',$request),'synthetic auto-login disabled in production mode');
    putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_LOCAL_DEMO');
    $standalone=['HTTP_HOST'=>'127.0.0.1:8091','REMOTE_ADDR'=>'127.0.0.1'];
    check(LocalRuntime::acceptsRequest('cli-server',$standalone),'optional CLI development harness remains loopback-only');
    check(!LocalRuntime::acceptsRequest('apache2handler',$standalone),'Apache requires explicit isolated mode');
    check(!LocalRuntime::acceptsRequest('cli-server',['REMOTE_ADDR'=>'192.0.2.1']+array_diff_key($standalone,['REMOTE_ADDR'=>true])),'remote standalone request denied');
    check(url(['view'=>'members'])==='/?view=members','standalone harness routes remain compatible');
    putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;dbname=synthetic_example');
    $native=['HTTP_HOST'=>'127.0.0.1:8091','REMOTE_ADDR'=>'127.0.0.1','REQUEST_URI'=>'/vereinsverwaltung/?view=finance'];
    check(LocalRuntime::acceptsRequest('cli-server',$native),'explicit native PHP server accepts local mounted requests');
    check(LocalRuntime::basePath()==='/vereinsverwaltung/'&&url(['view'=>'members'])==='/vereinsverwaltung/?view=members','native cookies and navigation retain the application mount');
    check(str_starts_with(LocalRuntime::database(),'mysql:'),'native mode selects explicit MySQL without connecting');
    check(!LocalRuntime::acceptsRequest('apache2handler',$native),'native flag does not enable Apache');
    foreach(['HTTP_HOST'=>'kulturhus.de','REMOTE_ADDR'=>'192.0.2.1','REQUEST_URI'=>'/src/Infrastructure/Database/Database.php'] as $field=>$value)
        check(!LocalRuntime::acceptsRequest('cli-server',[$field=>$value]+array_diff_key($native,[$field=>true])),'native request boundary '.$field);
    putenv('KULTURHUS_DEMO_DATABASE=sqlite:forbidden');$rejected=false;try{LocalRuntime::database();}catch(RuntimeException){$rejected=true;}check($rejected,'native mode rejects a non-MySQL DSN');
    putenv('KULTURHUS_DEMO_DATABASE=mysql:host=127.0.0.1;dbname=synthetic_example');putenv('VEREIN_MODE=production');
    check(!LocalRuntime::acceptsRequest('cli-server',$native),'native synthetic identity cannot enter production');
    $rejected=false;try{LocalRuntime::database();}catch(RuntimeException){$rejected=true;}check($rejected,'native database gate requires synthetic mode');
}finally{foreach(['VEREIN_MODE'=>$oldMode,'KULTURHUS_LOCAL_DEMO'=>$oldShared,'KULTURHUS_DEMO_DATABASE'=>$oldDatabase] as $name=>$value)putenv($value===false?$name:$name.'='.$value);}
echo "$checks runtime checks passed\n";
