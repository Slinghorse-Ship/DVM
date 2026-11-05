<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$entries=preg_split('/[ ,]+/',trim((string)getenv('DVM_TRUSTED_PROXIES')),-1,PREG_SPLIT_NO_EMPTY);$https='';
foreach($entries as$entry){$parts=explode('/',$entry);$address=$parts[0];$ipv4=filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false;$ipv6=filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false;if((!$ipv4&&!$ipv6)||count($parts)>2||(isset($parts[1])&&(!ctype_digit($parts[1])||(int)$parts[1]<1||(int)$parts[1]>($ipv4?32:128))))throw new RuntimeException('Explicit trusted proxy IP/CIDR required; wildcard proxies refused.');$https.='SetEnvIfExpr "-R \''.$entry.'\' && req(\'X-Forwarded-Proto\') == \'https\'" HTTPS=on'."\n";}
$config="ServerName localhost\nServerTokens Prod\nServerSignature Off\n<VirtualHost *:80>\nDocumentRoot /var/lib/dvm/web\n<Directory /var/lib/dvm/web>\nOptions -Indexes +FollowSymLinks\nAllowOverride All\nRequire all granted\nDirectoryIndex index.php\n</Directory>\n".$https."ErrorLog /proc/self/fd/2\nCustomLog /proc/self/fd/1 combined\n</VirtualHost>\n";
file_put_contents('/etc/apache2/sites-available/000-default.conf',$config);
