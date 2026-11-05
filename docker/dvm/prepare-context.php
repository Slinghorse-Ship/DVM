<?php declare(strict_types=1);
// A prebuilt upload package may contain a real setup key: never copy private/.
if(PHP_SAPI!=='cli'||$argc!==3)exit(2);
$package=realpath($argv[1]);$target=$argv[2];
if(!$package||is_link($argv[1])||file_exists($target))throw new RuntimeException('New context and verified WebBundle required.');
require $package.'/app/distribution/src/PackageStore.php';
$proof=PackageStore::json($package.'/private/app-files.json');
if(($proof['format']??null)!==1||!preg_match('/^[a-f0-9]{64}$/D',$proof['source']??'')||!is_array($proof['files']??null)||!$proof['files'])throw new RuntimeException('Application inventory required.');
PackageStore::directory($target);$seen=[];
foreach($proof['files']as$entry){$name=PackageStore::relative($entry['path']);if(isset($seen[$name]))throw new RuntimeException('Duplicate inventory member.');$seen[$name]=true;PackageStore::check($package.'/app/'.$name,$entry);$to=$target.'/app/'.$name;PackageStore::directory(dirname($to));if(!copy($package.'/app/'.$name,$to)||!chmod($to,(($entry['mode']??0644)&0111)?0755:0644))throw new RuntimeException('Verified app copy failed.');}
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package.'/app',FilesystemIterator::SKIP_DOTS))as$file)if($file->isLink()||!$file->isFile()||!isset($seen[substr($file->getPathname(),strlen($package.'/app/'))]))throw new RuntimeException('Unlisted application file or link.');
// Setup controller is generated afresh; assets must match their inventoried app originals.
$setup=$target.'/web/setup';PackageStore::directory($setup);
file_put_contents($setup.'/index.php',"<?php declare(strict_types=1);\n\$dvmSetupRoot=dirname(__DIR__,2);\nrequire \$dvmSetupRoot.'/app/distribution/web/index.php';\n");chmod($setup.'/index.php',0644);
require $target.'/app/vereinsverwaltung/native/bootstrap.php';
$assets=Kulturhus\Native\Web\BootstrapAssets::sources();
foreach(['setup.css','setup.js']as$name)$assets[$name]=$target.'/app/distribution/web/'.$name;
foreach($assets as$name=>$from){PackageStore::relative($name);if(str_contains($name,'/'))throw new RuntimeException('Setup asset name required.');$relative=substr($from,strlen($target.'/app/'));if(!isset($seen[$relative]))throw new RuntimeException('Setup asset is not inventoried.');if(!copy($from,$setup.'/'.$name)||!chmod($setup.'/'.$name,0644))throw new RuntimeException('Setup asset copy failed.');}
file_put_contents($target.'/app-files.json',file_get_contents($package.'/private/app-files.json'));chmod($target.'/app-files.json',0644);
echo json_encode(['prepared_files'=>count($seen),'source'=>$proof['source'],'private_files_copied'=>0,'setup_keys_copied'=>0],JSON_THROW_ON_ERROR)."\n";
