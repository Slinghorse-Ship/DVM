"""Embedded bytes only. Called exclusively after the OCR worker sandbox is active.

PDFium's size query precedes allocation. Attachment names are never file paths.
XML semantics and DTD rejection are handled by EInvoiceInspection in PHP.
"""
import base64
import ctypes
import pypdfium2 as pdfium
import pypdfium2.raw as raw

def extract(data):
    if not data.startswith(b'%PDF-') or len(data)>10485760:
        raise ValueError('PDF-Datei nicht sicher auswertbar.')
    with pdfium.PdfDocument(data) as pdf:
        count=pdf.count_attachments()
        if count<0 or count>16:
            raise ValueError('Zu viele PDF-Anhänge; Rechnungsdaten manuell prüfen.')
        candidates=[]
        for index in range(count):
            attachment=pdf.get_attachment(index)
            name=attachment.get_name()
            if len(name)>255:
                raise ValueError('PDF-Anhangname zu lang.')
            if name.lower().endswith('.xml'):
                candidates.append(attachment)
        if not candidates:
            return {'state':'ready','embedded':False}
        if len(candidates)!=1:
            raise ValueError('Mehrere XML-Anhänge: keine eindeutige E-Rechnung.')
        attachment=candidates[0]
        size=ctypes.c_ulong()
        if not raw.FPDFAttachment_GetFile(attachment,None,0,size) or not 0<size.value<=2097152:
            raise ValueError('Eingebettete XML fehlt oder überschreitet 2 MB.')
        buffer=ctypes.create_string_buffer(size.value)
        written=ctypes.c_ulong()
        if not raw.FPDFAttachment_GetFile(attachment,buffer,size.value,written) or written.value!=size.value:
            raise ValueError('Eingebettete XML konnte nicht sicher gelesen werden.')
        return {'state':'ready','embedded':True,'xml_base64':base64.b64encode(buffer.raw).decode('ascii')}
