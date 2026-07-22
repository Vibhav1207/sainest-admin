<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager']);

$pageTitle = 'Reports';
$activeNav = 'reports';

$preset        = $_GET['preset'] ?? 'this_month';
$from          = $_GET['from'] ?? date('Y-m-01');
$to            = $_GET['to'] ?? date('Y-m-d');
$reportType    = $_GET['report_type'] ?? 'all';
$roomNumber    = trim($_GET['room_number'] ?? '');
$roomTypeId    = (int)($_GET['room_type_id'] ?? 0);
$floor         = trim($_GET['floor'] ?? '');
$bookingStatus = $_GET['booking_status'] ?? '';
$guestName     = trim($_GET['guest_name'] ?? '');
$mobileNumber  = trim($_GET['mobile_number'] ?? '');
$paymentStatus = $_GET['payment_status'] ?? '';
$paymentMethod = $_GET['payment_method'] ?? '';
$corporate     = $_GET['corporate'] ?? 'all';

// Fetch filter options
$roomTypes = db()->query("SELECT id, name FROM room_types ORDER BY name ASC")->fetchAll();
$floors    = db()->query("SELECT DISTINCT floor FROM rooms WHERE floor IS NOT NULL AND floor != '' ORDER BY floor ASC")->fetchAll();
$roomsList = db()->query("SELECT room_number FROM rooms ORDER BY CAST(room_number AS UNSIGNED) ASC")->fetchAll();

require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
  <div>
    <h2>Reports &amp; Analytics</h2>
    <div class="desc">Filter, analyze, export and share comprehensive performance metrics.</div>
  </div>
  <div class="page-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
    <button type="button" id="mainExportBtn" class="btn btn-gold" onclick="exportToExcel('all')" style="gap:6px; display:inline-flex; align-items:center;">
      <span class="export-icon">📊</span> <span class="export-text">Export Filtered Data to Excel</span>
    </button>
    <button type="button" class="btn btn-outline" onclick="shareReport('whatsapp')" style="gap:6px; display:inline-flex; align-items:center; background:#25D366; color:#fff; border-color:#25D366; font-weight:600;">
      <span>📱</span> <span>Share to WhatsApp</span>
    </button>
    <button type="button" class="btn btn-outline" onclick="shareReport('email')" style="gap:6px; display:inline-flex; align-items:center; font-weight:600;">
      <span>✉️</span> <span>Share via Email</span>
    </button>
  </div>
</div>

<!-- Mobile Filter Toggle Button -->
<div class="mobile-filter-toggle">
  <button type="button" class="btn btn-outline btn-block" onclick="toggleFilterDrawer()" style="justify-content:space-between; margin-bottom:12px;">
    <span>🔍 Advanced Filter Controls</span>
    <span id="drawerStateIcon">▼</span>
  </button>
</div>

