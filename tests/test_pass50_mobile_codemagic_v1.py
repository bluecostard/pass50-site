#!/usr/bin/env python3
"""Static checks for Codemagic App Store / Play workflows."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
YAML = ROOT / "codemagic.yaml"
MOBILE = ROOT / "apps" / "mobile"


class Pass50MobileCodemagicV1Tests(unittest.TestCase):
    def test_codemagic_yaml_exists_at_repo_root(self):
        self.assertTrue(YAML.is_file(), "codemagic.yaml must live at repository root")

    def test_ios_workflows_target_pass50_bundle(self):
        text = YAML.read_text(encoding="utf-8")
        self.assertIn("pass50-ios-testflight:", text)
        self.assertIn("pass50-ios-appstore:", text)
        self.assertIn("pass50-android-internal:", text)
        self.assertIn("bundle_identifier: store.pass50.app", text)
        self.assertIn("BUNDLE_ID: store.pass50.app", text)
        self.assertIn("PACKAGE_NAME: store.pass50.app", text)
        self.assertIn("app_store_connect: Pass50", text)
        self.assertIn("MOBILE_DIR: apps/mobile", text)
        self.assertIn("npx expo prebuild --platform ios", text)
        self.assertIn("app-store-connect publish", text)
        self.assertIn("--altool-verbose-logging", text)
        self.assertIn("--testflight", text)
        self.assertIn("EXPO_PUBLIC_API_BASE: https://pass50.store/api/", text)

    def test_android_signing_support_file(self):
        gradle = MOBILE / "support-files" / "codemagic-android-signing.gradle"
        self.assertTrue(gradle.is_file())
        body = gradle.read_text(encoding="utf-8")
        self.assertIn("CM_KEYSTORE_PATH", body)
        self.assertIn("signingConfigs", body)

    def test_readme_documents_codemagic(self):
        readme = (MOBILE / "README.md").read_text(encoding="utf-8")
        self.assertIn("Codemagic", readme)
        self.assertIn("pass50-ios-testflight", readme)
        self.assertIn("APP_STORE_APPLE_ID", readme)


if __name__ == "__main__":
    unittest.main()
