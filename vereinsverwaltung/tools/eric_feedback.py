"""ERiC 44 / ElsterDatenabholung 32. Only attachment feedback for a known ticket.

Fetching NEVER acknowledges a provision. The application must durably store the
complete feedback first, then request a separate acknowledgement. No PIN on disk.
"""
import base64
import ctypes as c
import hashlib
import re
from pathlib import Path
from xml.etree import ElementTree as ET

NS = 'http://www.elster.de/elsterxml/schema/v11'
DA = 'http://finkonsens.de/elster/elsterdatenabholung/v3'
LIMIT = 1024 * 1024


def identifier(value):
    if not isinstance(value, str) or not re.fullmatch(r'[a-zA-Z0-9-]{16,64}', value):
        raise ValueError('Ungültige ELSTER-Referenz.')
    return value


def envelope(manufacturer, environment, ticket, cursor='', provisions=None):
    identifier(ticket)
    if environment not in ('test', 'production') or not re.fullmatch(r'[0-9]{5}', manufacturer):
        raise ValueError('Ungültige ELSTER-Umgebung.')
    art = 'PostfachBestaetigung' if provisions is not None else 'PostfachAnfrage'
    root = ET.Element('Elster', xmlns=NS)
    head = ET.SubElement(root, 'TransferHeader', version='11')
    for name, value in [('Verfahren', 'ElsterDatenabholung'), ('DatenArt', art), ('Vorgang', 'send-Auth')]:
        ET.SubElement(head, name).text = value
    if environment == 'test':
        ET.SubElement(head, 'Testmerker').text = '700000001'
    ET.SubElement(head, 'HerstellerID').text = manufacturer
    ET.SubElement(head, 'DatenLieferant').text = 'Vereinsmanager'
    file = ET.SubElement(head, 'Datei')
    for name, value in [('Verschluesselung', 'CMSEncryptedData'), ('Kompression', 'GZIP'), ('TransportSchluessel', '')]:
        ET.SubElement(file, name).text = value
    ET.SubElement(head, 'VersionClient').text = 'Vereinsmanager 1'
    block = ET.SubElement(ET.SubElement(root, 'DatenTeil'), 'Nutzdatenblock')
    nh = ET.SubElement(block, 'NutzdatenHeader', version='11')
    ET.SubElement(nh, 'NutzdatenTicket').text = ticket
    ET.SubElement(nh, 'Empfaenger', id='L').text = 'CS'
    mh = ET.SubElement(nh, 'Hersteller')
    ET.SubElement(mh, 'ProduktName').text = 'Vereinsmanager'
    ET.SubElement(mh, 'ProduktVersion').text = '1'
    ET.SubElement(nh, 'DatenLieferant').text = 'Vereinsmanager'
    payload = ET.SubElement(ET.SubElement(block, 'Nutzdaten'), 'Datenabholung', xmlns=DA, version='32')
    if provisions is None:
        attrs = {'einschraenkung': 'alle', 'max': '10', 'rolle': 'inhaber'}
        if cursor:
            attrs['paginierungLetzteBestId'] = identifier(cursor)
        query = ET.SubElement(payload, art, attrs)
        ET.SubElement(query, 'DatenartBereitstellung', name='AnhangRueckmeldung', lieferticket=ticket)
    else:
        if not isinstance(provisions, list) or not 1 <= len(provisions) <= 100 or len(set(provisions)) != len(provisions):
            raise ValueError('Ungültige Empfangsbestätigung.')
        ids = ET.SubElement(ET.SubElement(payload, art), 'Bereitstellungen')
        for value in provisions:
            ET.SubElement(ids, 'Bereitstellung', id=identifier(value))
    return '<?xml version="1.0" encoding="UTF-8"?>\n'+ET.tostring(root, encoding='unicode'), art + '_32'


def parse_xml(value):
    if not isinstance(value, str) or len(value.encode()) > 4 * LIMIT or re.search(r'<!\s*(DOCTYPE|ENTITY)', value, re.I):
        raise ValueError('Ungültige ELSTER-Rückmeldung.')
    return ET.fromstring(value)


