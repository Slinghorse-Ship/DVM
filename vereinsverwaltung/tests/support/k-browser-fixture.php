<?php declare(strict_types=1);

/** Only the pair of fresh databases created by native-k-browser may use old UI fixtures. */
function ownKBrowserFixture(): array
{
    $path=getenv('DVM_K_BROWSER_FIXTURE');
    if(getenv('KH_NATIVE_CI')!=='1'||!is_string($path)||realpath($path)!==$path||is_link($path)||!is_file($path)||(fileperms($path)&0077)||filesize($path)>131072)throw new RuntimeException('Explicit private K-browser fixture required.');
    $f=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    $root=dirname($path);$source=dirname(__DIR__,3);
    if(($f['owner']??null)!=='isolated-k-browser'||($f['source']??null)!==$source||($f['root']??null)!==$root||realpath($root)!==$root||is_link($root)||(fileperms($root)&0077))throw new RuntimeException('Owned K-browser root required.');
    if(($f['directory']??null)!==$root.'/results'||realpath($f['directory'])!==$f['directory']||is_link($f['directory'])||(fileperms($f['directory'])&0077))throw new RuntimeException('Private owned K-browser result directory required.');
    $parent=$f['parent_config']??'';
    if(!is_string($parent)||!is_file($parent)||is_link($parent)||hash_file('sha256',$parent)!==($f['parent_sha256']??null))throw new RuntimeException('Original K-browser parent required.');
    $p=json_decode(file_get_contents($parent),true,32,JSON_THROW_ON_ERROR);
    if(($p['owner']??null)!=='isolated-native-ci'||($p['label']??null)!=='native-k-browser'||($p['host']??null)!=='127.0.0.1'||($p['port']??null)!==13371||!preg_match('/^kh_native_[a-f0-9]{24}$/D',$p['database']??''))throw new RuntimeException('Exact synthetic K-browser parent required.');
    foreach($f['source_proofs']??[]as$name=>$sha){if(!is_string($name)||str_contains($name,'..')||str_starts_with($name,'/')||!is_file($source.'/'.$name)||is_link($source.'/'.$name)||hash_file('sha256',$source.'/'.$name)!==$sha)throw new RuntimeException('Original K-browser source required.');}
    if(count($f['source_proofs']??[])<4)throw new RuntimeException('Bound K-browser source proofs required.');
    $config=(string)getenv('KH_NATIVE_TEST_CONFIG');$c=\Kulturhus\Native\TestSupport::config();
    foreach(['standalone','integrated']as$mode){$a=$f['apps'][$mode]??[];
        if(($a['config']??null)!==$config)continue;
        if(realpath($config)!==$config||is_link($config)||(fileperms($config)&0077)||dirname($config)!==$root||hash_file('sha256',$config)!==($a['config_sha256']??null)||($c['owner']??null)!=='isolated-k-browser'||($c['label']??null)!=='native-k-browser-'.$mode||$c['database']===($p['database']??null)||($a['database']??null)!==$c['database']||!str_starts_with($a['runtime']??'',$root.'/')||realpath($a['runtime'])!==$a['runtime']||is_link($a['runtime']))throw new RuntimeException('Exact fresh K-browser child required.');
        return $f+['standalone'=>$mode==='standalone','runtime'=>$a['runtime'],'origin'=>$a['origin'],'home_title'=>$a['home_title'],'home_body'=>$a['home_body']];
    }
    throw new RuntimeException('Configuration is outside the fresh K-browser pair.');
}
