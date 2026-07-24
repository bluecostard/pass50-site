#!/usr/bin/env python3
from pathlib import Path
import argparse, shutil, re, datetime, os, sys

TAG='<script src="./pass50-metrics-ui.js?v=1.0"></script>'

def backup(path):
    stamp=datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    out=path.with_name(path.name+'.backup-'+stamp);shutil.copy2(path,out);return out

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('repo',nargs='?',default='.')
    args=ap.parse_args()
    repo=Path(args.repo).resolve()
    package=Path(__file__).resolve().parent
    api=repo/'api'; index=repo/'index.html'
    if not api.exists() or not index.exists():
        print('ERREUR : dépôt PASS50 invalide.',file=sys.stderr);return 1
    for name in ['metrics-core.php','metrics-collect.php','metrics-status.php','metrics-cron.php','metrics-config.example.php']:
        shutil.copy2(package/'api'/name,api/name)
    shutil.copy2(package/'migration-metrics-v1.sql',repo/'migration-metrics-v1.sql')
    shutil.copy2(package/'pass50-metrics-ui.js',repo/'pass50-metrics-ui.js')
    html=index.read_text(encoding='utf-8')
    html=re.sub(r'\s*<script src=["\']\./pass50-metrics-ui\.js[^"\']*["\']></script>','',html,flags=re.I)
    marker=re.search(r'(<script src=["\']\./v9-tools\.js[^"\']*["\']></script>)',html,re.I)
    if not marker:
        print('ERREUR : v9-tools.js introuvable.',file=sys.stderr);return 1
    backup(index)
    html=html[:marker.end()]+'\n'+TAG+html[marker.end():]
    index.write_text(html,encoding='utf-8')
    print('✓ Collecteur métriques installé')
    print('✓ Interface Administration → Métriques ajoutée')
    print('✓ API YouTube et X ajoutées')
    print('\nÀ faire :')
    print('1. Importer migration-metrics-v1.sql dans MySQL.')
    print('2. Ajouter PASS50_YOUTUBE_API_KEY et PASS50_METRICS_CRON_TOKEN dans api/config.php.')
    print('3. Publier les fichiers.')
    print('4. Ouvrir Administration → Métriques → Collecter maintenant.')
    return 0
if __name__=='__main__': raise SystemExit(main())
