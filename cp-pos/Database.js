const SHEET_SCHEMAS = Object.freeze({
  Tables: ['TableID', 'Name', 'Zone', 'Token', 'Status', 'CurrentSessionID', 'CreatedAt', 'UpdatedAt'],
  Categories: ['CategoryID', 'Name', 'Icon', 'SortOrder', 'Status', 'CreatedAt', 'UpdatedAt'],
  MenuItems: ['ItemID', 'CategoryID', 'Name', 'Price', 'Description', 'ImageURL', 'Status', 'SortOrder', 'IsPopular', 'CreatedAt', 'UpdatedAt'],
  Options: ['OptionID', 'ItemID', 'GroupName', 'Label', 'Price', 'InputType', 'IsRequired', 'SortOrder', 'Status'],
  AddOns: ['AddOnID', 'Name', 'Price', 'LinkedItemID', 'LinkedCategoryID', 'Status', 'SortOrder'],
  Promotions: ['PromoID', 'Code', 'Name', 'Description', 'DiscountType', 'DiscountValue', 'MinSpend', 'StartDate', 'EndDate', 'BannerImage', 'Status'],
  OrderSessions: ['SessionID', 'TableID', 'OpenTime', 'CloseTime', 'Status', 'Subtotal', 'Discount', 'ServiceCharge', 'Vat', 'Total', 'PromoCode', 'PaymentMethod', 'CreatedBy', 'IdempotencyKey', 'UpdatedAt'],
  OrderItems: ['OrderItemID', 'SessionID', 'RequestKey', 'ItemID', 'ItemName', 'Qty', 'UnitPrice', 'OptionsJSON', 'AddOnsJSON', 'Note', 'LineTotal', 'Status', 'KitchenNote', 'CreatedAt', 'UpdatedAt'],
  CallLogs: ['LogID', 'TableID', 'SessionID', 'Type', 'Status', 'AssignedStaffID', 'IdempotencyKey', 'CreatedAt', 'AcceptedAt', 'CompletedAt'],
  Payments: ['PaymentID', 'SessionID', 'IdempotencyKey', 'Amount', 'Method', 'Reference', 'PaidAt', 'StaffID'],
  Transactions: ['TransactionID', 'Type', 'IdempotencyKey', 'EntityID', 'Status', 'CreatedAt', 'UpdatedAt', 'ResultJSON'],
  Staff: ['StaffID', 'Name', 'PINHash', 'Role', 'Status', 'MustChangePin', 'CreatedAt', 'UpdatedAt', 'LastLogin'],
  Settings: ['Key', 'Value', 'UpdatedAt'],
  AuditLog: ['Timestamp', 'StaffID', 'Action', 'EntityType', 'EntityID', 'DetailJSON']
});

const INITIAL_STAFF_PIN = 'zaq1234';
const INITIAL_STAFF_PIN_VERSION = 'shared-default-v1';
const ADDON_SCOPE_VERSION = 'category-scope-v1';
const BRAND_SETTINGS_VERSION = 'brand-controls-v2';

function setupSystem() {
  return api_(function() {
    const db = getDb_();
    Object.keys(SHEET_SCHEMAS).forEach(function(name) {
      ensureSheet_(db, name, SHEET_SCHEMAS[name]);
    });
    migrateBrandSettings_();
    seedTables_();
    seedCatalog_();
    migrateAddOnScopes_();
    const initialPins = seedStaff_();
    const migratedRoles = migrateInitialStaffPins_();
    const folders = ensureDriveFolders_();
    const result = {
      spreadsheetId: db.getId(),
      spreadsheetUrl: db.getUrl(),
      driveFolderUrl: folders.rootUrl,
      initialPins: initialPins || (migratedRoles.length ? initialStaffPins_() : null),
      note: initialPins || migratedRoles.length ? 'PIN เริ่มต้นคือ zaq1234 และต้องเปลี่ยนหลังเข้าสู่ระบบครั้งแรก' : 'ระบบถูกตั้งค่าไว้แล้ว'
    };
    if (initialPins) Logger.log('Phius Order initial PINs: %s', JSON.stringify(initialPins));
    return result;
  });
}

