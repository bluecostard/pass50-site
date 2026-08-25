import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
ONBOARDING = (ROOT / "pass50-onboarding.js").read_text(encoding="utf-8")
APPROVED = (ROOT / "pass50-onboarding-approved-v1.js").read_text(encoding="utf-8")
LOADER = (ROOT / "public-ui-sanitizer-v1.js").read_text(encoding="utf-8")


class Pass50FirstOpenTutorialTests(unittest.TestCase):
    def test_five_screens_in_approved_order(self):
        welcome = ONBOARDING.index("type: 'welcome'")
        ranking = ONBOARDING.index("type: 'ranking'")
        bet = ONBOARDING.index("type: 'bet'")
        coules = ONBOARDING.index("type: 'coules'")
        final = ONBOARDING.index("type: 'final'")
        self.assertLess(welcome, ranking)
        self.assertLess(ranking, bet)
        self.assertLess(bet, coules)
        self.assertLess(coules, final)

    def test_copy_matches_brief(self):
        for fragment in (
            "L’actualité des influenceurs ivoiriens",
            "Classement actualisé toutes les 2h - 24h - 48h",
            "Aucun classement forcé",
            "Parie sur l’actualité",
            "Ça va faire le buzz",
            "Les coulés. Qui mousse plus",
            "Découvrir PASS50",
        ):
            self.assertIn(fragment, ONBOARDING)

    def test_left_and_right_corner_clicks_navigate(self):
        self.assertIn("p50-ob-hit-left", ONBOARDING)
        self.assertIn("p50-ob-hit-right", ONBOARDING)
        self.assertIn("addEventListener('click', retreat)", ONBOARDING)
        self.assertIn("addEventListener('click', advance)", ONBOARDING)
        self.assertIn("aria-label=\"Écran précédent\"", ONBOARDING)
        self.assertIn("aria-label=\"Écran suivant\"", ONBOARDING)

    def test_loader_and_replay_hook_exist(self):
        self.assertIn("pass50-onboarding.js?v=1.2", LOADER)
        self.assertIn("pass50-onboarding-approved-v1.js?v=1.2", LOADER)
        self.assertIn("Revoir le tutoriel", ONBOARDING)
        self.assertIn("p50-approved-boat", APPROVED)


if __name__ == "__main__":
    unittest.main()
