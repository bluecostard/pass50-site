import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
CSS = (ROOT / "data-engine-ui.css").read_text(encoding="utf-8")
TOOLS = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class AdminFullscreenLayoutTests(unittest.TestCase):
    def test_admin_modal_is_fullscreen_without_changing_generic_modals(self):
        self.assertRegex(CSS, r"#adminModal\s*\{[^}]*padding:0")
        box = re.search(r"#adminModal \.modal-box\s*\{([^}]*)\}", CSS)
        self.assertIsNotNone(box)
        rules = box.group(1)
        for declaration in (
            "width:100vw",
            "height:100dvh",
            "max-width:none",
            "max-height:none",
            "border-radius:0",
            "box-shadow:none",
            "overflow:hidden",
        ):
            self.assertIn(declaration, rules)
        self.assertIn("#adminModal .modal-body", CSS)
        self.assertRegex(CSS, r"#adminModal \.modal-body\{[^}]*overflow:hidden")
        self.assertRegex(CSS, r"#adminModal \.admin-pane\{[^}]*overflow-y:auto")
        self.assertRegex(CSS, r"#adminModal \.admin-pane\{[^}]*overflow-x:hidden")
        self.assertIn("grid-template-columns:250px minmax(0,1fr)", CSS)
        self.assertRegex(CSS, r"#adminModal \.admin-table-wrap\{[^}]*overflow-x:auto")

    def test_mobile_keeps_a_local_horizontal_menu_and_local_table_scroll(self):
        mobile = CSS[CSS.index("@media(max-width:760px){", CSS.index("Administration plein écran")) :]
        self.assertIn("#adminModal .admin-menu", mobile)
        self.assertIn("flex-direction:row", mobile)
        self.assertIn("overflow-x:auto", mobile)
        self.assertIn("grid-template-columns:minmax(0,1fr)", mobile)


class AdminHomeNavigationTests(unittest.TestCase):
    tabs = (
        "signals",
        "profiles",
        "media",
        "links",
        "news",
        "live",
        "update",
        "metricsdiag",
        "intelligence",
        "hub",
        "quality",
        "ranking",
        "data",
    )

    def test_home_is_first_and_all_existing_tabs_remain(self):
        items = re.search(r"const ADMIN_ITEMS=\[(.*?)\];", UI, re.DOTALL)
        self.assertIsNotNone(items)
        source = items.group(1)
        self.assertRegex(source.lstrip(), r"\['adminhome','Accueil'")
        for tab in self.tabs:
            self.assertIn(f"['{tab}',", source)
            self.assertIn(f'data-admin-tab="${{id}}"', UI)

    def test_home_content_and_open_behavior(self):
        self.assertIn("function deRenderAdminHome(pane)", UI)
        self.assertIn("ACCUEIL DE L’ADMINISTRATION", UI)
        self.assertIn(
            "Pilotez les données, les fiches, les métriques et la publication de PASS50.",
            UI,
        )
        self.assertIn("ui.adminTab='adminhome';renderAdmin()", INDEX)
        self.assertIn("adminTab:'adminhome'", INDEX)

    def test_header_separates_home_and_explicit_exit(self):
        admin_markup = re.search(
            r'<div class="modal" id="adminModal".*?</div></div></div>',
            INDEX,
        )
        self.assertIsNotNone(admin_markup)
        markup = admin_markup.group(0)
        self.assertIn("ADMINISTRATION PASS50", markup)
        self.assertIn("Accueil administration", markup)
        self.assertIn("Quitter l’administration", markup)
        self.assertNotIn('data-close="adminModal"', markup)
        self.assertIn("function p50ExitAdmin()", INDEX)

    def test_escape_and_backdrop_return_admin_to_home_only(self):
        close_function = re.search(r"function close\(id\)\{(.*?)\n\}", INDEX, re.DOTALL)
        self.assertIsNotNone(close_function)
        self.assertIn("if(id==='adminModal')return p50AdminGoHome()", close_function.group(1))
        self.assertIn("if(e.target===m)close(m.id)", INDEX)
        self.assertIn("if(visible)close(visible.id)", INDEX)
        go_home = re.search(r"function p50AdminGoHome\(\)\{([^}]*)\}", INDEX)
        self.assertIsNotNone(go_home)
        self.assertIn("ui.adminTab='adminhome'", go_home.group(1))
        self.assertIn("renderAdmin()", go_home.group(1))
        self.assertNotIn("location.href", go_home.group(1))
        self.assertNotIn("history.back", go_home.group(1))

    def test_reset_demo_explicitly_exits_admin(self):
        reset_demo = re.search(r"function resetDemo\(\)\{(.*?)\}", INDEX, re.DOTALL)
        self.assertIsNotNone(reset_demo)
        self.assertIn("p50ExitAdmin()", reset_demo.group(1))
        self.assertNotIn("close('adminModal')", reset_demo.group(1))

        exit_admin = re.search(r"function p50ExitAdmin\(\)\{(.*?)\}", INDEX, re.DOTALL)
        self.assertIsNotNone(exit_admin)
        self.assertIn("ui.adminTab='adminhome'", exit_admin.group(1))
        self.assertIn("modal.classList.remove('show')", exit_admin.group(1))

    def test_other_modal_close_buttons_are_unchanged(self):
        for modal in (
            "liveModal",
            "profileModal",
            "authModal",
            "userModal",
            "top50Modal",
            "notificationModal",
            "toolModal",
            "voteShareModal",
        ):
            self.assertIn(f'data-close="{modal}"', INDEX)

    def test_detailed_admin_views_offer_home_navigation(self):
        self.assertGreaterEqual(
            INDEX.count('data-admin-tab="adminhome"'), 2
        )
        self.assertGreaterEqual(
            UI.count('data-admin-tab="adminhome"'), 2
        )
        self.assertIn("← Accueil administration", INDEX)
        self.assertIn("← Accueil administration", UI)


class AdminCacheTests(unittest.TestCase):
    def test_data_engine_versions_and_cache_are_coherent(self):
        self.assertIn("data-engine-ui.js?v=18.27", TOOLS)
        self.assertIn("data-engine-ui.css?v=27.1", TOOLS)
        self.assertIn("data-engine-ui.js?v=18.27", SW)
        self.assertIn("data-engine-ui.css?v=27.1", SW)
        self.assertNotIn("data-engine-ui.js?v=15.0", TOOLS + SW + INDEX)
        self.assertIn("const CACHE='pass50-v89-stable-public-copy'", SW)


if __name__ == "__main__":
    unittest.main()
