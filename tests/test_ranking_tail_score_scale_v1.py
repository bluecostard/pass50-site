import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RANKING = (ROOT / "api/metrics-ranking-core.php").read_text(encoding="utf-8")


class RankingTailScoreScaleV1Tests(unittest.TestCase):
  """Audit comparatif falaise 24H (MR-V1.4) vs échelle queue MR-V1.5."""

  def test_v15_restores_audience_presence_scale(self):
    self.assertIn("P50_MR_ALGORITHM_VERSION='MR-V1.5'", RANKING)
    self.assertIn("function p50_mr_audience_only_base", RANKING)
    self.assertIn("function p50_mr_audience_presence_cap", RANKING)
    self.assertIn("P50_MR_AUDIENCE_PRESENCE_CAP_SHORT = 30.0", RANKING)
    self.assertIn("p50_mr_audience_only_base((float)$audiencePercentile,$periodKey)", RANKING)
    self.assertNotIn("$base=$audiencePercentile*$weights['audience']", RANKING)

  def test_blended_path_keeps_audience_at_five_percent(self):
    self.assertIn("if($feature==='audience')continue", RANKING)
    self.assertIn("$dynamicBase*(1-$weights['audience'])", RANKING)
    self.assertIn("$audiencePercentile*$weights['audience']", RANKING)
    self.assertIn("'audience'=>0.05", RANKING)

  def test_short_periods_use_thirty_percent_cap(self):
    self.assertIn("return in_array($periodKey,['2H','24H','48H'],true)?P50_MR_AUDIENCE_PRESENCE_CAP_SHORT", RANKING)

  def test_audience_only_base_math(self):
    # percentile 70 × cap 30 % → 21 % (vs ~3.5 % en MR-V1.4)
    self.assertIn("$audiencePercentile/100.0*$cap", RANKING)


if __name__ == "__main__":
  unittest.main()
