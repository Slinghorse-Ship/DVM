"""Use synthetic original/XML from the PHP hybrid test; no network/transport."""
import importlib.util
import io
import sys
from pathlib import Path
from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, DictionaryObject, NameObject

module_path = Path(__file__).parents[1] / 'tools' / 'build-hybrid-invoice.py'
spec = importlib.util.spec_from_file_location('hybrid_builder', module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
directory = Path(sys.argv[1])
original = (directory / 'original.pdf').read_bytes()
xml = (directory / 'invoice.xml').read_bytes()
candidate = module.build(original, xml)
reader = PdfReader(io.BytesIO(candidate))
assert reader.attachments['factur-x.xml'] == [xml]
root = reader.trailer['/Root']
af = root['/AF'][0].get_object()
assert af['/AFRelationship'] == '/Alternative'
assert af['/F'] == 'factur-x.xml' and af['/UF'] == 'factur-x.xml'
assert af['/EF']['/F'].get_object()['/Subtype'] in ('/text/xml', '/application/xml')
xmp = root['/Metadata'].get_data()
assert b'<pdfaid:part>3</pdfaid:part>' in xmp and b'<pdfaid:conformance>B</pdfaid:conformance>' in xmp
assert b'EN 16931' in xmp and b'factur-x.xml' in xmp
assert root['/OutputIntents'][0].get_object()['/DestOutputProfile'].get_object()['/N'] == 3
print('PASS attachment, associated-file relation, XML MIME, XMP profile and RGB output intent')

def refused(source, reason):
    try:
        module.build(source, xml)
    except ValueError:
        print('PASS', reason)
        return
    raise AssertionError(reason)

refused(candidate, 'already hybrid file is never nested or duplicated')
writer = PdfWriter(clone_from=PdfReader(io.BytesIO(original)))
writer.add_js('app.alert("synthetic");')
output = io.BytesIO(); writer.write(output)
refused(output.getvalue(), 'active PDF is rejected, never executed')
writer = PdfWriter(clone_from=PdfReader(io.BytesIO(original)))
writer._root_object[NameObject('/AcroForm')] = DictionaryObject({NameObject('/Fields'): ArrayObject()})
output = io.BytesIO(); writer.write(output)
refused(output.getvalue(), 'form or signature container is rejected, never flattened')
writer = PdfWriter(clone_from=PdfReader(io.BytesIO(original))); writer.encrypt('synthetic-password')
output = io.BytesIO(); writer.write(output)
refused(output.getvalue(), 'encrypted PDF is rejected')
print('5 hybrid PDF container and active-content checks passed')