function getDb_() {
  const props = PropertiesService.getScriptProperties();
  const storedId = props.getProperty('DATABASE_SPREADSHEET_ID');
  if (storedId) {
    try { return SpreadsheetApp.openById(storedId); } catch (error) { console.warn('Stored spreadsheet unavailable', error); }
  }

  const active = SpreadsheetApp.getActiveSpreadsheet();
  if (active) {
    props.setProperty('DATABASE_SPREADSHEET_ID', active.getId());
    return active;
  }

  const created = SpreadsheetApp.create(APP.NAME + ' Database');
  props.setProperty('DATABASE_SPREADSHEET_ID', created.getId());
  return created;
}

function ensureSheet_(db, name, headers) {
  let sheet = db.getSheetByName(name);
  if (!sheet) sheet = db.insertSheet(name);
  const width = Math.max(sheet.getLastColumn(), headers.length);
  const existing = sheet.getLastRow() ? sheet.getRange(1, 1, 1, width).getValues()[0].filter(String) : [];
  const finalHeaders = existing.slice();
  headers.forEach(function(header) {
    if (finalHeaders.indexOf(header) === -1) finalHeaders.push(header);
  });
  if (!finalHeaders.length) return sheet;
  sheet.getRange(1, 1, 1, finalHeaders.length).setValues([finalHeaders]);
  sheet.setFrozenRows(1);
  sheet.getRange(1, 1, 1, finalHeaders.length)
    .setFontWeight('bold')
    .setBackground('#B7442B')
    .setFontColor('#FFFFFF');
  return sheet;
}

function sheet_(name) {
  const sheet = getDb_().getSheetByName(name);
  if (!sheet) fail_('SYSTEM_NOT_READY', 'ยังไม่ได้ตั้งค่าระบบ กรุณารัน setupSystem() ก่อน');
  return sheet;
}

function readSheetObjects_(name) {
  const sheet = sheet_(name);
  const values = sheet.getDataRange().getValues();
  if (values.length < 2) return [];
  const headers = values[0].map(String);
  return values.slice(1).filter(function(row) {
    return row.some(function(value) { return value !== ''; });
  }).map(function(row, index) {
    const object = { _row: index + 2 };
    headers.forEach(function(header, columnIndex) {
      const value = row[columnIndex];
      object[header] = value instanceof Date ? value.toISOString() : value;
    });
    return object;
  });
}

function appendObjects_(name, objects) {
  if (!objects || !objects.length) return;
  const sheet = sheet_(name);
  const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0].map(String);
  const rows = objects.map(function(object) {
    return headers.map(function(header) { return object[header] == null ? '' : object[header]; });
  });
  sheet.getRange(sheet.getLastRow() + 1, 1, rows.length, headers.length).setValues(rows);
}

function findObject_(name, field, value) {
  return readSheetObjects_(name).find(function(row) { return String(row[field]) === String(value); }) || null;
}

function updateObject_(name, keyField, keyValue, patch) {
  const sheet = sheet_(name);
  const data = sheet.getDataRange().getValues();
  if (!data.length) fail_('SYSTEM_NOT_READY', 'ไม่พบโครงสร้างข้อมูล');
  const headers = data[0].map(String);
  const keyColumn = headers.indexOf(keyField);
  if (keyColumn === -1) fail_('SCHEMA_ERROR', 'ไม่พบคอลัมน์ ' + keyField);
  let rowIndex = -1;
  for (let i = 1; i < data.length; i += 1) {
    if (String(data[i][keyColumn]) === String(keyValue)) { rowIndex = i + 1; break; }
  }
  if (rowIndex === -1) fail_('NOT_FOUND', 'ไม่พบข้อมูลที่ต้องการ');
  Object.keys(patch).forEach(function(field) {
    const column = headers.indexOf(field);
    if (column !== -1) sheet.getRange(rowIndex, column + 1).setValue(patch[field]);
  });
  return findObject_(name, keyField, keyValue);
}

function upsertObject_(name, keyField, object) {
  const existing = findObject_(name, keyField, object[keyField]);
  if (existing) return updateObject_(name, keyField, object[keyField], object);
  appendObjects_(name, [object]);
  return findObject_(name, keyField, object[keyField]);
}

