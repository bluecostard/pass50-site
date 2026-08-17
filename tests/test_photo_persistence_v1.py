import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
AUDIT = (ROOT / "api/profile-assets-audit-cron-v1.php").read_text(encoding="utf-8")


class PhotoPersistenceV1Tests(unittest.TestCase):
    def test_cloud_merge_preserves_unlocked_validated_photos(self):
        self.assertIn("manualPhotos=Object.fromEntries((db.profiles||[]).filter(p=>{", INDEX)
        self.assertIn("['validated','verified','approved','manual_verified','pending']", INDEX)
        self.assertIn("const localBetter=!cloudHasPhoto||kept.photoManualLocked", INDEX)

    def test_public_photo_accepts_verified_aliases(self):
        self.assertIn("['validated','verified','approved','manual_verified'].includes(status)", INDEX)

    def test_migrate_locks_validated_photos(self):
        self.assertIn("if(!p.photoManualLocked&&(p.photoUrl||p.photoCandidateUrl)&&['validated','verified','approved','manual_verified'].includes(photoStatus))", INDEX)

    def test_propose_and_bulk_do_not_wipe_validated_urls(self):
        self.assertIn("La photo validée est protégée", V9)
        self.assertNotIn("p.photoUrl='';p.photoStatus='pending'", V9)
        self.assertIn("!p.photoUrl", V9)

    def test_profile_form_keeps_existing_photo_when_url_empty(self):
        self.assertIn("const nextPhotoUrl=String(fd.get('photoUrl')||'').trim()", INDEX)
        self.assertIn("else if(!p.photoUrl&&!p.photoCandidateUrl)", INDEX)
        self.assertNotIn("p.photoUrl=String(fd.get('photoUrl')||'');p.photoCandidateUrl=p.photoUrl;", INDEX)

    def test_audit_repair_writes_validated_and_locks(self):
        self.assertIn("$profile['photoStatus']='validated'", AUDIT)
        self.assertIn("$profile['photoManualLocked']=true", AUDIT)
        self.assertNotIn("$profile['photoStatus']='verified'", AUDIT)


if __name__ == "__main__":
    unittest.main()
