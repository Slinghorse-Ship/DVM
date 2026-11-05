"""Loopback HTTP regression for the standalone synthetic workflow preview."""
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import http.cookiejar
from html.parser import HTMLParser
from pathlib import Path

BASE = "http://127.0.0.1:8117/workflows"
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = []

def check(condition, label):
    if not condition:
        raise AssertionError(label)
    checks.append(label)

def request(query="", data=None, headers=None):
    req = urllib.request.Request(BASE + query, data=None if data is None else urllib.parse.urlencode(data).encode(), headers=headers or {})
    try:
        response = opener.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode("utf-8")
    return response.status, response.geturl(), dict(response.headers), body

class Forms(HTMLParser):
    def __init__(self, source):
        super().__init__(); self.forms = []; self.current = None; self.feed(source)
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form": self.current = {}; self.forms.append(self.current)
        if tag == "input" and self.current is not None and "name" in attrs:
            self.current[attrs["name"]] = attrs.get("value", "")
    def handle_endtag(self, tag):
        if tag == "form": self.current = None

def form(source, action):
    return next(row for row in Forms(source).forms if row.get("action") == action)

def csrf(source):
    return next(row["csrf"] for row in Forms(source).forms if "csrf" in row)

def post(action, fields=None, page=None):
    if page is None: page = request()[3]
    return request(data={"csrf": csrf(page), "action": action, **(fields or {})})

def document_export(doc, revision=None, fmt="json"):
    query = {"wf_export": doc, "wf_format": fmt}
    if revision is not None: query["wf_revision"] = str(revision)
    return request("?" + urllib.parse.urlencode(query))

def matrix_checks():
    fields = {"wf_title": "HTTP matrix template", "wf_description": "Synthetic numeric orientation", "wf_version": "0", "wf_fields[0][key]": "risk", "wf_fields[0][label]": "Einzelrisiko", "wf_fields[0][type]": "risk_matrix", "wf_fields[0][required]": "1"}
    status, url, _, page = post("document-workflow-template-save", fields)
    check(status == 200, "matrix template saved through controller")
    template = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["wf_template"][0]
    check(request(data=form(page, "document-workflow-template-publish"))[0] == 200, "matrix template published")
    status, url, _, page = post("document-workflow-document-create", {"wf_template": template, "wf_title": "HTTP matrix draft", "wf_folder": ""})
    check(status == 200, "matrix draft created")
    doc = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["wf_doc"][0]
    save = form(page, "document-workflow-document-save")
    save.update({"wf_title": "HTTP matrix draft", "wf_folder": "", "wf_values[risk][likelihood]": "", "wf_values[risk][severity]": ""})
    status, _, _, page = request(data=save)
    check(status == 200, "empty matrix draft saved")
    save = form(page, "document-workflow-document-save")
    save.update({"wf_title": "HTTP matrix draft", "wf_folder": "", "wf_values[risk][likelihood]": "3", "wf_values[risk][severity]": ""})
    check(request(data=save)[0] == 422, "partial matrix rejected")
    save["wf_values[risk][severity]"] = "6"
    check(request(data=save)[0] == 422, "matrix out of range rejected")
    save["wf_values[risk][severity]"] = "4"
    save["wf_values[risk][score]"] = "1"
    check(request(data=save)[0] == 422, "injected matrix score rejected by HTTP adapter")
    del save["wf_values[risk][score]"]
    check(request(data=save)[0] == 200, "complete matrix stored")
    exported = json.loads(document_export(doc)[3])
    check(exported["document"]["values"]["risk"] == {"likelihood": 3, "severity": 4}, "matrix stores only two typed integers")
    check(exported["risk_matrix_calculations"]["risk"]["score"] == 12, "matrix score computed by server")
    html = document_export(doc, fmt="html")[3]
    check("dwf-risk-selected" in html and "Keine automatische Tätigkeitsfreigabe" in html, "matrix HTML contains selection and scope note")

