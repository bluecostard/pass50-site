import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RANKING = (ROOT / "api/metrics-ranking-core.php").read_text(encoding="utf-8")
PUBLICATION = (ROOT / "api/metrics-ranking-publication-core.php").read_text(encoding="utf-8")
APPLY = (ROOT / "api/metrics-ranking-publication-apply-core.php").read_text(encoding="utf-8")
BACKFILL = (ROOT / "api/metrics-zero-score-backfill-core.php").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")


class ScoreEveryoneClassableV1Tests(unittest.TestCase):
    def test_audience_only_still_produces_a_capped_score(self):
        self.assertIn("if($dynamicWeightSum<=0)", RANKING)
        self.assertNotIn("if($dynamicWeightSum<=0)continue", RANKING)
        self.assertIn("if($audiencePercentile===null)continue", RANKING)
        self.assertIn("$base=$audiencePercentile*$weights['audience']", RANKING)
        self.assertIn("'audience'=>0.05", RANKING)

    def test_soft_exclusions_do_not_unclass_a_scored_alive_official_profile(self):
        self.assertIn("$classable=$score!==null&&(bool)$profile['alive']&&$official", RANKING)
        self.assertNotIn("$classable=$score!==null&&count($reasons)===0", RANKING)
        for reason in (
            "coverage_below_30",
            "confidence_below_40",
            "no_measurable_content",
            "stale_captures",
            "editorial_not_eligible",
        ):
            self.assertIn(reason, RANKING)

    def test_publication_keeps_any_positive_experimental_score(self):
        self.assertIn("(float)$score>0", PUBLICATION)
        self.assertNotIn("!empty($row['classable'])&&$row['rank']!==null&&$row['score']!==null", PUBLICATION)
        self.assertIn("candidateDerivedFromScoredExperimentalRows", PUBLICATION)
        self.assertNotIn("empty($profile['eligible'])", PUBLICATION)

    def test_publication_never_clears_a_public_score_on_exit(self):
        self.assertNotIn("['profileId'=>$profileId,'period'=>$period,'action'=>'clear'", APPLY)
        self.assertIn("$exits++", APPLY)

    def test_zero_score_backfill_copies_any_positive_experimental_score(self):
        self.assertIn("ZERO-SCORE-BACKFILL-V1.3", BACKFILL)
        self.assertNotIn("classable=1 AND score IS NOT NULL AND score>0", BACKFILL)

    def test_census_verified_links_are_official_and_get_a_presence_score(self):
        self.assertIn("legacy_social_link", RANKING)
        self.assertIn("verified_social_link", RANKING)
        self.assertIn("verifiedOfficialIds", RANKING)
        self.assertIn("awaiting_measurable_capture", RANKING)
        self.assertIn("$score=0.1", RANKING)
        self.assertNotIn("preg_match('/(?:unknown|candidate|unverified|legacy)/i'", RANKING)

    def test_youtube_public_fallback_records_subscribers(self):
        collectors = (ROOT / "api/metrics-collectors-core.php").read_text(encoding="utf-8")
        self.assertIn("function p50_mc_youtube_public_subscribers", collectors)
        self.assertIn("'followers'=>$subscribers", collectors)
        self.assertIn("'sourceType'=>'youtube_public_feed'", collectors)
        self.assertIn(
            "function isClassableProfile(p){return Boolean(p&&p.alive!==false)&&!p50IsDeletedProfileId(p&&p.id)&&hasPeriodScore(p);}",
            INDEX,
        )


if __name__ == "__main__":
    unittest.main()
