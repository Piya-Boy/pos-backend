function getPublicBootstrap(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    if (!isSystemReady_()) return { setupRequired: true, app: { name: APP.NAME, version: APP.VERSION } };
    migrateInitialStaffPins_();
    migrateAddOnScopes_();
    migrateBrandSettings_();
    const settings = getSettingsMap_();
    const response = {
      setupRequired: false,
      app: {
        appName: settings.AppName || APP.NAME,
        name: settings.RestaurantName || APP.NAME,
        restaurantName: settings.RestaurantName || APP.NAME,
        tagline: settings.RestaurantTagline || '',
        logoText: settings.BrandLogoText || 'ผ',
        logoUrl: settings.BrandLogoURL || '',
        primaryColor: settings.PrimaryColor || APP.THEME_COLOR,
        successColor: settings.SuccessColor || '#2F6B4F',
        backgroundColor: settings.BackgroundColor || APP.BACKGROUND_COLOR,
        surfaceColor: settings.SurfaceColor || '#FFFFFF',
        textColor: settings.TextColor || '#211E1B',
        heroKicker: settings.HeroKicker || 'อิ่มอร่อยในแบบของคุณ',
        heroTitle: settings.HeroTitle || 'เลือกเมนูโปรด\nแล้วส่งตรงถึงครัว',
        heroBadgeText: settings.HeroBadgeText || 'อร่อย',
        heroBadgeImageUrl: settings.HeroBadgeImageURL || '',
        currency: settings.Currency || 'THB',
        currencySymbol: settings.CurrencySymbol || '฿',
        pollSeconds: number_(settings.OrderPollingSeconds, APP.POLL_SECONDS),
        version: APP.VERSION
      }
    };
    if (input.tableToken) response.customer = getCustomerData_(input.tableToken);
    return response;
  });
}

function isSystemReady_() {
  try {
    const id = PropertiesService.getScriptProperties().getProperty('DATABASE_SPREADSHEET_ID');
    if (!id) return false;
    return Boolean(SpreadsheetApp.openById(id).getSheetByName('Settings'));
  } catch (error) {
    return false;
  }
}

function getCustomerData(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    return getCustomerData_(normalizeText_(input.tableToken, 100));
  });
}

function getCustomerData_(tableToken) {
  if (!tableToken) fail_('TABLE_REQUIRED', 'ไม่พบข้อมูลโต๊ะ กรุณาสแกน QR ใหม่');
  const table = findObject_('Tables', 'Token', tableToken);
  if (!table || String(table.Status) === 'DISABLED') fail_('TABLE_NOT_FOUND', 'QR โต๊ะนี้ไม่พร้อมใช้งาน');

  const catalog = getPublicCatalog_();
  const bundle = table.CurrentSessionID ? getSessionBundle_(table.CurrentSessionID) : null;
  return {
    table: { TableID: table.TableID, Name: table.Name, Zone: table.Zone, Status: table.Status },
    categories: catalog.categories,
    menu: catalog.menu,
    promotions: catalog.promotions,
    session: bundle
  };
}

