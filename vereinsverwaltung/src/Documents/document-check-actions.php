<?php declare(strict_types=1);
if(!isset($checkDraft,$checkService))throw new DomainException('Nachweisformular nicht vollständig übertragen. Eingaben prüfen.');
if($checkDraft['browse']!==''){$checkBrowseDraft=true;}
else{
 if($checkDraft['source_errors'])throw new DomainException(reset($checkDraft['source_errors']));
 $checkData=$checkDraft['data'];$checkFile=null;$checkUpload=$_FILES['check_file']??null;
 if(is_array($checkUpload)&&($checkUpload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
  if(($checkUpload['error']??-1)!==UPLOAD_ERR_OK||!\Kulturhus\Verein\LiveUploads::file($checkUpload['tmp_name']??''))throw new DomainException('Upload fehlgeschlagen. Bitte Dateigröße prüfen.');
  $checkFile=['name'=>(string)$checkUpload['name'],'bytes'=>\Kulturhus\Verein\LiveUploads::bytes($checkUpload['tmp_name'],10485760)];
 }
 if($checkDraft['id']===''){$checkId=$checkService->createOnce($checkData,$checkDraft['request_key'],$checkFile);}
 elseif($checkFile!==null)$checkId=$checkService->saveWithUpload($checkDraft['id'],$checkDraft['version'],$checkData,$checkFile['name'],$checkFile['bytes'],$checkDraft['request_key']);
 else $checkId=$checkService->save($checkDraft['id'],$checkDraft['version'],$checkData);
 $_SESSION['notice']=$hseActionText('Nachweis und Fristen gespeichert.');redirectTo(['view'=>'documents','tab'=>'checks','check'=>$checkId]);
}
