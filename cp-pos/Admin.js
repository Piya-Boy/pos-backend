const ADMIN_ENTITIES = Object.freeze({
  table: { sheet: 'Tables', key: 'TableID', prefix: 'T', fields: ['TableID', 'Name', 'Zone', 'Status'] },
  category: { sheet: 'Categories', key: 'CategoryID', prefix: 'cat_', fields: ['CategoryID', 'Name', 'Icon', 'SortOrder', 'Status'] },
  menu: { sheet: 'MenuItems', key: 'ItemID', prefix: 'menu_', fields: ['ItemID', 'CategoryID', 'Name', 'Price', 'Description', 'ImageURL', 'Status', 'SortOrder', 'IsPopular'] },
  option: { sheet: 'Options', key: 'OptionID', prefix: 'opt_', fields: ['OptionID', 'ItemID', 'GroupName', 'Label', 'Price', 'InputType', 'IsRequired', 'SortOrder', 'Status'] },
  addon: { sheet: 'AddOns', key: 'AddOnID', prefix: 'add_', fields: ['AddOnID', 'Name', 'Price', 'LinkedItemID', 'LinkedCategoryID', 'Status', 'SortOrder'] },
  promotion: { sheet: 'Promotions', key: 'PromoID', prefix: 'promo_', fields: ['PromoID', 'Code', 'Name', 'Description', 'DiscountType', 'DiscountValue', 'MinSpend', 'StartDate', 'EndDate', 'BannerImage', 'Status'] },
  staff: { sheet: 'Staff', key: 'StaffID', prefix: 'stf_', fields: ['StaffID', 'Name', 'Role', 'Status'] },
  setting: { sheet: 'Settings', key: 'Key', prefix: '', fields: ['Key', 'Value'] }
});
const BRAND_SETTING_KEYS = Object.freeze([
  'AppName', 'RestaurantName', 'RestaurantTagline', 'BrandLogoText', 'BrandLogoURL',
  'PrimaryColor', 'SuccessColor', 'BackgroundColor', 'SurfaceColor', 'TextColor',
  'HeroKicker', 'HeroTitle', 'HeroBadgeText',
  'HeroBadgeImageURL', 'CurrencySymbol', 'ServiceChargePercent', 'VatPercent', 'OrderPollingSeconds'
]);

function loginStaff(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const pin = normalizeText_(input.pin, 12);
    const expectedRole = normalizeText_(input.expectedRole, 20).toUpperCase();
    if (!/^[A-Za-z0-9]{4,12}$/.test(pin)) fail_('INVALID_PIN', 'PIN ต้องเป็นตัวอักษรภาษาอังกฤษหรือตัวเลข 4–12 ตัว');
    const hash = sha256_(getAuthSalt_() + ':' + pin);
    const matches = readSheetObjects_('Staff').filter(function(row) {
      return String(row.PINHash) === hash && String(row.Status) === 'ACTIVE';
    });
    const staff = matches.find(function(row) {
      return !expectedRole || String(row.Role) === expectedRole;
    }) || matches.find(function(row) {
      return expectedRole && String(row.Role) === 'ADMIN';
    });
    if (!staff) fail_('LOGIN_FAILED', 'PIN หรือบทบาทไม่ถูกต้อง');
    const token = uuid_('auth_') + uuid_();
    const session = { staffId: staff.StaffID, name: staff.Name, role: staff.Role, issuedAt: nowIso_(), mustChangePin: bool_(staff.MustChangePin) };
    CacheService.getScriptCache().put('auth:' + token, JSON.stringify(session), APP.AUTH_TTL_SECONDS);
    updateObject_('Staff', 'StaffID', staff.StaffID, { LastLogin: nowIso_(), UpdatedAt: nowIso_() });
    audit_(staff.StaffID, 'LOGIN', 'Staff', staff.StaffID, { role: staff.Role });
    return { token: token, user: session };
  });
}

function logoutStaff(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const token = normalizeText_(input.token, 120);
    if (token) CacheService.getScriptCache().remove('auth:' + token);
    return { loggedOut: true };
  });
}

