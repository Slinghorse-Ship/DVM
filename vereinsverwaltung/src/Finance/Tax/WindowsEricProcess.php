<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Memory-only, authenticated loopback IO where Windows anonymous pipes block. */
final class WindowsEricProcess
{
    public static function run(array $runner,array $command,array $environment,#[\SensitiveParameter] string $input,float $seconds=100):array
    {
        if(!$runner||!is_file($runner[0])||$seconds<=0||$seconds>100)throw new DomainException('Windows-Prüflaufzeit ist nicht eingerichtet.');
        if(strlen($input)>8388608)throw new DomainException('ELSTER-Auftrag überschreitet das Größenlimit.');
        $listener=stream_socket_server('tcp://127.0.0.1:0',$error,$message,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
        if(!$listener)throw new DomainException('Lokale Prüferverbindung konnte nicht geöffnet werden.');
        $address=stream_socket_get_name($listener,false);$token=bin2hex(random_bytes(32));$deadline=microtime(true)+$seconds;$socket=null;$process=null;
        try{
            $environment['VEREIN_ERIC_PROCESS_TOKEN']=$token;$environment['VEREIN_ERIC_PROCESS_COMMAND']=json_encode($command,JSON_THROW_ON_ERROR);
            $runner[]=dirname(__DIR__, 2).'/../tools/eric-process.py';$runner[]=substr($address,strrpos($address,':')+1);
            $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
            $process=proc_open($runner,[0=>['file',$null,'r'],1=>['file',$null,'w'],2=>['file',$null,'w']],$pipes,null,$environment,['bypass_shell'=>true]);
            unset($environment['VEREIN_ERIC_PROCESS_TOKEN'],$environment['VEREIN_ERIC_PROCESS_COMMAND']);
            if(!is_resource($process))throw new DomainException('Windows-Prüfprogramm konnte nicht gestartet werden.');
            do{
                $socket=@stream_socket_accept($listener,0);
                if($socket)break;
                if(microtime(true)>$deadline||!proc_get_status($process)['running'])throw new DomainException('ELSTER hat nicht rechtzeitig geantwortet. Status prüfen.');
                usleep(10000);
            }while(true);
            stream_set_blocking($socket,false);
            $received=self::read($socket,64,$deadline);
            if(!hash_equals($token,$received))throw new DomainException('Lokale Prüferverbindung nicht authentifiziert.');
            self::write($socket,pack('N',strlen($input)).$input,$deadline);
            if(function_exists('sodium_memzero'))sodium_memzero($input);$input='';
            $length=unpack('Nlength',self::read($socket,4,$deadline))['length'];
            if($length<2||$length>25000000)throw new DomainException('ELSTER-Antwort überschreitet das Größenlimit.');
            $result=json_decode(self::read($socket,$length,$deadline),true,16,JSON_THROW_ON_ERROR);
            if(($result['failure']??'')==='deadline')throw new DomainException('ELSTER hat nicht rechtzeitig geantwortet. Eine angefangene Abgabe nicht erneut auslösen; Status prüfen.');
            if(isset($result['failure']))throw new DomainException('ELSTER-Antwort überschreitet das Größenlimit.');
            $out=base64_decode($result['stdout']??'',true);$err=base64_decode($result['stderr']??'',true);
            if(!is_string($out)||!is_string($err)||strlen($out)>16000000||strlen($err)>1000000||!is_int($result['exit']??null))throw new DomainException('ELSTER-Prozessantwort unvollständig.');
            return [$out,$err,$result['exit']];
        }finally{
            if(is_resource($socket))fclose($socket);fclose($listener);
            if(is_resource($process)){
                // Closing the socket asks the runner to stop its entire child tree.
                $cleanup=microtime(true)+1;
                while(proc_get_status($process)['running']&&microtime(true)<$cleanup)usleep(10000);
                if(proc_get_status($process)['running'])proc_terminate($process);
                proc_close($process);
            }
            if($input!==''&&function_exists('sodium_memzero'))sodium_memzero($input);
            if(function_exists('sodium_memzero'))sodium_memzero($token);
        }
    }
    private static function read($socket,int $length,float $deadline):string
    {
        $out='';while(strlen($out)<$length){
            if(microtime(true)>$deadline)throw new DomainException('ELSTER hat nicht rechtzeitig geantwortet. Status prüfen.');
            $part=fread($socket,min(65536,$length-strlen($out)));
            if($part===false||($part===''&&feof($socket)))throw new DomainException('Lokale Prüferverbindung unterbrochen.');
            $out.=$part;if($part==='')usleep(10000);
        }return $out;
    }
    private static function write($socket,#[\SensitiveParameter] string $bytes,float $deadline):void
    {
        $offset=0;while($offset<strlen($bytes)){
            if(microtime(true)>$deadline)throw new DomainException('ELSTER hat nicht rechtzeitig geantwortet. Status prüfen.');
            $n=fwrite($socket,substr($bytes,$offset,65536));
            if($n===false||($n===0&&feof($socket)))throw new DomainException('Lokaler Prüferauftrag konnte nicht übergeben werden.');
            $offset+=$n;if($n===0)usleep(10000);
        }
    }
}
