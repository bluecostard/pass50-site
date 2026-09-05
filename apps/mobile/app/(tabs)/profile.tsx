import { SiteWebView, SITE_URLS } from '@/components/SiteWebView';

/** Mon espace — compte site (?open=account). */
export default function ProfileScreen() {
  return <SiteWebView url={SITE_URLS.account} title="MON ESPACE" />;
}
