import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
TOOLS = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
NOTIFY = (ROOT / "admin-notifications-v1.js").read_text(encoding="utf-8")


class AdminNotificationsV1Tests(unittest.TestCase):
    def test_collect_tasks_is_shared(self):
        self.assertIn("window.p50CollectAdminTodoTasks", UI)
        self.assertIn("await window.p50CollectAdminTodoTasks()", UI)

    def test_notification_module_wires_bell_and_poll(self):
        self.assertIn("p50AdminNotifyAfterTodoLoad", NOTIFY)
        self.assertIn("p50AdminNotificationsStart", NOTIFY)
        self.assertIn("addNotification(u.id, title, body", NOTIFY)
        self.assertIn("POLL_MS = 5 * 60 * 1000", NOTIFY)

    def test_loader_and_versions(self):
        self.assertIn("admin-notifications-v1.js?v=1.0", TOOLS)
        self.assertRegex(TOOLS, r"data-engine-ui\.js\?v=18\.\d+")

    def test_index_supports_admin_todo_notifications(self):
        self.assertIn("kind==='admin_todo'", INDEX)
        self.assertIn("data-open-admin-todo", INDEX)
        self.assertIn("localAddNotification(userId,title,body,meta)", INDEX)
        self.assertIn("adminOpen(tab)", INDEX)
        self.assertIn("p50AdminNotificationsStart", INDEX)


if __name__ == "__main__":
    unittest.main()
