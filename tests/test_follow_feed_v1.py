import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class FollowFeedV2Tests(unittest.TestCase):
    def test_feed_is_a_separate_finite_page(self):
        page = read("mon-fil.html")
        feed = read("mon-fil.js")
        self.assertIn('<body data-pass50-page="feed">', page)
        self.assertIn('id="feedList"', page)
        self.assertRegex(feed, r"PASS50-FOLLOW-FEED-PAGE-V2\.\d+")
        self.assertIn("const MAX_FOLLOWED = 5", feed)
        self.assertIn("const NEWS_PER_PROFILE = 2", feed)
        self.assertIn("slice(0, MAX_FOLLOWED)", feed)
        self.assertIn("Fin de votre fil", page)
        self.assertIn("aucune recommandation extérieure", page)

    def test_feed_uses_only_followed_profiles_and_validated_news(self):
        feed = read("mon-fil.js")
        self.assertIn("state.following.map(loadNewsFor)", feed)
        self.assertIn("content-feed.php", feed)
        self.assertIn("INFORMATION VALIDÉE", feed)
        self.assertIn("SOURCE OFFICIELLE", feed)
        self.assertIn("state.news.map(feedCard)", feed)

    def test_mobile_menu_is_compact_centered_and_has_no_live_tab(self):
        nav = read("mobile-bottom-nav-v1.js")
        self.assertIn("PASS50-MOBILE-BOTTOM-NAV-V1.8", nav)
        self.assertIn("position:fixed;left:50%;right:auto", nav)
        self.assertIn("width:min(400px,calc(100vw - 16px))", nav)
        self.assertIn("transform:translateX(-50%)", nav)
        self.assertIn("border-radius:24px", nav)
        self.assertIn("grid-template-columns:repeat(4,minmax(0,1fr))", nav)
        self.assertNotIn('data-p50-tab="live"', nav)
        self.assertNotIn("<span>En direct</span>", nav)
        self.assertIn('data-p50-tab="prono"', nav)
        self.assertIn("pronostics.html?v=83", nav)
        self.assertIn("touchend", nav)
        self.assertIn("freezeScroll", nav)

    def test_ranking_is_the_raised_middle_action_with_vector_icons(self):
        nav = read("mobile-bottom-nav-v1.js")
        feed_position = nav.index('data-p50-tab="feed"')
        prono_position = nav.index('data-p50-tab="prono"')
        ranking_position = nav.index('data-p50-tab="ranking"')
        account_position = nav.index('data-p50-tab="account"')
        self.assertLess(feed_position, prono_position)
        self.assertLess(prono_position, ranking_position)
        self.assertLess(ranking_position, account_position)
        self.assertIn("p50-bottom-link-ranking", nav)
        self.assertIn("min-height:78px", nav)
        self.assertIn("margin-top:-18px", nav)
        self.assertIn("transform:translateY(-3px)", nav)
        self.assertGreaterEqual(nav.count('<svg viewBox="0 0 24 24">'), 4)
        self.assertIn("Classement", nav)
        self.assertIn("Mon fil", nav)
        self.assertIn("Pronos", nav)
        self.assertIn("Mon espace", nav)

    def test_live_radar_stays_in_the_fixed_header_on_both_pages(self):
        page = read("mon-fil.html")
        feed = read("mon-fil.js")
        nav = read("mobile-bottom-nav-v1.js")
        index = read("index.html")
        self.assertIn('id="feedLiveRadarBtn"', page)
        self.assertIn('id="feedLiveModal"', page)
        self.assertIn("live-status.php", feed)
        self.assertIn("live-trust-gate-v1.js", page)
        self.assertIn("live-radar-v3.js", page)
        self.assertIn("syncLiveUi()", feed)
        self.assertIn("PASS50_LIVE_FILTER_PUBLIC", feed)
        self.assertIn("persistSharedLives", feed)
        self.assertIn('id="liveBtn"', index)
        self.assertIn("header>nav{display:none!important}", nav)
        self.assertNotIn("header>.actions{display:none!important}", nav)
        self.assertNotIn('id="liveSection"', page)

    def test_feed_live_radar_uses_shared_cache_with_ranking(self):
        page = read("mon-fil.html")
        feed = read("mon-fil.js")
        self.assertIn("window.db.liveStreams", page)
        self.assertIn("sharedLiveSource()", feed)
        self.assertIn("persistSharedLives", feed)
        self.assertIn("setInterval(syncLiveUi, 5000)", feed)
        self.assertNotIn("state.liveStreams = []", feed)

        loader = read("public-copy-fixes.js")
        worker = read("sw.js")
        page = read("mon-fil.html")
        self.assertIn("mobile-bottom-nav-v1.js?v=1.9", loader)
        self.assertNotIn("data-pass50-follow-watch", loader)
        self.assertIn("live-experience-v4-1.js?v=1.7", loader)
        self.assertIn("./mon-fil.html", worker)
        page_feed = re.search(r"mon-fil\.js\?v=([0-9.]+)", page)
        self.assertIsNotNone(page_feed)
        self.assertIn(f"mon-fil.js?v={page_feed.group(1)}", page)
        self.assertIn("mobile-bottom-nav-v1.js?v=1.9", worker)
        self.assertIn("live-radar-v3.js?v=1.9", worker)
        self.assertRegex(worker, r"pass50-v\d+-[a-z0-9-]+")


if __name__ == "__main__":
    unittest.main()
