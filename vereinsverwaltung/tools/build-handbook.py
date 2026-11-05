"""Build authenticated handbook from reviewed chapters and real UI captures.

Small Markdown dialect: headings, paragraphs, lists, emphasis, safe links and
inline PNG figures. No raw HTML. handbook-capture.cjs records numbered crops.
"""
from pathlib import Path
import html
import json
import re
import struct
import hashlib
import os
from datetime import date

ROOT = Path(__file__).resolve().parents[2]
RESOURCE = ROOT / 'vereinsverwaltung/resources'
SOURCES = ROOT / 'docs/vereinsverwaltung/handbuch'
CHAPTER_LINKS = {}


def repository_markdown(source):
    """Rebase chapter-relative links for the combined repository document."""
    def rebase(match):
        url = match.group(1)
        if url.startswith(('https://', 'http://', 'mailto:', '#', '?')):
            return match.group(0)
        path, separator, anchor = url.partition('#')
        target = (SOURCES / path).resolve()
        if not target.is_relative_to(ROOT):
            raise ValueError('Handbook reference outside repository')
        relative = os.path.relpath(target, ROOT / 'docs/vereinsverwaltung').replace(os.sep, '/')
        return '](' + relative + (separator + anchor if separator else '') + ')'
    return re.sub(r'\]\(([^)\n]+)\)', rebase, source)


def inline(value):
    pattern = r'\[([^\]]+)\]\(([^)]+)\)|\*\*([^*]+)\*\*|`([^`]+)`'
    output, last = [], 0
    for match in re.finditer(pattern, value):
        output.append(html.escape(value[last:match.start()]))
        label, url, bold, code = match.groups()
        if label is not None:
            url = CHAPTER_LINKS.get(url, url)
            if re.fullmatch(r'\.\./\.\./[a-zA-Z0-9_.-]+\.md', url) and (SOURCES / url).resolve().is_file():
                # Repository references have no public manager route. Keep their
                # readable label in the embedded handbook, without a broken URL.
                output.append(html.escape(label))
                last = match.end()
                continue
            if not re.match(r'^(?:https://|\?view=|#)', url):
                raise ValueError('Unsupported handbook link: ' + url)
            output.append('<a href="' + html.escape(url, quote=True) + '">' + html.escape(label) + '</a>')
        elif bold is not None:
            output.append('<strong>' + html.escape(bold) + '</strong>')
        else:
            output.append('<code>' + html.escape(code) + '</code>')
        last = match.end()
    output.append(html.escape(value[last:]))
    return ''.join(output)


