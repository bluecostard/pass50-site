from pathlib import Path

def test_feed_profile_returns_to_feed():
    feed=Path('mon-fil.js').read_text(encoding='utf-8')
    home=Path('index.html').read_text(encoding='utf-8')
    assert feed.count('return=mon-fil') >= 2
    assert "pass50.feed.return.v1" in feed
    assert "scrollY" in feed and "restoreFeedOrigin();" in feed
    assert "returnToFeedAfterProfile" in home
    assert "location.assign(target)" in home
    assert "candidate.pathname.endsWith('/mon-fil.html')" in home