function seedSettings_() {
  const defaults = {
    AppName: 'Phius Order',
    RestaurantName: 'Phius Thai Kitchen',
    RestaurantTagline: 'Modern Thai Vitality',
    BrandLogoText: 'ผ',
    BrandLogoURL: '',
    HeroKicker: 'อิ่มอร่อยในแบบของคุณ',
    HeroTitle: 'เลือกเมนูโปรด\nแล้วส่งตรงถึงครัว',
    HeroBadgeText: 'อร่อย',
    HeroBadgeImageURL: '',
    Currency: 'THB',
    CurrencySymbol: '฿',
    ServiceChargePercent: '0',
    VatPercent: '0',
    PrimaryColor: '#B7442B',
    SuccessColor: '#2F6B4F',
    BackgroundColor: '#FBF7F0',
    SurfaceColor: '#FFFFFF',
    TextColor: '#211E1B',
    OrderPollingSeconds: String(APP.POLL_SECONDS),
    AllowDriveUploads: 'TRUE'
  };
  const now = nowIso_();
  const existingKeys = readSheetObjects_('Settings').reduce(function(map, row) {
    map[String(row.Key)] = true;
    return map;
  }, {});
  const additions = [];
  Object.keys(defaults).forEach(function(key) {
    if (!existingKeys[key]) additions.push({ Key: key, Value: defaults[key], UpdatedAt: now });
  });
  appendObjects_('Settings', additions);
  CacheService.getScriptCache().remove('settings-map-v1');
}

function migrateBrandSettings_() {
  const props = PropertiesService.getScriptProperties();
  if (props.getProperty('BRAND_SETTINGS_VERSION') === BRAND_SETTINGS_VERSION) return false;
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return false;
  try {
    if (props.getProperty('BRAND_SETTINGS_VERSION') === BRAND_SETTINGS_VERSION) return false;
    seedSettings_();
    props.setProperty('BRAND_SETTINGS_VERSION', BRAND_SETTINGS_VERSION);
    cacheShellBrandSettings_(getSettingsMap_());
    return true;
  } finally {
    lock.releaseLock();
  }
}

function getSettingsMap_() {
  const cache = CacheService.getScriptCache();
  const cached = cache.get('settings-map-v1');
  if (cached) return safeJson_(cached, {});
  const settings = readSheetObjects_('Settings').reduce(function(map, row) {
    map[String(row.Key)] = row.Value;
    return map;
  }, {});
  cache.put('settings-map-v1', JSON.stringify(settings), 120);
  return settings;
}

function setSetting_(key, value) {
  const saved = upsertObject_('Settings', 'Key', { Key: key, Value: value, UpdatedAt: nowIso_() });
  CacheService.getScriptCache().remove('settings-map-v1');
  return saved;
}

function saveSettingsMap_(settings) {
  const sheet = sheet_('Settings');
  const values = sheet.getDataRange().getValues();
  const headers = values[0].map(String);
  const keyColumn = headers.indexOf('Key');
  const valueColumn = headers.indexOf('Value');
  const updatedColumn = headers.indexOf('UpdatedAt');
  const now = nowIso_();
  const rowByKey = {};
  values.slice(1).forEach(function(row, index) {
    if (row[keyColumn] !== '') rowByKey[String(row[keyColumn])] = index + 1;
  });
  Object.keys(settings).forEach(function(key) {
    const rowIndex = rowByKey[key];
    if (rowIndex) {
      values[rowIndex][valueColumn] = settings[key];
      if (updatedColumn !== -1) values[rowIndex][updatedColumn] = now;
      return;
    }
    const row = headers.map(function(header) {
      if (header === 'Key') return key;
      if (header === 'Value') return settings[key];
      if (header === 'UpdatedAt') return now;
      return '';
    });
    values.push(row);
    rowByKey[key] = values.length - 1;
  });
  if (values.length > 1) sheet.getRange(2, 1, values.length - 1, headers.length).setValues(values.slice(1));
  CacheService.getScriptCache().remove('settings-map-v1');
  return getSettingsMap_();
}

function seedTables_() {
  if (readSheetObjects_('Tables').length) return;
  const now = nowIso_();
  const rows = [];
  for (let i = 1; i <= 12; i += 1) {
    rows.push({
      TableID: 'T' + String(i).padStart(2, '0'),
      Name: 'โต๊ะ ' + String(i).padStart(2, '0'),
      Zone: i <= 6 ? 'โซนด้านใน' : 'โซนระเบียง',
      Token: uuid_('tbl_'),
      Status: 'AVAILABLE',
      CurrentSessionID: '',
      CreatedAt: now,
      UpdatedAt: now
    });
  }
  appendObjects_('Tables', rows);
}