function changeMyPin(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token);
    const newPin = normalizeText_(input.newPin, 12);
    if (!/^[A-Za-z0-9]{6,12}$/.test(newPin)) fail_('INVALID_PIN', 'PIN ใหม่ต้องเป็นตัวอักษรภาษาอังกฤษหรือตัวเลข 6–12 ตัว');
    if (newPin === INITIAL_STAFF_PIN) fail_('PIN_REUSE', 'กรุณาตั้ง PIN ใหม่ที่ไม่ใช่รหัสเริ่มต้น');
    updateObject_('Staff', 'StaffID', auth.staffId, {
      PINHash: sha256_(getAuthSalt_() + ':' + newPin), MustChangePin: false, UpdatedAt: nowIso_()
    });
    audit_(auth.staffId, 'CHANGE_PIN', 'Staff', auth.staffId, {});
    return { changed: true };
  });
}

function requireAuth_(token, roles) {
  const normalized = normalizeText_(token, 120);
  if (!normalized) fail_('AUTH_REQUIRED', 'กรุณาเข้าสู่ระบบ');
  const raw = CacheService.getScriptCache().get('auth:' + normalized);
  if (!raw) fail_('AUTH_EXPIRED', 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่');
  const session = safeJson_(raw, null);
  if (!session) fail_('AUTH_EXPIRED', 'เซสชันไม่ถูกต้อง');
  const staff = findObject_('Staff', 'StaffID', session.staffId);
  if (!staff || String(staff.Status) !== 'ACTIVE') fail_('PERMISSION_DENIED', 'บัญชีนี้ไม่พร้อมใช้งาน');
  session.role = String(staff.Role);
  session.name = String(staff.Name);
  session.mustChangePin = bool_(staff.MustChangePin);
  if (roles && roles.length && roles.indexOf(session.role) === -1 && session.role !== 'ADMIN') {
    fail_('PERMISSION_DENIED', 'คุณไม่มีสิทธิ์ทำรายการนี้');
  }
  CacheService.getScriptCache().put('auth:' + normalized, JSON.stringify(session), APP.AUTH_TTL_SECONDS);
  return session;
}

function getOpsDashboard(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const view = normalizeText_(input.view, 20).toUpperCase();
    const roles = view === 'KITCHEN' ? ['KITCHEN'] : view === 'STAFF' ? ['STAFF'] : view === 'CASHIER' ? ['CASHIER'] : ['ADMIN'];
    const auth = requireAuth_(input.token, roles);
    const tables = readSheetObjects_('Tables');
    const sessions = readSheetObjects_('OrderSessions');
    const items = readSheetObjects_('OrderItems');
    const calls = readSheetObjects_('CallLogs');
    const tableMap = indexBy_(tables, 'TableID');
    const sessionMap = indexBy_(sessions, 'SessionID');

    const activeItems = items.filter(function(item) {
      const session = sessionMap[String(item.SessionID)];
      return session && ['OPEN', 'PAYMENT_PENDING'].indexOf(String(session.Status)) !== -1 && String(item.Status) !== 'CANCELLED';
    }).map(function(item) {
      const clean = publicRow_(item);
      const session = sessionMap[String(item.SessionID)];
      clean.table = publicRow_(tableMap[String(session.TableID)]);
      clean.TableID = session.TableID;
      clean.options = safeJson_(item.OptionsJSON, []);
      clean.addOns = safeJson_(item.AddOnsJSON, []);
      delete clean.OptionsJSON;
      delete clean.AddOnsJSON;
      return clean;
    });
    const itemsBySession = activeItems.reduce(function(map, item) {
      const key = String(item.SessionID);
      if (!map[key]) map[key] = [];
      map[key].push(item);
      return map;
    }, {});

    const activeSessions = sessions.filter(function(session) {
      return ['OPEN', 'PAYMENT_PENDING'].indexOf(String(session.Status)) !== -1;
    }).map(function(session) {
      const clean = publicRow_(session);
      clean.table = publicRow_(tableMap[String(session.TableID)]);
      clean.items = itemsBySession[String(session.SessionID)] || [];
      return clean;
    });

    const activeCalls = calls.filter(function(call) {
      return ['OPEN', 'ASSIGNED'].indexOf(String(call.Status)) !== -1;
    }).map(function(call) {
      const clean = publicRow_(call);
      clean.table = publicRow_(tableMap[String(call.TableID)]);
      return clean;
    });
    return {
      user: auth,
      view: view,
      items: view === 'CASHIER' ? [] : activeItems,
      sessions: ['CASHIER', 'ALL'].indexOf(view) !== -1 ? activeSessions : [],
      calls: ['STAFF', 'ALL'].indexOf(view) !== -1 ? activeCalls : [],
      summary: {
        openTables: activeSessions.length,
        newOrders: activeItems.filter(function(item) { return String(item.Status) === 'NEW'; }).length,
        preparing: activeItems.filter(function(item) { return String(item.Status) === 'PREPARING'; }).length,
        ready: activeItems.filter(function(item) { return String(item.Status) === 'READY'; }).length,
        waitingCalls: calls.filter(function(call) { return String(call.Status) === 'OPEN'; }).length
      }
    };
  });
}

