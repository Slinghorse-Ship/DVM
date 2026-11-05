<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Communication\VideoRecovery;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-video-recovery'||(TestSupport::config()['owner']??'')!=='isolated-native-ci'||!function_exists('posix_kill'))exit(1);
$input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);$db=TestSupport::db();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.TestSupport::config()['database'];
$actor=new class($db,$input,$root) extends Access {
    public function __construct(private PDO $ownedDb,private array $input,private string $root){parent::__construct($ownedDb,'admin');}
    public function require(string $module,string $cap):array{
        if($module==='social'&&$cap==='edit'){
            $row=Db::run($this->ownedDb,'SELECT * FROM native_video_recoveries WHERE original_id=?',[$this->input['source']])->fetch();
            if($row){$path=$this->root.'/communication-videos/'.hash('sha256',$row['club_id']).'/'.$row['target_id'].'.mp4';$copied=is_file($path)&&hash_file('sha256',$path)===$row['sha256'];
                if(($this->input['stage']==='intent'&&!file_exists($path))||($this->input['stage']==='copied'&&$copied))posix_kill(getmypid(),9);
            }
        }
        return parent::require($module,$cap);
    }
};
(new VideoRecovery($db,$actor,$root.'/media'))->recover($input['source'],$input['fingerprint'],$input['item'],1,0,'Own process-abort original.mp4');
exit(1);
