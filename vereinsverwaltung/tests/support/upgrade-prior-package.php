<?php declare(strict_types=1);
/** Immutable pre-upgrader package, created locally only in the synthetic runner's private directory. */
function ownUpgradePriorPackage(string $repository,string $directory): string
{
    if (getenv('KH_NATIVE_CI')!=='1') throw new RuntimeException('Synthetic package fixture only.');
    require_once $repository.'/distribution/src/PackageStore.php';
    $archive=$directory.'/prior-package.tar'; $target=$directory.'/prior-package';
    $process=proc_open(['git','archive','--format=tar','--output='.$archive,'7ec486affedcfbeb1af8f8ef53a3df0016e3cb50'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repository);
    if(!is_resource($process))throw new RuntimeException('Prior package fixture unavailable.');
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0 || $output!=='' || $errors!=='')throw new RuntimeException('Upgrade regression requires local Git object 7ec486a (fetch-depth 0); no network requested by this test.');
    PackageStore::extract($archive,$target);unlink($archive);
    (new PackageStore($target.'/distribution'))->verify();
    return $target;
}
