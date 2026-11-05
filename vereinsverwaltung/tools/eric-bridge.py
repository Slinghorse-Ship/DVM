"""One ERiC process per request. No credentials in argv, environment or files.

The JSON runtime is operator-controlled and private. Business authorization,
immutable approvals and duplicate suppression are additionally enforced in PHP.
Licensed original SDK archives are retained with their distribution receipts.
The bridge loads a separately materialized private runtime; certificates stay private.
"""
import base64
import ctypes as c
from datetime import datetime, timezone, timedelta
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import tempfile
from xml.etree import ElementTree as ET

MAX_REQUEST = 8 * 1024 * 1024
MAX_XML = 4 * 1024 * 1024
NS = 'http://www.elster.de/elsterxml/schema/v11'
CONTEXT = {'transport_attempted': False, 'sent': False}


class Rejected(Exception):
    pass


def xml_root(xml):
    if not isinstance(xml, str) or len(xml.encode()) > MAX_XML or '\x00' in xml:
        raise Rejected('XML fehlt oder ist zu groß.')
    if re.search(r'<!\s*(DOCTYPE|ENTITY)', xml, re.I):
        raise Rejected('DTD und externe Entitäten sind nicht erlaubt.')
    try:
        return ET.fromstring(xml)
    except ET.ParseError:
        raise Rejected('XML ist nicht wohlgeformt.') from None


def check_envelope(xml, profile, manufacturer, environment):
    root = xml_root(xml)
    if root.tag != '{' + NS + '}Elster':
        raise Rejected('ElsterBasis-XML 11 erforderlich.')
    headers = root.findall('{' + NS + '}TransferHeader')
    if len(headers) != 1 or headers[0].get('version') != '11':
        raise Rejected('Genau ein TransferHeader 11 erforderlich.')
    header = headers[0]
    for key, expected in [('HerstellerID', manufacturer), ('DatenArt', profile['data_art']),
                          ('Verfahren', profile['verfahren']), ('Vorgang', profile['vorgang'])]:
        elements = header.findall('{' + NS + '}' + key)
        if len(elements) != 1 or (elements[0].text or '').strip() != expected:
            raise Rejected('Unpassender ELSTER-Kopf: ' + key)
    markers = header.findall('{' + NS + '}Testmerker')
    if environment == 'test':
        if len(markers) != 1 or (markers[0].text or '') != profile['test_marker']:
            raise Rejected('Der vorgeschriebene Testmerker fehlt.')
    elif markers:
        raise Rejected('Eine echte Abgabe darf keinen Testmerker enthalten.')
    payload = root.findall('{' + NS + '}DatenTeil/{' + NS + '}Nutzdatenblock/{' + NS + '}Nutzdaten')
    if len(payload) != 1 or len(payload[0]) not in (1, 2):
        raise Rejected('Genau ein Nutzdatenblock pro Auftrag erforderlich.')
    if payload[0][0].tag != profile['payload_tag']:
        raise Rejected('Formular, Jahr und Nutzdaten passen nicht zusammen.')
    if len(payload[0]) == 2:
        version = profile.get('attachment_version')
        if version not in (3, 4) or payload[0][1].tag != '{http://finkonsens.de/elster/anhaenge/simple/v'+str(version)+'}Anhaenge':
            raise Rejected('Anhänge passen nicht zum Verfahren.')
    return root


class PrintParameters(c.Structure):
    _fields_ = [('version', c.c_uint32), ('vorschau', c.c_uint32),
                ('duplexDruck', c.c_uint32), ('pdfName', c.c_char_p),
                ('fussText', c.c_char_p), ('pdfCallback', c.c_void_p),
                ('pdfCallbackBenutzerdaten', c.c_void_p)]


class CryptoParameters(c.Structure):
    _fields_ = [('version', c.c_uint32), ('zertifikatHandle', c.c_uint32), ('pin', c.c_char_p)]


