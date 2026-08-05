import json
import re
import unittest
import urllib.parse
import urllib.request


class ApoutchouFacebookProductionDiagnostic(unittest.TestCase):
    def test_first_facebook_item_and_both_plugins(self):
        feed_url = (
            "https://www.pass50.store/api/content-feed.php?period=24h&profileId=apoutchou"
            "&newsLimit=30&verify=python-diagnostic-all-facebook"
        )
        request = urllib.request.Request(
            feed_url,
            headers={
                "Accept": "application/json",
                "Cache-Control": "no-cache, no-store",
                "User-Agent": "PASS50-CI-Diagnostic/1.1",
            },
        )
        with urllib.request.urlopen(request, timeout=30) as response:
            feed = json.loads(response.read().decode("utf-8"))
        self.assertTrue(feed.get("ok"))
        self.assertTrue(feed.get("ready"))
        self.assertTrue((feed.get("rules") or {}).get("facebookEmbedRouting"))

        items = [
            item for item in feed.get("news", [])
            if str(item.get("platform", "")).lower() == "facebook"
        ]
        print(f"APOUTCHOU_FACEBOOK_ITEMS count={len(items)}")
        for index, candidate in enumerate(items):
            parsed = urllib.parse.urlparse(str(candidate.get("url", "")))
            safe_path = f"{parsed.hostname or ''}{parsed.path or '/'}"
            print(
                "APOUTCHOU_ITEM "
                f"index={index} itemType={candidate.get('itemType')} "
                f"playable={candidate.get('playableInPass50')} "
                f"route={candidate.get('facebookEmbedType')} path={safe_path}"
            )
        self.assertTrue(items, "Aucune actualité Facebook n’est renvoyée pour la fiche Apoutchou")

        item = items[0]
        source = str(item.get("url", ""))
        route = str(item.get("facebookEmbedType") or "missing")
        item_type = str(item.get("itemType") or "unknown")
        playable = item.get("playableInPass50") is True
        parsed = urllib.parse.urlparse(source)
        safe_path = f"{parsed.hostname or ''}{parsed.path or '/'}"
        results = {}

        for plugin in ("post", "video"):
            params = urllib.parse.urlencode({
                "href": source,
                "show_text": "true" if plugin == "post" else "false",
                "width": "820",
            })
            plugin_request = urllib.request.Request(
                f"https://www.facebook.com/plugins/{plugin}.php?{params}",
                headers={
                    "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8",
                    "User-Agent": "Mozilla/5.0",
                },
            )
            with urllib.request.urlopen(plugin_request, timeout=40) as response:
                body = response.read().decode("utf-8", "replace")
                status = response.status
            unavailable = bool(re.search(
                r"ce contenu n.est pas disponible|contenu indisponible|this content isn.t available|content not available|publication indisponible",
                body,
                flags=re.IGNORECASE,
            ))
            results[plugin] = {
                "http": status,
                "bytes": len(body.encode("utf-8")),
                "unavailable": unavailable,
            }
            print(
                f"APOUTCHOU_PLUGIN plugin={plugin} http={status} "
                f"bytes={results[plugin]['bytes']} unavailable={str(unavailable).lower()}"
            )

        print(
            "APOUTCHOU_RESULT "
            f"itemType={item_type} playable={str(playable).lower()} route={route} "
            f"postUnavailable={str(results['post']['unavailable']).lower()} "
            f"videoUnavailable={str(results['video']['unavailable']).lower()} path={safe_path}"
        )


if __name__ == "__main__":
    unittest.main()