def server_response(result, art, request_ticket):
    if result.get('code') != 0:
        detail = ''
        try:
            failed = parse_xml(result.get('server_xml', ''))
            detail = failed.findtext('{'+NS+'}TransferHeader/{'+NS+'}RC/{'+NS+'}Rueckgabe/{'+NS+'}Text', '')[:600]
        except (ValueError, ET.ParseError):
            pass
        raise ValueError('ELSTER-Abholung fehlgeschlagen (Code %s). %s' % (result.get('code'), detail))
    root = parse_xml(result.get('server_xml', ''))
    head = root.find('{'+NS+'}TransferHeader')
    if root.tag != '{'+NS+'}Elster' or head is None or head.findtext('{'+NS+'}DatenArt') != art:
        raise ValueError('Unpassende Antwort auf die ELSTER-Abholung.')
    rc = head.findtext('{'+NS+'}RC/{'+NS+'}Rueckgabe/{'+NS+'}Code')
    if rc != '0':
        raise ValueError('ELSTER hat die Abholung nicht bestätigt.')
    # Some successful responses omit Nutzdaten-RC, but errors at any layer fail closed.
    if any((n.text or '').strip() not in ('', '0') for n in root.iter('{'+NS+'}Code')):
        raise ValueError('ELSTER meldet einen Fehler bei der Abholung.')
    blocks = root.findall('{'+NS+'}DatenTeil/{'+NS+'}Nutzdatenblock')
    if len(blocks) != 1 or blocks[0].findtext('{'+NS+'}NutzdatenHeader/{'+NS+'}NutzdatenTicket') != request_ticket:
        raise ValueError('ELSTER-Abholauftrag stimmt nicht überein.')
    return root


def parse_list(result, ticket):
    root = server_response(result, 'PostfachAnfrage', ticket)
    answers = root.findall('.//{'+DA+'}PostfachAnfrageAntwort')
    if len(answers) != 1:
        raise ValueError('Postfachantwort fehlt.')
    answer = answers[0]
    cursor = answer.get('paginierungLetzteBestId', '')
    if cursor:
        identifier(cursor)
    rows = []
    for group in answer:
        if group.tag != '{'+DA+'}DatenartBereitstellung' or group.get('name') != 'AnhangRueckmeldung':
            raise ValueError('Nicht angeforderte Postfachdaten erhalten.')
        for entry in group.findall('{'+DA+'}Bereitstellungen/{'+DA+'}Bereitstellung'):
            meta = entry.findall('{'+DA+'}MetaInformationen/{'+DA+'}Meta[@name="lieferticket"]')
            if len(meta) != 1 or meta[0].text != ticket or entry.find('{'+DA+'}Anhaenge') is not None:
                raise ValueError('Rückmeldung lässt sich nicht sicher zuordnen.')
            packet = entry.find('{'+DA+'}Datenpaket')
            if packet is None:
                raise ValueError('ELSTER-Rückmeldepaket fehlt.')
            size = int(packet.findtext('{'+DA+'}Groesse', '0'))
            encoding = packet.findtext('{'+DA+'}Encoding', '')
            if not 0 < size <= LIMIT or encoding not in ('UTF-8', 'ISO-8859-15'):
                raise ValueError('ELSTER-Rückmeldepaket überschreitet die zulässigen Grenzen.')
            rows.append({'id': identifier(entry.get('id')), 'object_id': identifier(packet.findtext('{'+DA+'}ReferenzId')),
                         'size': size, 'encoding': encoding})
    if len(rows) > 10 or len({r['id'] for r in rows}) != len(rows):
        raise ValueError('Postfachantwort ist zu groß oder doppelt.')
    return rows, cursor


