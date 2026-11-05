"""Inspect the actual synthetic export against measurements of the supplied FS-RA-030.

The private source PDF, its fonts, identities, approvals and active objects are not fixtures.
"""
import io
import json
import sys
from pypdf import PdfReader

reader = PdfReader(io.BytesIO(sys.stdin.buffer.read()))
page = reader.pages[0]
assert abs(float(page.mediabox.width) - 841.92) < .001
assert abs(float(page.mediabox.height) - 595.32) < .001
rectangles, segments, stack = [], [], []
fill, move = (0, 0, 0), None


def point(x, y, matrix):
    a, b, c, d, e, f = matrix
    return (a*x+c*y+e, b*x+d*y+f)


def visit(operator, arguments, matrix, text_matrix):
    global fill, move
    if operator == b'q':
        stack.append(fill)
    elif operator == b'Q':
        fill = stack.pop()
    elif operator == b'rg':
        fill = tuple(round(float(channel)*255) for channel in arguments)
    elif operator == b're':
        x, y, width, height = map(float, arguments)
        rectangles.append((point(x, y, matrix), point(x+width, y+height, matrix), fill))
    elif operator == b'm':
        move = point(*map(float, arguments), matrix)
    elif operator == b'l' and move:
        end = point(*map(float, arguments), matrix)
        segments.append((move, end))
        move = end


text = page.extract_text(visitor_operand_before=visit)
assert 'Risikomatrix' in text and 'UMWELT' in text and 'SICHERHEIT' in text
assert 'Katastrophal' in text and 'Unwahrscheinlich' in text
palette = {(90, 184, 143), (255, 215, 68), (231, 52, 63)}
cells = [(a, b, colour) for a, b, colour in rectangles
         if colour in palette and abs((b[0]-a[0])-27.664565) < .002
         and abs((b[1]-a[1])-12.211584) < .002]
assert len(cells) == 25, 'All 25 cells must have a nonzero, correctly scaled physical box'
assert {colour for _, _, colour in cells} == palette
assert abs(min(a[0] for a, _, _ in cells)-585.370882) < .002
assert abs(min(a[1] for a, _, _ in cells)-467.686105) < .002
assert abs(max(b[0] for _, b, _ in cells)-723.693706) < .002
assert abs(max(b[1] for _, b, _ in cells)-528.744026) < .002
assert (198, 239, 206) in {colour for _, _, colour in rectangles}
assert (255, 235, 156) in {colour for _, _, colour in rectangles}
assert (173, 175, 177) in {colour for _, _, colour in rectangles}
boundaries, position = [20.64], 20.64
for width in [57.96, 124.10, 88.43, 27.48, 26.08, 26.28,
              213.38, 24.84, 24.48, 24.75, 76.31, 85.71]:
    position += width
    boundaries.append(position)
for x in boundaries:
    assert any(abs(a[0]-x) < .002 and abs(b[0]-x) < .002
               and abs(a[1]-b[1]) > 5 for a, b in segments), f'Original column boundary {x}'
assert not reader.trailer['/Root'].get('/OpenAction')
assert not reader.trailer['/Root'].get('/AcroForm')
print(json.dumps({'reference_pdf_contract': 'passed', 'coloured_cells': len(cells),
                  'column_boundaries': len(boundaries), 'copied_source_objects': False}))
