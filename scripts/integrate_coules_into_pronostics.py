from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
NAV=ROOT/'mobile-bottom-nav-v1.js'

text=NAV.read_text(encoding='utf-8')
if 'function loadPronoCoulesTab()' not in text:
    anchor='  function callWhenReady(name, callback, attempts = 100) {'
    loader="""  function loadPronoCoulesTab() {
    if (window.__pass50PronosticsCoulesTabV1 || document.querySelector('script[data-pass50-prono-coules-tab]')) return;
    const script = document.createElement('script');
    script.src = './pronostics-coules-tab-v1.js?v=1.0';
    script.async = false;
    script.dataset.pass50PronoCoulesTab = '1.0';
    document.head.appendChild(script);
  }

"""
    if anchor not in text:
        raise RuntimeError('Anchor loadPronoCoulesTab introuvable dans mobile-bottom-nav-v1.js')
    text=text.replace(anchor,loader+anchor,1)
if '    loadPronoCoulesTab();' not in text:
    anchor='    loadContextShare();\n'
    if anchor not in text:
        raise RuntimeError('Anchor init loadContextShare introuvable')
    text=text.replace(anchor,anchor+'    loadPronoCoulesTab();\n',1)
text=text.replace("PASS50-MOBILE-BOTTOM-NAV-V1.7","PASS50-MOBILE-BOTTOM-NAV-V1.8")
NAV.write_text(text,encoding='utf-8')

for path in list(ROOT.glob('*.html'))+[ROOT/'sw.js']:
    if not path.exists():
        continue
    body=path.read_text(encoding='utf-8')
    updated=body.replace('mobile-bottom-nav-v1.js?v=1.7','mobile-bottom-nav-v1.js?v=1.8')
    if updated!=body:
        path.write_text(updated,encoding='utf-8')

Path(__file__).unlink()
