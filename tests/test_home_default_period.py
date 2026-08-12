from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")


def test_home_opens_on_24h_ranking():
    assert '<button class="active" data-period="24H">24 H</button>' in INDEX
    assert '<button class="active" data-period="2H">2 H</button>' not in INDEX
    assert "let ui={period:'24H',region:'ALL'" in INDEX