def parse_feedback(text, ticket, filing_id, data_art, names):
    # The official SSb v1 specifies an XML fragment (not an Elster envelope).
    fragment = re.sub(r'^\s*<\?xml[^?]*\?>', '', text, count=1)
    root = parse_xml('<AnhangRueckmeldung>'+fragment+'</AnhangRueckmeldung>')
    if len(root) == 1 and root[0].tag == 'AnhangRueckmeldung':
        root = root[0]
    keys = [n.tag for n in root]
    expected = ['TransferTicket','NutzdatenTicket','Datenart','Anrede','Referenz','Anhaenge','Mittelteil','Anweisung','Grussformel']
    if len(set(keys)) != len(keys) or set(keys) not in (set(expected), set(expected+['OrdnungsBegriff'])):
        raise ValueError('Unbekannter Aufbau der Anlagen-Rückmeldung.')
    for key, value in [('TransferTicket', ticket), ('NutzdatenTicket', filing_id), ('Datenart', data_art)]:
        if root.findtext(key) != value:
            raise ValueError('Anlagen-Rückmeldung gehört nicht zu diesem Abgabeauftrag.')
    errors = []
    for item in root.find('Anhaenge'):
        name = item.findtext('Dateibezeichnung', '')
        message = item.findtext('Rueckmeldung', '')
        if item.tag != 'Anhang' or name not in names or not message or len(message) > 20000:
            raise ValueError('Beanstandete Anlage ist nicht eindeutig zuzuordnen.')
        errors.append({'name': name, 'message': message})
    if not 1 <= len(errors) <= 20 or len({e['name'] for e in errors}) != len(errors):
        raise ValueError('Ungültige Liste beanstandeter Anlagen.')
    return {'attachments': errors, 'reference': root.findtext('Referenz', ''),
            'instruction': root.findtext('Anweisung', ''), 'message': root.findtext('Mittelteil', '')}


class Otto:
    def __init__(self, library, work, certificate, pin, manufacturer):
        self.api = c.CDLL(str(library))  # OTTO uses cdecl, including Windows.
        self.handles = []
        self.manufacturer = manufacturer.encode('ascii')
        def bind(name, result, args):
            f = getattr(self.api, name); f.restype = result; f.argtypes = args
            return f
        ptr, out = c.c_void_p, c.POINTER(c.c_void_p)
        self.create = bind('OttoInstanzErzeugen', c.c_int, [c.c_char_p, ptr, ptr, out])
        self.close = bind('OttoInstanzFreigeben', c.c_int, [ptr])
        self.open_cert = bind('OttoZertifikatOeffnenAusBytes', c.c_int, [ptr, ptr, c.c_uint32, c.c_char_p, out])
        self.close_cert = bind('OttoZertifikatSchliessen', c.c_int, [ptr])
        self.alloc = bind('OttoRueckgabepufferErzeugen', c.c_int, [ptr, out])
        self.free = bind('OttoRueckgabepufferFreigeben', c.c_int, [ptr])
        self.size = bind('OttoRueckgabepufferGroesse', c.c_uint64, [ptr])
        self.content = bind('OttoRueckgabepufferInhalt', ptr, [ptr])
        self.begin = bind('OttoEmpfangBeginnen', c.c_int, [ptr, c.c_char_p, ptr, c.c_char_p, out])
        self.next = bind('OttoEmpfangFortsetzen', c.c_int, [ptr, ptr])
        self.end = bind('OttoEmpfangBeenden', c.c_int, [ptr])
        self.callback = c.CFUNCTYPE(c.c_int,c.c_char_p,c.c_char_p,c.c_int,c.c_char_p,ptr)(lambda *_: 0)
        self.instance, self.cert, self.buffer = ptr(), ptr(), ptr()
        try:
            self.check(self.create(str(work).encode('mbcs' if __import__('os').name == 'nt' else 'utf-8'), c.cast(self.callback,ptr), None, c.byref(self.instance)))
            self.handles.append((self.close,self.instance))
            buf = (c.c_ubyte*len(certificate)).from_buffer(certificate)
            self.check(self.open_cert(self.instance,buf,len(certificate),pin,c.byref(self.cert)))
            self.handles.append((self.close_cert,self.cert))
            self.check(self.alloc(self.instance,c.byref(self.buffer)))
            self.handles.append((self.free,self.buffer))
        except Exception:
            self.finish(); raise

    @staticmethod
    def check(rc):
        if rc:
            raise ValueError('ELSTER-Anlagenabholung fehlgeschlagen (OTTO %s).' % rc)

    def fetch(self, object_id):
        handle = c.c_void_p()
        self.check(self.begin(self.instance,identifier(object_id).encode(),self.cert,self.manufacturer,c.byref(handle)))
        result = bytearray()
        try:
            for _ in range(1024):
                self.check(self.next(handle,self.buffer))
                size = self.size(self.buffer)
                if len(result)+size > LIMIT:
                    raise ValueError('Anlagen-Rückmeldung zu groß.')
                if not size:
                    return bytes(result)
                result.extend(c.string_at(self.content(self.buffer),size))
            raise ValueError('Anlagen-Rückmeldung unvollständig.')
        finally:
            self.end(handle)

    def finish(self):
        for function, handle in reversed(self.handles):
            function(handle)
        self.handles.clear()