def build():
    sources = sorted(SOURCES.glob('[0-9][0-9]-*.md'))
    if len(sources) != 20:
        raise ValueError('Exactly 20 numbered handbook chapters required')
    CHAPTER_LINKS.clear()
    for path in sources:
        number, task = int(path.name[:2]), 0
        CHAPTER_LINKS[path.name] = f'#kapitel-{number}'
        for line in path.read_text(encoding='utf-8').splitlines():
            if line.startswith('### '):
                task += 1
                slug = re.sub(r'[^\w -]', '', line[4:].lower()).replace(' ', '-')
                CHAPTER_LINKS[path.name + '#' + slug] = f'#kapitel-{number}-{task}'
    intro = '# Kulturhus – Bedienhandbuch\n\nStand: 9. September 2026. Schritt für Schritt im vorhandenen Vereinsmanager. Die Abbildungen zeigen die lokale Testumgebung mit Beispieldaten; nummerierte Rahmen markieren die besprochenen Bedienelemente. Namen, Beträge und Termine sind Beispiele.\n\n'
    source = intro + '\n\n'.join(p.read_text(encoding='utf-8').strip() for p in sources) + '\n'
    figures = json.loads((SOURCES / 'figures.json').read_text(encoding='utf-8'))
    tutorials = json.loads((SOURCES / 'tutorials.json').read_text(encoding='utf-8'))
    output, toc, files, paragraph = [], [], [], []
    listing = None
    chapter = 0
    task = 0
    task_open = False

    def flush():
        nonlocal listing
        if paragraph:
            output.append('<p>' + inline(' '.join(paragraph)) + '</p>')
            paragraph.clear()
        if listing:
            output.append('</' + listing + '>')
            listing = None

    def figure(key):
        if key not in figures:
            raise ValueError('Unknown figure: ' + key)
        data = figures[key]
        filename = data['file']
        if not re.fullmatch(r'[a-z0-9-]+\.png', filename):
            raise ValueError('Invalid image filename')
        png = (RESOURCE / 'handbook' / filename).read_bytes()
        if data.get('sha256') != hashlib.sha256(png).hexdigest() or not data.get('privacy_reviewed_at'):
            raise ValueError('Screenshot requires renewed privacy review: ' + filename)
        if png[:8] != b'\x89PNG\r\n\x1a\n':
            raise ValueError('Not a PNG: ' + filename)
        width, height = struct.unpack('>II', png[16:24])
        files.append(filename)
        url = '?view=handbook&amp;handbook_image=' + filename
        output.append('<figure class="handbook-figure" id="bild-' + key + '"><figcaption><strong>' + html.escape(data['title']) + '</strong><span>Die Nummern im Bild gehören zu den Hinweisen darunter.</span></figcaption><a class="handbook-image" href="' + url + '" target="_blank" rel="noopener" aria-label="' + html.escape(data['title'], quote=True) + ' – Bild groß öffnen"><img loading="lazy" width="' + str(width) + '" height="' + str(height) + '" src="' + url + '" alt="' + html.escape(data['title'] + '. Markierungen: ' + '; '.join(str(n+1) + ': ' + x for n,x in enumerate(data['notes'])), quote=True) + '"></a><ol class="handbook-markers">')
        for note in data['notes']:
            output.append('<li>' + inline(note) + '</li>')
        captured = date.fromisoformat(data['captured_at'])
        output.append('</ol><a class="handbook-zoom" href="' + url + '" target="_blank" rel="noopener">Bild groß öffnen ↗</a><small>Lokale Beispielansicht · Aufnahme vom ' + captured.strftime('%d.%m.%Y') + ' · Ausschnitt mit Markierungen</small></figure>')

    for line in source.splitlines():
        if line.startswith('## '):
            flush()
            if task_open:
                output.append('</section>')
                task_open = False
            if chapter:
                output.append('</section>')
            title = line[3:]
            number = int(title.split('.')[0])
            if number != chapter + 1:
                raise ValueError('Chapter numbering must be consecutive')
            chapter, task = number, 0
            toc.append({'title': title, 'id': f'kapitel-{chapter}', 'tasks': []})
            output.append(f'<section class="handbook-chapter" aria-labelledby="kapitel-{chapter}"><h2 id="kapitel-{chapter}">{html.escape(title)}</h2>')
            for tutorial in tutorials:
                if tutorial['chapter'] == chapter:
                    output.append('<p class="handbook-video-link"><a href="#video-' + tutorial['id'] + '">▶ Videoanleitung: ' + html.escape(tutorial['title']) + '</a></p>')
        elif line.startswith('### '):
            flush()
            if task_open:
                output.append('</section>')
            task += 1
            anchor = f'kapitel-{chapter}-{task}'
            toc[-1]['tasks'].append({'title': line[4:], 'id': anchor})
            output.append(f'<section class="handbook-task" aria-labelledby="{anchor}"><h3 id="{anchor}">{html.escape(line[4:])}</h3>')
            task_open = True
        elif line.startswith('# '):
            flush()
            output.append('<h2 id="handbuch-start">' + html.escape(line[2:]) + '</h2>')
        elif re.fullmatch(r':::bild [a-z0-9-]+', line):
            flush()
            figure(line.split()[1])
        elif line.startswith('- ') or re.match(r'^\d+\. ', line):
            kind = 'ul' if line.startswith('- ') else 'ol'
            content = line[2:] if kind == 'ul' else line.split('. ', 1)[1]
            if listing != kind:
                flush()
                output.append('<' + kind + '>')
                listing = kind
            output.append('<li>' + inline(content) + '</li>')
        elif not line.strip():
            flush()
        else:
            if listing:
                flush()
            paragraph.append(line)
    flush()
    if task_open:
        output.append('</section>')
    output.append('</section>')
    nav = ['<nav class="handbook-toc" aria-label="Handbuch-Inhalt" id="handbuch-inhalt"><h2>Was möchtest du erledigen?</h2><label for="handbook-search">Anleitung finden</label><input id="handbook-search" type="search" placeholder="Zum Beispiel: Newsletter, Familie, fünf Dokumente" autocomplete="off"><p data-handbook-results role="status">Kapitel aufklappen und eine Aufgabe auswählen. Strg+F durchsucht zusätzlich alle Anleitungstexte.</p><div class="handbook-toc-grid">']
    for entry in toc:
        nav.append('<details data-handbook-chapter><summary>' + html.escape(entry['title']) + '</summary><ul><li><a href="#' + entry['id'] + '">Kapitelanfang</a></li>')
        for item in entry['tasks']:
            nav.append('<li data-handbook-result><a href="#' + item['id'] + '">' + html.escape(item['title']) + '</a></li>')
        nav.append('</ul></details>')
    nav.append('</div></nav>')
    (ROOT / 'docs/vereinsverwaltung/HANDBUCH.md').write_text(repository_markdown(source), encoding='utf-8')
    (RESOURCE / 'handbook.html').write_text('\n'.join(nav + output), encoding='utf-8')
    (RESOURCE / 'handbook-images.json').write_text(json.dumps(list(dict.fromkeys(files)), ensure_ascii=False, indent=2), encoding='utf-8')
    coverage = json.loads((SOURCES / 'menu-coverage.json').read_text(encoding='utf-8'))
    routes = [{'route': item['route'], 'chapter': item['chapter']} for group in coverage['groups'] for item in group['routes']]
    (RESOURCE / 'handbook-routes.json').write_text(json.dumps(routes, ensure_ascii=False, indent=2), encoding='utf-8')
    print(f'Built {len(toc)} chapters, {sum(len(x["tasks"]) for x in toc)} tasks, {len(files)} illustrated instructions')


if __name__ == '__main__':
    build()
