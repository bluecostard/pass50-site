from pathlib import Path

def test_profile_claim_contract():
    api=Path('api/profile-claims.php').read_text(encoding='utf-8')
    ui=Path('profile-claims-v1.js').read_text(encoding='utf-8')
    assert 'p50_profile_claims' in api
    assert "source_type,'manual_owner'" not in api
    assert "'manual_owner'" in api
    assert "match_status" in api and "evidence_json" in api
    assert "require_role($user,'owner','admin')" in api
    assert "Profil revendiqué" in ui
    assert "Revendications" in ui
    assert "connection_required" in api