def execute(eric, cfg, work, request, crypto_type, inspect_summary):
    if cfg.get('attachment_feedback_enabled') is not True:
        raise ValueError('Anlagen-Rückmeldungen sind noch nicht eingerichtet.')
    ticket, filing_id = identifier(request.get('transfer_ticket')), identifier(request.get('filing_id'))
    profile = cfg.get('profiles', {}).get(request.get('data_version'), {})
    if profile.get('attachment_version') not in (3,4):
        raise ValueError('Dieses Verfahren unterstützt keine Anlagen-Rückmeldung.')
    environment = request.get('environment')
    acknowledging = request['action'] == 'feedback-ack'
    provisions = request.get('provisions') if acknowledging else None
    if acknowledging and not provisions:
        raise ValueError('Empfangsbestätigung fehlt.')
    xml, version = envelope(str(cfg['manufacturer_id']),environment,ticket,request.get('cursor',''),provisions)
    validation = eric.run(xml,version)
    if validation.get('code') != 0:
        raise ValueError('ELSTER-Abholauftrag ungültig: '+str(validation.get('code')))
    certificate = bytearray()
    pin_buffer, handle, otto = None, c.c_uint32(), None
    try:
        pin = request.pop('pin','')
        if not isinstance(pin,str) or not 1 <= len(pin.encode()) <= 256 or '\x00' in pin:
            raise ValueError('Zertifikats-Passwort prüfen.')
        pin_buffer = c.create_string_buffer(pin.encode()); del pin
        certificate = bytearray(base64.b64decode(request.pop('certificate_b64',''),validate=True))
        if not 1 <= len(certificate) <= LIMIT:
            raise ValueError('Zertifikatsdatei prüfen.')
        buf = (c.c_ubyte*len(certificate)).from_buffer(certificate)
        if eric.open_certificate(c.byref(handle),buf,len(certificate),pin_buffer):
            raise ValueError('Zertifikat und Zertifikats-Passwort prüfen.')
        rc, properties = eric.buffer_call(eric.properties,handle,pin_buffer)
        if rc:
            raise ValueError('Zertifikat konnte nicht geprüft werden.')
        summary = inspect_summary(properties)
        if summary['test'] != (environment == 'test'):
            raise ValueError('Test- und Produktivzertifikat dürfen nicht verwechselt werden.')
        crypto = crypto_type(3,handle.value,c.cast(pin_buffer,c.c_char_p))
        result = eric.run(xml,version,crypto=crypto)
        if acknowledging:
            server_response(result,'PostfachBestaetigung',ticket)
            return {'code':0,'acknowledged':provisions,'environment':environment,'transfer_ticket':ticket}
        rows, cursor = parse_list(result,ticket)
        names = request.get('attachment_names')
        if not isinstance(names,list) or not 1 <= len(names) <= 20 or any(not isinstance(n,str) for n in names):
            raise ValueError('Anlagenliste fehlt.')
        output = []
        if rows:
            library = Path(cfg['library']).resolve().parent / ('otto.dll' if __import__('os').name == 'nt' else 'libotto.so')
            otto = Otto(library,work,certificate,pin_buffer,str(cfg['manufacturer_id']))
            for row in rows:
                raw = otto.fetch(row['object_id'])
                # SSb v32 p.18: Groesse describes encrypted/compressed transport,
                # while OTTO returns plaintext. Enforce our own bound on both.
                if not raw or len(raw) > LIMIT:
                    raise ValueError('Anlagen-Rückmeldung leer oder zu groß.')
                text = raw.decode(row['encoding'],errors='strict')
                parsed = parse_feedback(text,ticket,filing_id,profile['data_art'],names)
                output.append({**row,'xml':text,'sha256':hashlib.sha256(text.encode()).hexdigest(),'feedback':parsed})
        return {'code':0,'items':output,'cursor':cursor,'environment':environment,'transfer_ticket':ticket}
    finally:
        if otto:
            otto.finish()
        certificate[:] = b'\x00' * len(certificate)
        if pin_buffer is not None:
            c.memset(pin_buffer,0,c.sizeof(pin_buffer))
        if handle.value:
            eric.close_certificate(handle)
