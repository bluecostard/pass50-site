import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class MetricsRankingPublicationApplyV1Tests(unittest.TestCase):
    def test_apply_core_writes_public_state_with_backup(self):
        core = read("api/metrics-ranking-publication-apply-core.php")
        self.assertIn("PUBAPPLY-V1.0", core)
        self.assertIn("p50_metric_publication_applies", core)
        self.assertIn("UPDATE app_state", core)
        self.assertIn("backup_json", core)
        self.assertIn("p50_mrp_apply_rollback", core)
        self.assertIn("bootstrap", core)
        self.assertIn("p50_mrp_apply_is_skippable_plan", core)
        # Empty candidate periods must remain skippable even when exit_ratio co-fires.
        self.assertIn("'candidate_non_empty','successful_run','exit_ratio','entry_ratio','maximum_rank_movement'", core)
        self.assertIn("publishPlans", core)
        self.assertIn("p50_de_load_public_state_for_update", core)
        self.assertIn("p50_mrp_apply_mutate_state", core)
        self.assertIn("if(!$hasOtherScore)$state['profiles'][$i]['classable']=false", core)
        self.assertNotIn("['profileId'=>$profileId,'period'=>$period,'action'=>'clear'", core)
        self.assertIn("p50_mrp_apply_health", core)
        self.assertIn("'health'=>p50_mrp_apply_health", core)
        # Backup d’état : rédaction au lieu d’un rejet dur (sinon 0 écriture si un profil a token=…).
        self.assertIn("p50_mr_json($state,false)", core)
        # Preview HTTP allégée : pas de report de simulation complet (500 IONOS).
        self.assertIn("p50_mrp_apply_preview_for_http", core)
        self.assertIn("'gates'=>array_values(array_filter((array)($report['gates']??[]),'is_array'))", core)
        plan_return = core.split("function p50_mrp_apply_plan_period", 1)[1].split("function p50_mrp_apply_is_skippable_plan", 1)[0]
        self.assertNotIn("'report'=>$report", plan_return)
        self.assertNotIn("'report' => $report", plan_return)

    def test_backup_json_redacts_instead_of_blocking(self):
        schema = read("api/metrics-schema-core.php")
        ranking = read("api/metrics-ranking-core.php")
        self.assertIn("function p50_metrics_redact_unsafe", schema)
        self.assertIn("function p50_mr_json(array $value,bool $strict=true)", ranking)
        self.assertIn("p50_metrics_redact_unsafe($value)", ranking)

    def test_admin_and_cron_endpoints_exist_and_reject_forced_bootstrap(self):
        admin = read("api/metrics-ranking-publication-apply.php")
        cron = read("api/metrics-ranking-publication-apply-cron.php")
        self.assertIn("require_role($user,'owner','admin')", admin)
        self.assertIn("p50_mrp_apply_execute", admin)
        self.assertIn("p50_mo_verify_cron_signature", cron)
        self.assertIn("automaticPublicationEnabled", cron)
        self.assertIn("'action','confirm','dispatchId'", cron)
        self.assertNotIn("'action','bootstrap','confirm','dispatchId'", cron)
        self.assertIn("'bootstrap'=>false", cron)
        self.assertIn("'appliedBy'=>'cron-automatic'", cron)
        self.assertIn("forcedBootstrapEnabled", cron)
        self.assertIn("array_key_exists('bootstrap',$in)", admin)
        self.assertIn("bootstrap_recovery_consumed", admin)
        self.assertIn("'bootstrap'=>false", admin)
        self.assertIn("forcedBootstrapEnabled", admin)
        self.assertIn("p50_mrp_apply_health", admin)
        self.assertIn("action=health", read("data-engine-ui.js"))
        self.assertIn("?action=health", read("data-engine-ui.js"))
        self.assertIn("'health'=>$health", cron)
        self.assertIn("set_time_limit(300)", admin)
        self.assertIn("set_time_limit(300)", cron)
        self.assertIn("ignore_user_abort(true)", admin)
        self.assertIn("ignore_user_abort(true)", cron)
        self.assertIn("p50_mrp_apply_preview_for_http", admin)
        self.assertIn("p50_mrp_apply_preview_for_http", cron)

    def test_one_time_bootstrap_workflow_is_retired(self):
        cron = read("api/metrics-ranking-publication-apply-cron.php")
        admin = read("api/metrics-ranking-publication-apply.php")
        ui = read("data-engine-ui.js")
        self.assertNotIn("cron-bootstrap-recovery", cron)
        self.assertNotIn("$forceBootstrap", cron)
        self.assertNotIn("$forceBootstrap", admin)
        # L’UI admin ne doit plus envoyer la clé bootstrap (sinon 409 permanent).
        self.assertNotIn("bootstrap:!!preview.bootstrap", ui)
        self.assertNotIn("bootstrap:!!", ui)

    def test_admin_publish_post_omits_bootstrap_key(self):
        ui = read("data-engine-ui.js")
        self.assertIn("action:'apply',confirm:true,dispatchId:", ui)
        self.assertNotIn("bootstrap:!!preview.bootstrap", ui)

    def test_initial_bootstrap_remains_internal_to_first_success(self):
        core = read("api/metrics-ranking-publication-apply-core.php")
        self.assertIn("bool $forceBootstrap=false", core)
        self.assertIn("p50_mrp_apply_has_prior_success", core)
        self.assertIn("function p50_mrp_apply_state_actor", core)
        self.assertIn("p50_mrp_apply_state_actor($appliedBy)", core)
        # Les endpoints publics n'exposent plus le forçage ; le moteur conserve la compatibilité interne.
        self.assertNotIn("bootstrap:true", read("api/metrics-ranking-publication-apply-cron.php"))
        self.assertNotIn("bootstrap:true", read("api/metrics-ranking-publication-apply.php"))

    def test_config_exposes_publication_flags(self):
        example = read("api/config.example.php")
        self.assertIn("ranking_publication_enabled", example)
        self.assertIn("ranking_automatic_publication_enabled", example)
        self.assertIn("PASS50_RANKING_PUBLICATION_ENABLED", example)
        self.assertIn("PASS50_METRICS_ORCHESTRATOR_ENABLED", example)

    def test_workflow_and_admin_ui_wired(self):
        workflow = read(".github/workflows/metrics-ranking-publication-apply.yml")
        ui = read("data-engine-ui.js")
        self.assertIn("metrics-ranking-publication-apply-cron.php", workflow)
        self.assertIn("de-ranking-publish", ui)
        self.assertIn("metrics-ranking-publication-apply.php", ui)
        self.assertIn("dePublishNeedsRecalculate", ui)
        self.assertIn("run_freshness", ui)
        self.assertIn("successful_run", ui)

    def test_simulation_remains_read_only(self):
        core = read("api/metrics-ranking-publication-core.php")
        self.assertIn("'publicStateWrites'=>0", core)
        self.assertIn("'publicationEnabled'=>false", core)


if __name__ == "__main__":
    unittest.main()