class Eric:
    def __init__(self, cfg, work):
        library = Path(cfg['library']).resolve(strict=True)
        self.search = os.add_dll_directory(str(library.parent)) if os.name == 'nt' else None
        self.api = (c.WinDLL if os.name == 'nt' else c.CDLL)(str(library))
        def bind(name, result, args):
            f = getattr(self.api, name); f.restype = result; f.argtypes = args
            return f
        self.init = bind('EricInitialisiere', c.c_int, [c.c_char_p, c.c_char_p])
        self.end = bind('EricBeende', c.c_int, [])
        self.allocate = bind('EricRueckgabepufferErzeugen', c.c_void_p, [])
        self.free = bind('EricRueckgabepufferFreigeben', c.c_int, [c.c_void_p])
        self.content = bind('EricRueckgabepufferInhalt', c.c_char_p, [c.c_void_p])
        self.version = bind('EricVersion', c.c_int, [c.c_void_p])
        self.error = bind('EricHoleFehlerText', c.c_int, [c.c_int, c.c_void_p])
        self.process = bind('EricBearbeiteVorgang', c.c_int,
            [c.c_char_p, c.c_char_p, c.c_uint32, c.c_void_p, c.c_void_p, c.c_void_p, c.c_void_p])
        self.open_certificate = bind('EricZertifikatOeffnenAusBytes', c.c_int,
            [c.POINTER(c.c_uint32), c.c_void_p, c.c_uint32, c.c_char_p])
        self.close_certificate = bind('EricCloseHandleToCertificate', c.c_int, [c.c_uint32])
        self.properties = bind('EricHoleZertifikatEigenschaften', c.c_int,
            [c.c_uint32, c.c_char_p, c.c_void_p])
        self.log_callback_api = bind('EricRegistriereLogCallback', c.c_int,
            [c.c_void_p, c.c_uint32, c.c_void_p])
        encoding = 'mbcs' if os.name == 'nt' else 'utf-8'
        rc = self.init(str(library.parent / 'plugins').encode(encoding), str(work).encode(encoding))
        if rc:
            raise Rejected('ERiC-Initialisierung fehlgeschlagen: ' + str(rc))
        # Keep diagnostics in the structured result; never write tax contents to eric.log.
        callback_type = (c.WINFUNCTYPE if os.name == 'nt' else c.CFUNCTYPE)(None,c.c_char_p,c.c_int,c.c_char_p,c.c_void_p)
        self.log_callback = callback_type(lambda category,level,message,user: None)
        rc = self.log_callback_api(c.cast(self.log_callback,c.c_void_p),0,None)
        if rc:
            self.end()
            raise Rejected('Datensparsame ERiC-Protokollierung konnte nicht aktiviert werden.')

    def buffer_call(self, function, *args):
        handle = self.allocate()
        if not handle:
            raise Rejected('ERiC-Speicher nicht verfügbar.')
        try:
            rc = function(*args, handle)
            return rc, (self.content(handle) or b'').decode('utf-8', errors='replace')
        finally:
            self.free(handle)

    def run(self, xml, version, print_path=None, crypto=None):
        if version.startswith('Bilanz_'):
            from ebilanz_release import xml_package, check_runtime
            try:
                package = xml_package(xml, version)
                rc, native_version = self.buffer_call(self.version)
                if rc:
                    raise ValueError('Installierte ERiC-Version konnte nicht geprüft werden.')
                check_runtime(package, native_version)
            except ValueError as exc:
                raise Rejected(str(exc)) from None
        response, server = self.allocate(), self.allocate()
        if not response or not server:
            raise Rejected('ERiC-Speicher nicht verfügbar.')
        try:
            flags = 2  # ERIC_VALIDIERE
            printer = None
            if print_path is not None:
                flags |= 32  # ERIC_DRUCKE
                encoded = str(print_path).encode('mbcs' if os.name == 'nt' else 'utf-8')
                printer = PrintParameters(4, 0 if crypto else 1, 0, encoded, None, None, None)
            if crypto is not None:
                flags |= 4  # ERIC_SENDE, only reachable after all guards
            rc = self.process(xml.encode('utf-8'), version.encode('ascii'), flags,
                c.byref(printer) if printer else None, c.byref(crypto) if crypto else None,
                response, server)
            return {'code': rc, 'message': self.buffer_call(self.error, rc)[1],
                'response_xml': (self.content(response) or b'').decode('utf-8', errors='replace'),
                'server_xml': (self.content(server) or b'').decode('utf-8', errors='replace')}
        finally:
            self.free(response); self.free(server)


