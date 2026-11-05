<?php declare(strict_types=1);
require __DIR__.'/free-list-interface-browser-fixture.php';
$fixture=ownFreeListInterfaceBrowser($db,['cases'=>$cases,'literal'=>$literal,'label_contact'=>$labelContact,'first'=>$first,'foreign'=>$foreignContact,'page'=>$archive->page()]);
$root=$fixture['input']['root'];$input=$root.'/member-archive-input.json';file_put_contents($input,json_encode($fixture['input'],JSON_THROW_ON_ERROR));
$protected=array_values(array_diff($tables,['native_auth_sessions','native_auth_limits','native_language_preferences']));$beforeBrowser=ownOriginalDatabaseHash($db,$protected);
$keys=[];foreach(['auth','private']as$name)$keys[$name]=hash_file('sha256',$root.'/'.$name.'.key');$fileHashes=[];
foreach($db->query('SELECT document_id,revision FROM document_files')->fetchAll()as$file)$fileHashes[$file['document_id'].':'.$file['revision']]=hash('sha256',$docs->file($file['document_id'],(int)$file['revision'])['bytes']);
$people=array_column($cases,'person');$folders=array_values($caseFolders);$lastAudit=(int)$db->query('SELECT MAX(sequence) FROM audit_events')->fetchColumn();
$caseFilter=static function(string$table)use($people,$folders,$lastAudit):array{
    $in=implode(',',array_fill(0,count($people),'?'));
    return match($table){
        'contacts'=>[' WHERE id NOT IN ('.$in.')',$people],
        'contact_archives'=>[' WHERE contact_id NOT IN ('.$in.')',$people],
        'document_sources'=>[" WHERE NOT(source_type='contact' AND source_id IN (".$in.'))',$people],
        'document_folders'=>[' WHERE id NOT IN ('.implode(',',array_fill(0,count($folders),'?')).')',$folders],
        'audit_events'=>[' WHERE sequence<=? OR object_id NOT IN ('.$in.')',[$lastAudit,...$people]],
        default=>['',[]],
    };
};
$saveBefore=ownOriginalDatabaseHash($db,$protected,$caseFilter);$sourcesBefore=[];$foldersBefore=[];
foreach($people as$person){$sourcesBefore[$person]=$db->query("SELECT * FROM document_sources WHERE source_type='contact' AND source_id=".$db->quote($person))->fetch();$foldersBefore[$person]=$db->query('SELECT * FROM document_folders WHERE id='.$db->quote($caseFolders[$person]))->fetch();}
$readCompleted=false;$saveStarted=false;
try{
    $run=static function(string$phase)use($input):void{$p=proc_open(['node',dirname(__DIR__).'/member-archive-browser.mjs',$input,$phase],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($p)||proc_close($p)!==0)throw new RuntimeException('Actual protected archive '.$phase.' browser failed.');};
    if(getenv('DVM_MEMBER_ARCHIVE_ONLY_SAVE')!=='1'){$run('read');$check(ownOriginalDatabaseHash($db,$protected)===$beforeBrowser,'all complete business originals remain exact through actual bilingual archive reads and refused requests');$readCompleted=true;}
    $saveStarted=true;$run('save');
    $check(ownOriginalDatabaseHash($db,$protected,$caseFilter)===$saveBefore,'every complete unrelated original and every earlier audit remain exact through eight deliberate archive cycles and restorations');
    foreach($cases as$case){
        $person=$case['person'];$actual=$registry->contact($person);$expected=array_replace($caseOriginals[$person],['name'=>$case['current_name'],'notes'=>$case['current_note'],'version'=>6]);
        $check($actual===$expected,'exact original profile fields restored once with the reviewed later correction '.$case['tag']);
        $check((int)$db->query('SELECT active FROM actors WHERE id='.$db->quote($caseActors[$person]))->fetchColumn()===0,'original linked account remains blocked '.$case['tag']);
        $check((int)$db->query('SELECT COUNT(*) FROM contact_archives WHERE contact_id='.$db->quote($person))->fetchColumn()===0,'only the deliberately restored encrypted archive is removed '.$case['tag']);
        $source=$db->query("SELECT * FROM document_sources WHERE source_type='contact' AND source_id=".$db->quote($person))->fetch();
        $check($source===array_replace($sourcesBefore[$person],['label'=>$case['current_name'],'available'=>1]),'original document source identity date and folder survive the restore '.$case['tag']);
        $folder=$db->query('SELECT * FROM document_folders WHERE id='.$db->quote($caseFolders[$person]))->fetch();$expectedFolder=array_replace($foldersBefore[$person],['name'=>\Kulturhus\Verein\DocumentCatalog::segment($case['current_name']).' · '.substr($person,0,8)]);
        $check($folder===$expectedFolder,'original folder identity policy and parent stay exact '.$case['tag']);
        $audit=$db->query('SELECT action FROM audit_events WHERE sequence>'.$lastAudit.' AND object_id='.$db->quote($person).' ORDER BY sequence')->fetchAll(PDO::FETCH_COLUMN);
        $check($audit===['contact.restored','contact.saved','contact.archived','contact.restored'],'exactly one correction cycle and one acknowledged public restore without replay audit '.$case['tag']);
    }
}finally{
    $same=$saveStarted?ownOriginalDatabaseHash($db,$protected,$caseFilter)===$saveBefore:ownOriginalDatabaseHash($db,$protected)===$beforeBrowser;
    $sameFiles=true;foreach($fileHashes as$key=>$hash){[$doc,$revision]=explode(':',$key);if(hash('sha256',$docs->file($doc,(int)$revision)['bytes'])!==$hash)$sameFiles=false;}
    $sameKeys=true;foreach($keys as$name=>$hash)if(hash_file('sha256',$root.'/'.$name.'.key')!==$hash)$sameKeys=false;
    $evidence=\Kulturhus\Native\TestSupport::evidenceDirectory();foreach(glob($root.'/member-archive-*')as$file)if(is_file($file))copy($file,$evidence.'/'.basename($file));
    file_put_contents($evidence.'/originals.json',json_encode(['read_completed'=>$readCompleted,'protected_tables'=>count($protected),'whole_unrelated_originals_exact'=>$same,'earlier_file_revisions'=>count($fileHashes),'all_earlier_file_bytes_exact'=>$sameFiles,'same_private_keys_exact'=>$sameKeys],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    foreach($fixture['servers']as$index=>[$p,$log]){proc_terminate($p);proc_close($p);$meta=stream_get_meta_data($log);fflush($log);if(is_file($meta['uri']))copy($meta['uri'],$evidence.'/server-'.$index.'.log');fclose($log);}
    $check($same&&$sameFiles&&$sameKeys,'all protected whole originals every earlier private file revision and both private keys stay exact even on archive qualification failure');
}