function updateOrderItemStatus(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const target = normalizeText_(input.status, 20).toUpperCase();
    const auth = requireAuth_(input.token, ['KITCHEN', 'STAFF']);
    const item = findObject_('OrderItems', 'OrderItemID', normalizeText_(input.orderItemId, 100));
    if (!item) fail_('ITEM_NOT_FOUND', 'ไม่พบรายการอาหาร');
    const roleTargets = {
      KITCHEN: ['PREPARING', 'READY'],
      STAFF: ['SERVED'],
      ADMIN: ['NEW', 'PREPARING', 'READY', 'SERVED', 'CANCELLED']
    };
    if ((roleTargets[auth.role] || []).indexOf(target) === -1) fail_('INVALID_STATUS', 'ไม่สามารถเปลี่ยนเป็นสถานะนี้ได้');
    const updated = updateObject_('OrderItems', 'OrderItemID', item.OrderItemID, {
      Status: target, KitchenNote: normalizeText_(input.kitchenNote || item.KitchenNote, 200), UpdatedAt: nowIso_()
    });
    audit_(auth.staffId, 'UPDATE_ORDER_STATUS', 'OrderItem', item.OrderItemID, { from: item.Status, to: target });
    return publicRow_(updated);
  });
}

function updateCallStatus(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token, ['STAFF', 'CASHIER']);
    const call = findObject_('CallLogs', 'LogID', normalizeText_(input.logId, 100));
    if (!call) fail_('CALL_NOT_FOUND', 'ไม่พบงานเรียกนี้');
    const status = normalizeText_(input.status, 20).toUpperCase();
    if (['ASSIGNED', 'DONE'].indexOf(status) === -1) fail_('INVALID_STATUS', 'สถานะงานไม่ถูกต้อง');
    const patch = { Status: status, AssignedStaffID: auth.staffId };
    if (status === 'ASSIGNED') patch.AcceptedAt = nowIso_();
    if (status === 'DONE') patch.CompletedAt = nowIso_();
    const updated = updateObject_('CallLogs', 'LogID', call.LogID, patch);
    audit_(auth.staffId, 'UPDATE_CALL', 'CallLog', call.LogID, { status: status });
    return publicRow_(updated);
  });
}

