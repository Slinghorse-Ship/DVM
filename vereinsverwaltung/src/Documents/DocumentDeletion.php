<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Intake deletion only. Previously used and issued originals remain protected. */
trait DocumentDeletion
{
    public function deleteFolder(string $id,int $version):?string
    {
        return $this->tx(function()use($id,$version){
            $club=$this->actor()['club_id'];
            $this->q('SELECT id FROM document_folders WHERE club_id=? AND id=? FOR UPDATE',[$club,$id]);
            $folder=$this->rawFolder($id);$this->allow([$folder['scope']],true,DocumentProtection::folderRestricted($this->db,$club,$id));self::checkVersion($folder,$version);
            if($this->q('SELECT 1 FROM document_folders WHERE club_id=? AND parent_id=? LIMIT 1',[$club,$id])->fetchColumn())throw new DomainException('Bitte zuerst die Unterordner verschieben oder löschen.');
            if($this->q('SELECT 1 FROM documents WHERE club_id=? AND folder_id=? LIMIT 1',[$club,$id])->fetchColumn())throw new DomainException('Bitte zuerst die Dateien in einen anderen Ordner verschieben. Auch Archiv und Papierkorb gehören zum Ordner.');
            if($this->q('SELECT 1 FROM document_sources WHERE club_id=? AND folder_id=? LIMIT 1',[$club,$id])->fetchColumn())throw new DomainException('Dieser Ordner gehört zu einem Vorgang und wird dafür noch benötigt.');
            $this->audit($id,'folder-deleted',$version+1,['name'=>$folder['name'],'parent'=>$folder['parent_id'],'scope'=>$folder['scope']]);
            $q=$this->q('DELETE FROM document_folders WHERE club_id=? AND id=? AND version=?',[$club,$id,$version]);
            if($q->rowCount()!==1)throw new DomainException('Ordner inzwischen geändert. Bitte neu laden.');
            return $folder['parent_id'];
        });
    }
    private function assertUnassignedReceipt(array $doc):void
    {
        if($doc['origin']!=='upload'||$doc['kind']!=='receipt'||!empty($doc['admission_only'])||$doc['links']||$doc['references'])throw new DomainException('Nur nicht zugeordnete Eingangsrechnungen können gelöscht werden.');
        // Removing a current link must never make a previously used original disposable.
        foreach($this->q('SELECT action,details FROM document_history WHERE club_id=? AND document_id=?',[$doc['club_id'],$doc['id']]) as $event){
            $details=json_decode($event['details'],true);
            if(!is_array($details))throw new DomainException('Dokumentverlauf zuerst prüfen.');
            if($event['action']==='expense-linked'||!empty($details['links'])||!empty($details['before']['links'])||!empty($details['after']['links']))throw new DomainException('Diese Rechnung wurde bereits verwendet. Das Original bleibt erhalten.');
        }
        // Include future business references automatically. Technical import metadata is
        // disposable; the opaque request hash separately prevents mail reimport.
        $technical=['document_files','document_links','invoice_recognition','einvoice_validations','invoice_mail_imports','invoice_mail_original_links','document_purge_requests'];
        $refs=$this->q("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND ((REFERENCED_TABLE_NAME='documents' AND REFERENCED_COLUMN_NAME='id') OR (REFERENCED_TABLE_NAME='document_files' AND REFERENCED_COLUMN_NAME='document_id'))")->fetchAll();
        foreach($refs as $ref){
            $table=$ref['TABLE_NAME'];$column=$ref['COLUMN_NAME'];if(in_array($table,$technical,true))continue;
            if(!preg_match('/^[a-z_0-9]+$/D',$table)||!preg_match('/^[a-z_0-9]+$/D',$column))throw new DomainException('Dokumentverwendung zuerst prüfen.');
            if($this->q("SELECT 1 FROM `$table` WHERE club_id=? AND `$column`=? LIMIT 1",[$doc['club_id'],$doc['id']])->fetchColumn())throw new DomainException('Diese Rechnung wird noch verwendet und kann nicht gelöscht werden.');
        }
    }
    public function canTrashReceipt(string $id):bool
    {
        try{$doc=$this->document($id,true);$this->assertUnassignedReceipt($doc);return true;}catch(DomainException){return false;}
    }
    public function trashReceipt(string $id,int $version):void
    {
        $this->tx(function()use($id,$version){
            $doc=$this->document($id,true);self::checkVersion($doc,$version);$this->assertUnassignedReceipt($doc);
            $this->q('UPDATE documents SET version=version+1 WHERE club_id=? AND id=?',[$doc['club_id'],$id]);
            $this->audit($id,'trashed',$version+1,['reason'=>'Nicht zugeordnete Eingangsrechnung gelöscht','sha256'=>$doc['sha256']]);
        });
    }
    public function restoreReceipt(string $id,int $version):void
    {
        $this->tx(function()use($id,$version){
            $doc=$this->document($id,true,true);self::checkVersion($doc,$version);
            if(!$doc['trashed'])throw new DomainException('Diese Rechnung ist nicht im Papierkorb.');
            $this->assertUnassignedReceipt($doc);
            $this->q("UPDATE documents SET state='active',version=version+1 WHERE club_id=? AND id=?",[$doc['club_id'],$id]);
            $this->audit($id,'trash-restored',$version+1,['reason'=>'Eingangsrechnung aus Papierkorb wiederhergestellt','sha256'=>$doc['sha256']]);
        });
    }
    /** A minimal request/hash tombstone prevents a deleted mail attachment reappearing. */
    public function purgedUploadRequest(string $key):bool
    {
        $a=$this->actor();$this->allow(['finance']);
        return (bool)$this->q("SELECT 1 FROM document_requests r JOIN document_history h ON h.club_id=r.club_id AND h.document_id=r.document_id AND h.action='receipt-purged' WHERE r.club_id=? AND r.request_key=? LIMIT 1",[$a['club_id'],$key])->fetchColumn();
    }
    public function canPurgeReceipt(string $id):bool
    {
        try{$doc=$this->document($id,true,true);$this->assertUnassignedReceipt($doc);return true;}catch(DomainException){return false;}
    }
    public function purgeReceipt(string $id,int $version,bool $notRequired):void
    {
        $this->tx(function()use($id,$version,$notRequired){
            $doc=$this->document($id,true,true);self::checkVersion($doc,$version);$this->assertUnassignedReceipt($doc);
            if(!$notRequired)throw new DomainException('Bitte bestätigen, dass es sich um einen Fehlimport oder ein nicht benötigtes Duplikat handelt. Aufbewahrungspflichtige Originale nicht löschen.');
            $club=$doc['club_id'];
            $mail=$this->q('SELECT mailbox_key,message_uid FROM invoice_mail_original_links WHERE club_id=? AND document_id=?',[$club,$id])->fetchAll();
            $this->q('INSERT INTO document_purge_requests(club_id,document_id,document_version,connection_id) VALUES(?,?,?,CONNECTION_ID())',[$club,$id,$version]);
            foreach($mail as $ref){
                $this->q('SELECT mailbox_key FROM invoice_mail_originals WHERE club_id=? AND mailbox_key=? AND message_uid=? FOR UPDATE',[$club,$ref['mailbox_key'],$ref['message_uid']]);
                $this->q('INSERT INTO document_purge_mail_requests(club_id,document_id,mailbox_key,message_uid) VALUES(?,?,?,?)',[$club,$id,$ref['mailbox_key'],$ref['message_uid']]);
            }
            // Foreign keys protect against a newly created business reference as well.
            foreach(['invoice_recognition','einvoice_validations','invoice_mail_imports','invoice_mail_original_links','document_files'] as $table)$this->q('DELETE FROM '.$table.' WHERE club_id=? AND document_id=?',[$club,$id]);
            foreach($mail as $ref){
                $args=[$club,$ref['mailbox_key'],$ref['message_uid']];
                if($this->q('SELECT 1 FROM invoice_mail_original_links WHERE club_id=? AND mailbox_key=? AND message_uid=? LIMIT 1',$args)->fetchColumn())continue;
                $this->q('DELETE FROM invoice_mail_only WHERE club_id=? AND mailbox_key=? AND message_uid=?',$args);
                $this->q('DELETE FROM invoice_mail_events WHERE club_id=? AND mailbox_key=? AND message_uid=?',$args);
                $this->q('DELETE FROM invoice_mail_originals WHERE club_id=? AND mailbox_key=? AND message_uid=?',$args);
            }
            // No title, filename, addresses, OCR or document content retained in history.
            $this->q('DELETE FROM document_history WHERE club_id=? AND document_id=?',[$club,$id]);
            $this->q('DELETE FROM document_purge_requests WHERE club_id=? AND document_id=? AND connection_id=CONNECTION_ID()',[$club,$id]);
            $deleted=$this->q('DELETE FROM documents WHERE club_id=? AND id=? AND version=?',[$club,$id,$version]);
            if($deleted->rowCount()!==1)throw new DomainException('Rechnung inzwischen geändert. Bitte neu laden.');
            $this->audit($id,'receipt-purged',$version+1,['sha256'=>$doc['sha256'],'reason'=>'Fehlimport oder nicht benötigtes Duplikat ausdrücklich bestätigt']);
        });
    }
}
