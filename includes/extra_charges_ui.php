<div class="card" style="margin-bottom:24px;">
  <div class="card-head"><h3>➕ Additional Charges</h3></div>
  <div style="background:var(--cream-light, #faf9f5); border:1.5px solid var(--border-color, #e5e0d8); border-radius:8px; padding:16px; margin-bottom:18px;">
    <div class="form-row" style="align-items:end;">
      <div class="form-group" style="flex:2;">
        <label>Additional Charge *</label>
        <select id="presetSelect" class="form-control">
          <option value="">-- Select Additional Charge --</option>
          <option value="Tea" data-price="50">Tea</option>
          <option value="Coffee / Milk" data-price="40">Coffee / Milk</option>
          <option value="Extra Bed" data-price="500">Extra Bed</option>
        </select>
      </div>
      <div class="form-group" style="flex:2;" id="customNameGroup">
        <label>Item Name / Description *</label>
        <input type="text" id="chargeNameInput" class="form-control" placeholder="Select from dropdown or type...">
      </div>
      <div class="form-group" style="flex:1;">
        <label>Qty *</label>
        <input type="number" id="qtyInput" class="form-control" value="1" min="0.1" step="0.1" oninput="recalcChargeTotal()">
      </div>
      <div class="form-group" style="flex:1.5;">
        <label>Amount (₹) *</label>
        <input type="number" id="priceInput" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="recalcChargeTotal()">
      </div>
      <div class="form-group" style="flex:1.5;">
        <label>Total (₹)</label>
        <input type="number" id="totalInput" class="form-control" readonly style="background:#f5f3ef; font-weight:bold;">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;">
        <label>Remarks / Notes (optional)</label>
        <input type="text" id="remarksInput" class="form-control" placeholder="Optional notes">
      </div>
      <div class="form-group" style="align-self:flex-end; max-width:180px;">
        <button type="button" id="addChargeToListBtn" class="btn btn-gold" style="width:100%; white-space:nowrap;">➕ Add Another Charge</button>
      </div>
    </div>
    <div id="chargeFormError" style="display:none; margin-top:8px; font-size:0.88rem; color:var(--red, #d9534f); font-weight:bold;"></div>
  </div>

  <div style="margin-bottom:20px;">
    <label style="font-weight:700; margin-bottom:8px; display:block;">Selected Additional Charges</label>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Item / Charge</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Amount (₹)</th>
            <th class="text-right">Total (₹)</th>
            <th>Remarks</th>
            <th style="width:80px; text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody id="newChargesTableBody">
          <tr id="emptyNewChargesRow">
            <td colspan="6" class="text-muted" style="text-align:center; padding:18px;">
              No additional charges added. Use the form above to add charges.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div id="hiddenChargeInputs"></div>
  </div>
</div>

<script>
const presetSelect = document.getElementById('presetSelect');
const chargeNameInput = document.getElementById('chargeNameInput');
const qtyInput = document.getElementById('qtyInput');
const priceInput = document.getElementById('priceInput');
const totalInput = document.getElementById('totalInput');
const remarksInput = document.getElementById('remarksInput');
const addBtn = document.getElementById('addChargeToListBtn');
const errBox = document.getElementById('chargeFormError');
const tableBody = document.getElementById('newChargesTableBody');
const hiddenInputs = document.getElementById('hiddenChargeInputs');

if (presetSelect) {
  presetSelect.addEventListener('change', function () {
    if (this.value) {
      chargeNameInput.value = this.value;
      const opt = this.options[this.selectedIndex];
      if (opt.dataset.price) {
        priceInput.value = opt.dataset.price;
      }
      recalcChargeTotal();
    }
  });
}

function recalcChargeTotal() {
  const qty = parseFloat(qtyInput.value) || 0;
  const price = parseFloat(priceInput.value) || 0;
  if(totalInput) totalInput.value = (qty * price).toFixed(2);
}

const pendingCharges = [];

function renderPendingCharges() {
  if (!tableBody || !hiddenInputs) return;
  tableBody.innerHTML = '';
  hiddenInputs.innerHTML = '';

  if (pendingCharges.length === 0) {
    tableBody.innerHTML = '<tr id="emptyNewChargesRow"><td colspan="6" class="text-muted" style="text-align:center; padding:18px;">No additional charges added. Use the form above to add charges.</td></tr>';
    return;
  }

  pendingCharges.forEach((c, idx) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><strong>${escapeHtml(c.name)}</strong></td>
      <td class="text-right">${c.qty}</td>
      <td class="text-right">₹${c.price.toFixed(2)}</td>
      <td class="text-right"><strong>₹${c.total.toFixed(2)}</strong></td>
      <td>${escapeHtml(c.remarks || '—')}</td>
      <td style="text-align:right;">
        <button type="button" class="btn btn-sm btn-red" onclick="removePendingCharge(${idx})">✕ Remove</button>
      </td>
    `;
    tableBody.appendChild(tr);

    const hiddenName = document.createElement('input');
    hiddenName.type = 'hidden';
    hiddenName.name = 'charge_name[]';
    hiddenName.value = c.name;
    hiddenInputs.appendChild(hiddenName);

    const hiddenQty = document.createElement('input');
    hiddenQty.type = 'hidden';
    hiddenQty.name = 'charge_qty[]';
    hiddenQty.value = c.qty;
    hiddenInputs.appendChild(hiddenQty);

    const hiddenPrice = document.createElement('input');
    hiddenPrice.type = 'hidden';
    hiddenPrice.name = 'charge_price[]';
    hiddenPrice.value = c.price;
    hiddenInputs.appendChild(hiddenPrice);

    const hiddenRemarks = document.createElement('input');
    hiddenRemarks.type = 'hidden';
    hiddenRemarks.name = 'charge_remarks[]';
    hiddenRemarks.value = c.remarks;
    hiddenInputs.appendChild(hiddenRemarks);
  });
}

function removePendingCharge(index) {
  pendingCharges.splice(index, 1);
  renderPendingCharges();
}

if (addBtn) {
  addBtn.addEventListener('click', function () {
    if (errBox) errBox.style.display = 'none';

    const name = chargeNameInput.value.trim();
    const qty = parseFloat(qtyInput.value) || 0;
    const price = parseFloat(priceInput.value) || 0;
    const remarks = remarksInput.value.trim();

    if (!name) {
      if (errBox) { errBox.textContent = 'Please choose a charge type or type an item name.'; errBox.style.display = 'block'; }
      return;
    }
    if (qty <= 0) {
      if (errBox) { errBox.textContent = 'Please enter a valid quantity.'; errBox.style.display = 'block'; }
      return;
    }
    if (price < 0) {
      if (errBox) { errBox.textContent = 'Unit price cannot be negative.'; errBox.style.display = 'block'; }
      return;
    }

    pendingCharges.push({
      name: name,
      qty: qty,
      price: price,
      total: qty * price,
      remarks: remarks
    });

    renderPendingCharges();

    presetSelect.value = '';
    chargeNameInput.value = '';
    qtyInput.value = '1';
    priceInput.value = '';
    if(totalInput) totalInput.value = '';
    remarksInput.value = '';
  });
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
         .toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}
</script>
