/** URL du client mobile prod — même surface que la coque Capacitor (`shell/`). */
export const PASS50_NATIVE_APP_URL =
  'https://pass50.store/app.html?source=native';

export const PASS50_ALLOWED_HOSTS = new Set([
  'pass50.store',
  'www.pass50.store',
]);

export function isPass50Host(hostname: string): boolean {
  return PASS50_ALLOWED_HOSTS.has(hostname.toLowerCase());
}