function closeTable(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token, ['CASHIER']);
    const idempotencyKey = normalizeText_(input.idempotencyKey, 120);
    if (!idempotencyKey) fail_('IDEMPOTENCY_REQUIRED', 'กรุณาลองรับชำระใหม่');
    const lock = LockService.getScriptLock();
    if (!lock.tryLock(15000)) fail_('BUSY', 'ระบบกำลังปิดบิลอื่น กรุณาลองอีกครั้ง');
    try {
      const cached = beginTransaction_('PAYMENT', idempotencyKey);
      if (cached) return cached;
      try {
        const session = findObject_('OrderSessions', 'SessionID', normalizeText_(input.sessionId, 100));
        if (!session || ['OPEN', 'PAYMENT_PENDING'].indexOf(String(session.Status)) === -1) fail_('SESSION_CLOSED', 'รอบโต๊ะนี้ถูกปิดแล้ว');
        const table = findObject_('Tables', 'TableID', session.TableID);
        const totals = recalculateSessionTotals_(session.SessionID, session.PromoCode);
        const method = normalizeText_(input.method, 30).toUpperCase();
        if (['CASH', 'TRANSFER', 'CARD', 'OTHER'].indexOf(method) === -1) fail_('PAYMENT_METHOD_REQUIRED', 'กรุณาเลือกวิธีชำระเงิน');
        let payment = findObject_('Payments', 'IdempotencyKey', idempotencyKey);
        if (!payment) {
          payment = {
            PaymentID: uuid_('pay_'), SessionID: session.SessionID, IdempotencyKey: idempotencyKey, Amount: totals.total, Method: method,
            Reference: normalizeText_(input.reference, 100), PaidAt: nowIso_(), StaffID: auth.staffId
          };
          appendObjects_('Payments', [payment]);
        }
        updateObject_('OrderSessions', 'SessionID', session.SessionID, {
          CloseTime: payment.PaidAt, Status: 'PAID', PaymentMethod: method, UpdatedAt: payment.PaidAt
        });
        if (table) updateObject_('Tables', 'TableID', table.TableID, { Status: 'AVAILABLE', CurrentSessionID: '', UpdatedAt: payment.PaidAt });
        readSheetObjects_('CallLogs').filter(function(call) {
          return String(call.SessionID) === String(session.SessionID) && ['OPEN', 'ASSIGNED'].indexOf(String(call.Status)) !== -1;
        }).forEach(function(call) {
          updateObject_('CallLogs', 'LogID', call.LogID, { Status: 'DONE', AssignedStaffID: auth.staffId, CompletedAt: payment.PaidAt });
        });
        const result = {
          payment: publicRow_(payment),
          receipt: buildReceipt_(session.SessionID, payment),
          tableReset: Boolean(table)
        };
        completeTransaction_(idempotencyKey, payment.PaymentID, result);
        audit_(auth.staffId, 'CLOSE_TABLE', 'OrderSession', session.SessionID, { amount: totals.total, method: method });
        return result;
      } catch (error) {
        failTransaction_(idempotencyKey);
        throw error;
      }
    } finally {
      lock.releaseLock();
    }
  });
}

function buildReceipt_(sessionId, paymentOverride) {
  const bundle = getSessionBundle_(sessionId);
  if (!bundle) fail_('SESSION_NOT_FOUND', 'ไม่พบข้อมูลใบเสร็จ');
  const table = findObject_('Tables', 'TableID', bundle.session.TableID);
  const settings = getSettingsMap_();
  const payment = paymentOverride || readSheetObjects_('Payments').find(function(row) {
    return String(row.SessionID) === String(sessionId);
  }) || null;
  return {
    restaurantName: settings.RestaurantName || APP.NAME,
    table: table ? table.Name : bundle.session.TableID,
    session: bundle.session,
    items: bundle.items,
    payment: payment ? publicRow_(payment) : null,
    generatedAt: nowIso_()
  };
}

function adminGetData(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token, ['ADMIN']);
    const appUrl = getWebAppUrl_();
    const data = { user: auth, settings: getSettingsMap_() };
    Object.keys(ADMIN_ENTITIES).forEach(function(entityName) {
      const config = ADMIN_ENTITIES[entityName];
      data[config.sheet] = readSheetObjects_(config.sheet).map(publicRow_);
    });
    data.Tables = data.Tables.map(function(table) {
      table.orderUrl = appUrl ? appUrl + '?page=order&table=' + encodeURIComponent(table.Token) : '';
      return table;
    });
    const sessions = readSheetObjects_('OrderSessions');
    data.summary = {
      tables: data.Tables.length,
      menuItems: data.MenuItems.filter(function(row) { return String(row.Status) !== 'ARCHIVED'; }).length,
      activeSessions: sessions.filter(function(row) { return ['OPEN', 'PAYMENT_PENDING'].indexOf(String(row.Status)) !== -1; }).length,
      todaySales: money_(readSheetObjects_('Payments').filter(function(row) { return String(row.PaidAt).slice(0, 10) === nowIso_().slice(0, 10); }).reduce(function(sum, row) { return sum + number_(row.Amount); }, 0))
    };
    return data;
  });
}

