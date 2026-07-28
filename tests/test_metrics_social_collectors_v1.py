import pathlib,re,unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
COMMON=(ROOT/"api/metrics-collectors-core.php").read_text()
SOCIAL=(ROOT/"api/metrics-social-collectors-core.php").read_text()
ENDPOINT=(ROOT/"api/metrics-canonical-collect.php").read_text()
SCHEMA=(ROOT/"api/metrics-schema-core.php").read_text()
UI=(ROOT/"data-engine-ui.js").read_text()
ADAPTERS={name:(ROOT/f"api/metrics-collector-{name}.php").read_text() for name in ("tiktok","instagram","facebook","snapchat")}
ALL=COMMON+SOCIAL+ENDPOINT+SCHEMA+UI+"".join(ADAPTERS.values())

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
        for token in ("authorized_display","approved_research","research_approved","open_id","follower_count","following_count","likes_count","video_count","view_count","favorite_count"):
            self.assertIn(token,source+SOCIAL)
        self.assertNotIn("private",source.lower())

    def test_instagram_semantics(self):
        source=ADAPTERS["instagram"]
        for token in ("unsupported_account_type","CAROUSEL_ALBUM","REELS","STORY","like_count","comments_count","reach","plays","totalInteractions","accountsEngaged"):
            self.assertIn(token,source)
        self.assertNotIn("'views'=>p50_mc_int($insights,'reach')",source)
        self.assertNotIn("'views'=>p50_mc_int($insights,'plays')",source)

    def test_facebook_semantics(self):
        source=ADAPTERS["facebook"]
        for token in ("PAGE","unsupported_account_type","LIKE","reactionsTotal","loveReactions","hahaReactions","videoViews","postClicks"):
            self.assertIn(token,source)
        self.assertIn("'likes'=>p50_mc_int($reactions,'LIKE')",source)

    def test_snapchat_semantics(self):
        source=ADAPTERS["snapchat"]
        for token in ("public_profile_id","subscriber_count","spotlight","story","authorized","expired","views","shares","saves"):
            self.assertIn(token,source)

    def test_statuses_and_idempotence_contract(self):
        for status in ("success","partial","authorization_required","configuration_missing","unavailable_or_blocked","unsupported_account_type","rate_limited","error"):
            self.assertIn(status,ALL)
        self.assertIn("p50_mc_capture",SOCIAL)
        self.assertNotIn("runUuid",COMMON[COMMON.index("function p50_mc_capture"):COMMON.index("function p50_mc_request")])

    def test_no_publication_legacy_or_browser_bypass(self):
        new_runtime=COMMON+SOCIAL+ENDPOINT+"".join(ADAPTERS.values())
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

if __name__=="__main__": unittest.main()
