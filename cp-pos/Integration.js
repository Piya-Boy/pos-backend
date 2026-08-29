function ensureDriveFolders_() {
  const props = PropertiesService.getScriptProperties();
  let root = null;
  const rootId = props.getProperty('DRIVE_ROOT_FOLDER_ID');
  if (rootId) {
    try { root = DriveApp.getFolderById(rootId); } catch (error) { console.warn('Drive root unavailable', error); }
  }
  if (!root) {
    root = DriveApp.createFolder(APP.NAME + ' Assets');
    props.setProperty('DRIVE_ROOT_FOLDER_ID', root.getId());
  }
  const images = ensureChildFolder_(root, 'Menu Images', 'DRIVE_IMAGES_FOLDER_ID');
  const receipts = ensureChildFolder_(root, 'Receipts', 'DRIVE_RECEIPTS_FOLDER_ID');
  const qr = ensureChildFolder_(root, 'Table QR', 'DRIVE_QR_FOLDER_ID');
  return {
    rootId: root.getId(), rootUrl: root.getUrl(), imagesId: images.getId(), receiptsId: receipts.getId(), qrId: qr.getId()
  };
}

function ensureChildFolder_(root, name, propertyKey) {
  const props = PropertiesService.getScriptProperties();
  const storedId = props.getProperty(propertyKey);
  if (storedId) {
    try { return DriveApp.getFolderById(storedId); } catch (error) { console.warn('Stored folder unavailable', error); }
  }
  const existing = root.getFoldersByName(name);
  const folder = existing.hasNext() ? existing.next() : root.createFolder(name);
  props.setProperty(propertyKey, folder.getId());
  return folder;
}

function uploadMenuImage(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token, ['ADMIN']);
    const dataUrl = String(input.dataUrl || '');
    const match = dataUrl.match(/^data:(image\/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$/);
    if (!match) fail_('INVALID_IMAGE', 'รองรับเฉพาะรูป PNG, JPEG หรือ WebP');
    const bytes = Utilities.base64Decode(match[2]);
    if (bytes.length > 7 * 1024 * 1024) fail_('IMAGE_TOO_LARGE', 'รูปต้องมีขนาดไม่เกิน 7 MB');
    const extension = match[1] === 'image/png' ? 'png' : match[1] === 'image/webp' ? 'webp' : 'jpg';
    const safeName = normalizeText_(input.filename || 'menu-image', 80).replace(/[^a-zA-Z0-9ก-๙_-]+/g, '-');
    const folderId = ensureDriveFolders_().imagesId;
    const blob = Utilities.newBlob(bytes, match[1], safeName + '-' + Date.now() + '.' + extension);
    const file = DriveApp.getFolderById(folderId).createFile(blob);
    let publicAccess = true;
    try {
      file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
    } catch (error) {
      publicAccess = false;
      console.warn('Public Drive sharing unavailable', error);
    }
    if (!publicAccess) {
      try { file.setTrashed(true); } catch (trashError) { console.warn(trashError); }
      fail_('DRIVE_SHARING_DISABLED', 'บัญชีนี้ไม่อนุญาตแชร์รูปแบบสาธารณะ กรุณาใช้ URL รูปภาพ HTTPS แทน');
    }
    const imageUrl = publicAccess
      ? 'https://drive.google.com/thumbnail?id=' + encodeURIComponent(file.getId()) + '&sz=w1400'
      : file.getUrl();
    audit_(auth.staffId, 'UPLOAD_MENU_IMAGE', 'DriveFile', file.getId(), { name: file.getName(), publicAccess: publicAccess });
    return { fileId: file.getId(), name: file.getName(), url: imageUrl, driveUrl: file.getUrl(), publicAccess: publicAccess };
  });
}
