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
    def test_authoritative_rule_repairs_tma_like_legacy_profile(self):
        script = textwrap.dedent(
            r"""
            const fs = require('fs');
            const vm = require('vm');
            const source = fs.readFileSync('classability-sync-v1.js', 'utf8');
            let domReady = null;
            const context = {
              console,
              document: {
                readyState: 'loading',
                addEventListener(name, callback) { if (name === 'DOMContentLoaded') domReady = callback; }
              },
              HTMLFormElement: function HTMLFormElement() {},
              setInterval() { return 1; },
              clearInterval() {},
              setTimeout(callback) { callback(); return 1; },
              render() {},
              save() {},
            };
            context.window = context;
            context.__pass50CloudReady = true;
            context.isClassableProfile = profile => Boolean(profile?.eligible) && profile.classable !== false;
            vm.createContext(context);
            vm.runInContext(source, context);
            domReady();
            const api = context.PASS50_CLASSABILITY_SYNC;

            const tmaLike = {
              eligible: true,
              alive: true,
              classable: false,
              algorithmVersion: '15C-v1',
              scores: { '2H': 66 },
              links: { TikTok: 'https://tiktok.com/@tma' }
            };
            if (!api.repairProfile(tmaLike)) throw new Error('legacy profile was not repaired');
            if (tmaLike.classable !== true) throw new Error('eligible legacy profile remains non classable');
            if (!api.authoritativeIsClassableProfile(tmaLike)) throw new Error('authoritative rule rejects eligible legacy profile');
            if (!context.isClassableProfile(tmaLike)) throw new Error('public rule was not replaced');
            if (tmaLike.editorialEligible !== true) throw new Error('editorial eligibility was not copied');
            if (tmaLike.classabilitySource !== 'admin_eligibility') throw new Error('repair source missing');

            const disabled = { eligible: false, alive: true, classable: true, algorithmVersion: '15C-v1' };
            api.repairProfile(disabled);
            if (disabled.classable !== false) throw new Error('disabled profile remains classable');
            if (api.authoritativeIsClassableProfile(disabled)) throw new Error('disabled profile accepted');
            """
        )
        subprocess.run(
            ["node", "-e", script],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )

    def test_only_applied_mr_publication_keeps_metric_decision(self):
        script = textwrap.dedent(
            r"""
            const fs = require('fs');
            const vm = require('vm');
            const source = fs.readFileSync('classability-sync-v1.js', 'utf8');
            let domReady = null;
            const context = {
              console,
              document: {
                readyState: 'loading',
                addEventListener(name, callback) { if (name === 'DOMContentLoaded') domReady = callback; }
              },
              HTMLFormElement: function HTMLFormElement() {},
              setInterval() { return 1; },
              clearInterval() {},
              setTimeout(callback) { callback(); return 1; },
              render() {},
              save() {},
            };
            context.window = context;
            context.__pass50CloudReady = true;
            vm.createContext(context);
            vm.runInContext(source, context);
            domReady();
            const api = context.PASS50_CLASSABILITY_SYNC;

            const publishedMr = {
              eligible: true,
              alive: true,
              classable: false,
              algorithmVersion: '15C-v1',
              dataEngine: { algorithmVersion: 'MR-V1.0', scoreStatus: 'published_mr_v1' }
            };
            api.repairProfile(publishedMr);
            if (publishedMr.classable !== false) throw new Error('published MR decision was overwritten');
            if (api.authoritativeIsClassableProfile(publishedMr)) throw new Error('published MR exclusion was ignored');

            const experimentalOnly = {
              eligible: true,
              alive: true,
              classable: false,
              algorithmVersion: '15C-v1',
              dataEngine: { algorithmVersion: 'MR-V1.0', scoreStatus: 'experimental' }
            };
            api.repairProfile(experimentalOnly);
            if (experimentalOnly.classable !== true) throw new Error('experimental marker blocked public legacy profile');
            if (!api.authoritativeIsClassableProfile(experimentalOnly)) throw new Error('experimental-only profile remains excluded');
            """
        )
        subprocess.run(
            ["node", "-e", script],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )

    def test_profile_form_save_and_cloud_state_are_bridged(self):
        self.assertIn("form.id !== 'profileForm'", SYNC)
        self.assertIn("setTimeout(() => repairSavedProfile(profileId), 0)", SYNC)
        self.assertIn("repairAll({ forceRender: true })", SYNC)
        self.assertIn("profileItem.classable = expected", SYNC)
        self.assertIn("profileItem.editorialEligible = editorialEligible", SYNC)

    def test_public_rule_is_replaced_without_editing_ranking_engine(self):
        self.assertIn("function isClassableProfile(p){return Boolean(p?.eligible)&&p.classable!==false;}", INDEX)
        self.assertIn("window.isClassableProfile = authoritativeIsClassableProfile", SYNC)
        self.assertIn("function authoritativeIsClassableProfile", SYNC)
        self.assertIn("profileItem?.dataEngine", SYNC)
        self.assertIn("published_mr_v1", SYNC)
        self.assertIn("return true;", SYNC)

    def test_loader_and_cache_are_versioned(self):
        self.assertIn("PASS50-CLASSABILITY-SYNC-V1.1", SYNC)
        self.assertIn("classability-sync-v1.js?v=1.1", LOADER)
        self.assertIn("data-pass50-classability-sync", LOADER)
        self.assertIn("classability-sync-v1.js?v=1.1", SW)
        self.assertIn("pass50-v67-authoritative-classability", SW)


if __name__ == "__main__":
    unittest.main()