function seedCatalog_() {
  if (!readSheetObjects_('Categories').length) {
    const now = nowIso_();
    appendObjects_('Categories', [
      { CategoryID: 'CAT_RICE', Name: 'อาหารจานเดียว', Icon: '🍚', SortOrder: 1, Status: 'ACTIVE', CreatedAt: now, UpdatedAt: now },
      { CategoryID: 'CAT_SHARED', Name: 'กับข้าว', Icon: '🥘', SortOrder: 2, Status: 'ACTIVE', CreatedAt: now, UpdatedAt: now },
      { CategoryID: 'CAT_NOODLE', Name: 'เส้นและก๋วยเตี๋ยว', Icon: '🍜', SortOrder: 3, Status: 'ACTIVE', CreatedAt: now, UpdatedAt: now },
      { CategoryID: 'CAT_DRINK', Name: 'เครื่องดื่ม', Icon: '🥤', SortOrder: 4, Status: 'ACTIVE', CreatedAt: now, UpdatedAt: now },
      { CategoryID: 'CAT_DESSERT', Name: 'ของหวาน', Icon: '🍨', SortOrder: 5, Status: 'ACTIVE', CreatedAt: now, UpdatedAt: now }
    ]);
  }

  if (!readSheetObjects_('MenuItems').length) {
    const now = nowIso_();
    appendObjects_('MenuItems', [
      { ItemID: 'M001', CategoryID: 'CAT_RICE', Name: 'กะเพราหมูสับ', Price: 85, Description: 'กะเพราหอมกระทะ เสิร์ฟพร้อมข้าวหอมมะลิ', ImageURL: 'https://images.unsplash.com/photo-1562565652-a0d8f0c59eb4?auto=format&fit=crop&w=900&q=80', Status: 'ACTIVE', SortOrder: 1, IsPopular: true, CreatedAt: now, UpdatedAt: now },
      { ItemID: 'M002', CategoryID: 'CAT_RICE', Name: 'ข้าวผัดกุ้ง', Price: 110, Description: 'ข้าวผัดหอมกระทะกับกุ้งสด', ImageURL: 'https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=900&q=80', Status: 'ACTIVE', SortOrder: 2, IsPopular: true, CreatedAt: now, UpdatedAt: now },
      { ItemID: 'M003', CategoryID: 'CAT_SHARED', Name: 'ต้มยำกุ้งน้ำข้น', Price: 220, Description: 'กุ้งสดและสมุนไพรไทย รสเปรี้ยวเผ็ดกลมกล่อม', ImageURL: 'https://images.unsplash.com/photo-1548943487-a2e4e43b4853?auto=format&fit=crop&w=900&q=80', Status: 'ACTIVE', SortOrder: 1, IsPopular: true, CreatedAt: now, UpdatedAt: now },
      { ItemID: 'M004', CategoryID: 'CAT_SHARED', Name: 'แกงเขียวหวานไก่', Price: 180, Description: 'เครื่องแกงตำสด กะทิหอมและโหระพา', ImageURL: 'https://images.unsplash.com/photo-1455619452474-d2be8b1e70cd?auto=format&fit=crop&w=900&q=80', Status: 'ACTIVE', SortOrder: 2, IsPopular: false, CreatedAt: now, UpdatedAt: now },
      { ItemID: 'M005', CategoryID: 'CAT_NOODLE', Name: 'ผัดไทยกุ้งสด', Price: 125, Description: 'เส้นเหนียวนุ่ม ซอสมะขามสูตรร้าน', ImageURL: 'https://images.unsplash.com/photo-1559314809-0d155014e29e?auto=format&fit=crop&w=900&q=80', Status: 'ACTIVE', SortOrder: 1, IsPopular: true, CreatedAt: now, UpdatedAt: now },
      { ItemID: 'M006', CategoryID: 'CAT_DRINK', Name: 'ชาไทยเย็น', Price: 55, Description: 'ชาไทยเข้มข้น หวานมันกำลังดี', ImageURL: 'https://images.unsplash.com/photo-1558857563-b371033873b8?auto=format&fit=crop&w=900&q=80', Status: 'ACTIVE', SortOrder: 1, IsPopular: true, CreatedAt: now, UpdatedAt: now },
      { ItemID: 'M007', CategoryID: 'CAT_DRINK', Name: 'น้ำมะนาวโซดา', Price: 65, Description: 'มะนาวสดและโซดาซ่า', ImageURL: 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?auto=format&fit=crop&w=900&q=80', Status: 'ACTIVE', SortOrder: 2, IsPopular: false, CreatedAt: now, UpdatedAt: now },
      { ItemID: 'M008', CategoryID: 'CAT_DESSERT', Name: 'ข้าวเหนียวมะม่วง', Price: 120, Description: 'มะม่วงสุก ข้าวเหนียวมูนและกะทิสด', ImageURL: 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=900&q=80', Status: 'ACTIVE', SortOrder: 1, IsPopular: true, CreatedAt: now, UpdatedAt: now }
    ]);
  }

  if (!readSheetObjects_('Options').length) {
    appendObjects_('Options', [
      { OptionID: 'OPT001', ItemID: 'M001', GroupName: 'ระดับความเผ็ด', Label: 'ไม่เผ็ด', Price: 0, InputType: 'RADIO', IsRequired: true, SortOrder: 1, Status: 'ACTIVE' },
      { OptionID: 'OPT002', ItemID: 'M001', GroupName: 'ระดับความเผ็ด', Label: 'เผ็ดปกติ', Price: 0, InputType: 'RADIO', IsRequired: true, SortOrder: 2, Status: 'ACTIVE' },
      { OptionID: 'OPT003', ItemID: 'M001', GroupName: 'ระดับความเผ็ด', Label: 'เผ็ดมาก', Price: 0, InputType: 'RADIO', IsRequired: true, SortOrder: 3, Status: 'ACTIVE' },
      { OptionID: 'OPT004', ItemID: 'M005', GroupName: 'เครื่องเคียง', Label: 'ไม่ใส่ถั่ว', Price: 0, InputType: 'CHECKBOX', IsRequired: false, SortOrder: 1, Status: 'ACTIVE' }
    ]);
  }

  if (!readSheetObjects_('AddOns').length) {
    appendObjects_('AddOns', [
      { AddOnID: 'ADD001', Name: 'ไข่ดาว', Price: 15, LinkedItemID: '', LinkedCategoryID: 'CAT_RICE', Status: 'ACTIVE', SortOrder: 1 },
      { AddOnID: 'ADD002', Name: 'ไข่เจียว', Price: 20, LinkedItemID: '', LinkedCategoryID: 'CAT_RICE', Status: 'ACTIVE', SortOrder: 2 },
      { AddOnID: 'ADD003', Name: 'ข้าวเพิ่ม', Price: 20, LinkedItemID: '', LinkedCategoryID: 'CAT_RICE', Status: 'ACTIVE', SortOrder: 3 },
      { AddOnID: 'ADD004', Name: 'กุ้งเพิ่ม', Price: 45, LinkedItemID: 'M005', LinkedCategoryID: '', Status: 'ACTIVE', SortOrder: 4 }
    ]);
  }

  if (!readSheetObjects_('Promotions').length) {
    appendObjects_('Promotions', [{
      PromoID: 'PROMO_WELCOME', Code: 'WELCOME10', Name: 'Welcome Special', Description: 'ลด 10% เมื่อสั่งครบ 500 บาท', DiscountType: 'PERCENT', DiscountValue: 10, MinSpend: 500,
      StartDate: '2025-01-01', EndDate: '2035-12-31', BannerImage: 'https://images.unsplash.com/photo-1552566626-52f8b828add9?auto=format&fit=crop&w=1200&q=80', Status: 'ACTIVE'
    }]);
  }
}

function migrateAddOnScopes_() {
  const props = PropertiesService.getScriptProperties();
  if (props.getProperty('ADDON_SCOPE_VERSION') === ADDON_SCOPE_VERSION) return [];
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return [];
  try {
    if (props.getProperty('ADDON_SCOPE_VERSION') === ADDON_SCOPE_VERSION) return [];
    ensureSheet_(getDb_(), 'AddOns', SHEET_SCHEMAS.AddOns);
    const defaultScopes = { ADD001: 'CAT_RICE', ADD002: 'CAT_RICE', ADD003: 'CAT_RICE' };
    const migrated = [];
    readSheetObjects_('AddOns').forEach(function(addOn) {
      const categoryId = defaultScopes[String(addOn.AddOnID)];
      if (!categoryId || (String(addOn.LinkedItemID) && String(addOn.LinkedItemID) !== 'ALL')) return;
      updateObject_('AddOns', 'AddOnID', addOn.AddOnID, {
        LinkedItemID: '', LinkedCategoryID: categoryId
      });
      migrated.push(addOn.AddOnID);
    });
    props.setProperty('ADDON_SCOPE_VERSION', ADDON_SCOPE_VERSION);
    CacheService.getScriptCache().remove('public-catalog-v2');
    return migrated;
  } finally {
    lock.releaseLock();
  }
}

function seedStaff_() {
  if (readSheetObjects_('Staff').length) return null;
  const salt = getAuthSalt_();
  const now = nowIso_();
  const definitions = [
    ['ADMIN', 'ผู้ดูแลระบบ'],
    ['KITCHEN', 'ครัว'],
    ['STAFF', 'พนักงานเสิร์ฟ'],
    ['CASHIER', 'แคชเชียร์']
  ];
  const pins = {};
  const rows = definitions.map(function(definition) {
    const pin = INITIAL_STAFF_PIN;
    pins[definition[0]] = pin;
    return {
      StaffID: uuid_('stf_'), Name: definition[1], PINHash: sha256_(salt + ':' + pin), Role: definition[0], Status: 'ACTIVE',
      MustChangePin: true, CreatedAt: now, UpdatedAt: now, LastLogin: ''
    };
  });
  appendObjects_('Staff', rows);
  PropertiesService.getScriptProperties().setProperty('INITIAL_STAFF_PIN_VERSION', INITIAL_STAFF_PIN_VERSION);
  return pins;
}

function initialStaffPins_() {
  return { ADMIN: INITIAL_STAFF_PIN, KITCHEN: INITIAL_STAFF_PIN, STAFF: INITIAL_STAFF_PIN, CASHIER: INITIAL_STAFF_PIN };
}

function migrateInitialStaffPins_() {
  const props = PropertiesService.getScriptProperties();
  if (props.getProperty('INITIAL_STAFF_PIN_VERSION') === INITIAL_STAFF_PIN_VERSION) return [];

  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return [];
  try {
    if (props.getProperty('INITIAL_STAFF_PIN_VERSION') === INITIAL_STAFF_PIN_VERSION) return [];
    const definitions = [
      ['ADMIN', 'ผู้ดูแลระบบ'],
      ['KITCHEN', 'ครัว'],
      ['STAFF', 'พนักงานเสิร์ฟ'],
      ['CASHIER', 'แคชเชียร์']
    ];
    const rows = readSheetObjects_('Staff');
    const hash = sha256_(getAuthSalt_() + ':' + INITIAL_STAFF_PIN);
    const migratedRoles = [];

    definitions.forEach(function(definition) {
      const role = definition[0];
      const name = definition[1];
      const target = rows.find(function(row) {
        return String(row.Role) === role && String(row.Name) === name;
      }) || rows.find(function(row) {
        return String(row.Role) === role;
      });
      if (!target) return;
      updateObject_('Staff', 'StaffID', target.StaffID, {
        PINHash: hash,
        MustChangePin: true,
        UpdatedAt: nowIso_()
      });
      migratedRoles.push(role);
    });

    props.setProperty('INITIAL_STAFF_PIN_VERSION', INITIAL_STAFF_PIN_VERSION);
    return migratedRoles;
  } finally {
    lock.releaseLock();
  }
}

function getAuthSalt_() {
  const props = PropertiesService.getScriptProperties();
  let salt = props.getProperty('AUTH_SALT');
  if (!salt) {
    salt = uuid_('salt_') + uuid_();
    props.setProperty('AUTH_SALT', salt);
  }
  return salt;
}

function audit_(staffId, action, entityType, entityId, detail) {
  appendObjects_('AuditLog', [{
    Timestamp: nowIso_(), StaffID: staffId || 'PUBLIC', Action: action, EntityType: entityType || '', EntityID: entityId || '',
    DetailJSON: JSON.stringify(detail || {})
  }]);
}
