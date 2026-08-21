import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
CORE = (ROOT / "api/data-engine-core.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
FACTS = (ROOT / "api/facts.php").read_text(encoding="utf-8")
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
ROSEMARK = (ROOT / "profile-rosemark-marcel.js").read_text(encoding="utf-8")
JIAAN = (ROOT / "profile-jiaan-wu.js").read_text(encoding="utf-8")
SAMUELLA = (ROOT / "profile-samuella-kouassi.js").read_text(encoding="utf-8")
LEXES = (ROOT / "profile-lexes.js").read_text(encoding="utf-8")
ANGE = (ROOT / "profile-ange-morel.js").read_text(encoding="utf-8")
JPNDA = (ROOT / "profile-jp-nda.js").read_text(encoding="utf-8")
DANIEL = (ROOT / "profile-daniel-m.js").read_text(encoding="utf-8")
AKA = (ROOT / "profile-akalajoie.js").read_text(encoding="utf-8")


def extract_function(source, name):
    match = re.search(rf"function {re.escape(name)}\s*\(", source)
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


class PlausibleBirthAgeV1Tests(unittest.TestCase):
    def test_client_rejects_census_year_and_year_one(self):
        self.assertIn("function p50BirthYearIsPlausible(year,nowYear=new Date().getFullYear())", INDEX)
        self.assertIn("function p50ClearImplausibleBirth(p)", INDEX)
        self.assertIn("y>=1920&&y<=nowYear-5&&age>=5&&age<=110", INDEX)
        age_text = extract_function(INDEX, "ageText")
        self.assertIn("p50BirthYearIsPlausible(year)", age_text)
        self.assertIn("return'Non confirmé'", age_text)
        self.assertNotIn("slice(0,4)", age_text)

    def test_lock_and_preserve_ignore_garbage_dates(self):
        self.assertIn("p50ClearImplausibleBirth(p)", extract_function(INDEX, "p50LockBirthDate"))
        self.assertIn("p50BirthDateIsPlausible", extract_function(INDEX, "p50BirthShouldPreserve"))
        self.assertIn("p50ClearImplausibleBirth(p)", extract_function(INDEX, "p50FreezeExistingBirth"))
        self.assertIn("if(typeof p50ClearImplausibleBirth==='function')p50ClearImplausibleBirth(p)", V9)

    def test_engine_drops_current_year_birth_evidence(self):
        self.assertIn("function p50_de_is_plausible_birth_date(string $date, ?int $nowYear=null)", CORE)
        self.assertIn("function p50_de_clear_implausible_birth(array &$p)", CORE)
        self.assertIn("p50_de_sanitize_state_births($state)", CORE)
        self.assertIn("if ($factKey === 'birth_date')", CORE)
        self.assertIn("if (!p50_de_is_plausible_birth_date($normalizedValue)) return;", CORE)
        self.assertIn("if(!p50_de_is_plausible_birth_date($value))", FACTS)
        self.assertIn("p50BirthDateIsPlausible(date)", UI)

    def test_new_census_fiches_do_not_seed_2026_as_birth(self):
        for source in (ROSEMARK, JIAAN, SAMUELLA, LEXES, ANGE, JPNDA, DANIEL, AKA):
            self.assertIn("birthDate:null", source)
            self.assertIn("birthYear:null", source)
            self.assertIn("ageStatus:'unconfirmed'", source)
        self.assertIn("p50ClearImplausibleBirth(profile)", ROSEMARK)
        self.assertIn("p50ClearImplausibleBirth(profile)", JIAAN)
        self.assertIn("p50ClearImplausibleBirth(profile)", SAMUELLA)

    def test_age_text_runtime_hides_2026_and_year_one(self):
        helpers = "\n".join(
            extract_function(INDEX, name)
            for name in (
                "p50BirthDateValue",
                "p50EnsurePlainObject",
                "p50VerifiedFactsList",
                "p50BirthYearFromDate",
                "p50BirthYearIsPlausible",
                "p50BirthDateIsPlausible",
                "p50ClearImplausibleBirth",
                "ageText",
            )
        )
        script = f"""
        {helpers}
        const now = new Date().getFullYear();
        const cases = [
          [{{agePublic:true}}, 'Non confirmé'],
          [{{agePublic:true,birthYear:null,birthDate:null}}, 'Non confirmé'],
          [{{agePublic:true,birthYear:now,birthDate:now+'-08-20',ageStatus:'unconfirmed'}}, 'Non confirmé'],
          [{{agePublic:true,birthYear:1,birthDate:'0001-01-01',ageStatus:'unconfirmed'}}, 'Non confirmé'],
          [{{agePublic:true,birthYear:true,ageStatus:'unconfirmed'}}, 'Non confirmé'],
          [{{agePublic:true,birthYear:2004,birthDate:'2004-01-16',ageStatus:'confirmed',quality:{{birth:100}}}}, (now-2004)+' ans'],
        ];
        const out = cases.map(([p, expected]) => {{
          const got = ageText(p);
          return {{got, expected: expected, ok: got === expected || (expected.endsWith(' ans') && /^\\d+ ans$/.test(got))}};
        }});
        console.log(JSON.stringify(out));
        """
        result = subprocess.run(["node", "-e", script], capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        rows = json.loads(result.stdout)
        self.assertEqual(rows[0]["got"], "Non confirmé")
        self.assertEqual(rows[1]["got"], "Non confirmé")
        self.assertEqual(rows[2]["got"], "Non confirmé")
        self.assertEqual(rows[3]["got"], "Non confirmé")
        self.assertEqual(rows[4]["got"], "Non confirmé")
        self.assertRegex(rows[5]["got"], r"^\d+ ans$")
        self.assertNotIn("2024", rows[3]["got"])
        self.assertNotIn("2025", rows[3]["got"])


if __name__ == "__main__":
    unittest.main()
