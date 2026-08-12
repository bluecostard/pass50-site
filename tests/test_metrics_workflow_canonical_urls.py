from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
P0=(ROOT/'.github/workflows/metrics-priority-15m.yml').read_text(encoding='utf-8')
BASELINE=(ROOT/'.github/workflows/metrics-public-baseline-p1.yml').read_text(encoding='utf-8')

def test_p0_signs_canonical_https_urls():
    assert 'PROBE_URL="https://${PROBE_URL#http://}"' in P0
    assert 'CRON_URL="https://${CRON_URL#http://}"' in P0

def test_baseline_signs_canonical_https_url_in_both_steps():
    assert BASELINE.count('BASELINE_URL="https://${BASELINE_URL#http://}"')==2
