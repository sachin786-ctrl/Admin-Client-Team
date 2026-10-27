<?php if (defined('RP_MODAL_LOADED')) return; define('RP_MODAL_LOADED', true); ?>
<?php /* admin/footer.php me ek hi baar include karein. Dashboard, list, project, add_payment sab isi ko kholenge. */ ?>
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" action="/client-flow/admin/payments/mark_paid.php" class="modal-content" id="recordPaymentForm">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
      <input type="hidden" name="id" id="rpId">
      <input type="hidden" name="redirect" id="rpRedirect" value="">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cash-coin text-success me-2"></i>Record Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="bg-light border rounded p-3 mb-3">
          <div class="d-flex justify-content-between">
            <span class="badge bg-light text-dark border font-monospace" id="rpInvoice">INV</span>
            <small class="text-muted" id="rpClient"></small>
          </div>
          <div class="fw-semibold mt-1" id="rpTitle"></div>
          <div class="row text-center mt-2 g-2">
            <div class="col-4"><small class="text-muted d-block">Total</small><strong id="rpTotal">₹0</strong></div>
            <div class="col-4"><small class="text-success d-block">Paid</small><strong class="text-success" id="rpPaid">₹0</strong></div>
            <div class="col-4"><small class="text-danger d-block">Due</small><strong class="text-danger" id="rpDue">₹0</strong></div>
          </div>
        </div>

        <div class="mb-3">
          <div class="d-flex justify-content-between">
            <label class="form-label fw-semibold mb-1">Amount Received <span class="text-danger">*</span></label>
            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="rpFullBtn">Full due</button>
          </div>
          <input type="number" step="0.01" min="0.01" name="paid_amount" id="rpAmount" class="form-control fw-bold" required>
          <p class="text-danger small mt-1 mb-0 d-none" id="rpAmountError"></p>
          <small class="text-muted" id="rpAfter"></small>
        </div>

        <div class="row g-3">
          <div class="col-6">
            <label class="form-label fw-semibold">Date Received <span class="text-danger">*</span></label>
            <input type="date" name="paid_date" id="rpDate" class="form-control" required>
            <p class="text-danger small mt-1 mb-0 d-none" id="rpDateError"></p>
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold">Method <span class="text-danger">*</span></label>
            <select name="payment_method" id="rpMethod" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach (PAYMENT_METHODS as $m): ?>
                <option value="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($m); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-danger small mt-1 mb-0 d-none" id="rpMethodError"></p>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Transaction / UTR ID <small class="text-muted">Optional</small></label>
            <input type="text" name="transaction_id" maxlength="100" class="form-control" placeholder="e.g. UPI ref no.">
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success fw-bold" id="rpSubmit"><i class="bi bi-check-circle-fill me-1"></i> Save Payment</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modalEl = document.getElementById('recordPaymentModal');
  if (!modalEl) return;
  const modal  = new bootstrap.Modal(modalEl);
  const amount = document.getElementById('rpAmount');
  const after  = document.getElementById('rpAfter');
  const date   = document.getElementById('rpDate');
  const money  = n => '₹' + Number(n).toLocaleString('en-IN', {minimumFractionDigits: 2});
  let due = 0, total = 0, paid = 0;

  function refresh() {
    const v = parseFloat(amount.value) || 0;
    const submit = document.getElementById('rpSubmit');
    if (v > due + 0.005) {
      after.className = 'text-danger fw-semibold';
      after.textContent = 'Amount exceeds balance due: ' + money(due);
      submit.disabled = true;
    } else {
      const left = Math.max(0, due - v);
      after.className = left === 0 ? 'text-success fw-semibold' : 'text-muted';
      after.textContent = left === 0 ? 'Invoice fully settled.' : 'Remaining due: ' + money(left);
      submit.disabled = v <= 0;
    }
  }

  // Dashboard / list / project / add_payment: jahan bhi .btn-record-payment hai
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-record-payment');
    if (!btn) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const d = btn.dataset;
    total = parseFloat(d.total) || 0; paid = parseFloat(d.paid) || 0; due = parseFloat(d.due) || 0;

    const idInput = document.getElementById('rpId');
    if (idInput) idInput.value = d.payId || '';
    const redirInput = document.getElementById('rpRedirect');
    if (redirInput) redirInput.value = d.redirect || '';
    const invEl = document.getElementById('rpInvoice');
    if (invEl) invEl.textContent = d.invoice || 'INV';
    const clientEl = document.getElementById('rpClient');
    if (clientEl) clientEl.textContent = d.client || '';
    const titleEl = document.getElementById('rpTitle');
    if (titleEl) titleEl.textContent = d.title || '';
    const totalEl = document.getElementById('rpTotal');
    if (totalEl) totalEl.textContent = money(total);
    const paidEl = document.getElementById('rpPaid');
    if (paidEl) paidEl.textContent = money(paid);
    const dueEl = document.getElementById('rpDue');
    if (dueEl) dueEl.textContent = money(due);

    if (amount) {
      amount.value = due > 0 ? due.toFixed(2) : '';
      amount.max = due.toFixed(2);
    }
    if (date) {
      const today = new Date().toLocaleDateString('en-CA'); // local YYYY-MM-DD
      date.value = today;
      if (d.projectCreated) {
        date.min = d.projectCreated;
      } else {
        date.removeAttribute('min');
      }
      date.max = new Date().toISOString().slice(0, 10);
    }
    refresh();
    modal.show();
    setTimeout(() => { if (amount) { amount.focus(); amount.select(); } }, 300);
  });

  document.getElementById('rpFullBtn')?.addEventListener('click', function () {
    if (amount) {
      amount.value = due.toFixed(2);
      refresh();
    }
  });

  function setModalFieldError(pId, inputElem, msg) {
    const p = document.getElementById(pId);
    if (p) {
      p.textContent = msg;
      p.classList.remove('d-none');
    }
    if (inputElem) {
      inputElem.classList.add('is-invalid');
    }
  }

  function clearModalFieldValidation(pId, inputElem) {
    const p = document.getElementById(pId);
    if (p) {
      p.textContent = '';
      p.classList.add('d-none');
    }
    if (inputElem) {
      inputElem.classList.remove('is-invalid');
    }
  }

  if (amount) {
    amount.addEventListener('input', function() {
      clearModalFieldValidation('rpAmountError', amount);
      refresh();
    });
  }

  const methodSelect = document.getElementById('rpMethod');
  if (methodSelect) {
    methodSelect.addEventListener('change', function() {
      clearModalFieldValidation('rpMethodError', methodSelect);
    });
  }
  if (date) {
    date.addEventListener('change', function() {
      clearModalFieldValidation('rpDateError', date);
    });
  }

  const modalForm = document.getElementById('recordPaymentForm');
  if (modalForm) {
    modalForm.addEventListener('submit', function (e) {
      let isValid = true;
      const v = parseFloat(amount.value) || 0;
      if (v <= 0) {
        setModalFieldError('rpAmountError', amount, 'Amount Received is required and must be greater than 0.');
        isValid = false;
      } else if (v > due + 0.005) {
        setModalFieldError('rpAmountError', amount, 'Amount cannot exceed balance due: ' + money(due));
        isValid = false;
      } else {
        clearModalFieldValidation('rpAmountError', amount);
      }

      if (!date.value) {
        setModalFieldError('rpDateError', date, 'Date Received is required.');
        isValid = false;
      } else {
        clearModalFieldValidation('rpDateError', date);
      }

      if (!methodSelect.value) {
        setModalFieldError('rpMethodError', methodSelect, 'Payment Method is required.');
        isValid = false;
      } else {
        clearModalFieldValidation('rpMethodError', methodSelect);
      }

      if (!isValid) {
        e.preventDefault();
        e.stopPropagation();
        const firstInvalid = modalForm.querySelector('.is-invalid');
        if (firstInvalid) firstInvalid.focus();
      }
    });
  }
});
</script>
