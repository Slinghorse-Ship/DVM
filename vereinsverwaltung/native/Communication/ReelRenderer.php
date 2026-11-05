<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Cms\Media;
use Kulturhus\Native\Shared\{Access, Identity};

/** Fixed local recipes. Executable, input protocols and arguments are never form values. */
final class ReelRenderer
{
    public const ANIMATIONS = ['still', 'zoom', 'pan', 'fade'];
    public function __construct(private PDO $db, private Access $access, private string $mediaRoot) {}

    public static function animation(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ANIMATIONS, true)) throw new DomainException('Animationsvorlage auswählen.');
        return $value;
    }

    public function requireAvailable(): string
    {
        $this->access->require('social', 'edit');
        if (PHP_OS_FAMILY !== 'Linux' || !in_array(php_uname('m'), ['x86_64', 'amd64'], true) || !function_exists('proc_open')) throw new DomainException('Die automatische Videoerstellung benötigt den lokalen Linux-Videoencoder.');
        $package = dirname(__DIR__, 2).'/resources/reel-server';
        $manifest = $package.'/provenance.json';
        if (is_link($manifest) || !is_file($manifest)) throw new DomainException('Videoencoder über das vollständige Installationspaket bereitstellen.');
        $proof = json_decode(file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR)['binary'] ?? [];
        $source = $package.'/linux-amd64/ffmpeg';
        if (!is_string($proof['sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $proof['sha256']) || !is_int($proof['bytes'] ?? null)
            || is_link($source) || !is_file($source) || filesize($source) !== $proof['bytes'] || !hash_equals($proof['sha256'], hash_file('sha256', $source))) throw new DomainException('Videoencoder stimmt nicht mit dem Installationspaket überein.');
        $root = dirname($this->mediaRoot).'/reel-runtime';
        self::directory($root); $root .= '/'.substr($proof['sha256'], 0, 24); self::directory($root);
        $binary = $root.'/ffmpeg';
        if (!file_exists($binary)) {
            $temporary = $root.'/.encoder-'.bin2hex(random_bytes(12));
            try {
                if (!copy($source, $temporary) || !chmod($temporary, 0700) || !hash_equals($proof['sha256'], hash_file('sha256', $temporary))) throw new DomainException('Privaten Videoencoder prüfen.');
                if (!link($temporary, $binary) && !is_file($binary)) throw new DomainException('Privaten Videoencoder prüfen.');
            } finally { if (is_file($temporary)) unlink($temporary); }
        }
        if (is_link($binary) || !is_file($binary) || (fileperms($binary) & 0077) || !hash_equals($proof['sha256'], hash_file('sha256', $binary))) throw new DomainException('Privaten Videoencoder prüfen.');
        // Recovery restores every private file as 0600. Re-enable only the exact trusted encoder.
        if ((fileperms($binary) & 0777) === 0600 && !chmod($binary, 0700)) throw new DomainException('Privaten Videoencoder prüfen.');
        clearstatcache(true, $binary);
        if (!is_executable($binary)) throw new DomainException('Privaten Videoencoder prüfen.');
        return $binary;
    }

    private static function directory(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path)) || (!is_dir($path) && !mkdir($path, 0700)) || (fileperms($path) & 0077)) throw new DomainException('Privaten Videoarbeitsordner prüfen.');
    }

    /** Padded motion retains the complete card, including attribution and QR edges. */
    public static function filter(string $animation): string
    {
        return match (self::animation($animation)) {
            'still'=>'fps=30,format=yuv420p',
            'fade'=>'fps=30,format=yuv420p,fade=t=in:st=0:d=0.6',
            'zoom'=>"scale=972:1728,pad=1080:1920:54:96:color=0xf7f6f0,zoompan=z='1+0.00045*min(on,120)':x='iw/2-iw/zoom/2':y='ih/2-ih/zoom/2':d=1:s=1080x1920:fps=30,format=yuv420p",
            'pan'=>"scale=972:1728,pad=1080:1920:54:96:color=0xf7f6f0,zoompan=z=1.05:x='25*(1+sin(min(on,120)*PI/90))':y='(ih-ih/zoom)/2':d=1:s=1080x1920:fps=30,format=yuv420p",
        };
    }

    public function render(string $item, int $version, string $animation, ?string $requestKey = null): string
    {
        Content::id($item); self::animation($animation);
        if ($this->db->inTransaction()) throw new DomainException('Video benötigt einen eigenen Speichervorgang.');
        $this->access->require('social', 'edit');
        $draft = (new Workspace($this->db, $this->access, 'social', $this->mediaRoot))->get($item);
        if ($draft['kind'] !== 'reel' || $draft['version'] !== $version || !in_array($draft['status'], ['draft', 'review'], true)) throw new DomainException('Aktuellen Reel-Entwurf öffnen.');
        if (isset($draft['video_design']) && $draft['video_design']['animation'] !== $animation) throw new DomainException('Gespeicherte Animationsvorlage erneut öffnen.');
        $identity = Identity::forClub($this->db, $this->access->club());
        $name = 'Reel-'.$animation.'.mp4';
        $requests = new VideoSaveRequests($this->db, $this->access, $this->mediaRoot);
        if ($requestKey !== null && ($existing = $requests->review($item, $requestKey))) {
            if ($existing['item_version'] !== $version || $existing['identity_version'] !== $identity['version'] || $existing['name'] !== $name) throw new DomainException('Videoauftrag gehört zu einer anderen Fassung oder Vorlage.');
            return $existing['id'];
        }
        $binary = $this->requireAvailable();
        if ($identity['name'] === '' || $identity['origin'] === '') throw new DomainException('Vereinsname und Domain zuerst einrichten.');
        $root = dirname($this->mediaRoot).'/reel-tmp'; self::directory($root);
        $directory = $root.'/'.bin2hex(random_bytes(16)); self::directory($directory);
        $image = $directory.'/card.png'; $movie = $directory.'/reel.mp4';
        try {
            $media = new Media($this->db, $this->access, $this->mediaRoot);
            $cover = ''; foreach ($draft['blocks'] as $block) if ($block['type'] === 'image') { $cover = $block['media_ref']; break; }
            $design = ImageDesign::options($draft['image_design'] ?? []);
            $refs = $cover !== '' && $design['layout'] !== 'poster' ? [$cover] : [];
            if ($identity['logo_id'] !== '') $refs[] = $identity['logo_id'];
            $usage = ImageUsage::capture($this->db, $this->access->club(), $refs, 'social');
            $picture = $cover === '' ? null : $media->variant($cover, 'large');
            $logo = $identity['logo_id'] === '' ? null : $media->variant($identity['logo_id'], 'thumb');
            $canvas = ImageDesign::canvas(['title'=>$draft['title'], 'text'=>$draft['summary']], 1920, $picture, $logo, $identity, $draft['destination_url'] ?: $identity['origin'], $design, ImageAttribution::plan($usage, 1920));
            try { if (!imagepng($canvas, $image, 4) || !chmod($image, 0600)) throw new DomainException('Videobild konnte nicht erstellt werden.'); } finally { unset($canvas); }
            self::encode($binary, $directory, self::filter($animation));
            ReelFile::inspect($movie);
            if (Identity::forClub($this->db, $this->access->club())['version'] !== $identity['version'] || ImageUsage::capture($this->db, $this->access->club(), $refs, 'social') !== $usage) throw new DomainException('Vereins- oder Bildangaben geändert. Video erneut vorbereiten.');
            return $requestKey === null ? (new Videos($this->db, $this->access, $this->mediaRoot))->save($item, $version, $identity['version'], $movie, $name)
                : $requests->save($item, $version, $identity['version'], $movie, $name, $requestKey)['id'];
        } finally {
            foreach ([$image, $movie, $directory.'/encoder.log'] as $path) if (is_file($path) && !is_link($path)) unlink($path);
            if (is_dir($directory) && !is_link($directory)) rmdir($directory);
        }
    }

    private static function encode(string $binary, string $directory, string $filter): void
    {
        $log = $directory.'/encoder.log';
        $process = proc_open([$binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-protocol_whitelist', 'file,pipe', '-f', 'image2', '-loop', '1', '-framerate', '30', '-i', 'card.png', '-vf', $filter, '-t', '6', '-r', '30', '-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'stillimage', '-crf', '28', '-maxrate', '4000k', '-bufsize', '8000k', '-profile:v', 'baseline', '-pix_fmt', 'yuv420p', '-threads', '1', '-an', '-movflags', '+faststart', '-y', 'reel.mp4'],
            [0=>['pipe', 'r'], 1=>['file', $log, 'w'], 2=>['file', $log, 'a']], $pipes, $directory);
        if (!is_resource($process)) throw new DomainException('Videoencoder konnte nicht gestartet werden.');
        chmod($log, 0600); fclose($pipes[0]); $started = microtime(true); $exit = -1;
        try {
            do {
                $state = proc_get_status($process);
                if (!$state['running']) { $exit = $state['exitcode']; break; }
                clearstatcache(true, $log); clearstatcache(true, $directory.'/reel.mp4');
                if (microtime(true)-$started > 120 || filesize($log) > 1048576 || (is_file($directory.'/reel.mp4') && filesize($directory.'/reel.mp4') > ReelFile::MAX_BYTES)) throw new DomainException('Videoerstellung unterbrochen. Gespeicherten Entwurfsstatus prüfen.');
                usleep(50000);
            } while (true);
        } finally {
            $state = proc_get_status($process); if ($state['running']) proc_terminate($process, 9);
            $closed = proc_close($process); if ($exit < 0 && $closed >= 0) $exit = $closed;
        }
        if ($exit !== 0 || !is_file($directory.'/reel.mp4')) throw new DomainException('Video konnte nicht vorbereitet werden. Encoder und Speicher prüfen.');
        chmod($directory.'/reel.mp4', 0600);
    }
}
