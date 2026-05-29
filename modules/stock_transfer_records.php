<?php
require_once __DIR__ . '/../config.php';

$isAdmin = is_admin_user();
$canViewTransferRecords = $isAdmin || has_permission('inventory.transfer_record.view') || has_permission('inventory.transfer.reverse');
$canReverseTransfer = $isAdmin || has_permission('inventory.transfer.reverse');
$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentUsername = trim((string)($_SESSION['username'] ?? ''));
if($currentUsername === '') $currentUsername = 'User';

if(!$canViewTransferRecords){
    flash_msg('You do not have permission to view stock transfer records.', 'error');
    redirect_with_fallback('?module=sale');
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()){
    $action = (string)($_POST['action'] ?? '');
    if($action === 'reverse_transfer'){
        if(!$canReverseTransfer){
            flash_msg('You do not have permission to reverse stock transfer.', 'error');
            redirect_with_fallback('?module=stock_transfer_records');
        }

        $transferId = (int)($_POST['transfer_id'] ?? 0);
        $returnQty = (float)($_POST['return_qty'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));

        try {
            $pdo->beginTransaction();

            if($transferId <= 0){
                throw new Exception('Invalid transfer selected.');
            }
            if($returnQty <= 0){
                throw new Exception('Return quantity must be greater than zero.');
            }
            if($note === ''){
                throw new Exception('Remarks are required for reverse stock transfer.');
            }

            $transferStmt = $pdo->prepare("SELECT * FROM stock_transfers WHERE id=? LIMIT 1 FOR UPDATE");
            $transferStmt->execute([$transferId]);
            $transfer = $transferStmt->fetch();
            if(!$transfer){
                throw new Exception('Transfer record not found.');
            }

            $transferredQty = (float)($transfer['transferred_qty'] ?? 0);
            $alreadyReversed = (float)($transfer['reversed_qty'] ?? 0);
            $remainingQty = max($transferredQty - $alreadyReversed, 0);

            if($remainingQty <= 0){
                throw new Exception('This transfer is already fully reversed.');
            }
            if($returnQty > $remainingQty){
                throw new Exception('Return quantity exceeds remaining transferable quantity.');
            }

            $sourceBatchId = (int)($transfer['source_batch_id'] ?? 0);
            $destBatchId = (int)($transfer['destination_batch_id'] ?? 0);
            if($sourceBatchId <= 0 || $destBatchId <= 0){
                throw new Exception('Invalid transfer batch mapping.');
            }

            $sourceBatchStmt = $pdo->prepare("SELECT id FROM batches WHERE id=? LIMIT 1 FOR UPDATE");
            $sourceBatchStmt->execute([$sourceBatchId]);
            if(!$sourceBatchStmt->fetch()){
                throw new Exception('Source batch not found for this transfer.');
            }

            $destBatchStmt = $pdo->prepare("SELECT id, quantity FROM batches WHERE id=? LIMIT 1 FOR UPDATE");
            $destBatchStmt->execute([$destBatchId]);
            $destBatch = $destBatchStmt->fetch();
            if(!$destBatch){
                throw new Exception('Destination batch not found for this transfer.');
            }

            $destQty = (float)($destBatch['quantity'] ?? 0);
            if($destQty < $returnQty){
                throw new Exception('Destination branch currently does not have enough quantity for this reverse action.');
            }

            $pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id=?")->execute([$returnQty, $destBatchId]);
            $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id=?")->execute([$returnQty, $sourceBatchId]);

            $newReversedQty = $alreadyReversed + $returnQty;
            $newStatus = $newReversedQty >= $transferredQty ? 'reversed' : 'partial_return';

            $updTransfer = $pdo->prepare("UPDATE stock_transfers SET reversed_qty=?, status=? WHERE id=? LIMIT 1");
            $updTransfer->execute([$newReversedQty, $newStatus, $transferId]);

            $insReturn = $pdo->prepare("INSERT INTO stock_transfer_returns(stock_transfer_id, return_qty, returned_by_user_id, returned_by_username, note, created_at) VALUES(?,?,?,?,?,?)");
            $insReturn->execute([
                $transferId,
                $returnQty,
                $currentUserId > 0 ? $currentUserId : null,
                $currentUsername,
                $note !== '' ? $note : null,
                date('Y-m-d H:i:s'),
            ]);

            audit_log_action(
                'inventory',
                'reverse_transfer_stock',
                'Reversed stock transfer quantity.',
                [
                    'stock_transfer_id' => $transferId,
                    'return_qty' => $returnQty,
                    'new_reversed_qty' => $newReversedQty,
                    'status' => $newStatus,
                    'note' => $note,
                ],
                'stock_transfer',
                $transferId
            );

            $pdo->commit();
            flash_msg('Transfer reversed successfully.');
            redirect_with_fallback('?module=stock_transfer_records');
        } catch(Exception $e){
            if($pdo->inTransaction()){
                $pdo->rollBack();
            }
            flash_msg($e->getMessage(), 'error');
            redirect_with_fallback('?module=stock_transfer_records');
        }
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
if(!in_array($statusFilter, ['all', 'completed', 'partial_return', 'reversed'], true)){
    $statusFilter = 'all';
}

$where = ['1=1'];
$params = [];
if($search !== ''){
    $where[] = '(p.name LIKE ? OR bf.name LIKE ? OR bt.name LIKE ? OR COALESCE(st.created_by_username,"") LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if($statusFilter !== 'all'){
    $where[] = 'st.status = ?';
    $params[] = $statusFilter;
}

$sql = "SELECT st.*, p.name AS product_name,
               bf.name AS from_branch_name,
               bt.name AS to_branch_name,
               b1.batch_no AS source_batch_no,
               b2.batch_no AS destination_batch_no
        FROM stock_transfers st
        LEFT JOIN products p ON p.id=st.product_id
        LEFT JOIN branches bf ON bf.id=st.from_branch_id
        LEFT JOIN branches bt ON bt.id=st.to_branch_id
        LEFT JOIN batches b1 ON b1.id=st.source_batch_id
        LEFT JOIN batches b2 ON b2.id=st.destination_batch_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY st.created_at DESC, st.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$rowsMeta = paginate_array($rows, 'transfer_page', 15);
$rows = $rowsMeta['rows'];

$transferIds = [];
foreach($rows as $r){
    $transferIds[] = (int)$r['id'];
}
$returnMap = [];
if(!empty($transferIds)){
    $ph = implode(',', array_fill(0, count($transferIds), '?'));
    $retStmt = $pdo->prepare("SELECT stock_transfer_id, return_qty, returned_by_username, note, created_at FROM stock_transfer_returns WHERE stock_transfer_id IN ($ph) ORDER BY id DESC");
    $retStmt->execute($transferIds);
    foreach($retStmt->fetchAll() as $rr){
        $tid = (int)($rr['stock_transfer_id'] ?? 0);
        if(!isset($returnMap[$tid])) $returnMap[$tid] = [];
        $returnMap[$tid][] = $rr;
    }
}

$f = flash_msg();
?>
<div class="space-y-6">
    <?php if($f): ?><div class="p-3 rounded-lg text-sm border <?= $f['type']=='error'?'bg-red-50 text-red-700 border-red-200':'bg-emerald-50 text-emerald-700 border-emerald-200' ?>"><?= e((string)$f['msg']) ?></div><?php endif; ?>

    <section class="bg-white p-5 rounded-2xl shadow border border-slate-100">
        <form method="GET" class="grid md:grid-cols-4 gap-3 items-end">
            <input type="hidden" name="module" value="stock_transfer_records">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Product, branch, or user" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Status</label>
                <select name="status" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="partial_return" <?= $statusFilter === 'partial_return' ? 'selected' : '' ?>>Partial Return</option>
                    <option value="reversed" <?= $statusFilter === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Filter</button>
                <a href="?module=stock_transfer_records" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2.5 rounded-lg text-sm font-medium">Reset</a>
            </div>
        </form>
    </section>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="font-semibold text-slate-800">Stock Log</h3>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Date</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Product</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">From -> To</th>
                        <th class="px-4 py-3 text-right font-semibold text-slate-700">Transferred</th>
                        <th class="px-4 py-3 text-right font-semibold text-slate-700">Reversed</th>
                        <th class="px-4 py-3 text-right font-semibold text-slate-700">Remaining</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($rows)): ?>
                    <tr><td colspan="8" class="px-6 py-8 text-center text-slate-500">No transfer records found.</td></tr>
                <?php else: ?>
                    <?php foreach($rows as $r): ?>
                        <?php
                            $qty = (float)($r['transferred_qty'] ?? 0);
                            $rev = (float)($r['reversed_qty'] ?? 0);
                            $remaining = max($qty - $rev, 0);
                            $status = strtolower((string)($r['status'] ?? 'completed'));
                        ?>
                        <tr class="border-b border-slate-100 align-top">
                            <td class="px-4 py-3.5 text-slate-700"><?= e((string)($r['created_at'] ?? '')) ?></td>
                            <td class="px-4 py-3.5 text-slate-700">
                                <div><?= e((string)($r['product_name'] ?? 'Unknown Product')) ?></div>
                                <div class="text-xs text-slate-500">Src Batch: <?= e((string)($r['source_batch_no'] ?? '-')) ?> | Dest Batch: <?= e((string)($r['destination_batch_no'] ?? '-')) ?></div>
                            </td>
                            <td class="px-4 py-3.5 text-slate-700"><?= e((string)($r['from_branch_name'] ?? '-')) ?> -> <?= e((string)($r['to_branch_name'] ?? '-')) ?></td>
                            <td class="px-4 py-3.5 text-right text-slate-900"><?= e((string)$qty) ?></td>
                            <td class="px-4 py-3.5 text-right text-slate-900"><?= e((string)$rev) ?></td>
                            <td class="px-4 py-3.5 text-right text-slate-900"><?= e((string)$remaining) ?></td>
                            <td class="px-4 py-3.5">
                                <span class="px-2 py-0.5 rounded text-xs <?= $status === 'reversed' ? 'bg-emerald-100 text-emerald-700' : ($status === 'partial_return' ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700') ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span>
                                <div class="text-xs text-slate-500 mt-1">By: <?= e((string)($r['created_by_username'] ?? '-')) ?></div>
                            </td>
                            <td class="px-4 py-3.5">
                                <?php if($canReverseTransfer && $remaining > 0): ?>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-amber-100 text-amber-700 hover:bg-amber-200"
                                    title="Reverse Transfer"
                                    onclick="openReverseModal(this)"
                                    data-transfer-id="<?= (int)$r['id'] ?>"
                                    data-max-qty="<?= e((string)$remaining) ?>"
                                    data-product="<?= e((string)($r['product_name'] ?? 'Unknown Product')) ?>"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4v6h6M20 20v-6h-6"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 9a8 8 0 00-13.66-4.66L4 6m16 12l-2.34 1.66A8 8 0 014 15"/></svg>
                                </button>
                                <?php if($rev > 0): ?>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-sky-100 text-sky-700 hover:bg-sky-200 ml-1"
                                    title="View Reverse Details"
                                    onclick="openReverseDetailModal(this)"
                                    data-transfer-id="<?= (int)$r['id'] ?>"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3" stroke-width="1.8"/></svg>
                                </button>
                                <?php endif; ?>
                                <?php else: ?>
                                <?php if($rev > 0): ?>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-sky-100 text-sky-700 hover:bg-sky-200"
                                    title="View Reverse Details"
                                    onclick="openReverseDetailModal(this)"
                                    data-transfer-id="<?= (int)$r['id'] ?>"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3" stroke-width="1.8"/></svg>
                                </button>
                                <?php else: ?>
                                <span class="text-xs text-slate-500">No action</span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($rowsMeta) ?>
    </div>
</div>

<?php if($canReverseTransfer): ?>
<div id="reverseTransferModal" class="hidden fixed inset-0 bg-black/45 z-[90] items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-slate-200 shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-base font-semibold text-slate-800">Reverse Stock Transfer</h3>
            <button type="button" id="closeReverseModal" class="text-slate-500 hover:text-slate-700 text-xl leading-none">x</button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reverse_transfer">
            <input type="hidden" name="transfer_id" id="reverse_transfer_id" value="0">

            <div class="text-sm text-slate-700" id="reverse_product_label">Product: -</div>
            <div class="text-xs text-slate-500" id="reverse_max_label">Max reversible qty: 0</div>

            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Return Quantity</label>
                <input type="number" name="return_qty" id="reverse_return_qty" min="0.1" step="0.1" required class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" placeholder="0.0">
            </div>
            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Remarks <span class="text-red-500">*</span></label>
                <textarea name="note" id="reverse_note" required class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" rows="3" placeholder="Enter reason for reverse"></textarea>
            </div>
            <button type="submit" class="w-full bg-amber-600 hover:bg-amber-700 text-white py-2.5 rounded-lg text-sm font-medium">Submit Reverse</button>
        </form>
    </div>
</div>

<div id="reverseDetailModal" class="hidden fixed inset-0 bg-black/45 z-[95] items-center justify-center p-4">
    <div class="w-full max-w-2xl bg-white rounded-2xl border border-slate-200 shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-base font-semibold text-slate-800">Reverse Details</h3>
            <button type="button" id="closeReverseDetailModal" class="text-slate-500 hover:text-slate-700 text-xl leading-none">x</button>
        </div>
        <div class="p-5 space-y-3">
            <div id="reverseDetailBody" class="space-y-2 text-sm"></div>
        </div>
    </div>
</div>

<script>
var reverseModal = document.getElementById('reverseTransferModal');
var closeReverseModalBtn = document.getElementById('closeReverseModal');
var reverseDetailModal = document.getElementById('reverseDetailModal');
var closeReverseDetailModalBtn = document.getElementById('closeReverseDetailModal');
var reverseDetailBody = document.getElementById('reverseDetailBody');
var reverseHistoryMap = <?= json_encode($returnMap) ?>;

function openReverseModal(btn){
    if(!reverseModal) return;
    var transferId = btn.getAttribute('data-transfer-id') || '0';
    var maxQty = btn.getAttribute('data-max-qty') || '0';
    var product = btn.getAttribute('data-product') || '-';

    var idInput = document.getElementById('reverse_transfer_id');
    var qtyInput = document.getElementById('reverse_return_qty');
    var noteInput = document.getElementById('reverse_note');
    var productLabel = document.getElementById('reverse_product_label');
    var maxLabel = document.getElementById('reverse_max_label');

    if(idInput) idInput.value = transferId;
    if(qtyInput){
        qtyInput.value = '';
        qtyInput.setAttribute('max', maxQty);
    }
    if(noteInput) noteInput.value = '';
    if(productLabel) productLabel.textContent = 'Product: ' + product;
    if(maxLabel) maxLabel.textContent = 'Max reversible qty: ' + maxQty;

    reverseModal.classList.remove('hidden');
    reverseModal.classList.add('flex');
}

function closeReverseModal(){
    if(!reverseModal) return;
    reverseModal.classList.add('hidden');
    reverseModal.classList.remove('flex');
}

if(closeReverseModalBtn){
    closeReverseModalBtn.addEventListener('click', closeReverseModal);
}

if(reverseModal){
    reverseModal.addEventListener('click', function(e){
        if(e.target === reverseModal){
            closeReverseModal();
        }
    });
}

function openReverseDetailModal(btn){
    if(!reverseDetailModal || !reverseDetailBody) return;
    var transferId = btn.getAttribute('data-transfer-id') || '0';
    var rows = reverseHistoryMap[transferId] || reverseHistoryMap[String(transferId)] || [];

    if(!rows.length){
        reverseDetailBody.innerHTML = '<div class="text-slate-500">No reverse details found.</div>';
    } else {
        reverseDetailBody.innerHTML = rows.map(function(rr){
            var qty = rr.return_qty || '0';
            var by = rr.returned_by_username || '-';
            var at = rr.created_at || '-';
            var note = rr.note || '-';
            return '<div class="p-3 rounded-lg border border-amber-200 bg-amber-50 text-amber-900">'
                + '<div><strong>Returned Qty:</strong> ' + escapeHtml(String(qty)) + '</div>'
                + '<div><strong>By:</strong> ' + escapeHtml(String(by)) + '</div>'
                + '<div><strong>On:</strong> ' + escapeHtml(String(at)) + '</div>'
                + '<div><strong>Remarks:</strong> ' + escapeHtml(String(note)) + '</div>'
                + '</div>';
        }).join('');
    }

    reverseDetailModal.classList.remove('hidden');
    reverseDetailModal.classList.add('flex');
}

function closeReverseDetailModal(){
    if(!reverseDetailModal) return;
    reverseDetailModal.classList.add('hidden');
    reverseDetailModal.classList.remove('flex');
}

if(closeReverseDetailModalBtn){
    closeReverseDetailModalBtn.addEventListener('click', closeReverseDetailModal);
}

if(reverseDetailModal){
    reverseDetailModal.addEventListener('click', function(e){
        if(e.target === reverseDetailModal){
            closeReverseDetailModal();
        }
    });
}

function escapeHtml(text){
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
<?php endif; ?>
