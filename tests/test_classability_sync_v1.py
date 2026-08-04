import subprocess
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SYNC = (ROOT / "classability-sync-v1.js").read_text(encoding="utf-8")
LOADER = (ROOT / "public-copy-fixes.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")


class ClassabilitySyncV1Tests(unittest.TestCase):
    def test_legacy_eligible_profile_is_repaired_without_touching_mr(self):
        script = textwrap.dedent(
            r"""
            const fs = require('fs');
            const vm = require('vm');
            const source = fs.readFileSync('classability-sync-v1.js', 'utf8');
            let domReady = null;
            const context = {
              console,
              window: { addEventListener() {}, __pass50CloudReady: true },
              document: {
                readyState: 'loading',
                addEventListener(name, callback) { if (name === 'DOMContentLoaded') domReady = callback; }
              },
              HTMLFormElement: function HTMLFormElement() {},
              setInterval() { return 1; },
              clearInterval() {},
              setTimeout(callback) { callback(); return 1; },
            };
            vm.createContext(context);
            vm.runInContext(source, context);
            domReady();
            const api = context.window.PASS50_CLASSABILITY_SYNC;

            const legacy = { eligible: true, alive: true, classable: false, algorithmVersion: '15C-v1' };
            if (!api.repairProfile(legacy)) throw new Error('legacy profile was not repaired');
            if (legacy.classable !== true) throw new Error('eligible legacy profile remains non classable');
            if (legacy.editorialEligible !== true) throw new Error('editorial eligibility was not copied');
            if (legacy.classabilitySource !== 'admin_eligibility') throw new Error('repair source missing');

            const disabled = { eligible: false, alive: true, classable: true, algorithmVersion: '15C-v1' };
            api.repairProfile(disabled);
            if (disabled.classable !== false) throw new Error('disabled profile remains classable');

            const metrics = { eligible: true, alive: true, classable: false, algorithmVersion: 'MR-V1.0' };
            api.repairProfile(metrics);
            if (metrics.classable !== false) throw new Error('MR-V1.0 decision was overwritten');
            if (metrics.editorialEligible !== true) throw new Error('MR editorial eligibility was not transmitted');
            """
        )
        subprocess.run(
            ["node", "-e", script],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )

    def test_profile_form_save_is_bridged(self):
        self.assertIn("form.id !== 'profileForm'", SYNC)
        self.assertIn("setTimeout(() => repairSavedProfile(profileId), 0)", SYNC)
        self.assertIn("profileItem.classable = expected", SYNC)
        self.assertIn("profileItem.editorialEligible = editorialEligible", SYNC)

    def test_public_rule_and_metrics_guard_remain_separate(self):
        self.assertIn("function isClassableProfile(p){return Boolean(p?.eligible)&&p.classable!==false;}", INDEX)
        self.assertIn("metricsControlsClassability", SYNC)
        self.assertIn("MR-V1\\.0", SYNC)
        self.assertIn("if (!metricsControlled)", SYNC)

    def test_loader_and_cache_are_versioned(self):
        self.assertIn("classability-sync-v1.js?v=1.0", LOADER)
        self.assertIn("data-pass50-classability-sync", LOADER)
        self.assertIn("classability-sync-v1.js?v=1.0", SW)
        self.assertIn("pass50-v66-classability-sync", SW)


if __name__ == "__main__":
    unittest.main()
