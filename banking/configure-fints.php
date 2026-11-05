<?php declare(strict_types=1);
// Deployment administrator only. JSON on stdin has public bank/product metadata, never a PIN.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
 $root=$argv[1]??'';if($root===''||!is_dir($root)||!is_writable($root))throw new RuntimeException('Vorhandenes privates Banking-Verzeichnis als Argument angeben.');
 $root=realpath($root);$target=$root.'/fints.json';if(is_file($target)||is_file($root.'/fints-state.key'))throw new RuntimeException('Bestehende FinTS-Konfiguration bleibt unverändert. Änderungen kontrolliert durch den Betreiber durchführen.');
 $raw=stream_get_contents(STDIN,8193);if(strlen($raw)>8192)throw new RuntimeException('Konfiguration zu groß.');$input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);$data=[];
 foreach(['url','bank_code','product_id','product_version','club_id'] as $k){$v=$input[$k]??null;if(!is_string($v)||trim($v)===''||strlen($v)>300||preg_match('/[\x00-\x1f]/',$v))throw new RuntimeException('Bankendpunkt, BLZ, registrierte Produktnummer, Version und Verein angeben.');$data[$k]=trim($v);}
 // FinTS places this value only in HKVVB/Produktbezeichnung. The DK registration
 // number must be passed unchanged and in its complete 25-character form.
 if(!preg_match('/\A[A-Za-z0-9]{25}\z/D',$data['product_id']))throw new RuntimeException('Die FinTS-Registrierungs-ID muss ausschließlich aus genau 25 Zeichen bestehen.');
 if(strlen($data['product_version'])>5)throw new RuntimeException('Die FinTS-Produktversion darf höchstens 5 Zeichen enthalten.');
 $url=parse_url($data['url']);if(($url['scheme']??'')!=='https'||empty($url['host'])||filter_var($url['host'],FILTER_VALIDATE_IP)||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!preg_match('/^[0-9]{8}$/D',$data['bank_code']))throw new RuntimeException('Bestätigten HTTPS-Bankendpunkt und achtstellige BLZ verwenden.');
 if(PHP_OS_FAMILY!=='Windows'&&(fileperms($root)&0077))throw new RuntimeException('Das Verzeichnis muss ausschließlich dem Betreiber zugänglich sein (0700).');
 // Exclusive creation never replaces existing installation secrets.
 $handle=fopen($root.'/fints-state.key','x');if(!$handle)throw new RuntimeException('Schlüssel bereits vorhanden.');try{chmod($root.'/fints-state.key',0600);if(fwrite($handle,random_bytes(32))!==32)throw new RuntimeException('Schlüssel konnte nicht gespeichert werden.');}finally{fclose($handle);}
 $handle=fopen($target,'x');if(!$handle)throw new RuntimeException('Konfiguration bereits vorhanden.');try{chmod($target,0600);fwrite($handle,json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));}finally{fclose($handle);}
 echo "FinTS-Konfiguration privat vorbereitet. Es wurde keine Bankverbindung geöffnet.\n";
}catch(Throwable $e){fwrite(STDERR,'FinTS-Konfiguration nicht abgeschlossen: '.$e->getMessage()."\n");exit(1);}
