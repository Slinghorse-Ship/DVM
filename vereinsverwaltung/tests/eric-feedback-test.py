"""No network/credentials: authentic v32 and attachment feedback boundary shapes."""
import sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).parents[1]/'tools'))
import eric_feedback as f
T='eh2583z0r5q51j6yrij08gi1odod9idu'
ID='a'*32
FRAGMENT=f'<TransferTicket>{T}</TransferTicket><NutzdatenTicket>{ID}</NutzdatenTicket><Datenart>Belegnachreichung</Datenart><Anrede>Test</Anrede><Referenz>Test</Referenz><Anhaenge><Anhang><Dateibezeichnung>Anlage-1.pdf</Dateibezeichnung><Rueckmeldung>PDF ungültig.</Rueckmeldung></Anhang></Anhaenge><Mittelteil>Die Erklärung bleibt gültig.</Mittelteil><Anweisung>Bitte nachreichen.</Anweisung><Grussformel>Finanzamt</Grussformel>'
ENTRY=f'<DatenartBereitstellung name="AnhangRueckmeldung" anzahltreffer="1"><Bereitstellungen><Bereitstellung id="be2583z0r5q51j6yrij08gi1odod9idu"><MetaInformationen><Meta name="lieferticket">{T}</Meta></MetaInformationen><Datenpaket><ReferenzId>19920000-0000-0000-0000-000000000000</ReferenzId><Encoding>UTF-8</Encoding><Groesse>1000</Groesse></Datenpaket></Bereitstellung></Bereitstellungen></DatenartBereitstellung>'
def result(contents=ENTRY,art='PostfachAnfrage',cursor=''):
    return {'code':0,'server_xml':f'<Elster xmlns="{f.NS}"><TransferHeader version="11"><DatenArt>{art}</DatenArt><RC><Rueckgabe><Code>0</Code></Rueckgabe></RC></TransferHeader><DatenTeil><Nutzdatenblock><NutzdatenHeader><NutzdatenTicket>{T}</NutzdatenTicket></NutzdatenHeader><Nutzdaten><Datenabholung xmlns="{f.DA}" version="32"><{art}Antwort {cursor}>{contents}</{art}Antwort></Datenabholung></Nutzdaten></Nutzdatenblock></DatenTeil></Elster>'}
class Feedback(unittest.TestCase):
    def test_negative_feedback(self):
        r=f.parse_feedback(FRAGMENT,T,ID,'Belegnachreichung',['Anlage-1.pdf'])
        self.assertEqual(r['instruction'],'Bitte nachreichen.');self.assertEqual(r['attachments'][0]['name'],'Anlage-1.pdf')
    def test_fragment_or_root(self):
        self.assertEqual(f.parse_feedback(FRAGMENT,T,ID,'Belegnachreichung',['Anlage-1.pdf']),f.parse_feedback('<?xml version="1.0"?><AnhangRueckmeldung>'+FRAGMENT+'</AnhangRueckmeldung>',T,ID,'Belegnachreichung',['Anlage-1.pdf']))
    def test_ticket_and_payload_binding(self):
        for old,new in [(T,'b'*32),(ID,'c'*32),('Belegnachreichung','Other'),('Anlage-1.pdf','Anlage-2.pdf')]:
            with self.subTest(old=old),self.assertRaises(ValueError):f.parse_feedback(FRAGMENT.replace(old,new),T,ID,'Belegnachreichung',['Anlage-1.pdf'])
    def test_dtd_rejected(self):
        with self.assertRaises(ValueError):f.parse_feedback('<!DOCTYPE x SYSTEM "file:///secret">'+FRAGMENT,T,ID,'Belegnachreichung',['Anlage-1.pdf'])
    def test_duplicate_fields_rejected(self):
        with self.assertRaises(ValueError):f.parse_feedback(FRAGMENT+'<TransferTicket>'+T+'</TransferTicket>',T,ID,'Belegnachreichung',['Anlage-1.pdf'])
    def test_empty_is_not_success(self):
        with self.assertRaises(ValueError):f.parse_feedback('',T,ID,'Belegnachreichung',['Anlage-1.pdf'])
    def test_filtered_request(self):
        xml,version=f.envelope('12345','test',T)
        self.assertEqual(version,'PostfachAnfrage_32');self.assertIn('lieferticket="'+T+'"',xml);self.assertIn('<Empfaenger id="L">CS</Empfaenger>',xml);self.assertIn('700000001',xml)
    def test_production_has_no_test_marker(self):self.assertNotIn('Testmerker',f.envelope('12345','production',T)[0])
    def test_ack_requires_explicit_provisions(self):
        for ids in ([],['a'],['b'*32,'b'*32]):
            with self.subTest(ids=ids),self.assertRaises(ValueError):f.envelope('12345','test',T,provisions=ids)
        xml,version=f.envelope('12345','test',T,provisions=['b'*32]);self.assertEqual(version,'PostfachBestaetigung_32');self.assertIn('send-Auth',xml)
    def test_empty_mailbox(self):self.assertEqual(f.parse_list(result(''),T),([],''))
    def test_list_reference(self):
        rows,cursor=f.parse_list(result(),T);self.assertEqual(len(rows),1);self.assertEqual(rows[0]['encoding'],'UTF-8');self.assertEqual(cursor,'')
    def test_pagination(self):self.assertEqual(f.parse_list(result(cursor='paginierungLetzteBestId="'+'b'*32+'"'),T)[1],'b'*32)
    def test_unrequested_or_oversize_records(self):
        for old,new in [('AnhangRueckmeldung','ESB'),(T,'c'*32),('1000','2000000'),('UTF-8','evil'),('lieferticket','missing')]:
            with self.subTest(old=old),self.assertRaises(ValueError):f.parse_list(result(ENTRY.replace(old,new)),T)
    def test_server_error(self):
        r=result();r['server_xml']=r['server_xml'].replace('<Code>0</Code>','<Code>190017112</Code>')
        with self.assertRaises(ValueError):f.parse_list(r,T)
    def test_wrong_response_type(self):
        with self.assertRaises(ValueError):f.server_response(result(),'PostfachBestaetigung',T)
if __name__=='__main__':unittest.main()
