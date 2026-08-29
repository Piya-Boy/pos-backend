const APP = Object.freeze({
  NAME: 'Phius Order',
  SHORT_NAME: 'Phius Order',
  VERSION: '1.3.1',
  CACHE_VERSION: 'phius-order-shell-v12',
  THEME_COLOR: '#B7442B',
  BACKGROUND_COLOR: '#FBF7F0',
  ICON_192: 'https://placehold.co/192x192/B7442B/FFFFFF.png?text=Phius',
  ICON_512: 'https://placehold.co/512x512/B7442B/FFFFFF.png?text=Phius',
  AUTH_TTL_SECONDS: 21600,
  POLL_SECONDS: 8
});
const SHELL_BRAND_PROPERTY = 'SHELL_BRAND_JSON';

function doGet(e) {
  const params = (e && e.parameter) || {};
  if (params.resource === 'manifest') return serveManifest_();
  if (params.resource === 'sw') return serveServiceWorker_();

  const template = HtmlService.createTemplateFromFile('Index');
  const appUrl = getWebAppUrl_();
  const shellBrand = getShellBrand_();
  template.manifestUrl = appUrl ? appUrl + '?resource=manifest' : '';
  template.serviceWorkerUrl = appUrl ? appUrl + '?resource=sw&v=' + encodeURIComponent(APP.CACHE_VERSION) : '';
  template.shellAppName = shellBrand.appName;
  template.shellRestaurantName = shellBrand.restaurantName;
  template.shellLogoText = shellBrand.logoText;
  template.shellLogoUrl = shellBrand.logoUrl;
  template.shellPrimaryColor = shellBrand.primaryColor;
  template.shellBackgroundColor = shellBrand.backgroundColor;
  template.shellSurfaceColor = shellBrand.surfaceColor;
  template.shellTextColor = shellBrand.textColor;
  template.appConfigJson = JSON.stringify({
    name: shellBrand.appName,
    version: APP.VERSION,
    appUrl: appUrl,
    pollSeconds: APP.POLL_SECONDS,
    page: params.page || 'home',
    tableToken: params.table || ''
  }).replace(/</g, '\\u003c');

  return template.evaluate()
    .setTitle(shellBrand.appName)
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL)
    .addMetaTag('viewport', 'width=device-width, initial-scale=1, viewport-fit=cover')
    .addMetaTag('mobile-web-app-capable', 'yes')
    .addMetaTag('apple-mobile-web-app-capable', 'yes');
}

function getShellBrand_() {
  const props = PropertiesService.getScriptProperties();
  const cached = safeJson_(props.getProperty(SHELL_BRAND_PROPERTY), null);
  if (cached && cached.appName) return cached;
  let settings = {};
  try {
    if (isSystemReady_()) settings = getSettingsMap_();
  } catch (error) {
    console.warn('Shell brand settings unavailable', error);
  }
  return cacheShellBrandSettings_(settings);
}

function cacheShellBrandSettings_(settings) {
  const source = settings || {};
  const brand = {
    appName: String(source.AppName || APP.NAME).slice(0, 80),
    restaurantName: String(source.RestaurantName || 'Phius Thai Kitchen').slice(0, 120),
    logoText: String(source.BrandLogoText || 'ผ').slice(0, 8),
    logoUrl: /^https:\/\//i.test(String(source.BrandLogoURL || '')) ? String(source.BrandLogoURL) : '',
    primaryColor: shellColor_(source.PrimaryColor, APP.THEME_COLOR),
    backgroundColor: shellColor_(source.BackgroundColor, APP.BACKGROUND_COLOR),
    surfaceColor: shellColor_(source.SurfaceColor, '#FFFFFF'),
    textColor: shellColor_(source.TextColor, '#211E1B')
  };
  PropertiesService.getScriptProperties().setProperty(SHELL_BRAND_PROPERTY, JSON.stringify(brand));
  return brand;
}

function shellColor_(value, fallback) {
  return /^#[0-9A-F]{6}$/i.test(String(value || '')) ? String(value).toUpperCase() : fallback;
}

function include(filename) {
  return HtmlService.createHtmlOutputFromFile(filename).getContent();
}

