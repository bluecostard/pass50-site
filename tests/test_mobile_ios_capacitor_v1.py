import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class MobileIosCapacitorV1Tests(unittest.TestCase):
    def test_mobile_scaffold_exists(self):
        self.assertTrue((ROOT / "mobile/package.json").is_file())
        self.assertTrue((ROOT / "mobile/capacitor.config.json").is_file())
        self.assertTrue((ROOT / "mobile/www/index.html").is_file())
        self.assertTrue((ROOT / "mobile/README.md").is_file())
        cfg = read("mobile/capacitor.config.json")
        self.assertIn("store.pass50.app", cfg)
        self.assertIn("https://pass50.store", cfg)
        self.assertIn("PushNotifications", cfg)

    def test_push_api_and_bridge(self):
        core = read("api/push-core.php")
        self.assertIn("p50_push_devices", core)
        self.assertIn("p50_push_register", core)
        self.assertIn("p50_push_broadcast", core)
        self.assertIn("PUSH-V1.0", core)
        devices = read("api/push-devices.php")
        self.assertIn("auth_user(false)", devices)
        cron = read("api/push-send-cron.php")
        self.assertIn("p50_mo_verify_cron_signature", cron)
        bridge = read("mobile-bridge.js")
        self.assertIn("PushNotifications", bridge)
        self.assertIn("push-devices.php", bridge)
        self.assertIn("pass50-native-ios", bridge)
        self.assertIn("mobile-bridge.js?v=1.0", read("app-config.js"))

    def test_deep_links_and_config_example(self):
        aasa = read(".well-known/apple-app-site-association")
        self.assertIn("store.pass50.app", aasa)
        self.assertIn("TEAMID", aasa)
        example = read("api/config.example.php")
        self.assertIn("'push'", example)
        self.assertIn("PASS50_APNS_KEY_ID", example)
        self.assertIn("mobile/node_modules/", read(".gitignore"))


if __name__ == "__main__":
    unittest.main()