<!-- Filter Panel Card -->
<div class="card filter-card" id="filterCard">
  <form id="reportFilterForm" onsubmit="applyFilters(event)" class="filter-form">
    <div class="filter-grid">
      
      <!-- Date Preset -->
      <div class="form-group">
        <label>Date Range Preset</label>
        <select name="preset" id="presetSelect" class="form-control" onchange="handlePresetChange(this.value)">
          <option value="today" <?= $preset === 'today' ? 'selected' : '' ?>>📅 Today</option>
          <option value="yesterday" <?= $preset === 'yesterday' ? 'selected' : '' ?>>⏪ Yesterday</option>
          <option value="this_week" <?= $preset === 'this_week' ? 'selected' : '' ?>>📆 This Week</option>
          <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>📊 This Month</option>
          <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>⚙️ Custom Date Range</option>
        </select>
      </div>

      <!-- Custom From -->
      <div class="form-group" id="fromGroup">
        <label>From Date</label>
        <input type="date" name="from" id="fromDate" class="form-control" value="<?= e($from) ?>">
      </div>

      <!-- Custom To -->
      <div class="form-group" id="toGroup">
        <label>To Date</label>
        <input type="date" name="to" id="toDate" class="form-control" value="<?= e($to) ?>">
      </div>

      <!-- Report Type -->
      <div class="form-group">
        <label>Report Module</label>
        <select name="report_type" id="reportTypeSelect" class="form-control">
          <option value="all" <?= $reportType === 'all' ? 'selected' : '' ?>>📑 All Reports</option>
          <option value="bookings" <?= $reportType === 'bookings' ? 'selected' : '' ?>>📖 Bookings Report</option>
          <option value="reservations" <?= $reportType === 'reservations' ? 'selected' : '' ?>>📅 Advance Bookings</option>
          <option value="checkin" <?= $reportType === 'checkin' ? 'selected' : '' ?>>🛎️ Check-In Report</option>
          <option value="checkout" <?= $reportType === 'checkout' ? 'selected' : '' ?>>🚪 Check-Out Report</option>
          <option value="guests" <?= $reportType === 'guests' ? 'selected' : '' ?>>👤 Guests Report</option>
          <option value="revenue" <?= $reportType === 'revenue' ? 'selected' : '' ?>>💰 Revenue Report</option>
          <option value="occupancy" <?= $reportType === 'occupancy' ? 'selected' : '' ?>>🏨 Room Occupancy</option>
        </select>
      </div>

      <!-- Room Number -->
      <div class="form-group">
        <label>Room Number</label>
        <select name="room_number" id="roomNumberSelect" class="form-control">
          <option value="">All Rooms</option>
          <?php foreach ($roomsList as $rm): ?>
            <option value="<?= e($rm['room_number']) ?>" <?= $roomNumber === $rm['room_number'] ? 'selected' : '' ?>>Room <?= e($rm['room_number']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Room Type -->
      <div class="form-group">
        <label>Room Category</label>
        <select name="room_type_id" id="roomTypeSelect" class="form-control">
          <option value="0">All Room Types</option>
          <?php foreach ($roomTypes as $rt): ?>
            <option value="<?= $rt['id'] ?>" <?= $roomTypeId === (int)$rt['id'] ? 'selected' : '' ?>><?= e($rt['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Floor -->
      <div class="form-group">
        <label>Floor Level</label>
        <select name="floor" id="floorSelect" class="form-control">
          <option value="">All Floors</option>
          <?php foreach ($floors as $fl): ?>
            <option value="<?= e($fl['floor']) ?>" <?= $floor === $fl['floor'] ? 'selected' : '' ?>>Floor <?= e($fl['floor']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Booking Status -->
      <div class="form-group">
        <label>Booking Status</label>
        <select name="booking_status" id="bookingStatusSelect" class="form-control">
          <option value="">All Statuses</option>
          <option value="reserved" <?= $bookingStatus === 'reserved' ? 'selected' : '' ?>>Reserved</option>
          <option value="checked_in" <?= $bookingStatus === 'checked_in' ? 'selected' : '' ?>>Checked-In</option>
          <option value="checked_out" <?= $bookingStatus === 'checked_out' ? 'selected' : '' ?>>Checked-Out</option>
          <option value="cancelled" <?= $bookingStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
      </div>

      <!-- Guest Name Search -->
      <div class="form-group">
        <label>Guest Name / Company</label>
        <input type="text" name="guest_name" id="guestNameInput" class="form-control" placeholder="Search by name..." value="<?= e($guestName) ?>">
      </div>

      <!-- Mobile Number Search -->
      <div class="form-group">
        <label>Mobile Number</label>
        <input type="text" name="mobile_number" id="mobileInput" class="form-control" placeholder="Search by phone..." value="<?= e($mobileNumber) ?>">
      </div>

      <!-- Payment Status -->
      <div class="form-group">
        <label>Payment Status</label>
        <select name="payment_status" id="paymentStatusSelect" class="form-control">
          <option value="">All Payment Statuses</option>
          <option value="paid" <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>Fully Paid</option>
          <option value="partial" <?= $paymentStatus === 'partial' ? 'selected' : '' ?>>Partially Paid</option>
          <option value="pending" <?= $paymentStatus === 'pending' ? 'selected' : '' ?>>Pending Payment</option>
        </select>
      </div>

      <!-- Payment Method -->
      <div class="form-group">
        <label>Payment Method</label>
        <select name="payment_method" id="paymentMethodSelect" class="form-control">
          <option value="">All Payment Methods</option>
          <option value="cash" <?= $paymentMethod === 'cash' ? 'selected' : '' ?>>💵 Cash</option>
          <option value="upi" <?= $paymentMethod === 'upi' ? 'selected' : '' ?>>📱 UPI / QR</option>
          <option value="card" <?= $paymentMethod === 'card' ? 'selected' : '' ?>>💳 Credit / Debit Card</option>
          <option value="bank_transfer" <?= $paymentMethod === 'bank_transfer' ? 'selected' : '' ?>>🏛️ Bank Transfer</option>
        </select>
      </div>

      <!-- Corporate Booking -->
      <div class="form-group">
        <label>Corporate Booking</label>
        <select name="corporate" id="corporateSelect" class="form-control">
          <option value="all" <?= $corporate === 'all' ? 'selected' : '' ?>>All Bookings</option>
          <option value="yes" <?= $corporate === 'yes' ? 'selected' : '' ?>>🏢 Corporate Only</option>
          <option value="no" <?= $corporate === 'no' ? 'selected' : '' ?>>👤 Regular Guests Only</option>
        </select>
      </div>

    </div>

    <!-- Filter Buttons Toolbar -->
    <div class="filter-actions-bar">
      <button type="submit" class="btn btn-gold" id="applyFilterBtn">
        🔍 Apply Filters
      </button>
      <button type="button" class="btn btn-outline" onclick="resetFilters()">
        🔄 Reset Filters
      </button>
    </div>
  </form>
</div>

<!-- Dynamic KPI Container -->
<div class="grid-cards" id="kpiContainer">
  <div class="kpi-card c-green"><div class="kpi-icon">💰</div><div><div class="kpi-val" id="kpiTotRev">₹0.00</div><div class="kpi-label">Total Revenue</div></div></div>
  <div class="kpi-card c-gold"><div class="kpi-icon">🧾</div><div><div class="kpi-val" id="kpiInvCount">0</div><div class="kpi-label">Invoices Generated</div></div></div>
  <?php if (canViewCommission()): ?>
  <div class="kpi-card c-green"><div class="kpi-icon">🏨</div><div><div class="kpi-val" id="kpiActualRev">₹0.00</div><div class="kpi-label">Actual Room Revenue <span class="internal-only-tag">Internal</span></div></div></div>
  <div class="kpi-card c-blue"><div class="kpi-icon">🤝</div><div><div class="kpi-val" id="kpiCommPayable">₹0.00</div><div class="kpi-label">Commission Payable <span class="internal-only-tag">Internal</span></div></div></div>
  <?php endif; ?>
</div>

<!-- Bookings by Source Table -->
<div class="card" id="cardSource">
  <div class="card-head">
    <h3>Bookings &amp; Commission by Source</h3>
    <button type="button" class="btn btn-sm btn-outline" onclick="exportToExcel('source')">📥 Export Sheet</button>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Source</th><th>Bookings</th><th>Commission</th></tr></thead>
      <tbody id="bodyBySource">
        <tr><td colspan="3"><div class="loading-state">Loading report data...</div></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Revenue by Room Table -->
<div class="card" id="cardRoom">
  <div class="card-head">
    <h3>Revenue by Room</h3>
    <button type="button" class="btn btn-sm btn-outline" onclick="exportToExcel('rooms')">📥 Export Sheet</button>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Room Number</th><th>Floor / Category</th><th>Bookings Count</th><th>Revenue</th></tr></thead>
      <tbody id="bodyByRoom">
        <tr><td colspan="4"><div class="loading-state">Loading report data...</div></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Corporate vs Regular Breakdown Table -->
<div class="card" id="cardType">
  <div class="card-head">
    <h3>🏢 Booking Breakdown: Regular vs Corporate</h3>
    <button type="button" class="btn btn-sm btn-outline" onclick="exportToExcel('booking_types')">📥 Export Sheet</button>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Booking Type</th><th>Total Stays</th><th>Total Revenue Generated</th></tr></thead>
      <tbody id="bodyTypeBreakdown">
        <tr><td colspan="3"><div class="loading-state">Loading report data...</div></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Invoices Table -->
<div class="card" id="cardInvoices">
  <div class="card-head">
    <h3>Invoices &amp; Billed Amounts</h3>
    <button type="button" class="btn btn-sm btn-outline" onclick="exportToExcel('invoices')">📥 Export Sheet</button>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Invoice #</th><th>Guest / Company</th><th>Booking</th>
          <?php if (canViewCommission()): ?><th>Actual Room Revenue</th><th>Commission</th><?php endif; ?>
          <th>Total</th><th>Paid</th><th>Balance</th><th>Date</th><th></th>
        </tr>
      </thead>
      <tbody id="bodyInvoices">
        <tr><td colspan="10"><div class="loading-state">Loading report data...</div></td></tr>
      </tbody>
    </table>
  </div>
</div>

<style>
/* ---- Advanced Filter Grid Layout ---- */
.filter-card {
  margin-bottom: 20px;
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e0e0e0);
  border-radius: 8px;
  padding: 16px 20px;
}
.filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 14px 16px;
}
.filter-grid .form-group {
  margin-bottom: 0;
}
.filter-grid label {
  display: block;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text-muted, #666);
  margin-bottom: 4px;
}
.filter-actions-bar {
  display: flex;
  gap: 10px;
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--border-color, #eee);
  justify-content: flex-end;
}
.mobile-filter-toggle {
  display: none;
}
@media (max-width: 768px) {
  .mobile-filter-toggle {
    display: block;
  }
  .filter-card {
    display: none;
  }
  .filter-card.open {
    display: block;
  }
  .filter-grid {
    grid-template-columns: 1fr;
  }
  .filter-actions-bar {
    flex-direction: column;
  }
  .filter-actions-bar .btn {
    width: 100%;
  }
}
</style>

<script>
function handlePresetChange(preset) {
  const today = new Date().toISOString().split('T')[0];
  const fromInput = document.getElementById('fromDate');
  const toInput = document.getElementById('toDate');

  if (preset === 'today') {
    fromInput.value = today;
    toInput.value = today;
  } else if (preset === 'yesterday') {
    const yest = new Date();
    yest.setDate(yest.getDate() - 1);
    const yestStr = yest.toISOString().split('T')[0];
    fromInput.value = yestStr;
    toInput.value = yestStr;
  } else if (preset === 'this_week') {
    const d = new Date();
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1); // Monday
    const monday = new Date(d.setDate(diff)).toISOString().split('T')[0];
    fromInput.value = monday;
    toInput.value = today;
  } else if (preset === 'this_month') {
    const d = new Date();
    const firstDay = new Date(d.getFullYear(), d.getMonth(), 1).toISOString().split('T')[0];
    fromInput.value = firstDay;
    toInput.value = today;
  }
}

function toggleFilterDrawer() {
  const card = document.getElementById('filterCard');
  const icon = document.getElementById('drawerStateIcon');
  card.classList.toggle('open');
  icon.textContent = card.classList.contains('open') ? '▲' : '▼';
}

function applyFilters(e) {
  if (e) e.preventDefault();
  const form = document.getElementById('reportFilterForm');
  const formData = new FormData(form);
  const params = new URLSearchParams(formData).toString();

  const applyBtn = document.getElementById('applyFilterBtn');
  const oldText = applyBtn.innerHTML;
  applyBtn.innerHTML = '⏳ Applying...';
  applyBtn.disabled = true;

  fetch(`<?= BASE_URL ?>/api/get_reports_data.php?${params}`)
    .then(res => res.json())
    .then(data => {
      applyBtn.innerHTML = oldText;
      applyBtn.disabled = false;

      if (!data.success) return;

      // 1. Update KPIs
      if (document.getElementById('kpiTotRev')) document.getElementById('kpiTotRev').textContent = data.kpis.total_revenue;
      if (document.getElementById('kpiInvCount')) document.getElementById('kpiInvCount').textContent = data.kpis.invoices_count;
      if (document.getElementById('kpiActualRev')) document.getElementById('kpiActualRev').textContent = data.kpis.actual_room_rev;
      if (document.getElementById('kpiCommPayable')) document.getElementById('kpiCommPayable').textContent = data.kpis.commission_payable;

      // 2. Bookings by Source
      const bodySrc = document.getElementById('bodyBySource');
      if (data.bySource.length === 0) {
        bodySrc.innerHTML = '<tr><td colspan="3"><div class="empty-state">No source records found.</div></td></tr>';
      } else {
        bodySrc.innerHTML = data.bySource.map(s => `
          <tr>
            <td>${escapeHtml(s.booking_source.replace(/_/g,' ').toUpperCase())}</td>
            <td><strong>${s.c}</strong></td>
            <td>₹${parseFloat(s.commission).toFixed(2)}</td>
          </tr>
        `).join('');
      }

      // 3. Revenue by Room
      const bodyRm = document.getElementById('bodyByRoom');
      if (data.byRoom.length === 0) {
        bodyRm.innerHTML = '<tr><td colspan="4"><div class="empty-state">No room revenue data found.</div></td></tr>';
      } else {
        bodyRm.innerHTML = data.byRoom.map(r => `
          <tr>
            <td><strong>Room ${escapeHtml(r.room_number)}</strong></td>
            <td>Floor ${escapeHtml(r.floor)} &bull; ${escapeHtml(r.room_type_name)}</td>
            <td>${r.bookings_count}</td>
            <td><strong>₹${parseFloat(r.revenue).toFixed(2)}</strong></td>
          </tr>
        `).join('');
      }

      // 4. Booking Type Breakdown
      const bodyTb = document.getElementById('bodyTypeBreakdown');
      if (data.typeBreakdown.length === 0) {
        bodyTb.innerHTML = '<tr><td colspan="3"><div class="empty-state">No booking type records.</div></td></tr>';
      } else {
        bodyTb.innerHTML = data.typeBreakdown.map(tb => `
          <tr>
            <td>
              ${tb.booking_type === 'corporate' ? '<span class="badge badge-gold">🏢 Corporate Booking</span>' : '<span class="badge badge-gray">👤 Regular Guest</span>'}
            </td>
            <td><strong>${tb.cnt}</strong></td>
            <td><strong>₹${parseFloat(tb.total_rev).toFixed(2)}</strong></td>
          </tr>
        `).join('');
      }

      // 5. Invoices Table
      const bodyInv = document.getElementById('bodyInvoices');
      if (data.invoices.length === 0) {
        bodyInv.innerHTML = '<tr><td colspan="10"><div class="empty-state">No matching invoices found for applied filters.</div></td></tr>';
      } else {
        const canComm = <?= canViewCommission() ? 'true' : 'false' ?>;
        bodyInv.innerHTML = data.invoices.map(inv => {
          const netRev = parseFloat(inv.room_charges - inv.commission_amount).toFixed(2);
          const commVal = parseFloat(inv.commission_amount);
          const commHtml = commVal > 0 ? `<span class="badge badge-commission">💰 ₹${commVal.toFixed(2)}</span>` : '<span class="text-muted">—</span>';
          
          return `
            <tr>
              <td>${escapeHtml(inv.invoice_number)}</td>
              <td>
                <strong>${escapeHtml(inv.guest_name)}</strong>
                ${inv.booking_type === 'corporate' ? `<br><small style="color:var(--gold); font-weight:600;">🏢 ${escapeHtml(inv.company_name)}</small>` : ''}
              </td>
              <td>${escapeHtml(inv.booking_code)}</td>
              ${canComm ? `<td>₹${netRev}</td><td>${commHtml}</td>` : ''}
              <td><strong>₹${parseFloat(inv.total_amount).toFixed(2)}</strong></td>
              <td>₹${parseFloat(inv.paid_amount).toFixed(2)}</td>
              <td>₹${parseFloat(inv.balance_amount).toFixed(2)}</td>
              <td class="nowrap">${escapeHtml(inv.created_at.split(' ')[0])}</td>
              <td><a href="<?= BASE_URL ?>/invoice_print.php?id=${inv.id}" class="btn btn-sm btn-outline">View</a></td>
            </tr>
          `;
        }).join('');
      }
    })
    .catch(err => {
      applyBtn.innerHTML = oldText;
      applyBtn.disabled = false;
      console.error('Filter Error:', err);
    });
}

function resetFilters() {
  document.getElementById('reportFilterForm').reset();
  document.getElementById('presetSelect').value = 'this_month';
  handlePresetChange('this_month');
  applyFilters();
}

function exportToExcel(category = 'all') {
  const form = document.getElementById('reportFilterForm');
  const formData = new FormData(form);
  formData.append('category', category);
  const params = new URLSearchParams(formData).toString();

  const btn = document.getElementById('mainExportBtn');
  const icon = btn.querySelector('.export-icon');
  const text = btn.querySelector('.export-text');

  const oldIcon = icon.textContent;
  const oldText = text.textContent;

  icon.textContent = '⏳';
  text.textContent = 'Generating Excel...';
  btn.disabled = true;

  window.location.href = `<?= BASE_URL ?>/export_reports.php?${params}`;

  setTimeout(() => {
    icon.textContent = oldIcon;
    text.textContent = oldText;
    btn.disabled = false;
  }, 2500);
}

function shareReport(channel) {
  // Build summary text from currently displayed KPIs
  const totalRev   = document.getElementById('kpiTotRev')   ? document.getElementById('kpiTotRev').textContent   : '—';
  const invCount   = document.getElementById('kpiInvCount') ? document.getElementById('kpiInvCount').textContent : '—';
  const actualRev  = document.getElementById('kpiActualRev')  ? document.getElementById('kpiActualRev').textContent  : null;
  const commPayable= document.getElementById('kpiCommPayable')? document.getElementById('kpiCommPayable').textContent: null;

  // Build filter description
  const preset     = document.getElementById('presetSelect')   ? document.getElementById('presetSelect').options[document.getElementById('presetSelect').selectedIndex].text : '';
  const fromDate   = document.getElementById('fromDate')       ? document.getElementById('fromDate').value   : '';
  const toDate     = document.getElementById('toDate')         ? document.getElementById('toDate').value     : '';
  const periodText = preset ? preset.replace(/[^a-zA-Z0-9\s]/g,'').trim() : (fromDate + ' to ' + toDate);

  // Build export link with current filters
  const form   = document.getElementById('reportFilterForm');
  const params = new URLSearchParams(new FormData(form)).toString();
  const exportUrl = `<?= BASE_URL ?>/export_reports.php?${params}`;

  const lines = [
    '🏨 Hotel Sai Nest — Report Summary',
    '📅 Period: ' + periodText,
    '─────────────────────────',
    '💰 Total Revenue: ' + totalRev,
    '🧾 Invoices Generated: ' + invCount,
  ];
  if (actualRev)   lines.push('🏨 Actual Room Revenue: ' + actualRev);
  if (commPayable) lines.push('🤝 Commission Payable: ' + commPayable);
  lines.push('─────────────────────────');
  lines.push('📊 Download Excel Report: ' + exportUrl);

  const message = lines.join('\n');

  if (channel === 'whatsapp') {
    const waUrl = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(message);
    window.open(waUrl, '_blank');
  } else if (channel === 'email') {
    const subject = encodeURIComponent('Hotel Sai Nest — Report Summary (' + periodText + ')');
    const body    = encodeURIComponent(message);
    window.location.href = 'mailto:?subject=' + subject + '&body=' + body;
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', () => {
  applyFilters();
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
