import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
CORE = (ROOT / "api/data-engine-core.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
CRON = (ROOT / "api/birthdates-apply-cron-v1.php").read_text(encoding="utf-8")
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
KIM = (ROOT / "profile-kim-makosso.js").read_text(encoding="utf-8")


class BirthFreezeAllFiV1Tests(unittest.TestCase):
    def test_client_helpers_lock_every_confirmed_fiche(self):
        self.assertIn("function p50FreezeExistingBirth(p)", INDEX)
        self.assertIn("function p50LockBirthDate(p,adminConfirmed=false)", INDEX)
        self.assertIn("try{p50FreezeExistingBirth(p)}catch{}});", INDEX)
        self.assertIn("p50BirthShouldPreserve(p)", INDEX)
        self.assertIn("else if(p.ageStatus==='confirmed'||p.birthManualLocked)p50LockBirthDate(p,true)", INDEX)
        self.assertIn("if(Number(p.quality.birth||0)>=90&&p50BirthDateValue(p))p50LockBirthDate(p,true)", INDEX)

    def test_cloud_merge_preserves_confirmed_births_not_only_locked_flag(self):
        self.assertIn("filter(p=>p50BirthShouldPreserve(p))", INDEX)
        self.assertNotIn("filter(p=>p.birthManualLocked&&p.birthDate)", INDEX)

    def test_engine_publish_cannot_overwrite_frozen_birth(self):
        self.assertIn("function p50_de_profile_birth_frozen(array $p)", CORE)
        self.assertIn("function p50_de_lock_birth_date(array &$p, bool $adminConfirmed=false)", CORE)
        self.assertIn("if($existingBirth!==''&&$birthFrozen&&!$manual&&$existingBirth!==$date)", CORE)
        self.assertIn("p50_de_lock_birth_date($p,$manual)", CORE)
        self.assertIn("p50_de_profile_birth_frozen($stateProfile)&&p50_de_profile_birth_value($stateProfile)!==$date", CORE)

    def test_hub_and_cron_respect_existing_dates(self):
        self.assertIn("if(frozen&&String(p.birthDate||'')!==date)continue", UI)
        self.assertIn("if(frozen)continue", UI)
        self.assertIn("if(trim((string)($profile['birthDate']??''))!==''||!empty($profile['birthManualLocked']))continue", CRON)
        self.assertIn("$profile['birthManualLocked']=true", CRON)

    def test_future_and_present_fiches_start_or_migrate_into_the_same_lock(self):
        self.assertIn("birthManualLocked:false", INDEX)
        self.assertIn("if(typeof p50FreezeExistingBirth==='function')p50FreezeExistingBirth(p)", V9)
        self.assertIn("birthManualLocked:true", KIM)
        self.assertIn("buildProfile([", INDEX)


if __name__ == "__main__":
    unittest.main()