function adminSaveSettings(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token, ['ADMIN']);
    const source = input.settings || {};
    const settings = {};
    BRAND_SETTING_KEYS.forEach(function(key) {
      if (Object.prototype.hasOwnProperty.call(source, key)) settings[key] = source[key];
    });

    settings.AppName = normalizeText_(settings.AppName, 80);
    settings.RestaurantName = normalizeText_(settings.RestaurantName, 120);
    settings.RestaurantTagline = normalizeText_(settings.RestaurantTagline, 160);
    settings.BrandLogoText = normalizeText_(settings.BrandLogoText, 8);
    settings.HeroKicker = normalizeText_(settings.HeroKicker, 120);
    settings.HeroTitle = normalizeText_(settings.HeroTitle, 180);
    settings.HeroBadgeText = normalizeText_(settings.HeroBadgeText, 20);
    if (!settings.AppName) fail_('APP_NAME_REQUIRED', 'กรุณาระบุชื่อระบบ');
    if (!settings.RestaurantName) fail_('RESTAURANT_NAME_REQUIRED', 'กรุณาระบุชื่อร้าน');
    if (!settings.HeroTitle) fail_('HERO_TITLE_REQUIRED', 'กรุณาระบุข้อความหลักหน้าเมนู');

    settings.BrandLogoURL = settings.BrandLogoURL ? sanitizeHttpsUrl_(settings.BrandLogoURL) : '';
    settings.HeroBadgeImageURL = settings.HeroBadgeImageURL ? sanitizeHttpsUrl_(settings.HeroBadgeImageURL) : '';
    settings.PrimaryColor = validateHexColor_(settings.PrimaryColor, APP.THEME_COLOR);
    settings.SuccessColor = validateHexColor_(settings.SuccessColor, '#2F6B4F');
    settings.BackgroundColor = validateHexColor_(settings.BackgroundColor, APP.BACKGROUND_COLOR);
    settings.SurfaceColor = validateHexColor_(settings.SurfaceColor, '#FFFFFF');
    settings.TextColor = validateHexColor_(settings.TextColor, '#211E1B');
    settings.CurrencySymbol = normalizeText_(settings.CurrencySymbol, 4) || '฿';
    settings.ServiceChargePercent = validatePercentSetting_(settings.ServiceChargePercent, 'Service charge');
    settings.VatPercent = validatePercentSetting_(settings.VatPercent, 'VAT');
    const pollingSeconds = Math.floor(number_(settings.OrderPollingSeconds, APP.POLL_SECONDS));
    if (pollingSeconds < 5 || pollingSeconds > 60) fail_('INVALID_POLLING', 'ความถี่อัปเดตต้องอยู่ระหว่าง 5–60 วินาที');
    settings.OrderPollingSeconds = String(pollingSeconds);

    const lock = LockService.getScriptLock();
    if (!lock.tryLock(10000)) fail_('BUSY', 'ระบบกำลังบันทึกการตั้งค่าอื่น กรุณาลองอีกครั้ง');
    try {
      const saved = saveSettingsMap_(settings);
      cacheShellBrandSettings_(saved);
      audit_(auth.staffId, 'SAVE_BRAND_SETTINGS', 'Settings', 'BRAND', {
        keys: Object.keys(settings),
        hasLogo: Boolean(settings.BrandLogoURL),
        hasHeroImage: Boolean(settings.HeroBadgeImageURL)
      });
      return { settings: saved };
    } finally {
      lock.releaseLock();
    }
  });
}

