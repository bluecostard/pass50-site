import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class MobileModalVideoProgressV1Tests(unittest.TestCase):
    def test_bottom_navigation_is_hidden_while_a_modal_is_open(self):
        script = read("mobile-modal-video-progress-v1.js")
        self.assertIn("PASS50-MOBILE-MODAL-VIDEO-PROGRESS-V1.0", script)
        self.assertIn("body.p50-modal-active .p50-bottom-nav{display:none!important}", script)
        self.assertIn("document.querySelector('.modal.show')", script)
        self.assertIn("classList.toggle('p50-modal-active',active)", script)
        self.assertIn("max-height:100dvh!important", script)

    def test_video_generation_has_a_visible_busy_state(self):
        script = read("mobile-modal-video-progress-v1.js")
        self.assertIn("Création de la vidéo…", script)
        self.assertIn("p50-video-spinner", script)
        self.assertIn("button.disabled=videoBusy", script)
        self.assertIn("body.setAttribute('aria-busy'", script)
        self.assertIn("window.generateVoteShareVideo=wrapped", script)
        self.assertIn("finally{", script)
        self.assertIn("setVideoBusy(false)", script)

    def test_loader_and_service_worker_publish_the_fix(self):
        loader = read("public-copy-fixes.js")
        worker = read("sw.js")
        self.assertIn("mobile-modal-video-progress-v1.js?v=1.0", loader)
        self.assertIn("data-pass50-mobile-modal-video-progress", loader)
        self.assertIn("mobile-modal-video-progress-v1.js?v=1.0", worker)
        self.assertIn("pass50-v76-mobile-modal-video-progress", worker)


if __name__ == "__main__":
    unittest.main()
