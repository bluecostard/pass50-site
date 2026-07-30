from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    target=Path(path);text=target.read_text(encoding='utf-8');count=text.count(old)
    if count!=1:
        raise SystemExit(f'{path}: remplacement attendu 1 fois, trouvé {count}: {old[:100]!r}')
    target.write_text(text.replace(old,new,1),encoding='utf-8')


replace_once(
    'api/metrics-collectors-core.php',
    "require_once __DIR__.'/youtube-metrics-bridge-core.php';\n",
    "require_once __DIR__.'/youtube-metrics-bridge-core.php';\nrequire_once __DIR__.'/meta-metrics-bridge-core.php';\n",
)
replace_once(
    'api/metrics-collectors-core.php',
    "function p50_mc_official(PDO $pdo,string $profileId,string $platform): array {\n    $threshold=p50_mc_threshold();\n    $stmt=$pdo->prepare(\"SELECT r.profile_id,r.public_name,s.normalized_url,s.confidence\n      FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id\n      WHERE r.profile_id=? AND r.alive=1 AND s.platform=? AND s.status='verified' AND s.confidence>=? LIMIT 1\");\n    $stmt->execute([$profileId,$platform,$threshold]);$row=$stmt->fetch();\n    if(!$row&&$platform==='YouTube'&&function_exists('p50ym_official_profile'))$row=p50ym_official_profile($pdo,$profileId);\n    if(!$row)throw new InvalidArgumentException('Profil actif ou source officielle vérifiée introuvable.');\n    return $row;\n}\n",
    "function p50_mc_official(PDO $pdo,string $profileId,string $platform): array {\n    if(in_array($platform,['Facebook','Instagram'],true)&&function_exists('p50mm_official_profile')){\n        $mapped=p50mm_official_profile($pdo,$profileId,$platform);if($mapped)return $mapped;\n    }\n    $threshold=p50_mc_threshold();\n    $stmt=$pdo->prepare(\"SELECT r.profile_id,r.public_name,s.normalized_url,s.confidence\n      FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id\n      WHERE r.profile_id=? AND r.alive=1 AND s.platform=? AND s.status='verified' AND s.confidence>=? LIMIT 1\");\n    $stmt->execute([$profileId,$platform,$threshold]);$row=$stmt->fetch();\n    if(!$row&&$platform==='YouTube'&&function_exists('p50ym_official_profile'))$row=p50ym_official_profile($pdo,$profileId);\n    if(!$row)throw new InvalidArgumentException('Profil actif ou source officielle vérifiée introuvable.');\n    return $row;\n}\n",
)

replace_once(
    'api/metrics-orchestrator-core.php',
    "function p50_mo_candidate_ids(PDO $pdo,array $cadence,array $live,array $cfg): array {\n    if($cadence['key']==='p0')return array_values(array_unique(array_merge($live['profileIds'],p50_mo_viral_profiles($pdo),$cfg['priorityIds'])));",
    "function p50_mo_authorized_oauth_profiles(PDO $pdo): array {\n    $ids=[];\n    if(p50_metrics_table_exists($pdo,'p50_youtube_oauth_connections')&&p50_metrics_column_exists($pdo,'p50_youtube_oauth_connections','profile_id')){\n        $stmt=$pdo->query(\"SELECT DISTINCT y.profile_id FROM p50_youtube_oauth_connections y JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY y.profile_id WHERE y.profile_id IS NOT NULL AND y.status='active' AND r.alive=1 LIMIT 100\");\n        $ids=array_merge($ids,array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)));\n    }\n    if(function_exists('p50mm_authorized_profile_ids'))$ids=array_merge($ids,p50mm_authorized_profile_ids($pdo));\n    return array_values(array_unique(array_filter($ids)));\n}\n\nfunction p50_mo_candidate_ids(PDO $pdo,array $cadence,array $live,array $cfg): array {\n    if($cadence['key']==='p0')return array_values(array_unique(array_merge($live['profileIds'],p50_mo_viral_profiles($pdo),$cfg['priorityIds'],p50_mo_authorized_oauth_profiles($pdo))));",
)
replace_once(
    'api/metrics-orchestrator-core.php',
    "function p50_mo_enqueue_profile(PDO $pdo,string $profileId,string $platform,string $cadenceKey='p0',array $options=[]): array {",
    "function p50_mo_oauth_meta_rows(PDO $pdo,array $profileIds): array {\n    return function_exists('p50mm_orchestrator_rows')?p50mm_orchestrator_rows($pdo,$profileIds):[];\n}\n\nfunction p50_mo_enqueue_profile(PDO $pdo,string $profileId,string $platform,string $cadenceKey='p0',array $options=[]): array {",
)
replace_once(
    'api/metrics-orchestrator-core.php',
    "array_merge($stmt->fetchAll(),p50_mo_oauth_youtube_rows($pdo,$ids))",
    "array_merge($stmt->fetchAll(),p50_mo_oauth_youtube_rows($pdo,$ids),p50_mo_oauth_meta_rows($pdo,$ids))",
)

