import { SiteWebView, SITE_URLS } from '@/components/SiteWebView';

/** Mon fil — mon-fil.html du site mobile. */
export default function FeedScreen() {
  return <SiteWebView url={SITE_URLS.feed} title="MON FIL" />;
}