def register_checks():
    fields = {"wf_title": "HTTP register template", "wf_version": "0", "wf_fields[0][key]": "hazards", "wf_fields[0][label]": "Gefährdungen", "wf_fields[0][type]": "risk_register", "wf_fields[0][required]": "1", "wf_fields[0][matrix_profile]": "fluix_reference_v1"}
    invalid = dict(fields); invalid["wf_fields[0][matrix_profile]"] = "unknown_profile"
    check(post("document-workflow-template-save", invalid)[0] == 422, "unknown register profile rejected through HTTP")
    status, url, _, page = post("document-workflow-template-save", fields)
    check(status == 200, "register template saved through HTTP")
    template = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["wf_template"][0]
    check(request(data=form(page, "document-workflow-template-publish"))[0] == 200, "register template published")
    status, url, _, page = post("document-workflow-document-create", {"wf_template": template, "wf_title": "HTTP register draft", "wf_folder": ""})
    check(status == 200, "register document created")
    doc = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["wf_doc"][0]
    save = form(page, "document-workflow-document-save")
    save.update({"wf_title": "HTTP register draft", "wf_folder": ""})
    status, _, _, page = request(data=save)
    check(status == 200, "empty required register remains saveable as draft")
    check(json.loads(document_export(doc)[3])["document"]["values"]["hazards"] == [], "empty register roundtrip is typed list")
    save = form(page, "document-workflow-document-save")
    save.update({"wf_title": "HTTP register draft", "wf_folder": ""})
    for i in range(2):
        for col in ["category", "hazard", "impact", "controls", "responsible", "effectiveness"]:
            save[f"wf_values[hazards][{i}][{col}]"] = f"Synthetic {col} {i+1} < & >"
        for risk in ["initial", "residual"]:
            save[f"wf_values[hazards][{i}][{risk}][severity]"] = str(4-i)
            save[f"wf_values[hazards][{i}][{risk}][likelihood]"] = "2"
    invalid = dict(save); invalid["wf_values[hazards][0][initial][score]"] = "1"
    check(request(data=invalid)[0] == 422, "register score injection rejected")
    invalid = dict(save); invalid["wf_values[hazards][0][initial][severity]"] = ""
    check(request(data=invalid)[0] == 422, "register partial assessment rejected")
    status, _, _, page = request(data=save)
    check(status == 200, "two complete hazard rows saved")
    exported = json.loads(document_export(doc)[3]); old_revision = exported["document"]["version"]
    check(exported["risk_register_calculations"]["hazards"][0]["initial"]["score"] == 8 and exported["risk_register_calculations"]["hazards"][1]["residual"]["score"] == 6, "register row calculations remain aligned")
    check(exported["document"]["values"]["hazards"][0]["initial"] == {"likelihood": 2, "severity": 4}, "register risk values are typed integers only")
    check(exported["document"]["fields"][0]["matrix_profile"] == "fluix_reference_v1" and exported["risk_register_calculations"]["hazards"][0]["initial"]["band"] == "medium", "reference profile and derived band survive HTTP roundtrip")
    html = document_export(doc, fmt="html")[3]
    check("A4 landscape" in html and "Wirksamkeitsprüfung" in html and "&lt; &amp; &gt;" in html, "register HTML is landscape and escaped")
    check(all(color in html for color in ["#5ab88f", "#ffd744", "#e7343f"]), "register HTML contains the three source colors")
    check(all(text in html for text in ["Erste Hilfe; sofortige Erholung", "Kurze Abwesenheit (Tage)", "Längere Abwesenheit (Monate)", "Lebensverändernde Verletzung oder Behinderung", "Ein oder mehrere Todesfälle"]), "all five severity explanations are printed in the document")
    check(all(text in html for text in ["In der Branche bisher nicht bekannt", "In der Branche bekannt, in der Organisation nicht", "Kann in der Organisation auftreten", "Einmal bis mehrmals jährlich in der Organisation", "Einmal bis mehrmals jährlich am Standort"]), "all five likelihood explanations are printed in the document")
    check("<details" not in html and "<summary" not in html and all(text in html for text in ["Wirksamkeit überwachen", "soweit praktikabel umsetzen", "Risiko nicht akzeptabel"]), "matrix explanation and band meanings require no expandable control")
    register_start = html.index('<table class="dwf-register-table">')
    check(all(0 <= html.find(text) < register_start for text in ["Erste Hilfe; sofortige Erholung", "Einmal bis mehrmals jährlich am Standort", "Risiko nicht akzeptabel"]), "complete matrix explanation shares the compact document header")
    check(html.count("Erste Hilfe; sofortige Erholung") == 1, "compact document does not repeat the explanation beneath the register")
    check("FS-RA-030" not in html and "Vom Nutzer bereitgestellte Matrixlegende" not in html and "Keine automatische Tätigkeitsfreigabe" not in html, "printed reference document omits internal provenance and repeated disclaimer")
    save = form(page, "document-workflow-document-save"); save.update({"wf_title": "HTTP empty register", "wf_folder": ""})
    check(request(data=save)[0] == 200, "register rows can be removed from a draft")
    check(len(json.loads(document_export(doc, old_revision)[3])["document"]["values"]["hazards"]) == 2, "historic register retains previous rows after removal")