replace_once(
    'data-engine-ui.js',
    "const controlCenter=data.controlCenter||{},controlPlatforms=Array.isArray(controlCenter.platforms)?controlCenter.platforms:[],youtubeOAuth=controlCenter.youtubeOAuth||{},youtubeConnections=Array.isArray(youtubeOAuth.connections)?youtubeOAuth.connections:[],controlSummary=controlCenter.summary||{};",
    "const controlCenter=data.controlCenter||{},controlPlatforms=Array.isArray(controlCenter.platforms)?controlCenter.platforms:[],youtubeOAuth=controlCenter.youtubeOAuth||{},youtubeConnections=Array.isArray(youtubeOAuth.connections)?youtubeOAuth.connections:[],metaOAuth=controlCenter.metaOAuth||{},metaAssets=Array.isArray(metaOAuth.assets)?metaOAuth.assets:[],controlSummary=controlCenter.summary||{};",
)
replace_once(
    'data-engine-ui.js',
    "const controlState={operational:['Opérationnel','verified'],incomplete:['Incomplet','candidate'],degraded:['Dégradé','conflict'],no_coverage:['À démarrer','candidate'],no_verified_links:['Sans lien vérifié','empty'],authorization_required:['Autorisation requise','candidate'],not_configured:['Non configuré','empty']};",
    "const controlState={operational:['Opérationnel','verified'],incomplete:['Incomplet','candidate'],degraded:['Dégradé','conflict'],no_coverage:['À démarrer','candidate'],no_verified_links:['Sans source officielle','empty'],authorization_required:['Autorisation requise','candidate'],not_configured:['Non configuré','empty']};",
)
youtube_line="const youtubeMappingRows=youtubeConnections.map(row=>{const options=['<option value=\"\">Non associée</option>',...metricProfileOptions.map(profile=>`<option value=\"${deEsc(profile.id)}\" ${String(row.profileId||'')===String(profile.id)?'selected':''}>${deEsc(profile.name||profile.id)}</option>`)].join('');return `<tr><td><strong>${deEsc(row.channelTitle||row.channelId)}</strong><div class=\"muted\">${deEsc(row.channelId)}</div></td><td>${deEsc(row.status||'—')}</td><td><select class=\"de-youtube-metrics-profile\">${options}</select></td><td>${row.lastAnalyticsAt?deTime(row.lastAnalyticsAt):'Jamais'}</td><td><button class=\"btn de-youtube-metrics-map\" data-channel-id=\"${deEsc(row.channelId)}\">Enregistrer</button></td></tr>`}).join('');"
replace_once(
    'data-engine-ui.js',
    youtube_line,
    youtube_line+"\n    const metaMappingRows=metaAssets.map(row=>`<tr><td><strong>${deEsc(row.platform)}</strong></td><td><strong>${deEsc(row.assetName||row.username||row.assetId)}</strong><div class=\"muted\">${deEsc(row.username?('@'+row.username):row.assetId)}</div></td><td>${row.profileName?`<span class=\"de-status verified\">${deEsc(row.profileName)}</span>`:'<span class=\"de-status empty\">Non associé</span>'}</td><td>${row.insightsAuthorized?'<span class=\"de-status verified\">Base + Insights</span>':'<span class=\"de-status candidate\">Données de base</span>'}</td><td>${row.lastError?`<span class=\"muted\">${deEsc(row.lastError)}</span>`:(row.lastCheckedAt?deTime(row.lastCheckedAt):'Jamais')}</td></tr>`).join('');",
)
replace_once(
    'data-engine-ui.js',
    '<div class="media-hint">L’association active la collecte canonique YouTube. Les statistiques par période restent identifiées comme métriques d’intervalle et ne sont pas mélangées aux compteurs cumulés.</div></section>',
    '<div class="media-hint">L’association active la collecte canonique YouTube. Les statistiques par période restent identifiées comme métriques d’intervalle et ne sont pas mélangées aux compteurs cumulés.</div><div class="section-head"><div><div class="section-title">COMPTES META AUTORISÉS</div><div class="muted">Facebook et Instagram associés sont collectés automatiquement. Les métriques Insights ne sont appelées que lorsque la permission correspondante est réellement accordée.</div></div><span class="muted">${deObsNumber(metaOAuth.summary?.mapped)} associé(s) · ${deObsNumber(metaOAuth.summary?.unmapped)} non associé(s)</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Plateforme</th><th>Compte</th><th>Fiche PASS50</th><th>Capacité</th><th>Dernier contrôle</th></tr></thead><tbody>${metaMappingRows||\'<tr><td colspan="5">Aucun compte Meta OAuth connecté.</td></tr>\'}</tbody></table></div><div class="media-hint">« Données de base » couvre notamment le compte, les abonnés et les interactions publiques disponibles. « Base + Insights » ajoute les métriques avancées autorisées par Meta.</div></section>',
)

replace_once('v9-tools.js','data-engine-ui.js?v=18.1','data-engine-ui.js?v=18.2')
replace_once('sw.js','data-engine-ui.js?v=18.1','data-engine-ui.js?v=18.2')
for test_path in ['tests/test_metrics_ranking_experimental_v1.py','tests/test_metrics_ranking_calibration_v1.py']:
    path=Path(test_path);text=path.read_text(encoding='utf-8')
    if 'data-engine-ui.js?v=18.1' not in text:
        raise SystemExit(f'{test_path}: version 18.1 introuvable')
    path.write_text(text.replace('data-engine-ui.js?v=18.1','data-engine-ui.js?v=18.2'),encoding='utf-8')