function validateHexColor_(value, fallback) {
  const color = normalizeText_(value || fallback, 7).toUpperCase();
  if (!/^#[0-9A-F]{6}$/.test(color)) fail_('INVALID_COLOR', 'รหัสสีต้องอยู่ในรูปแบบ #RRGGBB');
  return color;
}

function validatePercentSetting_(value, label) {
  const amount = number_(value, 0);
  if (amount < 0 || amount > 100) fail_('INVALID_PERCENT', label + ' ต้องอยู่ระหว่าง 0–100');
  return String(amount);
}

function adminSaveEntity(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token, ['ADMIN']);
    const entityName = normalizeText_(input.entity, 30).toLowerCase();
    const config = ADMIN_ENTITIES[entityName];
    if (!config) fail_('INVALID_ENTITY', 'ประเภทข้อมูลไม่ถูกต้อง');
    const source = input.data || {};
    const object = {};
    config.fields.forEach(function(field) {
      if (Object.prototype.hasOwnProperty.call(source, field)) object[field] = source[field];
    });
    if (!object[config.key]) object[config.key] = config.prefix ? uuid_(config.prefix) : '';
    if (!object[config.key]) fail_('KEY_REQUIRED', 'กรุณาระบุรหัสข้อมูล');
    const existingRecord = findObject_(config.sheet, config.key, object[config.key]);
    if (entityName === 'table' && existingRecord && existingRecord.CurrentSessionID && object.Status && String(object.Status) !== String(existingRecord.Status)) {
      fail_('TABLE_IN_USE', 'ไม่สามารถเปลี่ยนสถานะโต๊ะขณะมีรอบการสั่งอาหาร');
    }
    validateAdminEntity_(entityName, object);
    const now = nowIso_();
    if (SHEET_SCHEMAS[config.sheet].indexOf('UpdatedAt') !== -1) object.UpdatedAt = now;
    if (!existingRecord && SHEET_SCHEMAS[config.sheet].indexOf('CreatedAt') !== -1) object.CreatedAt = now;
    if (entityName === 'table' && !existingRecord) {
      object.Token = uuid_('tbl_'); object.CurrentSessionID = '';
    }
    if (entityName === 'staff' && source.PIN) {
      const pin = normalizeText_(source.PIN, 12);
      if (!/^[A-Za-z0-9]{6,12}$/.test(pin)) fail_('INVALID_PIN', 'PIN ต้องเป็นตัวอักษรภาษาอังกฤษหรือตัวเลข 6–12 ตัว');
      object.PINHash = sha256_(getAuthSalt_() + ':' + pin);
      object.MustChangePin = true;
    }
    if (entityName === 'staff' && !existingRecord && !source.PIN) {
      fail_('PIN_REQUIRED', 'พนักงานใหม่ต้องมี PIN 6–12 ตัว');
    }
    const saved = upsertObject_(config.sheet, config.key, object);
    if (['category', 'menu', 'option', 'addon', 'promotion'].indexOf(entityName) !== -1) clearPublicCatalogCache_();
    audit_(auth.staffId, 'SAVE_' + entityName.toUpperCase(), config.sheet, object[config.key], object);
    return publicRow_(saved);
  });
}

function adminArchiveEntity(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token, ['ADMIN']);
    const entityName = normalizeText_(input.entity, 30).toLowerCase();
    const config = ADMIN_ENTITIES[entityName];
    if (!config || entityName === 'setting') fail_('INVALID_ENTITY', 'ไม่สามารถปิดข้อมูลประเภทนี้');
    const key = normalizeText_(input.id, 100);
    const existing = findObject_(config.sheet, config.key, key);
    if (!existing) fail_('NOT_FOUND', 'ไม่พบข้อมูล');
    if (entityName === 'staff' && String(existing.StaffID) === String(auth.staffId)) fail_('SELF_ARCHIVE_DENIED', 'ไม่สามารถปิดบัญชีที่กำลังใช้งาน');
    if (entityName === 'category') {
      const categoryInUse = readSheetObjects_('MenuItems').some(function(row) {
        return String(row.CategoryID) === key && String(row.Status) !== 'ARCHIVED';
      });
      if (categoryInUse) fail_('CATEGORY_IN_USE', 'ย้ายหรือลบเมนูในหมวดนี้ก่อน แล้วจึงลบหมวดหมู่');
      const addOnInUse = readSheetObjects_('AddOns').some(function(row) {
        return String(row.LinkedCategoryID) === key && String(row.Status) !== 'ARCHIVED';
      });
      if (addOnInUse) fail_('CATEGORY_ADDON_IN_USE', 'ย้ายหรือลบ Add-on ที่ผูกกับหมวดนี้ก่อน แล้วจึงลบหมวดหมู่');
    }
    const updated = updateObject_(config.sheet, config.key, key, { Status: 'ARCHIVED', UpdatedAt: nowIso_() });
    if (['category', 'menu', 'option', 'addon', 'promotion'].indexOf(entityName) !== -1) clearPublicCatalogCache_();
    audit_(auth.staffId, 'ARCHIVE_' + entityName.toUpperCase(), config.sheet, key, {});
    return publicRow_(updated);
  });
}

function rotateTableToken(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token, ['ADMIN']);
    const tableId = normalizeText_(input.tableId, 100);
    const table = findObject_('Tables', 'TableID', tableId);
    if (!table) fail_('TABLE_NOT_FOUND', 'ไม่พบโต๊ะนี้');
    if (table.CurrentSessionID) fail_('TABLE_IN_USE', 'ไม่สามารถเปลี่ยน QR ขณะโต๊ะกำลังใช้งาน');
    const updated = updateObject_('Tables', 'TableID', tableId, { Token: uuid_('tbl_'), UpdatedAt: nowIso_() });
    audit_(auth.staffId, 'ROTATE_TABLE_TOKEN', 'Table', tableId, {});
    return publicRow_(updated);
  });
}