def run():
    status, _, _, page = request()
    check(status == 200 and "Gefährdungsbeurteilung" in page, "renamed assessment module renders")
    check(request(data={"action": "document-workflow-folder-save", "wf_name": "CSRF must fail"})[0] == 403, "missing CSRF blocked")
    check(request(headers={"Host": "attacker.invalid:8117"})[0] == 403, "foreign Host blocked")
    check(post("workflow-demo-actor", {"actor": "unlisted"})[0] == 422, "unknown demo actor blocked")
    check(post("workflow-demo-actor", {"actor": "wf-author"})[0] == 200, "author selected")
    status, url, _, page = post("document-workflow-folder-save", {"wf_name": "HTTP regression", "wf_parent": "", "wf_version": "0"})
    check(status == 200, "folder created")
    folder = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["wf_folder"][0]
    fields = {"wf_title": "HTTP own template", "wf_description": "Synthetic regression only", "wf_version": "0"}
    for i, (key, kind) in enumerate([("note", "textarea"), ("day", "date"), ("count", "number"), ("choice", "select"), ("confirmed", "checkbox")]):
        fields.update({f"wf_fields[{i}][key]": key, f"wf_fields[{i}][label]": key, f"wf_fields[{i}][type]": kind, f"wf_fields[{i}][required]": "1", f"wf_fields[{i}][options]": "A\nB" if kind == "select" else ""})
    status, url, _, page = post("document-workflow-template-save", fields)
    check(status == 200, "template with five field types saved")
    template = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["wf_template"][0]
    publish = form(page, "document-workflow-template-publish")
    check(request(data=publish)[0] == 200, "template published")
    status, url, _, page = post("document-workflow-document-create", {"wf_template": template, "wf_folder": folder, "wf_title": "HTTP synthetic document"})
    check(status == 200, "document created")
    doc = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["wf_doc"][0]
    save = form(page, "document-workflow-document-save")
    save.update({"wf_title": "HTTP synthetic < & >", "wf_folder": folder, "wf_values[note]": "", "wf_values[day]": "", "wf_values[count]": "", "wf_values[choice]": ""})
    status, _, _, page = request(data=save)
    check(status == 200, "incomplete draft saved")
    incomplete = form(page, "document-workflow-document-transition"); incomplete["wf_transition"] = "submit"
    check(request(data=incomplete)[0] == 422, "incomplete submit blocked")
    save = form(page, "document-workflow-document-save")
    save.update({"wf_title": "HTTP synthetic < & >", "wf_folder": folder, "wf_values[note]": "<script>alert('synthetic')</script>", "wf_values[day]": "2026-10-07", "wf_values[count]": "3.5", "wf_values[choice]": "A", "wf_values[confirmed]": "1"})
    check(request(data=save)[0] == 422, "markup payload rejected")
    save["wf_values[note]"] = 'Synthetic text < & > "'
    status, _, _, page = request(data=save)
    check(status == 200 and "<script>alert('synthetic')</script>" not in page, "complete draft saved and HTML escaped")
    check(request(data=save)[0] == 422, "stale HTTP update blocked")
    assignment = form(page, "document-workflow-document-assign")
    assignment.update({"wf_assignee": "wf-author", "wf_reviewer": "wf-reviewer"})
    status, _, _, page = request(data=assignment)
    check(status == 200, "independent reviewer assigned")
    transition = form(page, "document-workflow-document-transition"); transition.update({"wf_transition": "submit", "wf_comment": "HTTP test"})
    status, _, _, page = request(data=transition)
    check(status == 200 and "In Prüfung" in page, "document submitted")
    status, _, headers, exported = document_export(doc)
    docdata = json.loads(exported)
    check(status == 200 and "attachment" in headers.get("Content-Disposition", ""), "JSON download headers")
    # Some export implementations wrap the document with provenance metadata.
    document = docdata.get("document", docdata)
    check(document["values"]["confirmed"] is True and document["status"] == "submitted", "JSON preserves typed values and status")
    revision = document.get("revision", document["version"])
    check(post("workflow-demo-actor", {"actor": "wf-assignee"})[0] == 200, "another authorized module writer selected")
    page = request("?wf_doc=" + doc)[3]
    transition = form(page, "document-workflow-document-transition"); transition.update({"wf_transition": "approve", "wf_comment": "Internal synthetic review only"})
    status, _, _, page = request(data=transition)
    check(status == 200 and "Freigegeben" in page, "authorized writer can review without fixed assignment")
    old = json.loads(document_export(doc, revision)[3]); old = old.get("document", old)
    check(old["status"] == "submitted", "historical version stays submitted")
    status, _, _, html = document_export(doc, fmt="html")
    check(status == 200 and "<script>alert(" not in html and "&lt; &amp; &gt;" in html, "HTML export escapes field values")
    check("interne Prüfung dieser Dokumentfassung" not in html, "print export contains no product approval note")
    check(post("workflow-demo-actor", {"actor": "wf-reader"})[0] == 200, "reader selected")
    check(post("document-workflow-folder-save", {"wf_version": "0", "wf_name": "Must not create"})[0] == 422, "reader cannot write")
    check(document_export(doc)[0] == 200, "reader can export")
    check(document_export(doc, fmt="invalid")[0] == 422, "unsupported export format rejected")
    check(post("workflow-demo-actor", {"actor": "wf-author"})[0] == 200, "demo returned to author")
    matrix_checks()
    register_checks()
    report = {"suite": "document-workflow-http", "checks": len(checks), "passed": checks, "base": BASE}
    output = Path(__file__).resolve().parents[2] / "audit" / "document-workflow-http-20261008.json"
    output.parent.mkdir(parents=True, exist_ok=True); output.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"PASS: {len(checks)} local workflow HTTP checks")

if __name__ == "__main__":
    run()
