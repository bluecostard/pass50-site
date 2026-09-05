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
        """Parity Safari mobile: WebView of pass50.store pages (not Capacitor shell)."""
        shell = (MOBILE / "components/SiteWebView.tsx").read_text(encoding="utf-8")
        ranking = (MOBILE / "app/(tabs)/index.tsx").read_text(encoding="utf-8")
        feed = (MOBILE / "app/(tabs)/feed.tsx").read_text(encoding="utf-8")
        prono = (MOBILE / "app/(tabs)/prono.tsx").read_text(encoding="utf-8")
        profile = (MOBILE / "app/(tabs)/profile.tsx").read_text(encoding="utf-8")
        package = (MOBILE / "package.json").read_text(encoding="utf-8")

        self.assertIn("react-native-webview", package)
        self.assertIn('tab="ranking"', ranking)
        self.assertIn('tab="feed"', feed)
        self.assertIn('tab="prono"', prono)
        self.assertIn('tab="account"', profile)
        self.assertIn("https://pass50.store", shell)
        self.assertIn("mon-fil.html", shell)
        self.assertIn("pronostics.html", shell)
        self.assertIn("mon-espace.html", shell)
        self.assertIn(".p50-bottom-nav", shell)
        self.assertIn("BLOCK_AUTH_REDIRECT_JS", shell)
        self.assertIn("need-auth", shell)
        self.assertIn("pathAllowedForTab", shell)
        self.assertNotIn("ACCOUNT_SHELL_CSS", shell)
        self.assertNotIn("OPEN_ACCOUNT_JS", shell)
        self.assertIn("expo-router", shell)
        self.assertNotIn("@react-navigation/native", shell)
        self.assertNotIn("/app.html", shell)
        self.assertNotIn("/app.html", ranking)

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


class Pass50MonEspacePageTests(unittest.TestCase):
    def test_dedicated_account_page_exists(self):
        html = (ROOT / "mon-espace.html").read_text(encoding="utf-8")
        js = (ROOT / "mon-espace.js").read_text(encoding="utf-8")
        nav = (ROOT / "mobile-bottom-nav-v1.js").read_text(encoding="utf-8")
        self.assertIn("Mon espace", html)
        self.assertIn("mon-espace.js", html)
        self.assertIn("pass50-auth-session.js", html)
        self.assertIn("login.php", js)
        self.assertIn("register.php", js)
        self.assertIn("me.php", js)
        self.assertIn("mon-espace.html", nav)
        self.assertIn("isAccount", nav)
        self.assertIn("location.replace('./mon-espace.html'", nav)


if __name__ == "__main__":
    unittest.main()
