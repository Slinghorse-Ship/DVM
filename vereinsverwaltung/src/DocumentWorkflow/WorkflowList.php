<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;

/** Small metadata pages; count and rows share the caller's original read snapshot. */
final class WorkflowList
{
    public const SIZE=25;
    public static function number(mixed $page):int
    {
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new WorkflowException('Gültige Workflow-Listenseite angeben.');
        return (int)$page;
    }
    public static function rows(array $rows,mixed $page):array
    {
        $number=self::number($page);$total=count($rows);$pages=max(1,(int)ceil($total/self::SIZE));$number=min($number,$pages);
        return ['rows'=>array_slice($rows,($number-1)*self::SIZE,self::SIZE),'total'=>$total,'page'=>$number,'pages'=>$pages,'size'=>self::SIZE];
    }
    public static function query(\PDO $db,string $query,string $count,array $parameters,mixed $page,?callable $map=null):array
    {
        $number=self::number($page);$owned=!$db->inTransaction();if($owned)$db->beginTransaction();
        try{
            $statement=$db->prepare($count);$statement->execute($parameters);$total=(int)$statement->fetchColumn();$pages=max(1,(int)ceil($total/self::SIZE));$number=min($number,$pages);
            $statement=$db->prepare($query.' LIMIT '.self::SIZE.' OFFSET '.(($number-1)*self::SIZE));$statement->execute($parameters);$rows=$statement->fetchAll(\PDO::FETCH_ASSOC);if($map!==null)$rows=array_map($map,$rows);
            if($owned)$db->commit();return ['rows'=>$rows,'total'=>$total,'page'=>$number,'pages'=>$pages,'size'=>self::SIZE];
        }catch(\Throwable $failure){if($owned&&$db->inTransaction())$db->rollBack();throw $failure;}
    }
}
