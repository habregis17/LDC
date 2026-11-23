<?php
if (!defined('BASE_URL')) {
    exit;
}
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Sidebar Navigation Component -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <i class="fa-solid fa-shield-virus fa-2x"></i>
    </div>
    <nav>
        <a href="<?= BASE_URL ?>Home/" class="<?= $current_page === 'Home/' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-line"></i> Home
        </a>
        <?php if (isset($_SESSION['role_code']) && $_SESSION['role_code'] === 'PRIMARY_VERIFIER'): ?>
            <a href="javascript:void(0)" onclick="openAddDrawModal()" class="<?= $current_page === 'Home/Draws/Initiate' ? 'active' : '' ?>">
                <i class="fa-solid fa-plus-circle"></i> New Draw Verification
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>Home/Draws/" class="<?= $current_page === 'Draws/' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-contract"></i> Certified Draws
        </a>
        <?php if (isset($_SESSION['role_code']) && $_SESSION['role_code'] === 'ADMIN'): ?>
            <a href="<?= BASE_URL ?>Home/Users/" class="<?= $current_page === 'Users/' ? 'active' : '' ?>">
                <i class="fa-solid fa-users-gear"></i> Manage System Users
            </a>
        <?php endif; ?>
    </nav>
</aside>
   <!-- Add Draw Session Date Modal -->
<div id="add-draw-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; width:100%; max-width:430px; border-radius:6px; border-top:5px solid var(--red); padding:22px; box-shadow:0 12px 30px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 style="margin:0; color:var(--charcoal); font-size:1.05rem;">
                <i class="fa-solid fa-calendar-plus"></i> Select Draw Date
            </h3>
            <button type="button" onclick="closeAddDrawModal()" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--slate);">&times;</button>
        </div>

        <p style="font-size:0.82rem; color:var(--slate); margin:0 0 14px;">
            Choose the draw date first. We will create the draw record, generate its hashcode, and open the verification page with the date locked.
        </p>

        <div style="margin-bottom:14px;">
            <label for="modal_draw_date" style="display:block; font-size:0.85rem; font-weight:700; color:var(--charcoal); margin-bottom:5px;">
                Draw Date
            </label>
            <input type="date" id="modal_draw_date" value="<?= date('Y-m-d') ?>" required
                   style="width:100%; padding:9px; border:1px solid var(--pale-charcoal); border-radius:4px; font-size:0.9rem;">
        </div>

        <div style="margin-bottom:18px;">
            <label for="modal_draw_session" style="display:block; font-size:0.85rem; font-weight:700; color:var(--charcoal); margin-bottom:5px;">
                Draw Session Name <span style="font-weight:400; color:var(--slate);">(optional now)</span>
            </label>
            <input type="text" id="modal_draw_session" placeholder="e.g. Intego 5/90 Evening Main Draw"
                   style="width:100%; padding:9px; border:1px solid var(--pale-charcoal); border-radius:4px; font-size:0.9rem;">
        </div>

        <div id="modal-draw-error" style="display:none; background:#fdecea; color:#842029; border:1px solid #f5c2c7; padding:8px 10px; border-radius:4px; font-size:0.8rem; margin-bottom:14px;"></div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" onclick="closeAddDrawModal()" style="background:var(--pale-charcoal); color:var(--charcoal); border:none; padding:9px 14px; border-radius:4px; font-weight:600; cursor:pointer;">
                Cancel
            </button>
            <button type="button" id="modal-create-draw-btn" onclick="submitPrecreateDraw()" style="background:var(--red); color:#fff; border:none; padding:9px 16px; border-radius:4px; font-weight:700; cursor:pointer;">
                <i class="fa-solid fa-arrow-right"></i> Create &amp; Open Initiate
            </button>
        </div>
    </div>
</div>

<script>
function openAddDrawModal() {
    const modal = document.getElementById('add-draw-modal');
    const errBox = document.getElementById('modal-draw-error');
    if (errBox) {
        errBox.style.display = 'none';
        errBox.textContent = '';
    }
    if (modal) modal.style.display = 'flex';
}

function closeAddDrawModal() {
    const modal = document.getElementById('add-draw-modal');
    if (modal) modal.style.display = 'none';
}

function submitPrecreateDraw() {
    const dateEl = document.getElementById('modal_draw_date');
    const sessEl = document.getElementById('modal_draw_session');
    const btn = document.getElementById('modal-create-draw-btn');
    const errBox = document.getElementById('modal-draw-error');

    const chosenDate = dateEl ? dateEl.value.trim() : '';
    const sessionName = sessEl ? sessEl.value.trim() : '';

    if (!chosenDate) {
        if (errBox) {
            errBox.textContent = 'Please select a draw date.';
            errBox.style.display = 'block';
        }
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating...';
    }

    const fd = new FormData();
    fd.append('ajax_action', 'precreate_draw');
    fd.append('draw_date', chosenDate);
    fd.append('draw_session', sessionName);

    fetch('<?= BASE_URL ?>Home/Draws/Initiate/', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    })
    .then(async res => {
        const txt = await res.text();
        try {
            return JSON.parse(txt);
        } catch (e) {
            throw new Error(txt.substring(0, 160) || 'Invalid server response');
        }
    })
    .then(data => {
        if (data.status === 'success' && data.draw_hash_code) {
            window.location.href = '<?= BASE_URL ?>Home/Draws/Initiate/?hash=' + encodeURIComponent(data.draw_hash_code);
        } else {
            throw new Error(data.message || 'Could not pre-create draw.');
        }
    })
    .catch(err => {
        if (errBox) {
            errBox.textContent = err.message;
            errBox.style.display = 'block';
        }
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-arrow-right"></i> Create &amp; Open Initiate';
        }
    });
}
</script>