function validateAdminEntity_(entityName, object) {
  const allowedStatuses = ['ACTIVE', 'INACTIVE', 'SOLD_OUT', 'AVAILABLE', 'DISABLED', 'OCCUPIED', 'PAYMENT_PENDING'];
  if (object.Status && allowedStatuses.indexOf(String(object.Status).toUpperCase()) === -1) fail_('INVALID_STATUS', 'สถานะข้อมูลไม่ถูกต้อง');
  if (object.Status) object.Status = String(object.Status).toUpperCase();
  if (entityName === 'menu') {
    if (!normalizeText_(object.Name, 120)) fail_('NAME_REQUIRED', 'กรุณาระบุชื่อเมนู');
    object.Name = normalizeText_(object.Name, 120);
    object.Description = normalizeText_(object.Description, 500);
    object.Price = money_(object.Price);
    if (object.Price < 0) fail_('INVALID_PRICE', 'ราคาต้องไม่ติดลบ');
    object.ImageURL = object.ImageURL ? sanitizeHttpsUrl_(object.ImageURL) : '';
    object.IsPopular = bool_(object.IsPopular);
  }
  if (entityName === 'category' || entityName === 'table' || entityName === 'staff') {
    object.Name = normalizeText_(object.Name, 120);
    if (!object.Name) fail_('NAME_REQUIRED', 'กรุณาระบุชื่อ');
  }
  if (entityName === 'promotion') {
    object.Code = normalizeText_(object.Code, 40).toUpperCase();
    object.Name = normalizeText_(object.Name, 120);
    object.Description = normalizeText_(object.Description, 300);
    object.DiscountValue = money_(object.DiscountValue);
    object.MinSpend = money_(object.MinSpend);
    object.BannerImage = object.BannerImage ? sanitizeHttpsUrl_(object.BannerImage) : '';
    object.DiscountType = String(object.DiscountType || '').toUpperCase();
    if (['PERCENT', 'FIXED'].indexOf(object.DiscountType) === -1) fail_('INVALID_DISCOUNT', 'ประเภทส่วนลดไม่ถูกต้อง');
  }
  if (entityName === 'option' || entityName === 'addon') {
    object.Price = money_(object.Price);
  }
  if (entityName === 'addon') {
    object.Name = normalizeText_(object.Name, 120);
    if (!object.Name) fail_('NAME_REQUIRED', 'กรุณาระบุชื่อส่วนเสริม');
    object.LinkedItemID = normalizeText_(object.LinkedItemID, 100);
    object.LinkedCategoryID = normalizeText_(object.LinkedCategoryID, 100);
    if (object.LinkedItemID === 'ALL' || object.LinkedCategoryID === 'ALL') {
      object.LinkedItemID = 'ALL';
      object.LinkedCategoryID = '';
    } else if (object.LinkedItemID) {
      object.LinkedCategoryID = '';
    } else if (object.LinkedCategoryID) {
      object.LinkedItemID = '';
    }
    if (!object.LinkedItemID && !object.LinkedCategoryID) object.LinkedItemID = 'ALL';
    if (object.LinkedItemID && object.LinkedItemID !== 'ALL' && !findObject_('MenuItems', 'ItemID', object.LinkedItemID)) {
      fail_('INVALID_ADDON_SCOPE', 'ไม่พบเมนูที่เลือกสำหรับส่วนเสริม');
    }
    if (object.LinkedCategoryID && object.LinkedCategoryID !== 'ALL' && !findObject_('Categories', 'CategoryID', object.LinkedCategoryID)) {
      fail_('INVALID_ADDON_SCOPE', 'ไม่พบหมวดอาหารที่เลือกสำหรับส่วนเสริม');
    }
  }
  if (entityName === 'setting') object.Value = normalizeText_(object.Value, 2000);
  if (entityName === 'staff') {
    object.Role = String(object.Role || '').toUpperCase();
    if (['ADMIN', 'KITCHEN', 'STAFF', 'CASHIER'].indexOf(object.Role) === -1) fail_('INVALID_ROLE', 'บทบาทพนักงานไม่ถูกต้อง');
  }
}
