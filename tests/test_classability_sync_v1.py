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
    def test_mass_repair_ignores_stale_or_experimental_mr_markers(self):
        script = textwrap.dedent(
            r"""
            const fs = require('fs');
            const vm = require('vm');
            const source = fs.readFileSync('classability-sync-v1.js', 'utf8');
            let domReady = null;
            let saveCalls = 0;
            let renderCalls = 0;
            const context = {
              console,
              addEventListener() {},
              document: {
                readyState: 'loading',
                addEventListener(name, callback) { if (name === 'DOMContentLoaded') domReady = callback; }
              },
              HTMLFormElement: function HTMLFormElement() {},
              setInterval() { return 1; },
              clearInterval() {},
              setTimeout(callback) { callback(); return 1; },
              render() { renderCalls += 1; },
              save() { saveCalls += 1; },
              db: {
                profiles: [
                  {
                    id: 'tma-crush', eligible: true, alive: true, classable: false,
                    algorithmVersion: 'MR-V1.0', scores: { '2H': 66 }
                  },
                  {
                    id: 'melanie-tms', eligible: true, alive: true, classable: false,
                    rankingAlgorithmVersion: 'MR-V1.0', scores: { '2H': 0 }
                  },
                  {
                    id: 'legacy-experimental', eligible: true, alive: true, classable: false,
                    classabilitySource: 'MR-V1.0 experimental',
                    dataEngine: { algorithmVersion: 'MR-V1.0', scoreStatus: 'experimental' }
                  },
                  {
                    id: 'published-mr-excluded', eligible: true, alive: true, classable: false,
                    dataEngine: { algorithmVersion: 'MR-V1.0', scoreStatus: 'published_mr_v1' }
                  },
                  {
                    id: 'disabled', eligible: false, alive: true, classable: true
                  }
                ]
              }
            };
            context.window = context;
            context.__pass50CloudReady = true;
            context.isClassableProfile = profile => Boolean(profile?.eligible) && profile.classable !== false;
            vm.createContext(context);
            vm.runInContext(source, context);
            domReady();

            const api = context.PASS50_CLASSABILITY_SYNC;
            const byId = Object.fromEntries(context.db.profiles.map(profile => [profile.id, profile]));

            for (const id of ['tma-crush', 'melanie-tms', 'legacy-experimental']) {
              if (byId[id].classable !== true) throw new Error(id + ' remains blocked');
              if (!api.authoritativeIsClassableProfile(byId[id])) throw new Error(id + ' rejected by public rule');
              if (!context.isClassableProfile(byId[id])) throw new Error(id + ' rejected by installed global rule');
            }

            if (byId['published-mr-excluded'].classable !== false) {
              throw new Error('published MR exclusion was overwritten');
            }
            if (api.authoritativeIsClassableProfile(byId['published-mr-excluded'])) {
              throw new Error('published MR exclusion was ignored');
            }
            if (byId.disabled.classable !== false) throw new Error('ineligible profile remains classable');

            const diagnostic = api.diagnose();
            if (diagnostic.total !== 5) throw new Error('wrong diagnostic total');
            if (diagnostic.eligible !== 4) throw new Error('wrong eligible count');
            if (diagnostic.classable !== 3) throw new Error('wrong classable count');
            if (diagnostic.publishedMrExcluded !== 1) throw new Error('wrong published MR exclusion count');
            if (saveCalls < 1 || renderCalls < 1) throw new Error('repair was not persisted and rendered');
            """
        )
        subprocess.run(
            ["node", "-e", script],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )

    def test_only_applied_mr_publication_controls_classability(self):
        script = textwrap.dedent(
            r"""
            const fs = require('fs');
            const vm = require('vm');
            const source = fs.readFileSync('classability-sync-v1.js', 'utf8');
            let domReady = null;
            const context = {
              console,
              addEventListener() {},
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
              db: { profiles: [] }
            };
            context.window = context;
            context.__pass50CloudReady = true;
            vm.createContext(context);
            vm.runInContext(source, context);
            domReady();
            const api = context.PASS50_CLASSABILITY_SYNC;

            const markerOnly = {
              eligible: true,
              alive: true,
              classable: false,
              algorithmVersion: 'MR-V1.0',
              rankingAlgorithmVersion: 'MR-V1.0',
              classabilitySource: 'MR-V1.0'
            };
            if (api.metricsControlsClassability(markerOnly)) {
              throw new Error('stale top-level MR marker still controls classability');
            }
            api.repairProfile(markerOnly);
            if (markerOnly.classable !== true) throw new Error('marker-only profile remains blocked');

            const publishedMr = {
              eligible: true,
              alive: true,
              classable: false,
              dataEngine: { algorithmVersion: 'MR-V1.0', scoreStatus: 'published_mr_v1' }
            };
            if (!api.metricsControlsClassability(publishedMr)) {
              throw new Error('published MR decision is not detected');
            }
            api.repairProfile(publishedMr);
            if (publishedMr.classable !== false) throw new Error('published MR decision was overwritten');
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
        self.assertIn("PASS50_CLASSABILITY_DIAGNOSTIC", SYNC)

    def test_public_rule_binding_is_replaced_directly(self):
        self.assertIn("function isClassableProfile(p){return Boolean(p?.eligible)&&p.classable!==false;}", INDEX)
        self.assertIn("isClassableProfile = authoritativeIsClassableProfile", SYNC)
        self.assertIn("window.isClassableProfile = authoritativeIsClassableProfile", SYNC)
        self.assertIn("function authoritativeIsClassableProfile", SYNC)
        self.assertNotIn("profileItem?.rankingAlgorithmVersion", SYNC)
        self.assertNotIn("profileItem?.algorithmVersion", SYNC)
        self.assertIn("engine.scoreStatus", SYNC)
        self.assertIn("published_mr_v1", SYNC)

    def test_loader_and_cache_are_versioned(self):
        self.assertIn("PASS50-CLASSABILITY-SYNC-V1.4", SYNC)
        self.assertIn("classability-sync-v1.js?v=1.4", LOADER)
        self.assertIn("data-pass50-classability-sync", LOADER)
        self.assertIn("classability-sync-v1.js?v=1.4", SW)
        self.assertIn("pass50-v71-fix-verify-links", SW)
        self.assertIn("PASS50_LINK_SAVE_RUNNING", SYNC)
        self.assertNotIn("repairAll({ forceRender: true }), 80", SYNC)

    def test_verified_official_links_auto_promote_eligibility(self):
        script = textwrap.dedent(
            r"""
            const fs = require('fs');
            const vm = require('vm');
            const source = fs.readFileSync('classability-sync-v1.js', 'utf8');
            let domReady = null;
            const context = {
              console,
              addEventListener() {},
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
              p50v9IsDirectPlatformLink(platform, url) {
                return /^https?:\/\//i.test(String(url || '')) && !/search/i.test(String(url || ''));
              },
              db: {
                profiles: [
                  {
                    id: 'african-ryou',
                    eligible: false,
                    alive: true,
                    classable: false,
                    links: { TikTok: 'https://www.tiktok.com/@african_ryou', Instagram: 'https://www.instagram.com/african_ryou/' },
                    linkChecks: {
                      TikTok: { status: 'manual_verified' },
                      Instagram: { status: 'owner_verified' }
                    }
                  },
                  {
                    id: 'no-links',
                    eligible: false,
                    alive: true,
                    classable: false,
                    links: {},
                    linkChecks: {}
                  },
                  {
                    id: 'published-mr-excluded',
                    eligible: false,
                    alive: true,
                    classable: false,
                    links: { YouTube: 'https://www.youtube.com/@x' },
                    linkChecks: { YouTube: { status: 'ok' } },
                    dataEngine: { algorithmVersion: 'MR-V1.0', scoreStatus: 'published_mr_v1' }
                  }
                ]
              }
            };
            context.window = context;
            context.__pass50CloudReady = true;
            context.isClassableProfile = profile => Boolean(profile?.eligible) && profile.classable !== false;
            vm.createContext(context);
            vm.runInContext(source, context);
            domReady();

            const api = context.PASS50_CLASSABILITY_SYNC;
            const byId = Object.fromEntries(context.db.profiles.map(profile => [profile.id, profile]));

            if (byId['african-ryou'].eligible !== true || byId['african-ryou'].classable !== true) {
              throw new Error('verified links did not promote african-ryou');
            }
            if (!api.authoritativeIsClassableProfile(byId['african-ryou'])) {
              throw new Error('promoted profile still rejected');
            }
            if (byId['african-ryou'].classabilitySource !== 'verified_official_links') {
              throw new Error('missing verified_official_links source');
            }
            if (byId['no-links'].eligible !== false) throw new Error('profile without links was promoted');
            if (byId['published-mr-excluded'].classable !== false) {
              throw new Error('published MR exclusion was overwritten by link promotion');
            }
            """
        )
        subprocess.run(
            ["node", "-e", script],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )


if __name__ == "__main__":
    unittest.main()