function getPublicCatalog_() {
  const cache = CacheService.getScriptCache();
  const cached = safeJson_(cache.get('public-catalog-v2'), null);
  if (cached && Array.isArray(cached.menu)) return cached;

  const categories = readSheetObjects_('Categories')
    .filter(function(row) { return String(row.Status) === 'ACTIVE'; })
    .sort(function(a, b) { return number_(a.SortOrder) - number_(b.SortOrder); });
  const options = readSheetObjects_('Options').filter(function(row) { return String(row.Status) === 'ACTIVE'; });
  const addOns = readSheetObjects_('AddOns').filter(function(row) { return String(row.Status) === 'ACTIVE'; });
  const optionsByItem = options.reduce(function(map, option) {
    const key = String(option.ItemID);
    if (!map[key]) map[key] = [];
    map[key].push(option);
    return map;
  }, {});
  const globalAddOns = [];
  const addOnsByItem = {};
  const addOnsByCategory = {};
  addOns.forEach(function(addOn) {
    const itemKey = String(addOn.LinkedItemID || '');
    const categoryKey = String(addOn.LinkedCategoryID || '');
    if ((!itemKey && !categoryKey) || itemKey === 'ALL' || categoryKey === 'ALL') {
      globalAddOns.push(addOn);
      return;
    }
    if (itemKey) {
      if (!addOnsByItem[itemKey]) addOnsByItem[itemKey] = [];
      addOnsByItem[itemKey].push(addOn);
    }
    if (categoryKey) {
      if (!addOnsByCategory[categoryKey]) addOnsByCategory[categoryKey] = [];
      addOnsByCategory[categoryKey].push(addOn);
    }
  });
  const menu = readSheetObjects_('MenuItems')
    .filter(function(row) { return String(row.Status) !== 'ARCHIVED'; })
    .sort(function(a, b) { return number_(a.SortOrder) - number_(b.SortOrder); })
    .map(function(item) {
      const clean = publicRow_(item);
      clean.available = String(item.Status) === 'ACTIVE';
      clean.options = (optionsByItem[String(item.ItemID)] || []).map(publicRow_);
      const scopedAddOns = globalAddOns
        .concat(addOnsByItem[String(item.ItemID)] || [])
        .concat(addOnsByCategory[String(item.CategoryID)] || []);
      const seenAddOns = {};
      clean.addOns = scopedAddOns.filter(function(addOn) {
        const key = String(addOn.AddOnID);
        if (seenAddOns[key]) return false;
        seenAddOns[key] = true;
        return true;
      }).map(publicRow_);
      return clean;
    });
  const catalog = {
    categories: categories.map(publicRow_),
    menu: menu,
    promotions: getActivePromotions_().map(publicRow_)
  };
  const serialized = JSON.stringify(catalog);
  if (serialized.length < 70000) cache.put('public-catalog-v2', serialized, 120);
  return catalog;
}

function clearPublicCatalogCache_() {
  CacheService.getScriptCache().remove('public-catalog-v2');
}

function addOnAppliesToItem_(addOn, item) {
  const linkedItem = String(addOn.LinkedItemID || '');
  const linkedCategory = String(addOn.LinkedCategoryID || '');
  if (!linkedItem && !linkedCategory) return true;
  if (linkedItem === 'ALL' || linkedCategory === 'ALL') return true;
  return linkedItem === String(item.ItemID) || linkedCategory === String(item.CategoryID);
}

function getOrderStatus(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const table = findObject_('Tables', 'Token', normalizeText_(input.tableToken, 100));
    const session = findObject_('OrderSessions', 'SessionID', normalizeText_(input.sessionId, 100));
    if (!table || !session || String(session.TableID) !== String(table.TableID)) {
      fail_('SESSION_NOT_FOUND', 'ไม่พบรอบการสั่งอาหารนี้');
    }
    return getSessionBundle_(session.SessionID);
  });
}

