<?php declare(strict_types=1);
/** Private, noninteractive native installation. Credentials arrive only through stdin. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/vereinsverwaltung/native/bootstrap.php';
try {
    if (in_array('--help',$argv,true)) {
        echo "Native Installation: php distribution/install-native.php --config-stdin [--resume]\nJSON: target, dsn, username, password, club_id, club_name, hosts, admin_email, admin_password.\nOptionales JSON-Feld scheduler_php: bestätigter PHP-CLI-Pfad für die Hostinganleitung.\nNeues privates Ziel außerhalb des Checkouts und leere MySQL-Datenbank erforderlich. HTTPS-Webroot anschließend auf <target>/web setzen.\n";
        exit;
    }
    if (!in_array('--config-stdin',$argv,true) || array_diff(array_slice($argv,1),['--config-stdin','--resume'])) throw new DomainException('Mit --help die privaten Einrichtungsangaben prüfen.');
    $raw = stream_get_contents(STDIN,65537);
    if (strlen($raw)>65536) throw new DomainException('Konfiguration zu groß.');
    $config = json_decode($raw,true,16,JSON_THROW_ON_ERROR); unset($raw);
    if (!is_array($config) || !str_starts_with($config['dsn']??'','mysql:')) throw new DomainException('Private MySQL-Konfiguration erforderlich.');
    $db = new PDO($config['dsn'],$config['username'],$config['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    $result=(new Kulturhus\Native\Installer\Install(dirname(__DIR__)))->run($config,$db,in_array('--resume',$argv,true));
    unset($config); echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    // PDO diagnostics can contain connection information. Never serialize supplied configuration.
    fwrite(STDERR,$e instanceof DomainException ? $e->getMessage()."\n" : "Native Installation nicht abgeschlossen. Private Konfiguration und Paketprüfung prüfen.\n"); exit(1);
}
