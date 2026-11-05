"""Fill preserved official donation forms as unsigned drafts; JSON in, PDF out.

The source PDFs have no AcroForm fields. Values are added as a separate page
content stream; the official wording, boxes and footer remain intact.
"""

import io
import json
import sys
from pathlib import Path

import reportlab
from pypdf import PdfReader, PdfWriter
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas


SOURCE_DIR = Path(__file__).resolve().parents[1] / "resources" / "donation-forms"
DRAFT_NOTICE = "ENTWURF - NICHT ZUR VORLAGE BEIM FINANZAMT"
FONT = "DonationForm"
BOLD = "DonationForm-Bold"
for name, filename in ((FONT, "Vera.ttf"), (BOLD, "VeraBd.ttf")):
    pdfmetrics.registerFont(TTFont(name, str(Path(reportlab.__file__).parent / "fonts" / filename)))

TEXT_LIMITS = {
    "issuer": 500, "donor": 500, "amount": 32, "amount_words": 150,
    "donated_on": 10, "purpose": 1000, "tax_office": 120,
    "tax_number": 60, "notice_date": 10, "assessment_period": 80,
    "goods_description": 600, "signature_place_date": 180,
}
ENUMS = {
    "kind": ("money", "goods"),
    "notice_type": ("", "exemption", "corporate", "assessment"),
    "waiver": ("", "yes", "no"),
    "goods_origin": ("", "business", "private", "unknown"),
}


def validated_values(data):
    if not isinstance(data, dict) or not isinstance(data.get("donation_form"), dict):
        raise ValueError("donation_form must be an object")
    raw = data["donation_form"]
    unknown = set(raw) - set(TEXT_LIMITS) - set(ENUMS) - {"goods_evidence"}
    if unknown:
        raise ValueError("Unknown donation form field")
    values = {}
    font_chars = pdfmetrics.getFont(FONT).face.charToGlyph
    for field, limit in TEXT_LIMITS.items():
        value = raw.get(field, "")
        if not isinstance(value, str) or len(value) > limit:
            raise ValueError(f"Invalid text in {field}")
        value = value.replace("\r\n", "\n").replace("\r", "\n").strip()
        if any((ord(char) < 32 and char != "\n") or ord(char) == 127 for char in value):
            raise ValueError(f"Control character in {field}")
        if any(char != "\n" and ord(char) not in font_chars for char in value):
            raise ValueError(f"Unsupported character in {field}")
        values[field] = value
    for field, choices in ENUMS.items():
        value = raw.get(field, "")
        if not isinstance(value, str) or value not in choices:
            raise ValueError(f"Invalid choice in {field}")
        values[field] = value
    evidence = raw.get("goods_evidence", False)
    if type(evidence) is not bool:
        raise ValueError("goods_evidence must be a boolean")
    values["goods_evidence"] = evidence
    return values


def wrapped_lines(text, width, size):
    lines = []
    for paragraph in text.split("\n"):
        current = ""
        for word in paragraph.split():
            if pdfmetrics.stringWidth(word, FONT, size) > width:
                return None
            candidate = f"{current} {word}" if current else word
            if pdfmetrics.stringWidth(candidate, FONT, size) > width:
                lines.append(current)
                current = word
            else:
                current = candidate
        lines.append(current)
    return lines


class Overlay:
    def __init__(self, width, height):
        self.buffer = io.BytesIO()
        self.page = canvas.Canvas(self.buffer, pagesize=(width, height), pageCompression=1)
        self.height = height
        self.page.setFillColorRGB(0.08, 0.15, 0.23)

    def text(self, field, value, x, top, width, height, size=9, single_line=False):
        if not value:
            return
        if single_line and "\n" in value:
            raise ValueError(f"{field} must be a single line")
        # Never silently clip, truncate or shrink below readable 8 pt.
        for selected in (size, 8.5, 8):
            if selected > size:
                continue
            lines = [value] if single_line else wrapped_lines(value, width, selected)
            if lines is None:
                continue
            ascent = pdfmetrics.getAscent(FONT, selected)
            descent = pdfmetrics.getDescent(FONT, selected)
            line_height = selected * 1.25
            needed = ascent - descent + line_height * (len(lines) - 1)
            if needed > height or any(pdfmetrics.stringWidth(line, FONT, selected) > width for line in lines):
                continue
            self.page.setFont(FONT, selected)
            for index, line in enumerate(lines):
                self.page.drawString(x, self.height - top - ascent - index * line_height, line)
            return
        raise ValueError(f"{field} does not fit its reserved area in the original form")

    def mark(self, x, top):
        self.page.saveState()
        self.page.setStrokeColorRGB(0.08, 0.15, 0.23)
        self.page.setLineWidth(1.3)
        self.page.line(x + 3, self.height - top - 3, x + 11, self.height - top - 11)
        self.page.line(x + 3, self.height - top - 11, x + 11, self.height - top - 3)
        self.page.restoreState()

    def notice(self, width):
        # Both originals have unused space above the first issuer box.
        self.page.saveState()
        self.page.setFillColorRGB(0.62, 0.12, 0.12)
        self.page.setFont(BOLD, 9)
        self.page.drawCentredString(width / 2, self.height - 25, DRAFT_NOTICE)
        self.page.restoreState()

    def finish(self):
        self.page.showPage()
        self.page.save()
        self.buffer.seek(0)
        return PdfReader(self.buffer).pages[0]


