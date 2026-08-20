import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
CLIENT = (ROOT / "content-intelligence.js").read_text(encoding="utf-8")
PUBLIC = (ROOT / "public-copy-fixes.js").read_text(encoding="utf-8")


def extract_function(source, name):
    match = re.search(rf"function {name}\s*\(", source)
    if not match:
        match = re.search(rf"{name}\s*=\s*function\s*\(", source)
    if not match:
        raise AssertionError(f"function {name} not found")
    start = match.start()
    brace = source.find("{", match.end() - 1)
    depth = 0
    for index, char in enumerate(source[brace:], brace):
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[start : index + 1]
    raise AssertionError(f"unterminated function {name}")


class RecentNewsCopyV1Tests(unittest.TestCase):
    def test_profile_news_has_no_explanatory_copy(self):
        news = extract_function(CLIENT, "renderProfileNews")
        self.assertIn("📰 Actualité récente", news)
        self.assertIn("if(!items.length)return", news.replace(" ", ""))
        for forbidden in (
            "Chargement des publications officielles",
            "contenus officiels + validations",
            "Aucune actu en 72 h",
            "moins de 72 heures",
            "moins de 7 jours",
            "Actualité momentanément indisponible",
            "p50ci-empty",
        ):
            self.assertNotIn(forbidden, news)

    def test_empty_trigger_is_hidden_on_public_fiches(self):
        self.assertIn("if(!e)return '';", INDEX)
        self.assertGreaterEqual(V9.count("if(!e||p50TriggerIsStale(e))return '';"), 2)
        self.assertNotIn("Aucune actualité récente mise en avant", V9)
        self.assertNotIn("Les nouvelles publications apparaîtront ici", V9)
        self.assertNotIn("Les anciennes informations ont été retirées", V9)
        self.assertNotIn("Élément déclencheur non encore validé", INDEX)
        self.assertIn(".trigger-empty{display:none!important}", INDEX)
        self.assertIn("removePublicNewsLectures", PUBLIC)

    def test_agrandir_label_is_not_painted_on_fi_photo(self):
        self.assertNotIn('content:"Agrandir"', INDEX)
        self.assertNotIn("Agrandir la photo", INDEX)
        self.assertIn("is-zoomable", INDEX)
        self.assertIn("hideFiPhotoAgrandirLabel", PUBLIC)


if __name__ == "__main__":
    unittest.main()
