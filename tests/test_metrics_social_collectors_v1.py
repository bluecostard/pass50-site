import pathlib,re,unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
COMMON=(ROOT/"api/metrics-collectors-core.php").read_text()
SOCIAL=(ROOT/"api/metrics-social-collectors-core.php").read_text()
ENDPOINT=(ROOT/"api/metrics-canonical-collect.php").read_text()
READINESS_CRON=(ROOT/"api/metrics-collector-readiness-cron.php").read_text()
ACTIVATION=(ROOT/".github/workflows/activate-multinetwork-collectors.yml").read_text()
SCHEMA=(ROOT/"api/metrics-schema-core.php").read_text()
UI=(ROOT/"data-engine-ui.js").read_text()
ADAPTERS={name:(ROOT/f"api/metrics-collector-{name}.php").read_text() for name in ("tiktok","instagram","facebook","snapchat")}
ALL=COMMON+SOCIAL+ENDPOINT+READINESS_CRON+ACTIVATION+SCHEMA+UI+"".join(ADAPTERS.values())

class SocialCollectorsV1Tests(unittest.TestCase):
    def test_platform_dispatch_and_separate_adapters(self):
        for platform in ("TikTok","Instagram","Facebook","Snapchat"):
            self.assertIn(f"'{platform}'",COMMON)
            self.assertIn(f"p50_mc_{platform.lower()}",SOCIAL)
        self.assertIn("function p50_mc_dispatch",SOCIAL)
        self.assertIn("p50_mc_dispatch($platform)",COMMON)

    def test_only_canonical_primitives_write(self):
        for source in ADAPTERS.values():
            self.assertNotRegex(source,r"(?:INSERT|UPDATE|DELETE).*p50_metric_",re.I)
        for primitive in ("p50_metrics_upsert_account","p50_metrics_upsert_content"):
            self.assertIn(primitive,SOCIAL)
        self.assertIn("p50_mc_capture",SOCIAL)
        self.assertIn("p50_metrics_record_capture",COMMON)

    def test_server_side_official_selection_and_endpoint_protection(self):
        self.assertIn("p50_profile_registry",COMMON);self.assertIn("p50_social_links",COMMON)
        for key in ("token","secret","url","contentUrl","endpoint","headers","authorization","cookie"):
            self.assertIn(f"'{key}'",ENDPOINT)
        self.assertNotIn("canonicalUrl",ENDPOINT)

    def test_credentials_are_server_only_and_diagnostic_is_boolean(self):
        self.assertIn("function p50_mc_credentials",SOCIAL)
        self.assertIn("function p50_mc_public_access",SOCIAL)
        public=SOCIAL[SOCIAL.index("function p50_mc_public_access"):SOCIAL.index("function p50_mc_dispatch")]
        self.assertNotIn("'secret'",public)
        for field in ("configured","authorized","mode","authorizationRequired"):
            self.assertIn(field,public)

    def test_identifier_parsers(self):
        self.assertIn("^@([A-Za-z0-9._-]{2,32})$",SOCIAL)
        self.assertIn("Instagram",SOCIAL);self.assertIn("Snapchat",SOCIAL)
        self.assertIn("function p50_msc_facebook_identity",SOCIAL)
        for forbidden in ("login","search","share","sharer"):
            self.assertIn(forbidden,SOCIAL)

    def test_content_types_are_additive(self):
        for content_type in ("video","post","reel","short","live","story","spotlight","photo","carousel","unknown"):
            self.assertIn(f"'{content_type}'",SCHEMA)
        self.assertIn("'expired'",ADAPTERS["snapchat"])

    def test_tiktok_modes_and_metrics(self):
        source=ADAPTERS["tiktok"]
        for token in ("authorized_display","approved_research","research_approved","open_id","follower_count","following_count","likes_count","video_count","view_count","favorites_count"):
            self.assertIn(token,source+SOCIAL)
        self.assertIn("/v2/user/info/",source)
        self.assertIn("/v2/video/list/",source)
        self.assertIn("/v2/research/user/info/",source)
        self.assertIn("/v2/research/video/query/",source)
        self.assertNotIn("'username'=>$username,'max_count'",source)

    def test_instagram_semantics(self):
        source=ADAPTERS["instagram"]
        for token in ("unsupported_account_type","CAROUSEL_ALBUM","REELS","STORY","like_count","comments_count","reach","plays","totalInteractions","accountsEngaged","/insights","business_discovery.username","p50_mc_instagram_insight_groups","$preferred,$fallback","'stories'","'media'"):
            self.assertIn(token,source)
        self.assertNotIn("/instagram_business_discovery",source)
        self.assertIn("storiesAuthorized",source)

    def test_facebook_semantics(self):
        source=ADAPTERS["facebook"]
        for token in ("PAGE","unsupported_account_type","reactions.type(LIKE)","comments.limit(0).summary(true)","posts","insights","reactionsTotal","videoViews","postClicks"):
            self.assertIn(token,source)
        self.assertIn("['id']??''",source)
        canonical=source[source.index("p50_msc_store_content"):source.index("],(int)$postsResponse")]
        self.assertIn("'views'=>p50_mc_int($insights,'post_video_views')",canonical)
        self.assertNotIn("'views'=>p50_mc_int($insights,'post_impressions_unique')",canonical)
        self.assertIn("'reach'=>p50_mc_int($insights,'post_impressions_unique')",canonical)

    def test_snapchat_semantics(self):
        source=ADAPTERS["snapchat"]
        for token in ("/public/v1/public_profiles/search","public_profiles","public_profile","subscriber_count","spotlights","saved_stories","stories","/stats","expired"):
            self.assertIn(token,source)
        self.assertNotIn("/v1/public_profiles/'.rawurlencode($username)",source)

    def test_http_contract_supports_get_post_json(self):
        self.assertIn("CURLOPT_CUSTOMREQUEST",COMMON)
        self.assertIn("CURLOPT_POSTFIELDS",COMMON)
        self.assertIn("Content-Type: application/json",COMMON)
        self.assertIn("string $method='GET'",COMMON)

    def test_insights_parser_accepts_all_official_shapes(self):
        parser=SOCIAL[SOCIAL.index("function p50_msc_graph_insights"):SOCIAL.index("function p50_msc_snap_assets")]
        self.assertIn("$item['value']",parser)
        self.assertIn("$values[0]['value']",parser)
        self.assertIn("$item['total_value']['value']",parser)

    def test_statuses_and_idempotence_contract(self):
        for status in ("success","partial","authorization_required","configuration_missing","unavailable_or_blocked","unsupported_account_type","rate_limited","error"):
            self.assertIn(status,ALL)
        self.assertIn("p50_mc_capture",SOCIAL)
        self.assertNotIn("runUuid",COMMON[COMMON.index("function p50_mc_capture"):COMMON.index("function p50_mc_request")])

    def test_no_publication_legacy_or_browser_bypass(self):
        new_runtime=COMMON+SOCIAL+ENDPOINT+READINESS_CRON+"".join(ADAPTERS.values())
        for forbidden in ("p50_de_publish_score_pipeline","p50_de_publish_profile","p50_de_15c_window","data-publish.php","UPDATE app_state","INSERT INTO app_state","live-radar-v3"):
            self.assertNotIn(forbidden,new_runtime)
        for forbidden in ("localStorage","document.cookie","playwright","selenium","puppeteer"):
            self.assertNotIn(forbidden,"".join(ADAPTERS.values())+SOCIAL)

    def test_admin_and_observability_six_platforms(self):
        self.assertIn("COLLECTE DES MÉTRIQUES SOCIALES",UI)
        self.assertIn("Collecte expérimentale : les données ne modifient pas encore le classement public.",UI)
        for platform in ("YouTube","X","TikTok","Instagram","Facebook","Snapchat"):
            self.assertIn(platform,UI)
        for field in ("usableCaptures","quarantinedCaptures","authorizationRequired","unavailableProfiles"):
            self.assertIn(field,COMMON)

    def test_schema_activation_from_pr43_remains_available(self):
        self.assertIn("INSTALLER LE SCHÉMA CANONIQUE",UI)
        self.assertIn("apiFetch('metrics-migrate.php'",UI)
        self.assertIn("canonical.migrationStatus==='applied'",UI)
        self.assertIn("Installe d’abord le schéma canonique.",UI)
        self.assertRegex(UI,r'class="btn de-collect-metrics"[^>]+schemaApplied')

    def test_multinetwork_probe_is_hmac_protected_and_read_only(self):
        self.assertIn("p50_mo_verify_cron_signature",READINESS_CRON)
        self.assertIn("HTTP_X_P50_TIMESTAMP",READINESS_CRON)
        self.assertIn("HTTP_X_P50_SIGNATURE",READINESS_CRON)
        self.assertIn("'action','dispatchId'",READINESS_CRON)
        self.assertIn("p50_mcr_status",READINESS_CRON)
        self.assertIn("p50_metrics_collectors_status",READINESS_CRON)
        self.assertIn("p50_mo_dispatch($pdo,'p1'",READINESS_CRON)
        self.assertIn("'preview'=>true",READINESS_CRON)
        self.assertIn("'secretsExposed'=>false",READINESS_CRON)
        self.assertIn("'publicStateWrites'=>0",READINESS_CRON)

    def test_activation_maps_all_supported_server_credentials(self):
        for secret in (
            "PASS50_X_BEARER_TOKEN","PASS50_TIKTOK_ACCESS_TOKEN","PASS50_TIKTOK_RESEARCH_TOKEN",
            "PASS50_TIKTOK_RESEARCH_APPROVED","PASS50_INSTAGRAM_ACCESS_TOKEN",
            "PASS50_INSTAGRAM_ACCOUNT_ID","PASS50_INSTAGRAM_DISCOVERY_ACCOUNT_ID",
            "PASS50_FACEBOOK_ACCESS_TOKEN","PASS50_FACEBOOK_PAGE_ID","PASS50_SNAPCHAT_ACCESS_TOKEN",
        ):
            self.assertIn(secret,ACTIVATION)
        for key in ("x_bearer_token","tiktok_access_token","tiktok_research_token","instagram_access_token","facebook_access_token","snapchat_access_token"):
            self.assertIn(key,ACTIVATION)
        self.assertIn("config.php.bak-multinet-",ACTIVATION)
        self.assertNotIn("cat /tmp/p50-multinet/config.php",ACTIVATION)
        self.assertIn("secretsExposed':False",ACTIVATION)

    def test_activation_verifies_coverage_and_dispatches_p1(self):
        self.assertIn("metrics-collector-readiness-cron.php",ACTIVATION)
        self.assertIn("candidatesByPlatform",READINESS_CRON)
        self.assertIn("metrics-top50-2h.yml/dispatches",ACTIVATION)
        self.assertIn("pass50/multinetwork-collection",ACTIVATION)
        self.assertIn("publicStateWrites == 0",ACTIVATION)
        self.assertIn("actions/upload-artifact@v4",ACTIVATION)

if __name__=="__main__": unittest.main()
