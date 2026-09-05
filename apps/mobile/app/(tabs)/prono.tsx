import { SiteWebView, SITE_URLS } from '@/components/SiteWebView';

/** Pronos — pronostics.html du site mobile. */
export default function PronoScreen() {
  return <SiteWebView url={SITE_URLS.prono} title="PRONOS" />;
}
