"""Isolated PDF checks: synthetic data, no database, network or mail transport.

Run with the same Python environment as the renderer. Optional --qa-dir writes
synthetic sample PDFs and renders them to PNG with pypdfium2 for visual review.
"""

import argparse
import hashlib
import importlib.util
import io
import json
import subprocess
import sys
import unittest
from pathlib import Path

import pdfplumber
from pypdf import PdfReader


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tools" / "render-donation-form.py"
spec = importlib.util.spec_from_file_location("donation_renderer", SCRIPT)
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)


def sample(kind, notice_type="exemption"):
    return {"donation_form": {
        "kind": kind,
        "issuer": "Musterverein e. V. (synthetischer Test)\nMusterstraße 1\n00000 Musterort",
        "donor": "Alex Muster\nTestweg 12\n00000 Beispielort",
        "amount": "125,50",
        "amount_words": "eins zwei fünf Euro und fünf null Cent",
        "donated_on": "05.09.2026",
        "purpose": "Förderung von Kunst und Kultur (synthetischer Testzweck)",
        "tax_office": "Musterstadt",
        "tax_number": "00/000/00000",
        "notice_date": "01.02.2025",
        "assessment_period": "2022 bis 2024",
        "notice_type": notice_type,
        "waiver": "no",
        "goods_description": "Zwei Mustertische, 3 Jahre alt, guter Zustand; Kaufpreis zusammen 250,00 Euro.",
        "goods_origin": "private",
        "goods_evidence": True,
        "signature_place_date": "Musterort, 05.09.2026",
    }}


