"""Internal issued ReportLab PDF + CII -> candidate PDF. NOT valid until Mustang/veraPDF passes.

Never accepts uploaded PDFs at the HTTP boundary. No network, OCR, JavaScript,
shell commands, rendered replacement pages, signatures or additional attachments.
"""
import io
import logging
import sys
from pathlib import Path

from PIL import ImageCms
from facturx import generate_from_binary
from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, DecodedStreamObject, DictionaryObject, NameObject, NumberObject, TextStringObject


def build(source: bytes, xml: bytes) -> bytes:
    if len(source) > 15_000_000 or len(xml) > 1_000_000 or b"<!DOCTYPE" in xml or b"<!ENTITY" in xml:
        raise ValueError("Input not supported")
    original = PdfReader(io.BytesIO(source), strict=True)
    if original.is_encrypted or not 1 <= len(original.pages) <= 100:
        raise ValueError("Encrypted or oversized original")
    root = original.trailer["/Root"]
    if any(key in root for key in ("/AcroForm", "/Perms", "/OpenAction", "/AA", "/AF")) or original.attachments:
        raise ValueError("Interactive, signed or already hybrid original")
    if "/JavaScript" in root.get("/Names", {}):
        raise ValueError("Active original")
    for page in original.pages:
        if any(key in page for key in ("/AA", "/Annots")):
            raise ValueError("Interactive original")
    # ReportLab embeds the visible fonts. Set the missing RGB output intent.
    # This does not claim arbitrary PDFs have been converted to PDF/A.
    writer = PdfWriter(clone_from=original)
    icc = DecodedStreamObject()
    icc.set_data(ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes())
    icc[NameObject("/N")] = NumberObject(3)
    intent = DictionaryObject({NameObject("/Type"): NameObject("/OutputIntent"), NameObject("/S"): NameObject("/GTS_PDFA1"), NameObject("/OutputConditionIdentifier"): TextStringObject("sRGB IEC61966-2.1"), NameObject("/Info"): TextStringObject("sRGB IEC61966-2.1"), NameObject("/RegistryName"): TextStringObject("http://www.color.org"), NameObject("/DestOutputProfile"): writer._add_object(icc)})
    writer._root_object[NameObject("/OutputIntents")] = ArrayObject([writer._add_object(intent)])
    staged = io.BytesIO()
    writer.write(staged)
    # Schematron runs locally in the mandatory Java validator, never on a remote server.
    output = generate_from_binary(staged.getvalue(), xml, flavor="factur-x", level="en16931", check_xsd=True, check_schematron=False, afrelationship="alternative", lang="de-DE")
    hybrid = PdfReader(io.BytesIO(output), strict=True)
    if set(hybrid.attachments) != {"factur-x.xml"} or hybrid.attachments["factur-x.xml"] != [xml]:
        raise ValueError("Embedded invoice mismatch")
    if len(hybrid.pages) != len(original.pages) or any(a.extract_text() != b.extract_text() or a.get_contents().get_data() != b.get_contents().get_data() for a, b in zip(original.pages, hybrid.pages)):
        raise ValueError("Original page contents changed")
    af = hybrid.trailer["/Root"]["/AF"]
    if len(af) != 1 or af[0].get_object().get("/AFRelationship") != "/Alternative":
        raise ValueError("Attachment relationship mismatch")
    return output


if __name__ == "__main__":
    logging.disable(logging.CRITICAL)
    if len(sys.argv) != 4:
        raise SystemExit("Expected source PDF, source XML and private output PDF")
    source_path, xml_path, output_path = map(Path, sys.argv[1:])
    output_path.write_bytes(build(source_path.read_bytes(), xml_path.read_bytes()))
