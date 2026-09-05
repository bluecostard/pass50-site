import { SiteWebView, SITE_URLS } from '@/components/SiteWebView';

/** Classement — page d’accueil mobile Safari (pass50.store/). */
export default function RankingScreen() {
  return <SiteWebView url={SITE_URLS.ranking} title="CLASSEMENT" />;
}