def response_ticket(xml):
    if not xml:
        return ''
    root = xml_root(xml)
    tickets = [(node.text or '').strip() for node in root.iter()
               if node.tag.rsplit('}', 1)[-1].lower() == 'transferticket']
    errors = [(node.text or '').strip() for node in root.iter()
              if node.tag.rsplit('}', 1)[-1] == 'Code' and (node.text or '').strip() not in ('', '0')]
    return tickets[0] if len(tickets) == 1 and tickets[0] and not errors else ''


def certificate_summary(properties_xml):
    """Only public, allowlisted metadata; never return subject identifiers or keys."""
    ns = '{http://www.elster.de/EricXML/2.0/EricHoleZertifikatEigenschaften}'
    root = xml_root(properties_xml)
    if root.tag != ns + 'EricHoleZertifikatEigenschaften':
        raise Rejected('Zertifikatseigenschaften sind unbekannt.')
    blocks = list(root)
    if not blocks or any(b.tag not in (ns+'Signaturzertifikateigenschaften', ns+'Verschluesselungszertifikateigenschaften') for b in blocks):
        raise Rejected('Zertifikatseigenschaften sind unvollständig.')
    def instant(value):
        m = re.fullmatch(r'(\d{10})(\d{2})?(\d{2})?(?:[.,]\d+)?(Z|[+-]\d{2}(?:\d{2})?)', value)
        if not m:
            raise Rejected('Zertifikatsgültigkeit konnte nicht geprüft werden.')
        zone = m[4]
        offset = timedelta() if zone == 'Z' else timedelta(hours=int(zone[1:3]), minutes=int(zone[3:] or '0')) * (1 if zone[0] == '+' else -1)
        return datetime.strptime(m[1]+(m[2] or '00')+(m[3] or '00'), '%Y%m%d%H%M%S').replace(tzinfo=timezone(offset))
    ends = []
    for block in blocks:
        start, end = instant(block.findtext(ns+'AusgestelltAm', '')), instant(block.findtext(ns+'GueltigBis', ''))
        if not start <= datetime.now(timezone.utc) < end:
            raise Rejected('Zertifikat ist abgelaufen oder noch nicht gültig.')
        ends.append(end)
    return {'organization': all(b.findtext(ns+'Registrierertyp') == 'Organisation' for b in blocks),
            'test': any(b.findtext(ns+'Testzertifikat', 'false').strip().lower() in ('true', '1') for b in blocks),
            'valid_until': min(ends).astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')}


def inspect_certificate(eric, request):
    """Local native certificate/PIN check only. Never calls EricBearbeiteVorgang."""
    handle = c.c_uint32(0)
    pin_buffer = None
    cert = bytearray()
    try:
        pin = request.pop('pin', '')
        if not isinstance(pin, str) or not 1 <= len(pin.encode()) <= 256 or '\x00' in pin:
            raise Rejected('Zertifikats-Passwort fehlt oder ist ungültig.')
        cert = bytearray(base64.b64decode(request.pop('certificate_b64', ''), validate=True))
        if not 1 <= len(cert) <= 1024 * 1024:
            raise Rejected('Zertifikatsdatei fehlt oder ist zu groß.')
        cert_buffer = (c.c_ubyte * len(cert)).from_buffer(cert)
        pin_buffer = c.create_string_buffer(pin.encode())
        del pin
        if eric.open_certificate(c.byref(handle), cert_buffer, len(cert), pin_buffer):
            raise Rejected('Zertifikat oder Zertifikats-Passwort prüfen.')
        rc, properties_xml = eric.buffer_call(eric.properties, handle, pin_buffer)
        if rc:
            raise Rejected('Zertifikat oder Zertifikats-Passwort prüfen.')
        return {'code': 0, 'sent': False, 'transport_attempted': False,
                'certificate': certificate_summary(properties_xml)}
    finally:
        cert[:] = b'\x00' * len(cert)
        if pin_buffer is not None:
            c.memset(pin_buffer, 0, c.sizeof(pin_buffer))
        if handle.value:
            eric.close_certificate(handle)


def main():
    os.umask(0o077)
    config_path = Path(sys.argv[1]).resolve(strict=True)
    cfg = json.loads(config_path.read_text(encoding='utf-8-sig'))
    raw = sys.stdin.buffer.read(MAX_REQUEST + 1)
    if len(raw) > MAX_REQUEST:
        raise Rejected('Auftrag zu groß.')
    request = json.loads(raw)
    del raw
    if not isinstance(request, dict) or request.get('action') not in ('status', 'certificate', 'validate', 'preview', 'send', 'feedback-fetch', 'feedback-ack', 'balance-generate', 'balance-runtime'):
        raise Rejected('Unbekannter ERiC-Auftrag.')
    manufacturer = str(cfg.get('manufacturer_id', ''))
    if not re.fullmatch(r'[0-9]{5}', manufacturer):
        raise Rejected('Eine fünfstellige ELSTER-Hersteller-ID ist erforderlich.')
    environment = request.get('environment', 'test')
    if environment not in ('test', 'production'):
        raise Rejected('Ungültige ELSTER-Umgebung.')
    if request['action'] in ('send', 'feedback-fetch', 'feedback-ack') and (
            cfg.get('transport_disabled') is True or os.environ.get('VEREIN_ERIC_DISABLE_TRANSPORT') == '1'):
        declared_hash = request.get('xml_sha256', '')
        CONTEXT.update(environment=environment, xml_sha256=declared_hash
                       if isinstance(declared_hash, str) and re.fullmatch('[a-f0-9]{64}', declared_hash) else '')
        raise Rejected('ELSTER-Netzwerkzugriffe sind in dieser Umgebung gesperrt.')
    # Preparation has no SDK dependency. Native production checks and transport
    # retain their distinct operator, actual runtime and annual package gates.
    balance = request['action'] in ('balance-generate', 'balance-runtime') or str(request.get('data_version','')).startswith('Bilanz_')
    if balance:
        permitted = (cfg.get('balance_test_enabled') is True if environment == 'test'
                     else cfg.get('balance_production_preparation' if request['action'] == 'balance-generate' else 'balance_production_enabled') is True)
        if not permitted:
            raise Rejected('Eigene E-Bilanz ist für diese Umgebung nicht freigegeben.')
    if request['action'] == 'balance-generate':
        if set(request) != {'action', 'environment', 'values', 'mapping', 'profile'}:
            raise Rejected('E-Bilanz-Auftrag enthält unbekannte Angaben.')
        from ebilanz_builder import EbilanzBuilder
        try:
            package = EbilanzBuilder(environment=environment).build(request['values'], request['mapping'], request['profile'], manufacturer)
        except (ValueError, KeyError, TypeError) as exc:
            raise Rejected(str(exc)) from None
        print(json.dumps({'code': 0, 'sent': False, 'transport_attempted': False, **package}, ensure_ascii=True))
        return  # No native SDK, certificate or network is opened by generation.
    runtime_package = None
    if request['action'] == 'balance-runtime':
        CONTEXT.update(environment=environment, xml_sha256='')
        if set(request) != {'action', 'environment', 'data_version', 'tax_year', 'package_sha256'}:
            raise Rejected('E-Bilanz-Laufzeitauftrag enthält unbekannte Angaben.')
        from ebilanz_release import ADAPTERS, package as release_package, digest as release_digest, require_environment
        adapters = [row for row in ADAPTERS.values() if row['data_version'] == request.get('data_version')]
        profile = cfg.get('profiles', {}).get(request.get('data_version'))
        if (len(adapters) != 1 or not profile or profile.get('family') != 'balance'
                or (environment == 'production' and profile.get('test_only') is True)):
            raise Rejected('E-Bilanz-Profil und native Prüfumgebung stimmen nicht überein.')
        try:
            runtime_package = require_environment(release_package(adapters[0]['taxonomy'], request.get('tax_year')), environment)
            if release_digest(runtime_package) != request.get('package_sha256'):
                raise ValueError('E-Bilanz-Laufzeit und ursprüngliches Jahrespaket stimmen nicht überein.')
        except ValueError as exc:
            raise Rejected(str(exc)) from None
    profile = None
    if request['action'] not in ('status', 'certificate', 'feedback-fetch', 'feedback-ack', 'balance-runtime'):
        profile = cfg.get('profiles', {}).get(request.get('data_version'))
        if not profile:
            raise Rejected('Diese amtliche Datenartversion ist nicht installiert.')
        if environment == 'production' and profile.get('test_only') is True:
            raise Rejected('Dieser amtliche Formularstand ist ausschließlich für Tests zugelassen.')
        check_envelope(request.get('xml'), profile, manufacturer, environment)
        digest = hashlib.sha256(request['xml'].encode()).hexdigest()
        if request.get('xml_sha256') != digest:
            raise Rejected('Prüfsumme des Auftrags stimmt nicht.')
        CONTEXT.update(environment=environment,xml_sha256=digest)
    sending = request['action'] == 'send'
    if balance and request['action'] != 'balance-runtime':
        from ebilanz_release import xml_package, require_environment
        try:
            require_environment(xml_package(request['xml'], request['data_version']), environment, transport=sending)
        except ValueError as exc:
            raise Rejected(str(exc)) from None
    if sending:
        if request.get('confirm') != 'SEND-' + environment.upper():
            raise Rejected('Ausdrückliche Übermittlungsbestätigung fehlt.')
        if environment == 'test' and request.get('synthetic_confirmed') is not True:
            raise Rejected('Testabgaben sind ausschließlich für bestätigte synthetische Daten vorgesehen.')
        if environment == 'production' and (cfg.get('production_enabled') is not True or
                request.get('data_version') not in cfg.get('production_versions', [])):
            raise Rejected('Produktivabgabe ist für diesen Formularstand nicht freigegeben.')
        if profile.get('transport_enabled') is not True:
            raise Rejected('Übermittlung für dieses Verfahren noch nicht freigegeben.')
    with tempfile.TemporaryDirectory(prefix='eric-job-', dir=cfg['scratch']) as temp:
        work = Path(temp)
        eric = Eric(cfg, work)
        handle = c.c_uint32(0)
        pin_buffer = None
        try:
            if request['action'] == 'status':
                rc, version = eric.buffer_call(eric.version)
                result = {'code': rc, 'version_xml': version, 'available': rc == 0,
                          'profiles': list(cfg.get('profiles', {})),
                          'transport_attempted': False, 'sent': False}
            elif request['action'] == 'balance-runtime':
                from ebilanz_release import check_runtime, digest as release_digest
                rc, version_xml = eric.buffer_call(eric.version)
                if rc:
                    raise Rejected('Installierte ERiC-Version konnte nicht geprüft werden.')
                try:
                    versions = check_runtime(runtime_package, version_xml)
                except ValueError as exc:
                    raise Rejected(str(exc)) from None
                result = {'code': 0, 'runtime_verified': True, 'sent': False, 'transport_attempted': False,
                          'environment': environment, 'package_sha256': release_digest(runtime_package),
                          'version_sha256': hashlib.sha256(version_xml.encode()).hexdigest(), 'eric_versions': versions}
            elif request['action'] == 'certificate':
                result = inspect_certificate(eric, request)
            elif request['action'] in ('feedback-fetch', 'feedback-ack'):
                import eric_feedback
                try:
                    result = eric_feedback.execute(eric,cfg,work,request,CryptoParameters,certificate_summary)
                except (ValueError, ET.ParseError) as exc:
                    raise Rejected(str(exc)) from None
            else:
                crypto = None
                # Complete local plausibility validation before loading any certificate.
                result = eric.run(request['xml'], request['data_version'])
                result.update(environment=environment, xml_sha256=digest, sent=False, transport_attempted=False)
                if result['code'] == 0 and request['action'] in ('preview', 'send'):
                    if sending:
                        pin = request.pop('pin', '')
                        if not isinstance(pin, str) or not 1 <= len(pin) <= 256 or '\x00' in pin:
                            raise Rejected('Zertifikats-PIN fehlt oder ist ungültig.')
                        cert = bytearray(base64.b64decode(request.pop('certificate_b64', ''), validate=True))
                        if not 1 <= len(cert) <= 1024 * 1024:
                            raise Rejected('Zertifikatsdatei fehlt oder ist zu groß.')
                        cert_buffer = (c.c_ubyte * len(cert)).from_buffer(cert)
                        pin_buffer = c.create_string_buffer(pin.encode())
                        del pin
                        rc = eric.open_certificate(c.byref(handle), cert_buffer, len(cert), pin_buffer)
                        cert[:] = b'\x00' * len(cert)
                        if rc:
                            raise Rejected('Zertifikat konnte nicht geöffnet werden: ' + str(rc))
                        rc, props_xml = eric.buffer_call(eric.properties, handle, pin_buffer)
                        if rc:
                            raise Rejected('Zertifikat konnte nicht geprüft werden: ' + str(rc))
                        props = xml_root(props_xml)
                        test_values = [(n.text or '').lower() for n in props.iter()
                                       if n.tag.rsplit('}', 1)[-1] == 'Testzertifikat']
                        is_test = any(v in ('true', '1') for v in test_values)
                        if (environment == 'test') != is_test:
                            raise Rejected('Test- und Produktivzertifikat dürfen nicht verwechselt werden.')
                        crypto = CryptoParameters(3, handle.value, c.cast(pin_buffer, c.c_char_p))
                    pdf = work / 'erklaerung.pdf' if profile.get('pdf_supported') else None
                    if sending:
                        CONTEXT['transport_attempted'] = True
                    result = eric.run(request['xml'], request['data_version'], pdf, crypto)
                    result.update(environment=environment, xml_sha256=digest, transport_attempted=sending)
                    ticket = response_ticket(result['server_xml']) if sending else ''
                    result.update(transfer_ticket=ticket, sent=bool(sending and result['code'] == 0 and ticket))
                    if sending:
                        c.memset(pin_buffer, 0, c.sizeof(pin_buffer))
                    if pdf and pdf.is_file():
                        if pdf.stat().st_size > 8 * 1024 * 1024:
                            raise Rejected('PDF-Ausgabe überschreitet das Größenlimit.')
                        result['pdf_b64'] = base64.b64encode(pdf.read_bytes()).decode('ascii')
                result.setdefault('sent', False)
            print(json.dumps(result, ensure_ascii=True))
        finally:
            if pin_buffer is not None:
                c.memset(pin_buffer, 0, c.sizeof(pin_buffer))
            if handle.value:
                eric.close_certificate(handle)
            eric.log_callback_api(None,0,None)
            eric.end()
            if eric.search:
                eric.search.close()


if __name__ == '__main__':
    try:
        main()
    except Rejected as exc:
        print(json.dumps({**CONTEXT, 'code': -1, 'message': str(exc)}))
        raise SystemExit(2)
    except Exception:
        # Never expose certificate material, XML, local paths or native logs in errors.
        print(json.dumps({**CONTEXT, 'code': -2, 'message': 'ERiC-Auftrag technisch nicht abgeschlossen.'}))
        raise SystemExit(2)