function submitOrder(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const idempotencyKey = normalizeText_(input.idempotencyKey, 120);
    if (!idempotencyKey) fail_('IDEMPOTENCY_REQUIRED', 'กรุณาลองส่งออเดอร์ใหม่');
    if (!Array.isArray(input.items) || !input.items.length) fail_('EMPTY_CART', 'ยังไม่มีรายการในตะกร้า');
    if (input.items.length > 50) fail_('TOO_MANY_ITEMS', 'รายการในตะกร้ามากเกินไป กรุณาแบ่งการสั่ง');

    const lock = LockService.getScriptLock();
    if (!lock.tryLock(15000)) fail_('BUSY', 'ระบบกำลังรับออเดอร์อื่น กรุณาลองอีกครั้ง');
    try {
      const cached = beginTransaction_('ORDER', idempotencyKey);
      if (cached) return cached;
      try {
        const result = createOrder_(input, idempotencyKey);
        completeTransaction_(idempotencyKey, result.SessionID, result);
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

function createOrder_(input, idempotencyKey) {
  const tableToken = normalizeText_(input.tableToken, 100);
  const table = findObject_('Tables', 'Token', tableToken);
  if (!table || ['DISABLED', 'PAYMENT_PENDING'].indexOf(String(table.Status)) !== -1) {
    fail_('TABLE_UNAVAILABLE', 'โต๊ะนี้ยังไม่พร้อมรับออเดอร์');
  }

  const recoveredItems = readSheetObjects_('OrderItems').filter(function(item) {
    return String(item.RequestKey) === String(idempotencyKey);
  });
  if (recoveredItems.length) {
    const recoveredSessionId = recoveredItems[0].SessionID;
    const recoveredSession = findObject_('OrderSessions', 'SessionID', recoveredSessionId);
    const recoveredTotals = recalculateSessionTotals_(recoveredSessionId, recoveredSession ? recoveredSession.PromoCode : input.promoCode);
    return {
      SessionID: recoveredSessionId,
      table: { TableID: table.TableID, Name: table.Name },
      totals: recoveredTotals,
      items: recoveredItems.map(publicRow_),
      submittedAt: recoveredItems[0].CreatedAt
    };
  }

  const menuRows = readSheetObjects_('MenuItems');
  const optionRows = readSheetObjects_('Options');
  const addOnRows = readSheetObjects_('AddOns');
  const menuMap = indexBy_(menuRows, 'ItemID');
  const optionMap = indexBy_(optionRows, 'OptionID');
  const addOnMap = indexBy_(addOnRows, 'AddOnID');
  const now = nowIso_();

  let sessionId = normalizeText_(table.CurrentSessionID, 100);
  let session = sessionId ? findObject_('OrderSessions', 'SessionID', sessionId) : null;
  if (!session || ['PAID', 'CLOSED', 'CANCELLED'].indexOf(String(session.Status)) !== -1) {
    sessionId = uuid_('ses_');
    session = {
      SessionID: sessionId, TableID: table.TableID, OpenTime: now, CloseTime: '', Status: 'OPEN', Subtotal: 0,
      Discount: 0, ServiceCharge: 0, Vat: 0, Total: 0, PromoCode: normalizeText_(input.promoCode, 40).toUpperCase(),
      PaymentMethod: '', CreatedBy: 'CUSTOMER', IdempotencyKey: idempotencyKey, UpdatedAt: now
    };
    appendObjects_('OrderSessions', [session]);
  }

  const orderRows = input.items.map(function(requested) {
    const item = menuMap[normalizeText_(requested.itemId, 80)];
    if (!item || String(item.Status) !== 'ACTIVE') fail_('ITEM_UNAVAILABLE', 'มีเมนูที่ไม่พร้อมจำหน่าย กรุณาตรวจตะกร้าใหม่');
    const qty = Math.floor(number_(requested.qty, 1));
    if (qty < 1 || qty > 20) fail_('INVALID_QTY', 'จำนวนของ ' + item.Name + ' ไม่ถูกต้อง');

    const selectedOptionIds = Array.isArray(requested.optionIds) ? requested.optionIds.map(String) : [];
    const selectedAddOnIds = Array.isArray(requested.addOnIds) ? requested.addOnIds.map(String) : [];
    const itemOptions = optionRows.filter(function(option) {
      return String(option.ItemID) === String(item.ItemID) && String(option.Status) === 'ACTIVE';
    });
    validateRequiredOptions_(itemOptions, selectedOptionIds, item.Name);
    const selectedOptions = selectedOptionIds.map(function(id) {
      const option = optionMap[id];
      if (!option || String(option.ItemID) !== String(item.ItemID) || String(option.Status) !== 'ACTIVE') {
        fail_('INVALID_OPTION', 'ตัวเลือกของ ' + item.Name + ' ไม่ถูกต้อง');
      }
      return { id: option.OptionID, group: option.GroupName, label: option.Label, price: money_(option.Price) };
    });
    const selectedAddOns = selectedAddOnIds.map(function(id) {
      const addOn = addOnMap[id];
      if (!addOn || String(addOn.Status) !== 'ACTIVE' || !addOnAppliesToItem_(addOn, item)) {
        fail_('INVALID_ADDON', 'ส่วนเพิ่มของ ' + item.Name + ' ไม่ถูกต้อง');
      }
      return { id: addOn.AddOnID, name: addOn.Name, price: money_(addOn.Price) };
    });
    const optionTotal = selectedOptions.reduce(function(sum, option) { return sum + option.price; }, 0);
    const addOnTotal = selectedAddOns.reduce(function(sum, addOn) { return sum + addOn.price; }, 0);
    const unitPrice = money_(item.Price + optionTotal + addOnTotal);
    return {
      OrderItemID: uuid_('ord_'), SessionID: sessionId, RequestKey: idempotencyKey, ItemID: item.ItemID, ItemName: item.Name, Qty: qty, UnitPrice: unitPrice,
      OptionsJSON: JSON.stringify(selectedOptions), AddOnsJSON: JSON.stringify(selectedAddOns), Note: normalizeText_(requested.note, 300),
      LineTotal: money_(unitPrice * qty), Status: 'NEW', KitchenNote: '', CreatedAt: now, UpdatedAt: now
    };
  });

  appendObjects_('OrderItems', orderRows);
  updateObject_('Tables', 'TableID', table.TableID, { Status: 'OCCUPIED', CurrentSessionID: sessionId, UpdatedAt: now });
  const promoCode = normalizeText_(input.promoCode || session.PromoCode, 40).toUpperCase();
  const totals = recalculateSessionTotals_(sessionId, promoCode);
  audit_('CUSTOMER', 'SUBMIT_ORDER', 'OrderSession', sessionId, { itemCount: orderRows.length, tableId: table.TableID });
  return {
    SessionID: sessionId,
    table: { TableID: table.TableID, Name: table.Name },
    totals: totals,
    items: orderRows.map(publicRow_),
    submittedAt: now
  };
}

function validateRequiredOptions_(options, selectedIds, itemName) {
  const requiredGroups = {};
  options.forEach(function(option) {
    if (bool_(option.IsRequired)) requiredGroups[String(option.GroupName)] = true;
  });
  Object.keys(requiredGroups).forEach(function(group) {
    const selected = options.some(function(option) {
      return String(option.GroupName) === group && selectedIds.indexOf(String(option.OptionID)) !== -1;
    });
    if (!selected) fail_('REQUIRED_OPTION', 'กรุณาเลือก “' + group + '” สำหรับ ' + itemName);
  });
}

function callStaff(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const type = normalizeText_(input.type, 30).toUpperCase();
    if (['ASSISTANCE', 'BILL'].indexOf(type) === -1) fail_('INVALID_CALL_TYPE', 'ประเภทการเรียกไม่ถูกต้อง');
    const table = findObject_('Tables', 'Token', normalizeText_(input.tableToken, 100));
    if (!table) fail_('TABLE_NOT_FOUND', 'ไม่พบโต๊ะนี้');
    if (type === 'BILL' && !table.CurrentSessionID) fail_('NO_ACTIVE_ORDER', 'ยังไม่มีออเดอร์สำหรับเรียกเก็บเงิน');
    const lock = LockService.getScriptLock();
    if (!lock.tryLock(5000)) fail_('BUSY', 'ระบบกำลังรับงานเรียกอื่น กรุณาลองอีกครั้ง');
    try {
    const existing = readSheetObjects_('CallLogs').find(function(call) {
      return String(call.TableID) === String(table.TableID) && String(call.Type) === type && ['OPEN', 'ASSIGNED'].indexOf(String(call.Status)) !== -1;
    });
    if (existing) {
      if (type === 'BILL') markPaymentPending_(table);
      return { call: publicRow_(existing), duplicate: true };
    }
    const call = {
      LogID: uuid_('call_'), TableID: table.TableID, SessionID: table.CurrentSessionID || '', Type: type, Status: 'OPEN', AssignedStaffID: '',
      IdempotencyKey: normalizeText_(input.idempotencyKey, 120), CreatedAt: nowIso_(), AcceptedAt: '', CompletedAt: ''
    };
    appendObjects_('CallLogs', [call]);
    if (type === 'BILL') markPaymentPending_(table);
    audit_('CUSTOMER', 'CALL_' + type, 'Table', table.TableID, {});
    return { call: publicRow_(call), duplicate: false };
    } finally {
      lock.releaseLock();
    }
  });
}

function markPaymentPending_(table) {
  const updatedAt = nowIso_();
  updateObject_('Tables', 'TableID', table.TableID, { Status: 'PAYMENT_PENDING', UpdatedAt: updatedAt });
  if (!table.CurrentSessionID) return;
  const session = findObject_('OrderSessions', 'SessionID', table.CurrentSessionID);
  if (session && String(session.Status) === 'OPEN') {
    updateObject_('OrderSessions', 'SessionID', session.SessionID, { Status: 'PAYMENT_PENDING', UpdatedAt: updatedAt });
  }
}

function getSessionBundle_(sessionId) {
  const session = findObject_('OrderSessions', 'SessionID', sessionId);
  if (!session) return null;
  const items = readSheetObjects_('OrderItems').filter(function(item) { return String(item.SessionID) === String(sessionId); });
  const calls = readSheetObjects_('CallLogs').filter(function(call) { return String(call.SessionID) === String(sessionId); });
  const cleanSession = publicRow_(session);
  if (String(cleanSession.Status) === 'OPEN' && calls.some(function(call) {
    return String(call.Type) === 'BILL' && ['OPEN', 'ASSIGNED'].indexOf(String(call.Status)) !== -1;
  })) cleanSession.Status = 'PAYMENT_PENDING';
  return {
    session: cleanSession,
    items: items.map(function(item) {
      const clean = publicRow_(item);
      clean.options = safeJson_(item.OptionsJSON, []);
      clean.addOns = safeJson_(item.AddOnsJSON, []);
      delete clean.OptionsJSON;
      delete clean.AddOnsJSON;
      return clean;
    }),
    calls: calls.map(publicRow_)
  };
}

function recalculateSessionTotals_(sessionId, promoCode) {
  const settings = getSettingsMap_();
  const items = readSheetObjects_('OrderItems').filter(function(item) {
    return String(item.SessionID) === String(sessionId) && String(item.Status) !== 'CANCELLED';
  });
  const subtotal = money_(items.reduce(function(sum, item) { return sum + number_(item.LineTotal); }, 0));
  const promo = findPromotion_(promoCode, subtotal);
  let discount = 0;
  if (promo) {
    discount = String(promo.DiscountType) === 'PERCENT'
      ? money_(subtotal * number_(promo.DiscountValue) / 100)
      : Math.min(subtotal, money_(promo.DiscountValue));
  }
  const net = Math.max(0, subtotal - discount);
  const serviceCharge = money_(net * number_(settings.ServiceChargePercent) / 100);
  const vat = money_((net + serviceCharge) * number_(settings.VatPercent) / 100);
  const total = money_(net + serviceCharge + vat);
  updateObject_('OrderSessions', 'SessionID', sessionId, {
    Subtotal: subtotal, Discount: discount, ServiceCharge: serviceCharge, Vat: vat, Total: total,
    PromoCode: promo ? promo.Code : '', UpdatedAt: nowIso_()
  });
  return { subtotal: subtotal, discount: discount, serviceCharge: serviceCharge, vat: vat, total: total, promo: promo ? publicRow_(promo) : null };
}

function getActivePromotions_() {
  const today = Utilities.formatDate(new Date(), Session.getScriptTimeZone() || 'Asia/Bangkok', 'yyyy-MM-dd');
  return readSheetObjects_('Promotions').filter(function(promo) {
    return String(promo.Status) === 'ACTIVE' && (!promo.StartDate || String(promo.StartDate).slice(0, 10) <= today) && (!promo.EndDate || String(promo.EndDate).slice(0, 10) >= today);
  });
}

function findPromotion_(code, subtotal) {
  const normalized = normalizeText_(code, 40).toUpperCase();
  if (!normalized) return null;
  return getActivePromotions_().find(function(promo) {
    return String(promo.Code).toUpperCase() === normalized && subtotal >= number_(promo.MinSpend);
  }) || null;
}

function beginTransaction_(type, key) {
  const existing = findObject_('Transactions', 'IdempotencyKey', key);
  if (existing && String(existing.Status) === 'COMPLETED') return safeJson_(existing.ResultJSON, null);
  if (existing) {
    updateObject_('Transactions', 'IdempotencyKey', key, { Type: type, Status: 'PROCESSING', UpdatedAt: nowIso_(), ResultJSON: '' });
    return null;
  }
  appendObjects_('Transactions', [{
    TransactionID: uuid_('txn_'), Type: type, IdempotencyKey: key, EntityID: '', Status: 'PROCESSING', CreatedAt: nowIso_(), UpdatedAt: nowIso_(), ResultJSON: ''
  }]);
  return null;
}

function completeTransaction_(key, entityId, result) {
  updateObject_('Transactions', 'IdempotencyKey', key, { EntityID: entityId, Status: 'COMPLETED', UpdatedAt: nowIso_(), ResultJSON: JSON.stringify(result) });
}

function failTransaction_(key) {
  try { updateObject_('Transactions', 'IdempotencyKey', key, { Status: 'FAILED', UpdatedAt: nowIso_() }); } catch (error) { console.error(error); }
}

function indexBy_(rows, key) {
  return rows.reduce(function(map, row) { map[String(row[key])] = row; return map; }, {});
}

function publicRow_(row) {
  if (!row) return null;
  return Object.keys(row).reduce(function(object, key) {
    if (key !== '_row' && key !== 'PINHash') object[key] = row[key];
    return object;
  }, {});
}
