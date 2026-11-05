<?php declare(strict_types=1);
/** Controlled fixture action only: review the exact present original before calling the versioned service. */
function ownReviewedDocumentShare(\Kulturhus\Verein\PortalDocuments $portal,\Kulturhus\Verein\Documents $documents,string $id,string $contact,bool $enabled):void{
 $review=$documents->document($id);$portal->share($id,$contact,$enabled,(int)$review['version'],(int)$review['revision']);
}
