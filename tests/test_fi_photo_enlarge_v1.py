from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")


class FiPhotoEnlargeV1Tests(unittest.TestCase):
    def test_fi_left_photo_is_zoomable(self):
        self.assertIn("zoomable:true", INDEX)
        self.assertIn("zoomable:true", V9)
        self.assertIn('data-fi-photo="', INDEX)
        self.assertIn("is-zoomable", INDEX)
        self.assertIn("function openFiPhoto(p)", INDEX)
        self.assertIn("function closeFiPhoto()", INDEX)
        self.assertIn('id="fiPhotoLightbox"', INDEX)
        self.assertIn("#profileBody .avatar.is-zoomable", INDEX)
        self.assertNotIn('content:"Agrandir"', INDEX)

    def test_lightbox_closes_without_leaving_the_fiche(self):
        self.assertIn("if($('#fiPhotoLightbox')?.classList.contains('show')){closeFiPhoto();return;}", INDEX)
        self.assertIn("if(id==='profileModal'){closeFiPhoto();restoreProfileOrigin();p50ClearProfileQuery();}", INDEX)
        self.assertIn("e.target.closest('#fiPhotoLightbox')", INDEX)

    def test_other_avatars_stay_as_fiche_entry_points(self):
        self.assertIn("${avatarHtml(p)}<div><strong>${p.handle||p.name||'Influenceur'}</strong>", INDEX)
        self.assertIn("${avatarHtml(p)}<div><strong><a href=\"/fi/", INDEX)
        self.assertNotIn("avatarHtml(p,'',{zoomable:true})", INDEX[INDEX.find("function personRow"):INDEX.find("function personRow")+400])


if __name__ == "__main__":
    unittest.main()
