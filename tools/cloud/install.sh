#!/bin/bash
set -euo pipefail
mkdir -p /workspace/.setup/apt/lists/partial /workspace/.setup/apt/cache/archives/partial /workspace/.setup/runtime /workspace/.setup/bin /workspace/.setup/php-conf /workspace/.setup/composer /workspace/.setup/apache
cat > /workspace/.setup/apt/sources.list <<'SOURCES'
deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] https://deb.debian.org/debian trixie main
SOURCES
opts=(-o Debug::NoLocking=1 -o Dir::Etc::parts=- -o Dir::Etc::sourcelist=/workspace/.setup/apt/sources.list -o Dir::Etc::sourceparts=- -o Dir::State::lists=/workspace/.setup/apt/lists -o Dir::Cache=/workspace/.setup/apt/cache)
/usr/bin/apt-get "${opts[@]}" update
/usr/bin/apt-get "${opts[@]}" --download-only -y install php8.4-cli php8.4-curl php8.4-mbstring php8.4-xml php8.4-mysql php8.4-sqlite3 php8.4-zip php8.4-gd composer apache2-bin libapache2-mod-php8.4
for package in /workspace/.setup/apt/cache/archives/*.deb; do dpkg-deb -x "$package" /workspace/.setup/runtime; done
python3 - <<'PYTHON'
from pathlib import Path
r=Path('/workspace/.setup/runtime')
for p in (r/'usr/share/php').rglob('*.php'):
 s=p.read_text(); t=s.replace('/usr/share/php/',str(r)+'/usr/share/php/')
 if t!=s:p.write_text(t)
mods=sorted(x.name for x in (r/'usr/lib/php/20240924').glob('*.so') if x.name not in ['opcache.so','pdo.so','mysqlnd.so','dom.so'])
Path('/workspace/.setup/php-conf/php.ini').write_text('extension_dir=/workspace/.setup/runtime/usr/lib/php/20240924\n'+''.join('extension='+x+'\n' for x in ['pdo.so','mysqlnd.so','dom.so']+mods)+'date.timezone=Europe/Berlin\nupload_max_filesize=256M\npost_max_size=520M\nmax_file_uploads=50\nmax_input_vars=8192\n')
PYTHON
cat > /workspace/.setup/bin/php <<'LOCALFILE'
#!/bin/sh
export LD_LIBRARY_PATH=/workspace/.setup/runtime/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}
export PHPRC=/workspace/.setup/php-conf/php.ini
exec /workspace/.setup/runtime/usr/bin/php8.4 -c /workspace/.setup/php-conf/php.ini "$@"
LOCALFILE
cat > /workspace/.setup/bin/composer <<'LOCALFILE'
#!/bin/sh
export COMPOSER_HOME=/workspace/.setup/composer
exec /workspace/.setup/bin/php /workspace/.setup/runtime/usr/bin/composer "$@"
LOCALFILE
cat > /workspace/.setup/apache/httpd.conf <<'LOCALFILE'
ServerRoot "/workspace/.setup/runtime/etc/apache2"
ServerName localhost
Listen 127.0.0.1:8092
PidFile /workspace/.setup/apache/httpd.pid
LoadModule mpm_prefork_module /workspace/.setup/runtime/usr/lib/apache2/modules/mod_mpm_prefork.so
LoadModule authz_core_module /workspace/.setup/runtime/usr/lib/apache2/modules/mod_authz_core.so
LoadModule dir_module /workspace/.setup/runtime/usr/lib/apache2/modules/mod_dir.so
LoadModule alias_module /workspace/.setup/runtime/usr/lib/apache2/modules/mod_alias.so
LoadModule mime_module /workspace/.setup/runtime/usr/lib/apache2/modules/mod_mime.so
LoadModule php_module /workspace/.setup/runtime/usr/lib/apache2/modules/libphp8.4.so
PHPIniDir /workspace/.setup/php-conf
TypesConfig /etc/mime.types
User agent
Group agent
ErrorLog /workspace/.setup/apache/error.log
DocumentRoot /workspace/.setup/apache
Alias /vereinsverwaltung/ /workspace/Kulturhus/vereinsverwaltung/public/
<Directory /workspace/Kulturhus/vereinsverwaltung/public>
Require all granted
DirectoryIndex index.php
<FilesMatch "\.php$">
SetHandler application/x-httpd-php
</FilesMatch>
</Directory>
LOCALFILE
chmod +x /workspace/.setup/bin/php /workspace/.setup/bin/composer
export PATH=/workspace/.setup/bin:$PATH
cd /workspace/Kulturhus
composer install --no-dev --no-plugins --no-scripts --no-interaction --prefer-source
composer check-platform-reqs --no-dev
composer install --working-dir=banking --no-dev --no-plugins --no-scripts --no-interaction --prefer-source
composer install --working-dir=vereinsverwaltung/push-runtime --no-dev --no-plugins --no-scripts --no-interaction --prefer-source
composer check-platform-reqs --working-dir=banking --no-dev
composer check-platform-reqs --working-dir=vereinsverwaltung/push-runtime --no-dev
php banking/bin/check.php
docker pull mysql:8.4@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242


cat > /workspace/.setup/native-fixture.php <<'NATIVE_HELPER'
<?php
$path='/workspace/.setup/native-demo.json';
if(is_file($path))exit(0);
$db=new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4','root','synthetic-ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='kh_native_'.bin2hex(random_bytes(12));
$db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
file_put_contents($path,json_encode(['host'=>'127.0.0.1','port'=>13371,'database'=>$name,'username'=>'root','password'=>'synthetic-ci-only','owner'=>'cloud-onboarding-synthetic','label'=>'demo']));chmod($path,0600);
NATIVE_HELPER

cat > /workspace/.setup/prepare-native.sh <<'NATIVE_HELPER'
#!/bin/bash
set -euo pipefail
export PATH=/workspace/.setup/bin:$PATH
export LD_LIBRARY_PATH=/workspace/.setup/runtime/usr/lib/x86_64-linux-gnu
export PHPRC=/workspace/.setup/php-conf/php.ini
cd /workspace/Kulturhus
DVM_LOCAL_TEST_DATABASES=1 python3 tools/cloud/start-test-databases.py --native
php -r 'new PDO("mysql:host=127.0.0.1;port=13371","root","synthetic-ci-only");'
php /workspace/.setup/native-fixture.php
export KH_NATIVE_TEST_CONFIG=/workspace/.setup/native-demo.json KULTURHUS_DISABLE_MAIL=1
php tools/cloud/prepare-native-key.php
export VEREIN_ERIC_DISABLE_TRANSPORT=1
export VEREIN_PYTHON=/workspace/.setup/invoice-runtime/python/run
export VEREIN_OCR_RUNTIME=/workspace/.setup/invoice-private/runtime-config/ocr.json
export VEREIN_KOSIT_RUNTIME=/workspace/.setup/invoice-private/runtime-config/kosit.json
export VEREIN_HYBRID_RUNTIME=/workspace/.setup/invoice-private/runtime-config/hybrid.json
test -f "$VEREIN_OCR_RUNTIME"
echo 'Private Komponentenfixture und ursprünglicher Schlüssel bereit; kein zweiter HTTP-Dienst.'
NATIVE_HELPER

npm install --prefix /workspace/.setup/browser --cache /workspace/.setup/npm-cache --no-save --package-lock=false playwright@1.56.1
cat > /workspace/.setup/browser/image-export-browser.mjs <<'BROWSER_HELPER'
process.env.PLAYWRIGHT_MODULE='/workspace/.setup/browser/node_modules/playwright/index.mjs';
process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE='/usr/bin/chromium';
await import('file:///workspace/Kulturhus/vereinsverwaltung/tests/image-export-browser.mjs');
BROWSER_HELPER

# Verified private invoice runtime; OCR uses a separate networkless container.
cat > /workspace/.setup/install-invoice-runtime.php <<'INVOICE_HELPER'
<?php declare(strict_types=1);
require '/workspace/Kulturhus/distribution/src/PackageStore.php';
require '/workspace/Kulturhus/distribution/src/RuntimeInstaller.php';
$store=new PackageStore('/workspace/Kulturhus/distribution');
$verified=$store->verify();
$runtime='/workspace/.setup/invoice-runtime';
$private='/workspace/.setup/invoice-private';
if(!is_dir($runtime)){
    $stage='/workspace/.setup/invoice-runtime-stage-'.bin2hex(random_bytes(12));
    PackageStore::directory($stage);
    PackageStore::directory($stage.'/packages-tmp');
    foreach($verified['linux']['components'] as $component){
        $archive=$store->materialize($component,$stage.'/packages-tmp');
        $install=$component['install'];
        PackageStore::extract($archive,$stage.'/'.PackageStore::relative($install['target']),$install['type'],(int)($install['strip_components']??0));
        unlink($archive);
    }
    rmdir($stage.'/packages-tmp');
    if(!rename($stage,$runtime))throw new RuntimeException('Private runtime staging could not be committed.');
}
PackageStore::directory($private);
$environment=RuntimeInstaller::configure($runtime,'/workspace/Kulturhus',$private,'docker','debian@sha256:7c7b2c966bc9ee8cedfeef67e0e279108992c77681fa595db4a9d65c06ccc587','/usr/bin/python3');
$content="<?php\nforeach (".var_export($environment,true)." as \$k=>\$v)putenv(\$k.\"=\".\$v);\n";
$config=$private.'/runtime.php';
if(file_exists($config)){
    if(is_link($config)||!is_file($config)||file_get_contents($config)!==$content)throw new RuntimeException('Existing private runtime exports differ; preserve and investigate.');
}else PackageStore::writeNew($config,$content);
chmod($config,0600);
echo "Verified invoice runtime and isolated OCR configuration prepared.\n";
INVOICE_HELPER
docker image inspect debian@sha256:7c7b2c966bc9ee8cedfeef67e0e279108992c77681fa595db4a9d65c06ccc587 >/dev/null 2>&1 || docker pull debian@sha256:7c7b2c966bc9ee8cedfeef67e0e279108992c77681fa595db4a9d65c06ccc587
php /workspace/.setup/install-invoice-runtime.php
python3 distribution/build-source-manifest.py --check
php distribution/install.php --verify

# Native integrated manager fixture (created explicitly on first startup).
cat > /workspace/.setup/install-integrated-manager.php <<'INTEGRATED_INSTALL'
<?php declare(strict_types=1);
umask(0077);
$root='/workspace/Kulturhus';
require $root.'/vereinsverwaltung/native/bootstrap.php';
$dir=$root.'/.local/native-cms-integration';
if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Private fixture directory unavailable.');
$path=$dir.'/integrated-manager.json';
if(!is_file($path)){
    $db=new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4','root','synthetic-ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $name='kh_native_'.bin2hex(random_bytes(12));
    $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $file=fopen($path,'x');if(!$file)throw new RuntimeException('Fixture configuration occupied.');
    fwrite($file,json_encode(['host'=>'127.0.0.1','port'=>13371,'database'=>$name,'username'=>'root','password'=>'synthetic-ci-only','owner'=>'cloud-onboarding-synthetic','label'=>'integrated-manager'],JSON_THROW_ON_ERROR));fclose($file);chmod($path,0600);
}
putenv('KH_NATIVE_TEST_CONFIG='.$path);
$fixture=Kulturhus\Native\TestSupport::config();
if(($fixture['owner']??'')!=='cloud-onboarding-synthetic'||($fixture['label']??'')!=='integrated-manager')throw new RuntimeException('Registered private integration fixture required.');
Kulturhus\Native\Installer\SchemaRegistry::install(Kulturhus\Native\TestSupport::db());
require $root.'/vereinsverwaltung/bin/native-manager-demo-seed.php';
echo "Integrated manager fixture initialized; private accounts remain local.\n";
INTEGRATED_INSTALL
cat > /workspace/.setup/start-integrated-manager.sh <<'INTEGRATED_START'
#!/bin/bash
set -euo pipefail
umask 077
export PATH=/workspace/.setup/bin:$PATH
export PHPRC=/workspace/.setup/php-conf/php.ini
export LD_LIBRARY_PATH=/workspace/.setup/runtime/usr/lib/x86_64-linux-gnu
export VEREIN_ERIC_DISABLE_TRANSPORT=1
export VEREIN_PYTHON=/workspace/.setup/invoice-runtime/python/run
export VEREIN_OCR_RUNTIME=/workspace/.setup/invoice-private/runtime-config/ocr.json
export VEREIN_KOSIT_RUNTIME=/workspace/.setup/invoice-private/runtime-config/kosit.json
export VEREIN_HYBRID_RUNTIME=/workspace/.setup/invoice-private/runtime-config/hybrid.json
cd /workspace/Kulturhus
php /workspace/.setup/install-integrated-manager.php
python3 - <<'PY'
from pathlib import Path
root=Path('/workspace/Kulturhus')
s=(root/'vereinsverwaltung/tests/support/native-manager-router.php').read_text()
s=s.replace('$root=dirname(__DIR__,3);',"$root='/workspace/Kulturhus';")
old="$root.'/.local/vereinsverwaltung',...$pdfRoots"
new="$root.'/.local/vereinsverwaltung',$root.'/vendor','/workspace/.setup/invoice-runtime','/workspace/.setup/invoice-private/runtime-config',...$pdfRoots"
assert old in s
s=s.replace(old,new)
Path('/workspace/.setup/integrated-manager-router.php').write_text(s)
PY
if ! curl --silent --fail http://127.0.0.1:8111/login/ > /workspace/.setup/integrated-login.html; then
 nohup php -S 127.0.0.1:8111 -t vereinsverwaltung/public /workspace/.setup/integrated-manager-router.php > /workspace/.setup/integrated-manager-server.log 2>&1 &
 echo $! > /workspace/.setup/integrated-manager-server.pid
 for attempt in {1..10}; do
  if curl --silent --fail http://127.0.0.1:8111/login/ > /workspace/.setup/integrated-login.html; then break; fi
  sleep 1
 done
fi
python3 - <<'PY'
from pathlib import Path
s=Path('/workspace/.setup/integrated-login.html').read_text()
assert 'Anmelden und Vereinsmanager öffnen' in s and 'Fatal error' not in s
print('Integrated manager native login request passed')
PY
INTEGRATED_START
bash /workspace/.setup/prepare-native.sh
bash /workspace/.setup/start-integrated-manager.sh

python3 tools/cloud/retire-legacy-preview.py
