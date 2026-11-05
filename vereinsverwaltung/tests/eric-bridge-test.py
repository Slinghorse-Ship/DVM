"""Pure envelope tests. No SDK, certificates, tax servers or file writes."""
import copy,importlib.util,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('bridge',Path(__file__).parents[1]/'tools/eric-bridge.py')
b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b)
PROFILE={'data_art':'KSt','verfahren':'ElsterErklaerung','vorgang':'send-Auth','test_marker':'700000004','payload_tag':'{urn:test}Form'}
XML='<Elster xmlns="'+b.NS+'"><TransferHeader version="11"><Verfahren>ElsterErklaerung</Verfahren><DatenArt>KSt</DatenArt><Vorgang>send-Auth</Vorgang><Testmerker>700000004</Testmerker><HerstellerID>12345</HerstellerID></TransferHeader><DatenTeil><Nutzdatenblock><Nutzdaten><Form xmlns="urn:test"/></Nutzdaten></Nutzdatenblock></DatenTeil></Elster>'
class Envelope(unittest.TestCase):
 def test_valid_test(self):self.assertIsNotNone(b.check_envelope(XML,PROFILE,'12345','test'))
 def test_valid_production(self):self.assertIsNotNone(b.check_envelope(XML.replace('<Testmerker>700000004</Testmerker>',''),PROFILE,'12345','production'))
 def test_wrong_marker(self):
  for marker in ('700000001','','0'):
   with self.subTest(marker=marker),self.assertRaises(b.Rejected):b.check_envelope(XML.replace('700000004',marker),PROFILE,'12345','test')
 def test_test_marker_never_production(self):
  with self.assertRaises(b.Rejected):b.check_envelope(XML,PROFILE,'12345','production')
 def test_wrong_header(self):
  for old,new in [('12345','00000'),('KSt','EUER'),('send-Auth','send'),('version="11"','version="10"')]:
   with self.subTest(value=new),self.assertRaises(b.Rejected):b.check_envelope(XML.replace(old,new),PROFILE,'12345','test')
 def test_duplicate_header(self):
  with self.assertRaises(b.Rejected):b.check_envelope(XML.replace('</TransferHeader>','<HerstellerID>12345</HerstellerID></TransferHeader>'),PROFILE,'12345','test')
 def test_dtd(self):
  with self.assertRaises(b.Rejected):b.xml_root('<!DOCTYPE Elster [<!ENTITY x SYSTEM "file:///etc/passwd">]>'+XML)
 def test_unknown_payload(self):
  with self.assertRaises(b.Rejected):b.check_envelope(XML.replace('urn:test','urn:other'),PROFILE,'12345','test')
 def test_second_payload(self):
  with self.assertRaises(b.Rejected):b.check_envelope(XML.replace('</Nutzdaten>','<Another/></Nutzdaten>'),PROFILE,'12345','test')
 def test_attachment_profile(self):
  x=XML.replace('</Nutzdaten>','<Anhaenge xmlns="http://finkonsens.de/elster/anhaenge/simple/v4" version="4"/></Nutzdaten>');p=copy.deepcopy(PROFILE);p['attachment_version']=4
  self.assertIsNotNone(b.check_envelope(x,p,'12345','test'))
  with self.assertRaises(b.Rejected):b.check_envelope(x,PROFILE,'12345','test')
 def test_no_ticket_from_failed_response(self):
  self.assertEqual(b.response_ticket('<r><Transferticket>test</Transferticket><Code>99</Code></r>'),'')
  self.assertEqual(b.response_ticket('<r><Transferticket>test</Transferticket><Transferticket>other</Transferticket></r>'),'')
  self.assertEqual(b.response_ticket('<r><Transferticket>test</Transferticket><Code>0</Code></r>'),'test')
class Certificate(unittest.TestCase):
 def props(self,kind='Organisation',test='false',end='20990101000000Z'):
  return '<EricHoleZertifikatEigenschaften xmlns="http://www.elster.de/EricXML/2.0/EricHoleZertifikatEigenschaften"><Verschluesselungszertifikateigenschaften><AusgestelltAm>20200101000000Z</AusgestelltAm><GueltigBis>'+end+'</GueltigBis><Registrierertyp>'+kind+'</Registrierertyp><Testzertifikat>'+test+'</Testzertifikat><Subjekt><Private>never expose this</Private></Subjekt></Verschluesselungszertifikateigenschaften></EricHoleZertifikatEigenschaften>'
 def test_org(self):
  p=b.certificate_summary(self.props());self.assertEqual(set(p),{'organization','test','valid_until'});self.assertTrue(p['organization']);self.assertFalse(p['test'])
 def test_person_and_unknown(self):
  for kind in ['Person','Unbekannt','Rechteverwalter','']:
   self.assertFalse(b.certificate_summary(self.props(kind))['organization'])
 def test_test_certificate(self):self.assertTrue(b.certificate_summary(self.props(test='true'))['test'])
 def test_expired(self):
  with self.assertRaises(b.Rejected):b.certificate_summary(self.props(end='20010101000000Z'))
 def test_bad_namespace(self):
  with self.assertRaises(b.Rejected):b.certificate_summary(self.props().replace('http://www.elster.de/EricXML/2.0/EricHoleZertifikatEigenschaften','urn:wrong'))
 def test_timezone(self):self.assertEqual(b.certificate_summary(self.props(end='20990101020000+02'))['valid_until'],'2099-01-01T00:00:00Z')
 def test_inspection_never_sends(self):
  import inspect
  self.assertNotIn('eric.run(',inspect.getsource(b.inspect_certificate))
if __name__=='__main__':unittest.main()
