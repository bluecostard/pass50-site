from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / "api" / "weekly-digest-core.php").read_text(encoding="utf-8")
CRON = (ROOT / "api" / "weekly-digest-cron-v1.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github" / "workflows" / "weekly-digest-friday.yml").read_text(encoding="utf-8")
RENDER = (ROOT / "api" / "weekly-digest-render-core.php").read_text(encoding="utf-8")
PDF = (ROOT / "api" / "weekly-digest-pdf.php").read_text(encoding="utf-8")


class WeeklyDigestV1Tests(unittest.TestCase):
    def test_core_computes_three_mandatory_stats(self):
        self.assertIn("WEEKLY-DIGEST-V1.0", CORE)
        self.assertIn("weekly_digest", CORE)
        self.assertIn("Africa/Abidjan", CORE)
        self.assertIn("p50_weekly_digest_top_live", CORE)
        self.assertIn("p50_weekly_digest_top_rank_one", CORE)
        self.assertIn("p50_weekly_digest_top_prono", CORE)
        self.assertIn("p50_metric_captures", CORE)
        self.assertIn("p50_live_streams", CORE)
        self.assertIn("rank_position = 1", CORE)
        self.assertIn("period_key = ?", CORE)
        self.assertIn("p50_prono_votes", CORE)
        self.assertIn("Live le plus suivi", CORE)
        self.assertIn("N°1 du classement le plus souvent", CORE)
        self.assertIn("Influenceur le plus pronostiqué", CORE)

    def test_broadcast_is_mandatory_for_all_subscribers(self):
        self.assertIn("p50_weekly_digest_subscriber_ids", CORE)
        self.assertIn("deleted_at IS NULL", CORE)
        self.assertIn("p50_notification_create", CORE)
        self.assertNotIn("notification_mode", CORE)
        self.assertIn("p50_weekly_digest_runs", CORE)
        self.assertIn("already_sent", CORE)

    def test_cron_endpoint_is_signed_like_other_jobs(self):
        self.assertIn("weekly-digest-core.php", CRON)
        self.assertIn("p50_mo_verify_cron_signature", CRON)
        self.assertIn("'digest'", CRON)
        self.assertIn("'preview'", CRON)
        self.assertIn("p50_weekly_digest_run", CRON)

    def test_friday_21h_abidjan_schedule(self):
        self.assertIn("cron: '0 21 * * 5'", WORKFLOW)
        self.assertIn("weekly-digest-cron-v1.php", WORKFLOW)
        self.assertIn("WEEKLY-DIGEST-V1.0", WORKFLOW)
        self.assertIn("pass50/weekly-digest", WORKFLOW)
        self.assertRegex(WORKFLOW, r"Abidjan")

    def test_single_page_pdf_and_html(self):
        card = (ROOT / "weekly-digest-card.html").read_text(encoding="utf-8")
        public = (ROOT / "bilan-semaine.php").read_text(encoding="utf-8")
        index = (ROOT / "index.html").read_text(encoding="utf-8")
        poster = (ROOT / "weekly-digest-share-v2.js").read_text(encoding="utf-8")
        self.assertIn("p50_weekly_digest_view_model", RENDER)
        self.assertIn("p50_weekly_digest_pdf_bytes", RENDER)
        self.assertIn("p50_weekly_digest_render_html", RENDER)
        self.assertIn("application/pdf", PDF)
        self.assertIn("weekly-digest-share-v2.js", card)
        self.assertIn("weekly-digest-render-core.php", public)
        self.assertIn("Télécharger le PDF", index)
        self.assertIn("weeklyDigestPdfUrl", index)
        self.assertIn("p50_weekly_digest_pdf_url", CORE)
        self.assertIn("pdfUrl", CORE)

    def test_poster_layout_locked_with_store_badges(self):
        poster = (ROOT / "weekly-digest-share-v2.js").read_text(encoding="utf-8")
        self.assertIn("PASS50-WEEKLY-DIGEST-POSTER-V1-LOCK", poster)
        self.assertIn("drawStoreBadges", poster)
        self.assertIn("App Store", poster)
        self.assertIn("Google Play", poster)
        self.assertIn("storeBadgesY", poster)

    def test_staff_can_dispatch_weekly_digest_test(self):
        dispatch = (ROOT / "api" / "weekly-digest-dispatch-test-v1.php").read_text(encoding="utf-8")
        index = (ROOT / "index.html").read_text(encoding="utf-8")
        self.assertIn("require_role($user, 'owner', 'admin')", dispatch)
        self.assertIn("p50_weekly_digest_compute_stats", dispatch)
        self.assertIn("p50_notification_create", dispatch)
        self.assertIn("weekly-digest-dispatch-test-v1.php", index)
        self.assertIn("weeklyDigestTestDispatch", index)
        self.assertIn("weeklyDigestCardUrl", index)
        self.assertIn("Voir l’affiche", index)


if __name__ == "__main__":
    unittest.main()
