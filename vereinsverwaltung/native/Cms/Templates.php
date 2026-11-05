<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\RichText;

/** Native content schema. Plain fields are escaped by renderers; body_html is sanitized. */
final class Templates
{
    public static function all(): array
    {
        return [
            'standard' => ['label' => 'Inhaltsseite', 'fields' => ['body', 'media_ids']],
            'event' => ['label' => 'Veranstaltung', 'fields' => ['body', 'event_start', 'event_end', 'event_location', 'event_address', 'event_status', 'event_ticket_url', 'event_price', 'media_ids']],
            'gallery' => ['label' => 'Galerie', 'fields' => ['body', 'media_ids']],
            'news' => ['label' => 'Beitrag / Aktuelles', 'fields' => ['body', 'news_date', 'media_ids']],
        ];
    }

    public static function normalize(array $input, array $additionalTemplates = []): array
    {
        $bodyHtml = array_key_exists('body_html', $input) ? $input['body_html'] : '';
        if (!is_string($bodyHtml)) {
            throw new InvalidArgumentException('Formatierter Inhalt muss UTF-8-Text sein.');
        }
        // Empty HTML preserves an explicitly supplied plain body for older full-page DTO callers.
        // WYSIWYG callers clear both fields (or omit body) when the editor is emptied.
        if ($bodyHtml !== '') {
            $bodyHtml = RichText::sanitize($bodyHtml);
            $body = RichText::plain($bodyHtml);
        } else {
            $body = $input['body'] ?? '';
        }
        $title = self::text($input['title'] ?? '', 'Titel', 200, true);
        $slug = $input['slug'] ?? '';
        if (!is_string($slug)) {
            throw new InvalidArgumentException('URL-Pfad muss Text sein.');
        }
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*\z/D', $slug) || strlen($slug) > 180) {
            throw new InvalidArgumentException('URL-Pfad: Kleinbuchstaben, Zahlen, Bindestriche und optionale Unterpfade verwenden.');
        }
        if ($slug === 'status' || str_starts_with($slug, 'status/')) {
            throw new InvalidArgumentException('Dieser URL-Pfad ist für den Systemstatus reserviert.');
        }
        if (preg_match('~\A(?:file|file-preview)(?:/|$)~D',$slug)) {
            throw new InvalidArgumentException('Dieser URL-Pfad ist für Dateizugriffe reserviert.');
        }
        if ($slug === 'api' || str_starts_with($slug, 'api/')) {
            throw new \InvalidArgumentException('Diese Adresse ist für die API reserviert.');
        }
        if ($slug === 'extensions' || str_starts_with($slug, 'extensions/')) {
            throw new InvalidArgumentException('Dieser URL-Pfad ist für Erweiterungen reserviert.');
        }
        $template = $input['template'] ?? 'standard';
        if (!is_string($template)) {
            throw new InvalidArgumentException('Seitenvorlage muss Text sein.');
        }
        if (!isset(self::all()[$template]) && !isset($additionalTemplates[$template])) {
            throw new InvalidArgumentException('Unbekannte Seitenvorlage.');
        }
        $ids = $input['media_ids'] ?? [];
        if (!is_array($ids) || count($ids) > 100) {
            throw new InvalidArgumentException('Eine Seite darf maximal 100 Galerie-Bilder enthalten.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
                throw new InvalidArgumentException('Ungültige Medienreferenz.');
            }
        }
        $ids = array_values(array_unique($ids));
        $galleryItems = $input['gallery_items'] ?? [];
        if (!is_array($galleryItems) || !array_is_list($galleryItems) || count($galleryItems) > 100) {
            throw new InvalidArgumentException('Galeriebeschreibungen müssen eine Liste mit maximal 100 Einträgen sein.');
        }
        $galleryById = [];
        foreach ($galleryItems as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['id', 'caption', 'tags']) || !is_string($item['id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $item['id']) || isset($galleryById[$item['id']])) {
                throw new InvalidArgumentException('Galeriebeschreibungen benötigen eindeutige gültige Bild-IDs.');
            }
            $tags = $item['tags'] ?? [];
            if (!is_array($tags) || !array_is_list($tags) || count($tags) > 20) {
                throw new InvalidArgumentException('Je Bild maximal 20 Schlagwörter als Liste angeben.');
            }
            $tags = array_map(static fn ($tag): string => self::text($tag, 'Schlagwort', 50, true), $tags);
            $galleryById[$item['id']] = ['id' => $item['id'], 'caption' => self::text($item['caption'] ?? '', 'Bildbeschriftung', 500), 'tags' => array_values(array_unique($tags))];
        }
        // Removed images lose their metadata; current media_ids determine order and membership.
        $normalizedGallery = array_map(static fn (string $id): array => $galleryById[$id] ?? ['id' => $id, 'caption' => '', 'tags' => []], $ids);
        $parent = ($input['parent_id'] ?? null) ?: null;
        if ($parent !== null && (!is_string($parent) || !preg_match('/\A[a-f0-9]{32}\z/D', $parent))) {
            throw new InvalidArgumentException('Ungültige übergeordnete Seite.');
        }
        $start = $input['event_start'] ?? '';
        if (!is_string($start)) {
            throw new InvalidArgumentException('Veranstaltungsbeginn muss Text sein.');
        }
        $start = trim($start);
        $timezone=$input['event_timezone']??'Europe/Berlin';
        if(!is_string($timezone)||!in_array($timezone,\DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Gültige IANA-Zeitzone erforderlich.');
        if ($start !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $start, new \DateTimeZone($timezone));
            if (!$date || $date->format('Y-m-d\TH:i') !== $start) {
                throw new InvalidArgumentException('Veranstaltungsbeginn im Format JJJJ-MM-TTTHH:MM angeben.');
            }
        }
        $utc = $input['event_start_utc'] ?? '';
        $end = $input['event_end'] ?? '';
        if(array_key_exists('event_end_local',$input)) {
            $localEnd=$input['event_end_local'];
            if(!is_string($localEnd))throw new InvalidArgumentException('Veranstaltungsende muss Text sein.');
            $localEnd=trim($localEnd);
            if($localEnd==='')$end='';
            else {
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$localEnd,new \DateTimeZone($timezone));
                if(!$date||$date->format('Y-m-d\TH:i')!==$localEnd)throw new InvalidArgumentException('Veranstaltungsende im Format JJJJ-MM-TTTHH:MM angeben.');
                $end=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            }
        }
        foreach ([$utc,$end] as $instant) {
            if (!is_string($instant) || ($instant !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$instant) || (new \DateTimeImmutable($instant))->format('Y-m-d\TH:i:s\Z') !== $instant))) throw new InvalidArgumentException('Gültige UTC-Terminzeit erforderlich.');
        }
        if ($utc !== '' && (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d\TH:i') !== $start) throw new InvalidArgumentException('UTC-Zeit und lokaler Beginn widersprechen sich.');
        if ($end !== '' && ($start === '' || new \DateTimeImmutable($end) < ($utc !== '' ? new \DateTimeImmutable($utc) : new \DateTimeImmutable($start,new \DateTimeZone($timezone))))) throw new InvalidArgumentException('Terminende liegt vor dem Beginn.');
        $order = filter_var($input['nav_order'] ?? 0, FILTER_VALIDATE_INT);
        if ($order === false || $order < -100000 || $order > 100000) {
            throw new InvalidArgumentException('Ungültige Navigationsreihenfolge.');
        }
        $newsDate = self::text($input['news_date'] ?? '', 'Beitragsdatum', 10, $template === 'news');
        if ($newsDate !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $newsDate, new \DateTimeZone('Europe/Berlin'));
            if (!$date || $date->format('Y-m-d') !== $newsDate) {
                throw new InvalidArgumentException('Beitragsdatum im Format JJJJ-MM-TT angeben.');
            }
        }
        $sections = [];
        if (array_key_exists('content_sections',$input)) {
            $normalizedSections = ContentSections::normalize($input['content_sections']);
            $sections = ['content_sections'=>$normalizedSections,'content_sections_text'=>ContentSections::plain($normalizedSections)];
        }
        return [
            'title' => $title, 'slug' => $slug, 'template' => $template,
            'body' => self::text($body, 'Inhalt', 100000), 'body_html' => $bodyHtml,
            'excerpt' => self::text($input['excerpt'] ?? '', 'Kurztext', 1200),
            'seo_title' => self::text($input['seo_title'] ?? '', 'SEO-Titel', 200),
            'seo_description' => self::text($input['seo_description'] ?? '', 'SEO-Beschreibung', 320),
            'noindex' => self::boolean($input['noindex'] ?? false),
            'nav_visible' => self::boolean($input['nav_visible'] ?? true),
            'nav_label' => self::text($input['nav_label'] ?? '', 'Menütitel', 100),
            'nav_order' => $order, 'parent_id' => $parent,
            'media_ids' => $ids, 'gallery_items' => $normalizedGallery,
            'event_start' => $start, 'event_timezone' => $timezone,
            'event_start_utc' => $utc, 'event_end' => $end,
            'event_location' => self::text($input['event_location'] ?? '', 'Veranstaltungsort', 300),
            'news_date' => $newsDate,
        ] + EventDetails::normalize($input)
            + $sections
            + (array_key_exists('page_policy',$input) ? ['page_policy'=>TemplatePolicy::pagePolicy($input['page_policy'])] : [])
            + (array_key_exists('document_links',$input) ? ['document_links'=>WebsiteDocuments::normalize($input['document_links'])] : [])
            + (isset($input['home_layout']) ? ['home_layout'=>HomeLayout::normalize($input['home_layout'])] : []);
    }

    private static function boolean(mixed $value): bool
    {
        if (!in_array($value, [true, false, 1, 0, '1', '0'], true)) {
            throw new InvalidArgumentException('Ungültiger Wahrheitswert.');
        }
        return $value === true || $value === 1 || $value === '1';
    }

    private static function text(mixed $value, string $field, int $max, bool $required = false): string
    {
        if (!is_string($value) || !preg_match('//u', $value)) {
            throw new InvalidArgumentException($field . ': gültigen UTF-8-Text angeben.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException($field . ': Steuerzeichen sind unzulässig.');
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        $length = preg_match_all('/./us', $value);
        if ($length > $max || ($required && $length === 0)) {
            throw new InvalidArgumentException($field . ': Länge außerhalb des erlaubten Bereichs.');
        }
        return $value;
    }
}
