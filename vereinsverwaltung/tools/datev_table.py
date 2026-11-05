"""Fail-closed geometry and completeness checks for the pinned DATEV tables."""
import collections
import re


def clean(parts):
    text = ' '.join(parts)
    text = re.sub(r'(\w)- (?!und\b|oder\b|sowie\b)([a-zäöüß])', r'\1\2', text)
    return re.sub(r'\s+', ' ', text).strip()


def account_column(page, words, left, right):
    dividers = [e for e in page.edges if e['height'] > 400 and 250 < e['x0'] < 335]
    if not dividers:
        raise ValueError('Account-table divider missing; source layout requires review')
    bottom = max(e['bottom'] for e in dividers)
    if not 750 < bottom < page.height-15:
        raise ValueError('Account-table bottom requires review')
    candidates = [w for w in words if left < w['x0'] < right-70 and 116 < w['top'] < bottom
                  and re.fullmatch(r'\d{4}', w['text'])]
    if not candidates:
        return None
    anchor = collections.Counter(round(w['x0']) for w in candidates).most_common(1)[0][0]
    printed = set()
    for word in candidates:
        if abs(word['x0']-anchor) >= 2:
            continue
        tails = [w for w in words if abs(w['top']-word['top']) < 1
                 and 0 <= w['x0']-word['x1'] < 4 and re.fullmatch(r'\d', w['text'])]
        if len(tails) == 1:
            printed.add(word['text']+tails[0]['text'])
    boundaries = [e['x1'] for e in page.edges if e['width'] > 400]
    if not boundaries:
        raise ValueError('Account-table horizontal boundary missing')
    boundary = min(e['x0'] for e in dividers) if left == 100 else max(boundaries)
    if not anchor+120 < boundary < anchor+160:
        raise ValueError('Account text boundary requires review')
    return anchor, (anchor-19, 116, boundary-1, bottom), printed


def check_coverage(entries, printed):
    represented = {(entry['page'], entry[key]) for entry in entries for key in ('code', 'end')}
    missing = sorted(printed-represented)
    if missing:
        raise ValueError('Printed account rows lost during extraction: '+repr(missing))


def merged_intervals(intervals, tolerance=1):
    """PDF borders consist of many touching strokes, not one rectangle."""
    result = []
    for start, end in sorted(intervals):
        if result and start <= result[-1][1] + tolerance:
            result[-1][1] = max(end, result[-1][1])
        else:
            result.append([start, end])
    return result


def statement_cells(page, words, anchor, box):
    """Read the *boxed* statement positions, never the nearest label above.

    A label can cover dozens of accounts, or just one. Blank space between
    boxes is deliberately not forward-filled. Both sides must form the same
    continuous border. This works with the differently scaled 2025/26 PDFs.
    """
    headers = [w for w in words if 'Posten' in w['text']
               and 70 < w['top'] < 116 and anchor-150 < w['x0'] < anchor-50]
    if len(headers) != 1:
        raise ValueError('Bilanz-/GuV-Spaltenkopf fehlt oder ist mehrdeutig.')
    header = headers[0]
    numbers = [w['text'] for w in words if abs(w['x0']-anchor)<2
               and 116<w['top']<box[3] and re.fullmatch(r'\d{4}',w['text'])]
    if numbers and all(n.startswith('9') for n in numbers):
        return {'kind':'statistical','cells':[], 'marks':[]}
    vertical = [e for e in page.edges if e['width'] < 1 and e['height'] > 2
                and e['bottom'] > 116 and header['x0']-12 < e['x0'] < anchor-40]
    xs = merged_intervals([(e['x0'], e['x0']) for e in vertical])
    lefts = [x[0] for x in xs if x[0] < header['x0']]
    if not lefts:
        raise ValueError('Linke Begrenzung der Abschlussposten fehlt.')
    left = max(lefts)
    rights = [x[1] for x in xs if 40 < x[1]-left < 70]
    if not rights:
        raise ValueError('Rechte Begrenzung der Abschlussposten fehlt.')
    right = min(rights)  # The next stroke belongs to the program/closing-purpose column.
    def spans(x):
        return merged_intervals([(max(116, e['top']), min(box[3], e['bottom']))
            for e in vertical if abs(e['x0']-x) < 1 and e['bottom'] > 116])
    a, b = spans(left), spans(right)
    if len(a) != len(b) or any(abs(s-t) > 1 for aa, bb in zip(a,b) for s,t in zip(aa,bb)):
        raise ValueError('Uneindeutige Rahmen der Abschlussposten.')
    cells = []
    for top, bottom in a:
        if bottom-top < 2:
            continue
        bbox = (left+1, top, right-1, bottom)
        lines = page.crop(bbox).extract_text_lines(x_tolerance=1)
        label = clean([line['text'] for line in lines])
        if not label:
            raise ValueError('Leerer gerahmter Abschlussposten.')
        cells.append({'label': label, 'bbox': [round(x,2) for x in bbox]})
    # HB/SB and program references are source facts, not tax decisions.
    marks = [w for w in words if right+1 < w['x0'] and w['x1'] < anchor-19
             and 116 < w['top'] < box[3]]
    types = {kind for label,kind in [('GuV','guv'),('Bilanz','balance')] for w in words
             if label in w['text'] and abs(w['x0']-header['x0']) < 12 and 70 < w['top'] < 116}
    if len(types)!=1:
        raise ValueError('Art des Abschlusspostens im Spaltenkopf nicht eindeutig.')
    kind = next(iter(types))
    return {'kind':kind, 'cells':cells, 'marks':marks}


def statement_at(layout, line):
    center = (line['top']+line['bottom'])/2
    cells = [c for c in layout['cells'] if c['bbox'][1] <= center <= c['bbox'][3]]
    if len(cells) > 1:
        raise ValueError('Kontenzeile in mehreren Abschlussposten.')
    parts = sorted((w for w in layout['marks'] if abs(w['top']-line['top']) < 3),key=lambda w:w.get('x0',0))
    joined = ''.join(w['text'] for w in parts)
    # Some editions draw each letter in a separate PDF word. These two printed
    # codes are unambiguous; never repair arbitrary source words or tax digits.
    marks = [joined] if joined in ('HB','SB') else sorted({w['text'] for w in parts})
    return {'kind':layout['kind'], 'label':cells[0]['label'] if cells else '',
            'marks':marks, 'status':'printed' if cells else 'not_printed',
            'bbox':cells[0]['bbox'] if cells else None}