class DonationFormPdfTests(unittest.TestCase):
    def test_numbered_paper_certificate_preserves_form_without_draft_mark(self):
        for kind in ("money", "goods"):
            data = sample(kind)
            data.update(kind="donation_receipt", receipt_number="ZB-2026-ABCDEF123456", signature_mode="handwritten", non_deductible_memberships=True)
            pdf = renderer.render(data)
            text = PdfReader(io.BytesIO(pdf)).pages[0].extract_text()
            self.assertNotIn(renderer.DRAFT_NOTICE, text)
            self.assertIn("ZB-2026-ABCDEF123456", text)
            self.assertIn("125,50", text)
            self.assertEqual(len(PdfReader(io.BytesIO(pdf)).pages), 1)
        data["receipt_number"] = "Unvalidated"
        with self.assertRaises(ValueError):
            renderer.render(data)

    def test_original_wording_page_size_and_source_files_preserved(self):
        for kind in ("money", "goods"):
            with self.subTest(kind=kind):
                source = ROOT / "resources" / "donation-forms" / f"{kind}-original.pdf"
                before = source.read_bytes()
                original = PdfReader(io.BytesIO(before)).pages[0]
                output = PdfReader(io.BytesIO(renderer.render(sample(kind))))
                self.assertEqual(len(output.pages), 1)
                self.assertEqual(list(output.pages[0].mediabox), list(original.mediabox))
                self.assertIn(original.extract_text(), output.pages[0].extract_text())
                self.assertEqual(hashlib.sha256(before).hexdigest(), hashlib.sha256(source.read_bytes()).hexdigest())

    def test_draft_notice_and_values_extract_correctly(self):
        for kind in ("money", "goods"):
            data = sample(kind)
            reader = PdfReader(io.BytesIO(renderer.render(data)))
            text = reader.pages[0].extract_text()
            self.assertIn(renderer.DRAFT_NOTICE, text)
            for field in ("amount", "amount_words", "donated_on", "purpose", "tax_office", "tax_number", "notice_date", "assessment_period", "signature_place_date"):
                self.assertIn(data["donation_form"][field], text)
            self.assertIn("Musterstraße 1", text)
            self.assertEqual(reader.metadata.subject, renderer.DRAFT_NOTICE)
            self.assertFalse(reader.get_fields())
            self.assertFalse(reader.pages[0].get("/Annots"))

    def test_no_values_or_date_are_invented_for_an_empty_draft(self):
        for kind in ("money", "goods"):
            output = renderer.render({"donation_form": {"kind": kind}})
            original = PdfReader(ROOT / "resources" / "donation-forms" / f"{kind}-original.pdf").pages[0].extract_text()
            text = PdfReader(io.BytesIO(output)).pages[0].extract_text()
            self.assertEqual(text.replace(original, "", 1).strip(), renderer.DRAFT_NOTICE)

    def test_only_selected_notice_branch_receives_values(self):
        for kind in ("money", "goods"):
            for notice_type, top_range in (("exemption", (378, 389) if kind == "money" else (483, 494)), ("assessment", (446, 457) if kind == "money" else (544, 555))):
                data = sample(kind, notice_type)
                with pdfplumber.open(io.BytesIO(renderer.render(data))) as pdf:
                    # The originals contain literal blank placeholder spaces;
                    # exclude these when locating newly overlaid text.
                    added = pdf.pages[0].filter(lambda obj: "BitstreamVeraSans" in obj.get("fontname", ""))
                    occurrences = [word for word in added.extract_words() if word["text"] == "Musterstadt"]
                    self.assertEqual(len(occurrences), 1)
                    self.assertGreaterEqual(occurrences[0]["top"], top_range[0])
                    self.assertLessEqual(occurrences[0]["bottom"], top_range[1])
                text = PdfReader(io.BytesIO(renderer.render(data))).pages[0].extract_text()
                if notice_type == "assessment":
                    self.assertNotIn("2022 bis 2024", text)

    def test_four_address_lines_and_two_words_lines_fit(self):
        data = sample("money")
        data["donation_form"]["issuer"] = "Musterverein e. V.\nAbteilung Kunst\nMusterstraße 1\n00000 Musterort"
        data["donation_form"]["donor"] = "Alex Muster\nZusatzzeile\nTestweg 12\n00000 Beispielort"
        data["donation_form"]["amount_words"] = "eins zwei drei vier fünf sechs sieben acht\nEuro und fünf null Cent"
        text = PdfReader(io.BytesIO(renderer.render(data))).pages[0].extract_text()
        self.assertIn("Abteilung Kunst", text)
        self.assertIn("Zusatzzeile", text)
        self.assertIn("Euro und fünf null Cent", text)

    def test_user_input_is_plain_text(self):
        data = sample("money")
        data["donation_form"]["donor"] = "Alex <b>Muster</b> & Familie"
        text = PdfReader(io.BytesIO(renderer.render(data))).pages[0].extract_text()
        self.assertIn("Alex <b>Muster</b> & Familie", text)

    def test_inserted_text_never_overlaps_official_wording(self):
        for kind in ("money", "goods"):
            for notice in ("exemption", "assessment"):
                with self.subTest(kind=kind, notice=notice):
                    with pdfplumber.open(io.BytesIO(renderer.render(sample(kind, notice)))) as pdf:
                        chars = [char for char in pdf.pages[0].chars if char["text"].strip()]
                        added = [char for char in chars if "BitstreamVeraSans" in char["fontname"]]
                        original = [char for char in chars if "BitstreamVeraSans" not in char["fontname"]]
                        for char in added:
                            self.assertGreaterEqual(char["size"], 8)
                            self.assertGreaterEqual(char["x0"], 0)
                            self.assertLessEqual(char["x1"], 595.28)
                            for fixed in original:
                                vertical = min(char["bottom"], fixed["bottom"]) - max(char["top"], fixed["top"])
                                horizontal = min(char["x1"], fixed["x1"]) - max(char["x0"], fixed["x0"])
                                self.assertFalse(vertical > 0.2 and horizontal > 0.2, f"Added {char['text']!r} overlaps original {fixed['text']!r}")

    def test_checkbox_marks_stay_inside_selected_original_boxes(self):
        for kind, expected in (("money", [(432.6, 268.6), (57, 302.2)]), ("goods", [(57, 329.1), (57, 374.5), (57, 407.1)])):
            with pdfplumber.open(io.BytesIO(renderer.render(sample(kind)))) as pdf:
                marks = [line for line in pdf.pages[0].lines if abs(line["linewidth"] - 1.3) < 0.01]
                self.assertEqual(len(marks), len(expected) * 2)
                for x, top in expected:
                    contained = [line for line in marks if x + 2 <= line["x0"] and line["x1"] <= x + 12 and top + 2 <= line["top"] and line["bottom"] <= top + 12]
                    self.assertEqual(len(contained), 2)

    def test_layout_overflow_is_rejected_without_truncation(self):
        for field, value in (("issuer", "\n".join(["Musterzeile"] * 8)), ("tax_office", "Musterstadt " * 9), ("amount_words", "eins zwei " * 15), ("goods_description", "Musterbeschreibung " * 25), ("purpose", "Musterzweck " * 50), ("signature_place_date", "Ort\nDatum")):
            with self.subTest(field=field):
                data = sample("goods", "assessment")
                data["donation_form"][field] = value
                with self.assertRaisesRegex(ValueError, field):
                    renderer.render(data)

    def test_invalid_types_and_enums_are_rejected(self):
        for field, value in (("kind", "../../other"), ("kind", None), ("donor", ["Alex"]), ("notice_type", "custom"), ("goods_evidence", "yes"), ("goods_evidence", 1), ("goods_origin", "anything"), ("waiver", True)):
            with self.subTest(field=field):
                data = sample("money")
                data["donation_form"][field] = value
                with self.assertRaises(ValueError):
                    renderer.render(data)

    def test_control_characters_unknown_glyphs_and_excessive_text_rejected(self):
        for text in ("Muster\x00ort", "Muster\x1bort", "Muster\tort", "Muster\U0001f600ort", "x" * 501):
            data = sample("money")
            data["donation_form"]["donor"] = text
            with self.assertRaisesRegex(ValueError, "donor"):
                renderer.render(data)

    def test_unknown_fields_and_invalid_container_rejected(self):
        for data in ([], {}, {"donation_form": []}, {"donation_form": {"kind": "money", "source": "/tmp/other.pdf"}}):
            with self.assertRaises(ValueError):
                renderer.render(data)

    def test_cli_outputs_only_pdf_bytes(self):
        result = subprocess.run([sys.executable, str(SCRIPT)], input=json.dumps(sample("goods"), ensure_ascii=False).encode("utf-8"), capture_output=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertEqual(result.stderr, b"")
        self.assertTrue(result.stdout.startswith(b"%PDF-"))
        self.assertEqual(len(PdfReader(io.BytesIO(result.stdout)).pages), 1)

    def test_cli_errors_do_not_return_partial_pdf_or_donor_data(self):
        for raw in (b"{", b"x" * 65537, json.dumps({"donation_form": {"kind": "money", "donor": "PRIVATE_DONOR" * 50}}).encode()):
            result = subprocess.run([sys.executable, str(SCRIPT)], input=raw, capture_output=True, check=False)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(result.stdout, b"")
            self.assertIn(b"Donation form could not be rendered", result.stderr)
            self.assertNotIn(b"PRIVATE_DONOR", result.stderr)


def write_qa(directory):
    import pypdfium2
    directory.mkdir(parents=True, exist_ok=True)
    for kind in ("money", "goods"):
        for notice in ("exemption", "assessment"):
            path = directory / f"{kind}-{notice}-draft.pdf"
            path.write_bytes(renderer.render(sample(kind, notice)))
            document = pypdfium2.PdfDocument(path)
            document[0].render(scale=1.75).to_pil().save(path.with_suffix(".png"))
            document.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--qa-dir", type=Path)
    args, remaining = parser.parse_known_args()
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(DonationFormPdfTests)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    if not result.wasSuccessful():
        raise SystemExit(1)
    if args.qa_dir:
        write_qa(args.qa_dir)