def render(data):
    values = validated_values(data)
    kind = values["kind"]
    source = SOURCE_DIR / f"{kind}-original.pdf"
    reader = PdfReader(source)
    if len(reader.pages) != 1 or reader.get_fields() or reader.pages[0].get("/Annots"):
        raise ValueError("Unexpected original form structure; review the template before filling")
    page = reader.pages[0]
    width, height = float(page.mediabox.width), float(page.mediabox.height)
    if abs(width - 595.28) > 0.1 or abs(height - 841.89) > 0.1 or page.rotation:
        raise ValueError("Unexpected original form dimensions")
    overlay = Overlay(width, height)
    issued = data.get("kind") == "donation_receipt"
    if issued:
        number = data.get("receipt_number", "")
        import re
        if not re.fullmatch(r"ZB-[0-9]{4}-[A-F0-9]{12}", number) or data.get("signature_mode") != "handwritten":
            raise ValueError("Invalid issued certificate metadata")
        overlay.text("receipt_number", number, 46, 18, 502, 12, single_line=True)
    else:
        overlay.notice(width)

    def field(name, x, top, w, h, size=9, single=False):
        overlay.text(name, values[name], x, top, w, h, size, single)

    field("issuer", 46, 52 if kind == "money" else 50, 502, 38)
    field("donor", 46, 155 if kind == "money" else 153, 502, 38)
    field("amount", 47, 229 if kind == "money" else 225, 120, 12, single=True)
    field("amount_words", 178 if kind == "money" else 175, 226 if kind == "money" else 222, 246, 20, size=8)
    field("donated_on", 434 if kind == "money" else 431, 229 if kind == "money" else 225, 115, 12, single=True)

    if kind == "money":
        if values["waiver"]:
            overlay.mark(373.1 if values["waiver"] == "yes" else 432.6, 268.6)
    else:
        field("goods_description", 46, 269, 502, 23)
        origin = values["goods_origin"]
        if origin:
            overlay.mark(57, {"business": 306.4, "private": 329.1, "unknown": 352.2}[origin])
        if values["goods_evidence"]:
            overlay.mark(57, 374.5)

    # Fill exactly one tax notice branch; an unknown notice leaves both blank.
    if values["notice_type"] in ("exemption", "corporate"):
        overlay.mark(57, 302.2 if kind == "money" else 407.1)
        field("purpose", 82.2, 320 if kind == "money" else 425, 466, 36)
        tax_top = 379 if kind == "money" else 483.9
        field("tax_office", 82.2, tax_top, 137 if kind == "money" else 134, 9, 8, True)
        field("tax_number", 248 if kind == "money" else 245, tax_top, 101 if kind == "money" else 104, 9, 8, True)
        field("notice_date", 375, tax_top, 66 if kind == "money" else 75, 9, 8, True)
        field("assessment_period", 166, 394 if kind == "money" else 498.9, 98 if kind == "money" else 109, 9, 8, True)
    elif values["notice_type"] == "assessment":
        overlay.mark(57, 428.1 if kind == "money" else 527.3)
        tax_top = 446.6 if kind == "money" else 544.4
        field("tax_office", 82.2, tax_top, 110 if kind == "money" else 134, 9, 8, True)
        field("tax_number", 219 if kind == "money" else 245, tax_top, 77 if kind == "money" else 105, 9, 8, True)
        field("notice_date", 368 if kind == "money" else 423, tax_top, 57 if kind == "money" else 65, 9, 8, True)
        field("purpose", 82.2, 478 if kind == "money" else 575, 466, 43 if kind == "money" else 23)

    if kind == "money" and data.get("non_deductible_memberships") is True:
        overlay.mark(45.66, 621.79)

    field("purpose", 46, 549 if kind == "money" else 621, 502, 35)
    # Only the supplied place/date is rendered. No signature or legal
    # certification checkbox is inferred from a financial booking.
    field("signature_place_date", 46, 679 if kind == "money" else 686, 502, 10, size=8, single=True)

    page.merge_page(overlay.finish())
    writer = PdfWriter()
    writer.add_page(page)
    writer.add_metadata({
        "/Title": "Entwurf - Zuwendungsbestätigung" if kind == "money" else "Entwurf - Sachzuwendungsbestätigung",
        "/Subject": "Zur eigenhändigen Unterschrift" if issued else DRAFT_NOTICE,
        "/Creator": "Kulturhus Vereinsverwaltung",
    })
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue()


def main():
    try:
        raw = sys.stdin.buffer.read(65537)
        if len(raw) > 65536:
            raise ValueError("Donation form input exceeds 64 KiB")
        data = json.loads(raw.decode("utf-8"))
        result = render(data)
    except (ValueError, TypeError, KeyError, OSError) as error:
        print(f"Donation form could not be rendered: {error}", file=sys.stderr)
        return 1
    sys.stdout.buffer.write(result)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