function serveManifest_() {
  const appUrl = getWebAppUrl_() || './';
  let settings = {};
  try {
    if (isSystemReady_()) {
      migrateBrandSettings_();
      settings = getSettingsMap_();
    }
  } catch (error) {
    console.warn('Manifest settings unavailable', error);
  }
  const appName = settings.AppName || APP.NAME;
  const restaurantName = settings.RestaurantName || APP.NAME;
  const primaryColor = /^#[0-9A-F]{6}$/i.test(String(settings.PrimaryColor || '')) ? settings.PrimaryColor : APP.THEME_COLOR;
  const backgroundColor = /^#[0-9A-F]{6}$/i.test(String(settings.BackgroundColor || '')) ? settings.BackgroundColor : APP.BACKGROUND_COLOR;
  const logoUrl = settings.BrandLogoURL || '';
  const manifest = {
    name: appName + ' — ' + restaurantName,
    short_name: appName,
    description: settings.RestaurantTagline || 'ระบบสั่งอาหารผ่าน QR สำหรับร้านอาหาร',
    start_url: appUrl,
    scope: appUrl,
    display: 'standalone',
    background_color: backgroundColor,
    theme_color: primaryColor,
    lang: 'th',
    icons: logoUrl ? [
      { src: logoUrl, sizes: '192x192', purpose: 'any' },
      { src: logoUrl, sizes: '512x512', purpose: 'any maskable' }
    ] : [
      { src: APP.ICON_192, sizes: '192x192', type: 'image/png', purpose: 'any' },
      { src: APP.ICON_512, sizes: '512x512', type: 'image/png', purpose: 'any maskable' }
    ]
  };
  return ContentService.createTextOutput(JSON.stringify(manifest))
    .setMimeType(ContentService.MimeType.JSON);
}

function serveServiceWorker_() {
  const source = [
    "const CACHE='" + APP.CACHE_VERSION + "';",
    "self.addEventListener('install',event=>{self.skipWaiting();});",
    "self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim()));});",
    "self.addEventListener('fetch',event=>{if(event.request.method!=='GET'||event.request.mode!=='navigate')return;event.respondWith(fetch(event.request).then(response=>{const copy=response.clone();caches.open(CACHE).then(cache=>cache.put(event.request,copy));return response;}).catch(()=>caches.match(event.request)));});"
  ].join('\n');
  return ContentService.createTextOutput(source)
    .setMimeType(ContentService.MimeType.JAVASCRIPT);
}

function getWebAppUrl_() {
  try {
    return ScriptApp.getService().getUrl() || '';
  } catch (error) {
    return '';
  }
}

function ok_(data, message) {
  return JSON.stringify({ ok: true, data: data == null ? null : data, message: message || '' });
}

function fail_(code, message, details) {
  const error = new Error(message || 'เกิดข้อผิดพลาด');
  error.code = code || 'ERROR';
  error.details = details || null;
  throw error;
}

function api_(work) {
  try {
    return ok_(work());
  } catch (error) {
    console.error(error && error.stack ? error.stack : error);
    return JSON.stringify({
      ok: false,
      error: {
        code: error.code || 'INTERNAL_ERROR',
        message: error.code ? error.message : 'ระบบไม่สามารถทำรายการได้ กรุณาลองใหม่',
        details: error.code ? (error.details || null) : null
      }
    });
  }
}

function parsePayload_(payload) {
  if (payload == null || payload === '') return {};
  if (typeof payload === 'string') {
    try { return JSON.parse(payload); } catch (error) { fail_('INVALID_JSON', 'ข้อมูลที่ส่งมาไม่ถูกต้อง'); }
  }
  return payload;
}

function uuid_(prefix) {
  return (prefix || '') + Utilities.getUuid().replace(/-/g, '').slice(0, 20);
}

function nowIso_() {
  return Utilities.formatDate(new Date(), Session.getScriptTimeZone() || 'Asia/Bangkok', "yyyy-MM-dd'T'HH:mm:ssXXX");
}

function normalizeText_(value, maxLength) {
  const text = String(value == null ? '' : value).replace(/[<>]/g, '').trim();
  return maxLength ? text.slice(0, maxLength) : text;
}

function number_(value, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : (fallback == null ? 0 : fallback);
}

function money_(value) {
  return Math.round((number_(value, 0) + Number.EPSILON) * 100) / 100;
}

function bool_(value) {
  return value === true || String(value).toLowerCase() === 'true' || String(value) === '1';
}

function safeJson_(value, fallback) {
  try { return JSON.parse(value || ''); } catch (error) { return fallback; }
}

function sha256_(value) {
  const bytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, String(value), Utilities.Charset.UTF_8);
  return bytes.map(function(byte) {
    const normalized = byte < 0 ? byte + 256 : byte;
    return ('0' + normalized.toString(16)).slice(-2);
  }).join('');
}

function sanitizeHttpsUrl_(value) {
  const url = normalizeText_(value, 1000);
  if (!url) return '';
  if (!/^https:\/\//i.test(url)) fail_('INVALID_URL', 'ลิงก์รูปภาพต้องขึ้นต้นด้วย https://');
  return url;
}
