<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Settings;
use Kulturhus\Native\Web\{Workspace as BaseWorkspace, Html};
final class Workspace extends BaseWorkspace
{
    public function action(array $post,array $files=[]): array
    {
        $this->validatePost($post);
        $op = (string) ($post["operation"] ?? "");
        $version = (int) ($post["version"] ?? 0);

        if($op==='meta-webhook-save'){
            if(($post['webhook_present']??null)!=='1'||(isset($post['enabled'])&&$post['enabled']!=='1'))throw new \DomainException('Webhook-Empfang prüfen.');
            (new \Kulturhus\Native\Communication\MetaWebhookConfiguration($this->db,$this->box()))->save($this->access,['enabled'=>isset($post['enabled']),'app_secret'=>$post['app_secret']??'','verify_token'=>$post['verify_token']??''],IndexingPanel::version($post));
        }elseif($op==='meta-webhook-resume'){
            if(!is_string($post['receipt_id']??null))throw new \DomainException('Eigenen Eingang auswählen.');(new \Kulturhus\Native\Communication\MetaWebhookWorker($this->db,$this->box()))->resume($this->access,$post['receipt_id']);
            return ['view'=>'settings','webhook_focus'=>$post['receipt_id'],'notice'=>'Eingang wird erneut lesend geprüft.'];
        }elseif ($op === 'event-workflow') {
            (new \Kulturhus\Native\Events\Workflow($this->db,$this->access))->save(EventWorkflowPanel::input($post),IndexingPanel::version($post));
        } elseif ($op === 'website-design') {
            try {
                (new \Kulturhus\Native\Cms\WebsiteDesign($this->db,$this->access))->useTemplate($post['website_template']??null,IndexingPanel::version($post));
            } catch (\DomainException $error) {
                throw new \InvalidArgumentException($error->getMessage(),0,$error);
            }
        } elseif ($op === 'news-policy') {
            (new \Kulturhus\Native\Cms\NewsPolicy($this->db,$this->access))->save(NewsPolicyPanel::choice($post),IndexingPanel::version($post));
        } elseif ($op === "website-indexing") {
            (new \Kulturhus\Native\Cms\WebsiteSettings(
                $this->db,
                $this->access,
            ))->save(
                IndexingPanel::choice($post),
                IndexingPanel::version($post),
            );
        } elseif ($op === "club-settings") {
            $version=IndexingPanel::version($post);
            $identity=new \Kulturhus\Native\Shared\Identity($this->db,$this->access);
            $this->access->require('website','manage');
            if(isset($post['logo_upload_required'])&&$post['logo_upload_required']!=='1')throw new \InvalidArgumentException('Logoauswahl prüfen.');
            $file=$files['logo_photo']??null;
            if(($post['logo_upload_required']??null)==='1'&&(!is_array($file)||($file['error']??null)===UPLOAD_ERR_NO_FILE))throw new \InvalidArgumentException('Das neue Logo wurde nicht übernommen. Bitte die Datei erneut auswählen.');
            if($file!==null&&(!is_array($file)||!is_int($file['error']??null)))throw new \InvalidArgumentException('Logoupload prüfen.');
            if(is_array($file)&&$file['error']!==UPLOAD_ERR_NO_FILE){
                if($file['error']!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null))throw new \InvalidArgumentException('Logo konnte nicht hochgeladen werden. Bild erneut auswählen.');
                $upload=$post['logo_upload_id']??null;
                if(!is_string($upload)||!preg_match('/\A[a-f0-9]{32}\z/D',$upload))throw new \InvalidArgumentException('Logoauswahl neu öffnen.');
                if(\Kulturhus\Native\Shared\Db::run($this->db,'SELECT 1 FROM khcms_media WHERE club_id=? AND id=?',[$this->access->club(),$upload])->fetchColumn())throw new \InvalidArgumentException('Das Logo ist bereits hochgeladen. Lade die gespeicherten Website-Angaben neu.');
                (new \Kulturhus\Native\Cms\Media($this->db,$this->access,$this->mediaRoot))->upload($file['tmp_name'],$file['name'],'Vereinslogo',null,function(array $image)use($identity,$post,$version):void{$identity->save(array_replace($post,['logo_id'=>$image['id']]),$version);},$upload);
            }else $identity->save($post,$version);
        } elseif ($op === "mail-account") {
            (new \Kulturhus\Native\Delivery\MailAccount(
                $this->db,
                $this->box(),
            ))->save($this->access, $post, $version);
        } elseif ($op === "mail-account-migrate") {
            (new \Kulturhus\Native\Delivery\MailAccount(
                $this->db,
                $this->box(),
            ))->migrate(
                $this->access,
                $version,
                (int) ($post["legacy_version"] ?? 0),
            );
        } elseif (
            in_array(
                $op,
                [
                    "stadtlandfunk-connect",
                    "stadtlandfunk-verify",
                    "stadtlandfunk-senders",
                    "stadtlandfunk-sender",
                    "stadtlandfunk-disconnect",
                ],
                true,
            )
        ) {
            $connection = new \Kulturhus\Native\Integration\StadtLandFunk\Connection(
                $this->db,
                $this->box(),
            );
            if ($op === "stadtlandfunk-connect") {
                if (
                    !is_string($post["username"] ?? null) ||
                    !is_string($post["password"] ?? null)
                ) {
                    throw new \DomainException(
                        "Anmeldename und Passwort prüfen.",
                    );
                }
                $connection->connect(
                    $this->access,
                    $post["username"],
                    $post["password"],
                    $version,
                    isset($post["remember_password"]),
                );
            } elseif ($op === "stadtlandfunk-verify") {
                $connection->verify($this->access, $version);
            } elseif ($op === 'stadtlandfunk-senders') {
                $connection->loadSenders($this->access, IndexingPanel::version($post));
            } elseif ($op === 'stadtlandfunk-sender') {
                if (!is_string($post['organization_id']??null)) throw new \DomainException('Absender auswählen.');
                $connection->selectSender($this->access, $post['organization_id'], IndexingPanel::version($post));
            } else {
                if (!isset($post["disconnect_confirm"])) {
                    throw new \DomainException(
                        "Entfernen des gespeicherten Zugangs bestätigen.",
                    );
                }
                $connection->disconnect($this->access, $version);
            }
        } elseif ($op === "api-account-disconnect") {
            if (!isset($post["disconnect_confirm"])) {
                throw new \DomainException(
                    "Entfernen des gespeicherten Zugangs bestätigen.",
                );
            }
            (new \Kulturhus\Native\Integration\Accounts(
                $this->db,
                $this->box(),
            ))->disconnect(
                $this->access,
                (string) ($post["provider"] ?? ""),
                $version,
            );
        } elseif ($op === "api-account") {
            (new \Kulturhus\Native\Integration\Accounts(
                $this->db,
                $this->box(),
            ))->save(
                $this->access,
                (string) ($post["provider"] ?? ""),
                $post,
                $version,
            );
        } else {
            throw new \DomainException("Unbekannte Einstellungsaktion.");
        }
        return ["view" => "settings", "notice" => "Einstellungen gespeichert."];
    }
    public function manager(array $webhookFailure=[]): string
    {
        $identity = new \Kulturhus\Native\Shared\Identity(
            $this->db,
            $this->access,
        );
        $c = $identity->get();
        $source = $identity->source();
        $shared = $source !== null;
        $failed=http_response_code()>=400&&($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??'')==='club-settings'&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf']);
        if($failed){
            foreach($shared?['origin']:['name','origin','address'] as $key)if(is_string($_POST[$key]??null)&&strlen($_POST[$key])<=4000&&preg_match('//u',$_POST[$key])&&!preg_match('/[\x00-\x1f\x7f]/',$_POST[$key]))$c[$key]=$_POST[$key];
            try{$c['version']=IndexingPanel::version($_POST);}catch(\DomainException){}
            if($shared&&is_string($_POST['club_version']??null)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$_POST['club_version']))$source['version']=$_POST['club_version'];
        }
        $h =
            DesignPanel::render($this->db, $this->access, $this->csrf) .
            "<h2>Gemeinsame Vereinsdaten</h2>".($failed?'<p><a data-nav href="/?view=settings">Gespeicherte Website-Angaben neu laden</a></p>':'');
        if ($source) {
            $h .=
                '<p><a href="/vereinsverwaltung/?view=settings&amp;section=club">Vereinsdaten und Briefpapier im Hauptmanager bearbeiten</a></p>';
            if ($source["configured"]) {
                $h .=
                    "<p><strong>" .
                    Html::e($c["name"]) .
                    "</strong><br>" .
                    nl2br(Html::e($c["address"])) .
                    "</p>";
                if ($source["previous"]) {
                    $h .=
                        "<details><summary>Frühere abweichende Website-Angaben</summary><p>" .
                        Html::e($source["previous"]["name"]) .
                        "<br>" .
                        nl2br(Html::e($source["previous"]["address"])) .
                        "</p><p>Die gemeinsamen Vereinsdaten gelten für neue Inhalte. Bereits ausgegebene Originale behalten ihre gespeicherten Angaben.</p></details>";
                }
            } else {
                $h .=
                    "<p>Gemeinsame Vereinsdaten noch nicht gespeichert. Bis dahin gelten die bisherigen Website-Angaben.</p>";
            }
        }
        $h .=
            str_replace('<form method="post">','<form method="post" enctype="multipart/form-data">',$this->start("club-settings")) .
            Html::hidden("version", $c["version"]);
        if ($shared) {
            $h .= Html::hidden("club_version", $source["version"]);
        } else {
            $h .=
                Html::input(
                    "name",
                    "Vereinsname",
                    $c["name"],
                    "text",
                    "required",
                ) . Html::input("address", "Vereinsanschrift", $c["address"]);
        }
        $h .=
            Html::input(
                "origin",
                "Website-Domain (HTTPS)",
                $c["origin"],
                "url",
                "required",
            ) .
            $this->logoPicker($c["logo_id"]) .
            '<button class="btn btn-solid theme-primary">Website-Angaben speichern</button></form>';
        $technicalOpen=$webhookFailure!==[]||(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??'')!=='club-settings'&&($_POST['operation']??'')!=='website-design');
        $h.='<details class="panel website-technical-setup" id="website-technical-setup"'.($technicalOpen?' open':'').'><summary>Technische Einrichtung &amp; Anbieter</summary><p>Für die technische Betreuung: Suchmaschinen, Veranstaltungsquelle, Ticketweg, Mailzugänge und Anbieteranbindungen einrichten.</p>'.IndexingPanel::render($this->db,$this->access,$this->csrf).NewsPolicyPanel::render($this->db,$this->access,$this->csrf).EventWorkflowPanel::render($this->db,$this->access,$this->csrf);
        try {
            $status = (new \Kulturhus\Native\Delivery\MailAccount(
                $this->db,
                $this->box(),
            ))->status($this->access);
            if ($status["shared"]) {
                $h .=
                    "<h2>Gemeinsame Postfächer und Versandzwecke</h2><p>Newsletter und Bestätigungen verwenden das zugeordnete Postfach „" .
                    Html::e(
                        \Kulturhus\Verein\MailChannelSettings::CHANNELS[
                            $status["channel"]
                        ],
                    ) .
                    '“.</p><p><a href="/vereinsverwaltung/?view=settings&amp;section=mail#mail-postfaecher">SMTP-Postfächer und Versandzuordnungen bearbeiten</a> · <a href="/vereinsverwaltung/?view=settings&amp;section=mail#rechnungsfach">Rechnungsfach mit IMAP-Abruf bearbeiten</a></p>';
                if ($status["legacy_version"]) {
                    $h .=
                        "<p>Ein früherer Website-Mailzugang ist noch separat gespeichert. Die Übernahme verwendet das zugeordnete gemeinsame Postfach und meldet vorhandene abweichende Einstellungen.</p>" .
                        $this->start("mail-account-migrate") .
                        Html::hidden("version", $status["version"]) .
                        Html::hidden(
                            "legacy_version",
                            $status["legacy_version"],
                        ) .
                        '<button class="btn btn-solid theme-primary">Bisherigen Website-Zugang übernehmen</button></form>';
                }
            } else {
                $h .=
                    "<h2>Zentraler Mailzugang</h2><p>" .
                    ($status["configured"]
                        ? "Verschlüsselter Zugang eingerichtet."
                        : "Noch kein Zugang eingerichtet.") .
                    "</p>" .
                    $this->start("mail-account") .
                    Html::hidden("version", $status["version"]) .
                    Html::input(
                        "host",
                        "SMTP-Hostname",
                        "",
                        "text",
                        "required",
                    ) .
                    Html::select(
                        "port",
                        "TLS-Port",
                        ["587" => "587 · STARTTLS", "465" => "465 · TLS"],
                        "587",
                    ) .
                    Html::input(
                        "username",
                        "Benutzername",
                        "",
                        "text",
                        'autocomplete="off" required',
                    ) .
                    Html::input(
                        "password",
                        "Passwort",
                        "",
                        "password",
                        'autocomplete="new-password" required',
                    ) .
                    Html::input("from", "Absender", "", "email", "required") .
                    Html::input(
                        "reply_to",
                        "Antwortadresse",
                        "",
                        "email",
                        "required",
                    ) .
                    '<p>Zugangsdaten werden verschlüsselt gespeichert und nicht wieder angezeigt.</p><button class="btn btn-solid theme-primary">Mailzugang speichern</button></form>';
            }
        } catch (\DomainException) {
            $h .=
                "<p>Privater Installationsschlüssel noch nicht eingerichtet.</p>";
        }
        try {
            $h .= (new ApiPanel(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->render($webhookFailure);
        } catch (\DomainException) {
            $h .=
                "<p>Verbindungen benötigen den privaten Installationsschlüssel und Verwaltungsrechte.</p>";
        }
        $h .= WorkerPanel::render($this->db, $this->access);
        return $h.'</details>';
    }
    private function logoPicker(string $selected):string
    {
        $upload=bin2hex(random_bytes(16));$pending=false;
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??'')==='club-settings'&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf'])){
            $posted=$_POST['logo_id']??null;
            if(is_string($posted)&&($posted===''||preg_match('/\A[a-f0-9]{32}\z/D',$posted)))$selected=$posted;
            $original=$_POST['logo_upload_id']??null;
            $accepted=is_string($original)&&preg_match('/\A[a-f0-9]{32}\z/D',$original)&&\Kulturhus\Native\Shared\Db::run($this->db,'SELECT 1 FROM khcms_media WHERE club_id=? AND id=?',[$this->access->club(),$original])->fetchColumn();
            if($accepted)$selected=$original;
            $file=$_FILES['logo_photo']??null;
            $pending=http_response_code()>=400&&!$accepted&&((is_array($file)&&isset($file['error'])&&$file['error']!==UPLOAD_ERR_NO_FILE)||($_POST['logo_upload_required']??null)==='1');
        }
        $h='<fieldset data-logo-picker data-page-media-preview="/media-preview/00000000000000000000000000000000/medium"><legend>Vereinslogo</legend><img data-logo-preview'.($selected===''?' hidden':' src="/media-preview/'.Html::e($selected).'/medium"').' alt="Ausgewähltes Vereinslogo" style="max-width:16rem;max-height:12rem;object-fit:contain"><p data-logo-empty'.($selected!==''?' hidden':'').'>Noch kein Logo ausgewählt.</p><label for="website-logo-choice">Bild auswählen</label>'.(new \Kulturhus\Native\Web\ReferencePicker($this->db,$this->access))->render('media','logo_id','website-logo-choice',$selected,false,'','','Kein Logo');
        if($this->can('website','edit'))$h.='<label>'.($pending?'Neues Logo erneut auswählen':'Neues Logo hochladen').'<input class="form-control" type="file" name="logo_photo" accept="image/jpeg,image/png,image/webp"'.($pending?' required aria-describedby="logo-upload-reselect"':'').'></label>'.Html::hidden('logo_upload_id',$upload).($pending?Html::hidden('logo_upload_required','1').'<p id="logo-upload-reselect" role="alert">Das neue Logo wurde nicht übernommen. Bitte die Datei erneut auswählen.</p>':'').'<p>JPEG, PNG oder WebP bis 12 MiB. Das neue Logo wird mit „Website-Angaben speichern“ übernommen.</p>';
        return $h.'<button type="button" class="btn btn-outline theme-secondary" data-logo-clear hidden>Logo entfernen</button></fieldset>';
    }

}
