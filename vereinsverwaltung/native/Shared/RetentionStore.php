<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
use PDO;
use Kulturhus\Native\Cms\Conflict;

/** Shared persistence only. Each module still owns rights, input and captured removal deadlines. */
final class RetentionStore
{
    public const DEFAULT_DAYS=30;
    public const MAX_DAYS=3650;
    private const SCOPES=[
        'images'=>['khcms_media_retention','khcms_media_retention_history','utf8mb4_unicode_ci'],
        'videos'=>['native_video_retention','native_video_retention_history','utf8mb4_bin'],
        'files'=>['khcms_file_retention','khcms_file_retention_history','utf8mb4_bin'],
    ];
    private static function tables(string $scope):array
    {return self::SCOPES[$scope]??throw new \DomainException('Unbekannter Aufbewahrungsbereich.');}
    public static function count(PDO $db,string $scope):int
    {
        [$current,$history]=self::tables($scope);
        return (int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?) AND TABLE_TYPE='BASE TABLE'",[$current,$history])->fetchColumn();
    }
    public static function install(PDO $db,string $scope):void
    {
        [$current,$history,$collation]=self::tables($scope);
        foreach([$current=>'updated_by',$history=>'actor_id']as$table=>$actor){
            $key=$table===$current?'PRIMARY KEY(club_id)':'PRIMARY KEY(club_id,version)';
            $db->exec("CREATE TABLE IF NOT EXISTS $table (club_id VARCHAR(64) NOT NULL,version INT UNSIGNED NOT NULL,minimum_days SMALLINT UNSIGNED NOT NULL,$actor VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,$key) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$collation");
        }
    }
    public static function current(PDO $db,string $scope,string $club,string $incomplete):array
    {
        $count=self::count($db,$scope);
        if($count===0)return ['version'=>0,'minimum_days'=>self::DEFAULT_DAYS];
        if($count!==2)throw new \DomainException($incomplete);
        [$table]=self::tables($scope);
        $row=Db::run($db,'SELECT version,minimum_days FROM '.$table.' WHERE club_id=?'.($db->inTransaction()?' FOR SHARE':''),[$club])->fetch();
        if(!$row)return ['version'=>0,'minimum_days'=>self::DEFAULT_DAYS];
        if((int)$row['version']<1||(int)$row['minimum_days']<0||(int)$row['minimum_days']>self::MAX_DAYS)throw new \DomainException('Gespeicherte Aufbewahrungsregel prüfen.');
        return ['version'=>(int)$row['version'],'minimum_days'=>(int)$row['minimum_days']];
    }
    /** Caller holds its club mutex and current module authority throughout the transaction. */
    public static function write(PDO $db,string $scope,string $club,int $days,int $version,string $actor,string $conflict):array
    {
        if(!$db->inTransaction()||$days<0||$days>self::MAX_DAYS||$version<0||$version>=4294967295||self::count($db,$scope)!==2)throw new \DomainException('Aufbewahrung benötigt einen aktuellen eigenen Datenbankvorgang.');
        if(self::current($db,$scope,$club,'Aufbewahrungsinstallation unvollständig.')['version']!==$version)throw new Conflict($conflict);
        [$table,$history]=self::tables($scope);$args=[$club,$version+1,$days,$actor];
        Db::run($db,'INSERT INTO '.$table.' VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),minimum_days=VALUES(minimum_days),updated_by=VALUES(updated_by),created_at=VALUES(created_at)',$args);
        Db::run($db,'INSERT INTO '.$history.' VALUES(?,?,?,?,UTC_TIMESTAMP(6))',$args);
        return self::current($db,$scope,$club,'Aufbewahrungsinstallation unvollständig.');
    }
}
