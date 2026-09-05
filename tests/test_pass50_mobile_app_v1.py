#!/usr/bin/env python3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOBILE = ROOT / "apps" / "mobile"


class Pass50MobileAppV1Tests(unittest.TestCase):
    def test_expo_app_has_pass50_tabs(self):
        layout = (MOBILE / "app/(tabs)/_layout.tsx").read_text(encoding="utf-8")
        tabbar = (MOBILE / "components/Pass50TabBar.tsx").read_text(encoding="utf-8")
        for tab in ("index", "feed", "prono", "live", "profile"):
            self.assertIn(f'name="{tab}"', layout)
        self.assertIn("Pass50TabBar", layout)
        self.assertIn("Mon fil", tabbar)
        self.assertIn("Pronos", tabbar)
        self.assertIn("Classement", tabbar)
        self.assertIn("Mon espace", tabbar)
        self.assertIn("elevated", tabbar)
        self.assertIn("href: null", layout)

    def test_tabs_load_site_mobile_safari_pages(self):
        """Parity Safari mobile: WebView of pass50.store pages (not app.html)."""
        shell = (MOBILE / "components/SiteWebView.tsx").read_text(encoding="utf-8")
        ranking = (MOBILE / "app/(tabs)/index.tsx").read_text(encoding="utf-8")
        feed = (MOBILE / "app/(tabs)/feed.tsx").read_text(encoding="utf-8")
        prono = (MOBILE / "app/(tabs)/prono.tsx").read_text(encoding="utf-8")
        profile = (MOBILE / "app/(tabs)/profile.tsx").read_text(encoding="utf-8")
        package = (MOBILE / "package.json").read_text(encoding="utf-8")

        self.assertIn("react-native-webview", package)
        self.assertIn("SiteWebView", ranking)
        self.assertIn("SiteWebView", feed)
        self.assertIn("SiteWebView", prono)
        self.assertIn("SiteWebView", profile)
        self.assertIn("https://pass50.store", shell)
        self.assertIn("mon-fil.html", shell)
        self.assertIn("pronostics.html", shell)
        self.assertIn("open=account", shell)
        self.assertIn(".p50-bottom-nav", shell)
        self.assertNotIn("app.html?", shell)
        self.assertNotIn("/app.html", shell)
        self.assertNotIn("/app.html", ranking)
        self.assertIn("SITE_URLS.ranking", ranking)

    def test_api_client_targets_pass50_store(self):
        client = (MOBILE / "src/api/client.ts").read_text(encoding="utf-8")
        self.assertIn("https://pass50.store/api/", client)
        self.assertIn("live-status.php?mode=status", client)
        self.assertIn("public-ranking.php", client)
        self.assertIn("prono-feed.php", client)
        self.assertIn("coules-history.php", client)
        self.assertNotIn("mode=quick", client)

    def test_live_screen_polls_read_only_status(self):
        live = (MOBILE / "app/(tabs)/live.tsx").read_text(encoding="utf-8")
        self.assertIn("setInterval(load, 20000)", live)
        self.assertIn("lecture seule", live)

    def test_app_json_branding(self):
        app_json = (MOBILE / "app.json").read_text(encoding="utf-8")
        self.assertIn('"name": "PASS50"', app_json)
        self.assertIn('"scheme": "pass50"', app_json)
        self.assertIn("#050705", app_json)

    def test_onboarding_first_open_flow(self):
        entry = (MOBILE / "app/index.tsx").read_text(encoding="utf-8")
        layout = (MOBILE / "app/_layout.tsx").read_text(encoding="utf-8")
        slides = (MOBILE / "src/onboarding/slides.ts").read_text(encoding="utf-8")
        storage = (MOBILE / "src/onboarding/storage.ts").read_text(encoding="utf-8")

        self.assertIn("hasCompletedOnboarding", entry)
        self.assertIn("/onboarding", entry)
        self.assertIn('name="onboarding/index"', layout)
        self.assertIn("pass50_onboarding_seen_v1", slides)
        self.assertIn("'final'", slides)
        self.assertIn("'coules'", slides)
        self.assertIn("resetOnboarding", storage)

    def test_influencer_profile_from_public_ranking(self):
        influencer = (MOBILE / "app/influencer/[id].tsx").read_text(encoding="utf-8")
        lookup = (MOBILE / "src/ranking/lookup.ts").read_text(encoding="utf-8")
        self.assertIn("findInfluencerInRanking", influencer)
        self.assertIn("pass50Api.ranking", influencer)
        self.assertIn("RANKING_PERIODS", lookup)

    def test_screen_shell_matches_site_chrome(self):
        shell = (MOBILE / "components/ScreenShell.tsx").read_text(encoding="utf-8")
        colors = (MOBILE / "constants/Colors.ts").read_text(encoding="utf-8")
        self.assertIn("eyebrow", shell)
        self.assertIn("status", shell)
        self.assertIn("PASS", shell)
        self.assertIn("#050705", colors)
        self.assertIn("#b7ff00", colors)


if __name__ == "__main__":
    unittest.main()
