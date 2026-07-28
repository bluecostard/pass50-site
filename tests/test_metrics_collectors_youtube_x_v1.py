import pathlib,re,unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
CORE=(ROOT/"api/metrics-collectors-core.php").read_text()
ENDPOINT=(ROOT/"api/metrics-canonical-collect.php").read_text()
OBS=(ROOT/"api/metrics-observability-core.php").read_text()
UI=(ROOT/"data-engine-ui.js").read_text()

class MetricsCollectorsContractTests(unittest.TestCase):
    def test_only_verified_server_side_sources(self):
        self.assertIn("p50_profile_registry",CORE)
        self.assertIn("p50_social_links",CORE)
        self.assertIn("r.alive=1",CORE)
        self.assertIn("s.status='verified'",CORE)
        self.assertNotIn("canonicalUrl",(ENDPOINT))

    def test_uses_canonical_primitives(self):
        for name in ("p50_metrics_ensure_schema","p50_metrics_upsert_account","p50_metrics_upsert_content","p50_metrics_record_capture","p50_metrics_start_run","p50_metrics_finish_run"):
            self.assertIn(name,CORE)
        self.assertNotRegex(CORE,r"INSERT\s+(?:IGNORE\s+)?INTO\s+p50_metric_(?:accounts|contents|captures)")

    def test_youtube_contract(self):
        for token in ("forHandle","forUsername","channel/","playlistItems","videos.list","hiddenSubscriberCount","totalViews","videoCount","youtube_public_feed","quota_exceeded","rate_limited","p50_mc_youtube_content_type","p50_mc_youtube_duration_seconds","liveStreamingDetails","youtubeFormat","shortCandidate"):
            self.assertIn(token,CORE)
        self.assertNotIn("snippet['isShort']",CORE)
        self.assertNotIn("contentDetails']['contentType']",CORE)
        self.assertIn("'shares'=>null",CORE)
        self.assertIn("'saves'=>null",CORE)

    def test_x_contract(self):
        for token in ("users/by/username","followers_count","following_count","tweet_count","impression_count","reply_count","retweet_count","quote_count","bookmark_count","unavailable_or_blocked"):
            self.assertIn(token,CORE)
        for forbidden in ("browser automation","private endpoint","document.cookie","localStorage.getItem('token"):
            self.assertNotIn(forbidden,CORE.lower())
        request=CORE[CORE.index("$tweets=p50_mc_request"):CORE.index("$tweetStatus=")]
        self.assertIn("'tweet.fields'=>'created_at,public_metrics'",request)
        self.assertNotIn("non_public_metrics",request)

    def test_subrequest_http_failures_are_not_silent_success(self):
        for endpoint in ("playlistItems.list","videos.list","users/:id/tweets"):
            self.assertIn(endpoint,CORE)
        self.assertGreaterEqual(CORE.count("$result['status']='partial'"),3)
        self.assertGreaterEqual(CORE.count("$result['rateLimited']="),3)

    def test_null_zero_and_validation_delegated_to_schema(self):
        self.assertIn("p50_mc_int",CORE)
        self.assertIn("array_key_exists",CORE)
        self.assertIn("p50_mc_future_metrics",CORE)
        self.assertIn("'qualityStatus'=>$invalid",CORE)
        parser=CORE[CORE.index("function p50_mc_int"):CORE.index("function p50_mc_official")]
        self.assertNotRegex(parser,r"\?\?\s*0")
        self.assertIn("p50_metrics_record_capture",CORE)

    def test_secrets_are_not_persisted(self):
        provenance=CORE[CORE.index("function p50_mc_provenance"):CORE.index("function p50_mc_result")]
        self.assertNotIn("key",provenance.lower())
        self.assertNotIn("token",provenance.lower())
        self.assertNotIn("Authorization",provenance)
        self.assertIn("rawPayloadHash",CORE)

    def test_endpoint_limits_and_authorization(self):
        self.assertIn("require_role($user,'owner','admin')",ENDPOINT)
        self.assertIn("GET_LOCK",ENDPOINT)
        self.assertIn("RELEASE_LOCK",ENDPOINT)
        self.assertIn("min(10",ENDPOINT)
        self.assertIn("min(5",ENDPOINT)
        self.assertIn("collect_profile",ENDPOINT)
        self.assertIn("collect_batch",ENDPOINT)

    def test_no_ranking_or_publication_writes(self):
        joined=CORE+ENDPOINT
        for forbidden in ("p50_de_publish_score_pipeline","p50_de_publish_profile","p50_de_15c_window","data-publish.php","UPDATE app_state","INSERT INTO app_state","ranking_snapshots"):
            self.assertNotIn(forbidden,joined)

    def test_observability_and_admin(self):
        self.assertIn("'collectors'=>",OBS)
        for token in ("configured","latestCaptureAt","captures24h","rateLimitedCount","unavailableProfiles"):
            self.assertIn(token,CORE)
        self.assertIn("COLLECTE YOUTUBE & X",UI)
        self.assertIn("Collecte expérimentale : les données ne modifient pas encore le classement public.",UI)
        self.assertIn("metrics-canonical-collect.php",UI)

if __name__=="__main__":
    unittest.main()
