import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")


class ExactContentLinkTests(unittest.TestCase):
    def test_youtube_watch_is_not_generic(self):
        body = re.search(r"function p50v9IsGenericLink\(url=''\)\{(.*?)\n\}", V9, re.S).group(0)
        self.assertIn("path==='watch'&&u.searchParams.get('v')", body)
        self.assertNotIn("/^(home|feed|watch)", body)

    def test_event_html_requires_exact_link_when_validated(self):
        self.assertIn(
            "const valid=e.originalLinkValidated&&p50v9ExactContentLink(e.url)",
            V9,
        )


if __name__ == "__main__":
    unittest.main()
