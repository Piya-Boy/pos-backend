function generateReceiptPdf(payload) {
  return api_(function() {
    const input = parsePayload_(payload);
    const auth = requireAuth_(input.token, ['CASHIER']);
    const receipt = buildReceipt_(normalizeText_(input.sessionId, 100));
    const session = receipt.session;
    if (String(session.Status) !== 'PAID') fail_('PAYMENT_REQUIRED', 'ต้องปิดบิลก่อนสร้างใบเสร็จ');

    const payment = receipt.payment || {};
    const rows = receipt.items.map(function(item) {
      const details = receiptItemDetails_(item);
      return [
        '<tr><td><strong>', escapeHtmlServer_(item.ItemName), ' × ', number_(item.Qty), '</strong>',
        '<small>', money_(item.UnitPrice).toFixed(2), ' บาท/รายการ</small>',
        details ? '<small>' + escapeHtmlServer_(details) + '</small>' : '',
        '</td><td class="money">', money_(item.LineTotal).toFixed(2), '</td></tr>'
      ].join('');
    }).join('');
    const html = [
      '<!doctype html><html><head><meta charset="utf-8"><style>',
      'body{font-family:Arial,sans-serif;color:#211e1b;padding:28px}h1{color:#b7442b;margin:0 0 4px}.meta{color:#706a63;font-size:12px;margin-top:4px}table{width:100%;border-collapse:collapse;margin-top:20px}td{padding:10px 0;border-bottom:1px solid #e7ded2;vertical-align:top}.money{text-align:right;white-space:nowrap}small{display:block;color:#706a63;margin-top:3px}.total{font-size:20px;font-weight:700}',
      '</style></head><body>',
      '<h1>' + escapeHtmlServer_(receipt.restaurantName) + '</h1>',
      '<div>' + escapeHtmlServer_(receipt.table) + ' · ' + escapeHtmlServer_(session.SessionID) + '</div>',
      '<div class="meta">ชำระโดย ' + escapeHtmlServer_(paymentMethodLabelServer_(payment.Method || session.PaymentMethod)) +
        (payment.Reference ? ' · อ้างอิง ' + escapeHtmlServer_(payment.Reference) : '') +
        (payment.PaidAt ? ' · ' + escapeHtmlServer_(payment.PaidAt) : '') + '</div>',
      '<table>' + rows,
      '<tr><td>ยอดก่อนส่วนลด</td><td style="text-align:right">' + money_(session.Subtotal).toFixed(2) + '</td></tr>',
      '<tr><td>ส่วนลด</td><td style="text-align:right">-' + money_(session.Discount).toFixed(2) + '</td></tr>',
      '<tr><td>ค่าบริการ</td><td style="text-align:right">' + money_(session.ServiceCharge).toFixed(2) + '</td></tr>',
      '<tr><td>ภาษีมูลค่าเพิ่ม</td><td style="text-align:right">' + money_(session.Vat).toFixed(2) + '</td></tr>',
      '<tr class="total"><td>ยอดชำระ</td><td style="text-align:right">฿' + money_(session.Total).toFixed(2) + '</td></tr>',
      '</table><p>ขอบคุณที่ใช้บริการ</p></body></html>'
    ].join('');
    const pdfBlob = Utilities.newBlob(html, MimeType.HTML, 'receipt.html')
      .getAs(MimeType.PDF)
      .setName('Receipt-' + session.SessionID + '.pdf');
    const folderId = ensureDriveFolders_().receiptsId;
    const file = DriveApp.getFolderById(folderId).createFile(pdfBlob);
    audit_(auth.staffId, 'GENERATE_RECEIPT_PDF', 'OrderSession', session.SessionID, { fileId: file.getId() });
    return { fileId: file.getId(), name: file.getName(), url: file.getUrl() };
  });
}

function receiptItemDetails_(item) {
  const details = [];
  (item.options || []).forEach(function(option) {
    const group = option.group || option.GroupName || '';
    const label = option.label || option.Label || '';
    if (label) details.push((group ? group + ': ' : '') + label);
  });
  (item.addOns || []).forEach(function(addOn) {
    const name = addOn.name || addOn.Name || '';
    if (name) details.push('+ ' + name);
  });
  if (item.Note) details.push('หมายเหตุ: ' + item.Note);
  return details.join(' · ');
}

function paymentMethodLabelServer_(method) {
  return {
    CASH: 'เงินสด',
    TRANSFER: 'โอนเงิน',
    CARD: 'บัตร',
    OTHER: 'อื่น ๆ'
  }[String(method || '').toUpperCase()] || 'ไม่ระบุ';
}

function escapeHtmlServer_(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
