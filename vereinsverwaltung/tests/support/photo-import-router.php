<?php declare(strict_types=1);
// Private, loopback-only browser contract; no application or transport configuration.
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1')exit(1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\Web\PhotoImportAssets;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
header("Content-Security-Policy: default-src 'none'; script-src 'self'; worker-src 'self'; style-src 'self'; img-src 'self' blob:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if(str_starts_with($path,'/assets/')&&PhotoImportAssets::serve(substr($path,8)))exit;
if($path==='/fixture.js'){
    header('Content-Type: text/javascript');
    echo <<<'JS'
window.photoChanges=[];
document.addEventListener('change',event=>{if(event.target.type==='file')window.photoChanges.push([...event.target.files].map(file=>({name:file.name,type:file.type,size:file.size})));});
document.querySelector('form').addEventListener('submit',async event=>{event.preventDefault();const response=await fetch('/upload',{method:'POST',body:new FormData(event.target)});window.photoUpload=await response.json();});
JS;
    exit;
}
if($path==='/upload'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');$f=$_FILES['image']??[];$images=[];
    foreach(($f['tmp_name']??[])as$i=>$tmp){
        if(($f['error'][$i]??null)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp))throw new RuntimeException('Real HTTP upload required.');
        $info=getimagesize($tmp);$image=imagecreatefromstring(file_get_contents($tmp));$pixel=imagecolorsforindex($image,imagecolorat($image,(int)floor($info[0]/2),(int)floor($info[1]/2)));
        $images[]=['name'=>$f['name'][$i],'mime'=>$info['mime'],'width'=>$info[0],'height'=>$info[1],'pixel'=>$pixel,'sha256'=>hash_file('sha256',$tmp)];
    }
    echo json_encode(['images'=>$images,'note'=>$_POST['note']??''],JSON_THROW_ON_ERROR);exit;
}
if($path!=='/'||!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(404);exit;}
header('Content-Type: text/html; charset=utf-8');
if($_SERVER['REQUEST_METHOD']==='HEAD')exit;
$input=($_GET['newsletter']??'')==='1'?'<input type="file" name="image[]" accept="image/jpeg,image/png" data-nl-image-input hidden>':'<label>Eigene Bilder<input type="file" name="image[]" accept="image/jpeg,image/png" multiple></label>';
echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/photo-import.css"><link rel="stylesheet" href="/assets/photo-editor.css"><script src="/assets/photo-import.js" defer></script><script src="/assets/photo-editor.js" defer></script><script src="/fixture.js" defer></script><title>Eigene lokale Bildprüfung</title><form method="post" enctype="multipart/form-data">'.$input.'<label>Eigene Notiz<input name="note" value="Unveränderter Text"></label><button type="submit">Hochladen</button><button type="submit" disabled>Vorher deaktiviert</button></form></html>';
