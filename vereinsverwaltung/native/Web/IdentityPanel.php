<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use Kulturhus\Native\Integration\{AccountIdentity, ManagerAccess};
use Kulturhus\Native\Auth\Service;
use Kulturhus\Native\Shared\{Db, SecretBox};

/** Embedded account/profile administration uses the same original manager roles. */
final class IdentityPanel
{
    public static function render(
        \PDO $db,
        ManagerAccess $access,
        Service $auth,
        SecretBox $box,
        string $csrf,
        ?string $managerActor = null,
    ): string {
        try {
            $access->require("accounts", "manage");
        } catch (\DomainException) {
            return "";
        }
        $identity = new AccountIdentity($db, $access, $auth, $box);
        $rows = $identity->directory($managerActor);
        if (!$rows) return "";
        $e = Html::e(...);
        $club = Db::run($db, "SELECT club_id FROM actors WHERE id=?", [
            $access->managerActor(),
        ])->fetchColumn();
        $h =
            '<section class="card" id="personal-accounts"><h2>Persönliches Konto und Profil</h2><p><a class="btn btn-outline theme-secondary button secondary" href="/accounts">Einladungen und Kontosicherheit öffnen</a></p><div class="role-cards identity-account-list">';
        foreach ($rows as $row) {
            $h .=
                '<article class="role-menu-card"><header><strong>' .
                $e($row["name"]) .
                "</strong><br>" .
                $e($row["email"]) .
                (!empty($row["login_name"])
                    ? "<br>Benutzername: " . $e($row["login_name"])
                    : "") .
                "<p>" .
                ($row["suspended"] || !$row["active"]
                    ? "Gesperrt"
                    : (!$row["verified"]
                        ? "Einladung offen"
                        : ($row["account_active"]
                            ? "Aktiv"
                            : "Gesperrt"))) .
                "</p></header><div>";
            if ($row["manager_actor"] === $access->managerActor()) {
                $h .=
                    '<p>Dein eigener Verwaltungszugang bleibt geschützt. Passwort und zweiten Faktor bearbeitest du unter <a href="/account">Mein Konto</a>.</p></div></article>';
                continue;
            }
            $posted =
                in_array($_POST["action"] ?? "",["identity-assign","identity-browse"],true) &&
                ($_POST["account_id"] ?? "") === $row["id"];
            $role =
                $posted && is_string($_POST["role_id"] ?? null)
                    ? $_POST["role_id"]
                    : $row["role_id"] ?? "";
            $contact =
                $posted && is_string($_POST["contact_id"] ?? null)
                    ? $_POST["contact_id"]
                    : $row["contact_id"] ?? "";
            $h .=
                '<form method="post" class="settings-actions">' .
                Html::hidden("csrf", $csrf) .
                Html::hidden("action", "identity-assign") .
                Html::hidden("account_id", $row["id"]) .
                Html::hidden(
                    "version",
                    $posted
                        ? (is_string($_POST["version"]??null)?$_POST["version"]:"")
                        : (string) $row["version"],
                ) .
                Html::hidden(
                    "role_version",
                    $posted
                        ? (is_string($_POST["role_version"]??null)?$_POST["role_version"]:"")
                        : (string) $row["role_version"],
                ) .
                Html::hidden(
                    "expected_contact",
                    $posted
                        ? (is_string($_POST["expected_contact"]??null)?$_POST["expected_contact"]:"")
                        : $row["contact_id"] ?? "",
                );
            $choiceInput=$posted?$_POST:$_GET;
            $choices=[];
            foreach(['role'=>$role,'profile'=>$contact] as $kind=>$selection){
                try{$choices[$kind]=$identity->choices($kind,$choiceInput,$selection!==''&&$selection!=='__base__'?$selection:null);}
                catch(\DomainException $failure){$h.='<p role="alert">'.$e($failure->getMessage()).'</p>';$choices[$kind]=$identity->choices($kind,[],$kind==='role'?($row['role_id']?:null):($row['contact_id']?:null));}
                $listing=$choices[$kind];$prefix=$listing['prefix'];$label=$kind==='role'?'Vereinsrolle':'Eigenes Mitgliederprofil';
                $options=$kind==='role'?[''=>'Bisherige Rechte beibehalten','__base__'=>'Nur Grundrolle verwenden']:[''=>'Keine Profilverknüpfung'];
                foreach($listing['rows'] as $choice)$options[$choice['id']]=$choice['name'];
                if($listing['selected'])$options[$listing['selected']['id']]=$listing['selected']['name'];
                if($selection!==''&&$selection!=='__base__'&&!isset($options[$selection]))$options[$selection]='Gewählte Zuordnung nicht verfügbar';
                $h.='<fieldset class="identity-choice"><legend>'.$e($label).'</legend>'.Html::input($prefix.'_q',$kind==='role'?'Rolle suchen':'Profil suchen',$listing['q'],'search','maxlength="150"').Html::input($prefix.'_page','Auswahlseite',(string)$listing['page'],'number','min="1" max="'.$listing['pages'].'" step="1" required').'<span>'.$listing['total'].' passende '.($kind==='role'?'Rollen':'Profile').' · Seite '.$listing['page'].' von '.$listing['pages'].'</span><button class="btn btn-outline theme-secondary" name="action" value="identity-browse" type="submit">Auswahl anzeigen</button>'.Html::select($kind==='role'?'role_id':'contact_id',$label,$options,$selection).'</fieldset>';
            }
            $h .=
                '<label><input class="check" type="checkbox" name="enabled" value="1" ' .
                (($posted
                        ? ($_POST["enabled"] ?? "") === "1"
                        : !$row["suspended"] &&
                            $row["active"] &&
                            (!$row["verified"] || $row["account_active"]))
                    ? "checked"
                    : "") .
                '> Zugang erlauben</label><label><input class="check" type="checkbox" name="confirmed" value="1"'.($posted&&($_POST['confirmed']??null)==='1'?' checked':'').'> Eine geänderte persönliche Profilzuordnung ausdrücklich bestätigen</label><button class="btn btn-solid theme-primary">Zugriffsrechte speichern</button></form>';
            if (
                $row["verified"] &&
                $row["active"] &&
                $row["account_active"] &&
                $row["role"] !== "editor"
            ) {
                $h .=
                    '<details><summary>Technische Kontoverwaltung</summary><form method="post" class="settings-actions">' .
                    Html::hidden("csrf", $csrf) .
                    Html::hidden("action", "identity-superuser") .
                    Html::hidden("account_id", $row["id"]) .
                    Html::hidden("version", $row["version"]) .
                    '<label><input class="check" type="checkbox" name="enabled" value="1" ' .
                    ($row["is_superuser"] ? "checked" : "") .
                    '> Technische Administration für Website und Vereinsmanager</label><label><input class="check" type="checkbox" name="confirmed" value="1" required> Diese erweiterten Konten- und Systemrechte ausdrücklich bestätigen</label><button class="btn btn-solid theme-primary">Technische Rechte speichern</button></form></details>';
            }
            $h .= "</div></article>";
        }
        return $h . "</div></section>";
    }
}
