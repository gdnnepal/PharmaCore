<?php
require_once __DIR__ . '/../config.php';

$isAdmin = is_admin_user();
$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentUserBranchId = (int)($_SESSION['branch_id'] ?? 0);
$canViewInventory = $isAdmin || has_any_permission(['inventory.view', 'inventory.manage', 'inventory.transfer']);
$canManageInventory = $isAdmin || has_permission('inventory.manage');
$canTransferStock = $isAdmin || has_permission('inventory.transfer');
if($currentUserBranchId <= 0 && $currentUserId > 0){
    $stmt = $pdo->prepare("SELECT branch_id FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$currentUserId]);
    $currentUserBranchId = (int)($stmt->fetchColumn() ?: 0);
    if($currentUserBranchId > 0){
        $_SESSION['branch_id'] = $currentUserBranchId;
    }
}

if(!$canViewInventory){
    flash_msg('You do not have permission to view inventory.', 'error');
    redirect_with_fallback('?module=sale');
}

if($_SERVER['REQUEST_METHOD']==='POST' && verify_csrf()){
    try {
        $pdo->beginTransaction();

        if(isset($_POST['add_product'])){
            if(!$canManageInventory) throw new Exception('You do not have permission to manage inventory.');
            $pdo->prepare("INSERT INTO products(name,generic_name,category,min_stock) VALUES(?,?,?,?)")
                ->execute([
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['gen'] ?? '')),
                    trim((string)($_POST['cat'] ?? '')),
                    (float)($_POST['min'] ?? 0),
                ]);
            $newProductId = (int)$pdo->lastInsertId();
            audit_log_action(
                'inventory',
                'add_product',
                'Added new product.',
                [
                    'product_id' => $newProductId,
                    'name' => trim((string)($_POST['name'] ?? '')),
                    'generic_name' => trim((string)($_POST['gen'] ?? '')),
                    'category' => trim((string)($_POST['cat'] ?? '')),
                    'min_stock' => (float)($_POST['min'] ?? 0),
                ],
                'product',
                $newProductId
            );
        } else if(isset($_POST['update_product'])){
            if(!$canManageInventory) throw new Exception('You do not have permission to manage inventory.');
            $pid = (int)($_POST['product_id'] ?? 0);
            if($pid <= 0) throw new Exception('Invalid product');

            $beforeStmt = $pdo->prepare("SELECT id, name, generic_name, category, min_stock FROM products WHERE id=? LIMIT 1");
            $beforeStmt->execute([$pid]);
            $beforeProduct = $beforeStmt->fetch() ?: null;

            $pdo->prepare("UPDATE products SET name=?, generic_name=?, category=?, min_stock=? WHERE id=?")
                ->execute([
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['gen'] ?? '')),
                    trim((string)($_POST['cat'] ?? '')),
                    (float)($_POST['min'] ?? 0),
                    $pid,
                ]);
            audit_log_action(
                'inventory',
                'update_product',
                'Updated product.',
                [
                    'before' => $beforeProduct,
                    'after' => [
                        'id' => $pid,
                        'name' => trim((string)($_POST['name'] ?? '')),
                        'generic_name' => trim((string)($_POST['gen'] ?? '')),
                        'category' => trim((string)($_POST['cat'] ?? '')),
                        'min_stock' => (float)($_POST['min'] ?? 0),
                    ],
                ],
                'product',
                $pid
            );
        } else if(isset($_POST['add_batch'])){
            if(!$canManageInventory) throw new Exception('You do not have permission to manage inventory.');
            $supplierId = (int)($_POST['sup_id'] ?? 0);
            $supplierId = $supplierId > 0 ? $supplierId : null;

            $branchId = $isAdmin ? (int)($_POST['branch_id'] ?? 0) : $currentUserBranchId;
            if($branchId <= 0){
                throw new Exception($isAdmin ? 'Please select a branch.' : 'Your account is not assigned to a branch.');
            }
            if($isAdmin){
                $branchStmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE id=?");
                $branchStmt->execute([$branchId]);
                if((int)$branchStmt->fetchColumn() <= 0){
                    throw new Exception('Selected branch is invalid.');
                }
            }

            $pdo->prepare("INSERT INTO batches(product_id,supplier_id,branch_id,batch_no,expiry_date,quantity,cost_price,sell_price) VALUES(?,?,?,?,?,?,?,?)")
                ->execute([
                    (int)($_POST['pid'] ?? 0),
                    $supplierId,
                    $branchId,
                    trim((string)($_POST['bno'] ?? '')),
                    (string)($_POST['exp'] ?? ''),
                    (float)($_POST['qty'] ?? 0),
                    (float)($_POST['cp'] ?? 0),
                    (float)($_POST['sp'] ?? 0),
                ]);
            $newBatchId = (int)$pdo->lastInsertId();
            audit_log_action(
                'inventory',
                'add_batch',
                'Added stock batch.',
                [
                    'batch_id' => $newBatchId,
                    'product_id' => (int)($_POST['pid'] ?? 0),
                    'supplier_id' => $supplierId,
                    'branch_id' => $branchId,
                    'batch_no' => trim((string)($_POST['bno'] ?? '')),
                    'expiry_date' => (string)($_POST['exp'] ?? ''),
                    'quantity' => (float)($_POST['qty'] ?? 0),
                    'cost_price' => (float)($_POST['cp'] ?? 0),
                    'sell_price' => (float)($_POST['sp'] ?? 0),
                ],
                'batch',
                $newBatchId
            );
        } else if(isset($_POST['update_batch'])){
            if(!$canManageInventory) throw new Exception('You do not have permission to manage inventory.');
            $bid = (int)($_POST['batch_id'] ?? 0);
            if($bid <= 0) throw new Exception('Invalid batch');

            $beforeStmt = $pdo->prepare("SELECT id, product_id, supplier_id, branch_id, batch_no, expiry_date, quantity, cost_price, sell_price FROM batches WHERE id=? LIMIT 1");
            $beforeStmt->execute([$bid]);
            $beforeBatch = $beforeStmt->fetch() ?: null;

            if(!$isAdmin){
                if($currentUserBranchId <= 0) throw new Exception('Your account is not assigned to a branch.');
                $ownerStmt = $pdo->prepare("SELECT branch_id FROM batches WHERE id=? LIMIT 1");
                $ownerStmt->execute([$bid]);
                $ownerBranchId = (int)($ownerStmt->fetchColumn() ?: 0);
                if($ownerBranchId !== $currentUserBranchId){
                    throw new Exception('You can only update stock from your assigned branch.');
                }
            }

            $supplierId = (int)($_POST['sup_id'] ?? 0);
            $supplierId = $supplierId > 0 ? $supplierId : null;

            $branchId = $isAdmin ? (int)($_POST['branch_id'] ?? 0) : $currentUserBranchId;
            if($branchId <= 0){
                throw new Exception($isAdmin ? 'Please select a branch.' : 'Your account is not assigned to a branch.');
            }
            if($isAdmin){
                $branchStmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE id=?");
                $branchStmt->execute([$branchId]);
                if((int)$branchStmt->fetchColumn() <= 0){
                    throw new Exception('Selected branch is invalid.');
                }
            }

            $pdo->prepare("UPDATE batches SET product_id=?, supplier_id=?, branch_id=?, batch_no=?, expiry_date=?, quantity=?, cost_price=?, sell_price=? WHERE id=?")
                ->execute([
                    (int)($_POST['pid'] ?? 0),
                    $supplierId,
                    $branchId,
                    trim((string)($_POST['bno'] ?? '')),
                    (string)($_POST['exp'] ?? ''),
                    (float)($_POST['qty'] ?? 0),
                    (float)($_POST['cp'] ?? 0),
                    (float)($_POST['sp'] ?? 0),
                    $bid,
                ]);
            audit_log_action(
                'inventory',
                'update_batch',
                'Updated stock batch.',
                [
                    'before' => $beforeBatch,
                    'after' => [
                        'id' => $bid,
                        'product_id' => (int)($_POST['pid'] ?? 0),
                        'supplier_id' => $supplierId,
                        'branch_id' => $branchId,
                        'batch_no' => trim((string)($_POST['bno'] ?? '')),
                        'expiry_date' => (string)($_POST['exp'] ?? ''),
                        'quantity' => (float)($_POST['qty'] ?? 0),
                        'cost_price' => (float)($_POST['cp'] ?? 0),
                        'sell_price' => (float)($_POST['sp'] ?? 0),
                    ],
                ],
                'batch',
                $bid
            );
        } else if(isset($_POST['delete_product'])){
            if(!$canManageInventory) throw new Exception('You do not have permission to manage inventory.');
            $pid = (int)($_POST['product_id'] ?? 0);
            if($pid <= 0) throw new Exception('Invalid product');

            $beforeStmt = $pdo->prepare("SELECT id, name, generic_name, category, min_stock FROM products WHERE id=? LIMIT 1");
            $beforeStmt->execute([$pid]);
            $beforeProduct = $beforeStmt->fetch() ?: null;

            $stmtB = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE product_id=?");
            $stmtB->execute([$pid]);
            $batchCount = (int)$stmtB->fetchColumn();

            $stmtS = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id=?");
            $stmtS->execute([$pid]);
            $saleItemCount = (int)$stmtS->fetchColumn();

            if($batchCount > 0 || $saleItemCount > 0){
                throw new Exception('Cannot delete product with stock/batch or sale history.');
            }

            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$pid]);
            audit_log_action(
                'inventory',
                'delete_product',
                'Deleted product.',
                [
                    'deleted_product' => $beforeProduct,
                ],
                'product',
                $pid
            );
        } else if(isset($_POST['delete_batch'])){
            if(!$canManageInventory) throw new Exception('You do not have permission to manage inventory.');
            $bid = (int)($_POST['batch_id'] ?? 0);
            if($bid <= 0) throw new Exception('Invalid batch');

            $beforeStmt = $pdo->prepare("SELECT id, product_id, supplier_id, branch_id, batch_no, expiry_date, quantity, cost_price, sell_price FROM batches WHERE id=? LIMIT 1");
            $beforeStmt->execute([$bid]);
            $beforeBatch = $beforeStmt->fetch() ?: null;

            if(!$isAdmin){
                if($currentUserBranchId <= 0) throw new Exception('Your account is not assigned to a branch.');
                $ownerStmt = $pdo->prepare("SELECT branch_id FROM batches WHERE id=? LIMIT 1");
                $ownerStmt->execute([$bid]);
                $ownerBranchId = (int)($ownerStmt->fetchColumn() ?: 0);
                if($ownerBranchId !== $currentUserBranchId){
                    throw new Exception('You can only delete stock from your assigned branch.');
                }
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE batch_id=?");
            $stmt->execute([$bid]);
            $usedCount = (int)$stmt->fetchColumn();

            if($usedCount > 0){
                throw new Exception('Cannot delete batch with sale history.');
            }

            $pdo->prepare("DELETE FROM batches WHERE id=?")->execute([$bid]);
            audit_log_action(
                'inventory',
                'delete_batch',
                'Deleted stock batch.',
                [
                    'deleted_batch' => $beforeBatch,
                ],
                'batch',
                $bid
            );
        } else if(isset($_POST['transfer_stock'])){
            if(!$canTransferStock) throw new Exception('You do not have permission to transfer stock.');

            $fromBranchId = (int)($_POST['from_branch_id'] ?? 0);
            $toBranchId = (int)($_POST['to_branch_id'] ?? 0);
            $productId = (int)($_POST['pid'] ?? 0);
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $transferQty = (float)($_POST['transfer_qty'] ?? 0);

            if($fromBranchId <= 0 || $toBranchId <= 0){
                throw new Exception('Please select both source and destination branches.');
            }
            if($fromBranchId === $toBranchId){
                throw new Exception('Source and destination branches must be different.');
            }
            if($productId <= 0 || $batchId <= 0){
                throw new Exception('Please select product and batch for transfer.');
            }
            if($transferQty <= 0){
                throw new Exception('Transfer quantity must be greater than zero.');
            }

            $batchStmt = $pdo->prepare("SELECT id, product_id, supplier_id, branch_id, batch_no, expiry_date, quantity, cost_price, sell_price FROM batches WHERE id=? FOR UPDATE");
            $batchStmt->execute([$batchId]);
            $sourceBatch = $batchStmt->fetch();
            if(!$sourceBatch){
                throw new Exception('Selected source batch not found.');
            }
            if((int)$sourceBatch['product_id'] !== $productId){
                throw new Exception('Selected batch does not belong to selected product.');
            }
            if((int)$sourceBatch['branch_id'] !== $fromBranchId){
                throw new Exception('Selected batch does not belong to selected source branch.');
            }

            $availableQty = (float)($sourceBatch['quantity'] ?? 0);
            if($availableQty < $transferQty){
                throw new Exception('Insufficient source branch stock for transfer.');
            }

            $pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id=?")->execute([$transferQty, $batchId]);

            $destStmt = $pdo->prepare("SELECT id FROM batches WHERE product_id=? AND branch_id=? AND batch_no=? AND expiry_date=? AND cost_price=? AND sell_price=? AND ((supplier_id IS NULL AND ? IS NULL) OR supplier_id = ?) LIMIT 1 FOR UPDATE");
            $destSupplierId = ($sourceBatch['supplier_id'] === null || $sourceBatch['supplier_id'] === '') ? null : (int)$sourceBatch['supplier_id'];
            $destStmt->execute([
                (int)$sourceBatch['product_id'],
                $toBranchId,
                (string)$sourceBatch['batch_no'],
                (string)$sourceBatch['expiry_date'],
                (float)$sourceBatch['cost_price'],
                (float)$sourceBatch['sell_price'],
                $destSupplierId,
                $destSupplierId,
            ]);
            $destBatchId = (int)($destStmt->fetchColumn() ?: 0);

            if($destBatchId > 0){
                $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id=?")->execute([$transferQty, $destBatchId]);
            } else {
                $pdo->prepare("INSERT INTO batches(product_id,supplier_id,branch_id,batch_no,expiry_date,quantity,cost_price,sell_price) VALUES(?,?,?,?,?,?,?,?)")
                    ->execute([
                        (int)$sourceBatch['product_id'],
                        $destSupplierId,
                        $toBranchId,
                        (string)$sourceBatch['batch_no'],
                        (string)$sourceBatch['expiry_date'],
                        $transferQty,
                        (float)$sourceBatch['cost_price'],
                        (float)$sourceBatch['sell_price'],
                    ]);
                $destBatchId = (int)$pdo->lastInsertId();
            }

            $branchLookupStmt = $pdo->prepare("SELECT id, name, code FROM branches WHERE id IN (?, ?)");
            $branchLookupStmt->execute([$fromBranchId, $toBranchId]);
            $branchMap = [];
            foreach($branchLookupStmt->fetchAll() as $branchRow){
                $branchMap[(int)$branchRow['id']] = trim((string)($branchRow['code'] ?? '')) !== ''
                    ? trim((string)$branchRow['code'])
                    : trim((string)($branchRow['name'] ?? ''));
            }

            $fromBranchLabel = $branchMap[$fromBranchId] ?? ('#' . $fromBranchId);
            $toBranchLabel = $branchMap[$toBranchId] ?? ('#' . $toBranchId);

            $productNameStmt = $pdo->prepare("SELECT name FROM products WHERE id=? LIMIT 1");
            $productNameStmt->execute([(int)$sourceBatch['product_id']]);
            $productName = trim((string)($productNameStmt->fetchColumn() ?: 'Unknown Product'));

            $transferDescription = 'Stock transfer: ' . $fromBranchLabel
                . ' to ' . $toBranchLabel
                . ', Product: ' . $productName
                . ', Batch: ' . (string)$sourceBatch['batch_no']
                . ', Qty: ' . (string)$transferQty;

            $transferRecordStmt = $pdo->prepare("INSERT INTO stock_transfers(source_batch_id, destination_batch_id, product_id, from_branch_id, to_branch_id, transferred_qty, reversed_qty, status, created_by_user_id, created_by_username, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
            $transferRecordStmt->execute([
                (int)$sourceBatch['id'],
                (int)$destBatchId,
                (int)$sourceBatch['product_id'],
                $fromBranchId,
                $toBranchId,
                $transferQty,
                0,
                'completed',
                $currentUserId > 0 ? $currentUserId : null,
                (string)($_SESSION['username'] ?? ''),
                date('Y-m-d H:i:s'),
            ]);
            $transferRecordId = (int)$pdo->lastInsertId();

            audit_log_action(
                'inventory',
                'transfer_stock',
                $transferDescription,
                [
                    'stock_transfer_id' => $transferRecordId,
                ],
                'batch',
                (int)$sourceBatch['id']
            );

            flash_msg('Stock transferred successfully.');
        }

        $pdo->commit();
        flash_msg("Inventory updated successfully.");

        $redirectParams = ['module' => 'inventory'];
        $productSearchParam = trim((string)($_GET['product_search'] ?? ''));
        $batchSearchParam = trim((string)($_GET['batch_search'] ?? ''));
        $categoryParam = trim((string)($_GET['category'] ?? ''));
        $supplierIdParam = (int)($_GET['supplier_id'] ?? 0);
        $expiryParam = (string)($_GET['expiry'] ?? 'all');
        $showProductsParam = (string)($_GET['show_products'] ?? '1');
        $lowStockParam = (string)($_GET['low_stock'] ?? '0');

        if($productSearchParam !== '') $redirectParams['product_search'] = $productSearchParam;
        if($batchSearchParam !== '') $redirectParams['batch_search'] = $batchSearchParam;
        if($categoryParam !== '') $redirectParams['category'] = $categoryParam;
        if($supplierIdParam > 0) $redirectParams['supplier_id'] = $supplierIdParam;
        if(in_array($expiryParam, ['all', 'expired', '30', '90'], true) && $expiryParam !== 'all') $redirectParams['expiry'] = $expiryParam;
        if($showProductsParam === '0') $redirectParams['show_products'] = '0';
        if($lowStockParam === '1') $redirectParams['low_stock'] = '1';

        redirect_with_fallback('?' . http_build_query($redirectParams));
    } catch(Exception $e){
        $pdo->rollBack();
        flash_msg($e->getMessage(),'error');
    }
}

$suppliers = $pdo->query("SELECT id,name FROM suppliers ORDER BY name")->fetchAll();
$branches = $pdo->query("SELECT id, name, code FROM branches WHERE is_active=1 ORDER BY name ASC")->fetchAll();
$branchNameById = [];
foreach($branches as $branchRow){
    $branchNameById[(int)$branchRow['id']] = (string)$branchRow['name'];
}
$categories = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll();

$productSearch = trim((string)($_GET['product_search'] ?? ''));
$batchSearch = trim((string)($_GET['batch_search'] ?? ''));
$categoryFilter = trim((string)($_GET['category'] ?? ''));
$supplierFilter = (int)($_GET['supplier_id'] ?? 0);
$expiryFilter = (string)($_GET['expiry'] ?? 'all');
$showProducts = ((string)($_GET['show_products'] ?? '1')) === '1';
$lowStockFilter = ((string)($_GET['low_stock'] ?? '0')) === '1';

if(!in_array($expiryFilter, ['all', 'expired', '30', '90'], true)){
    $expiryFilter = 'all';
}

$lowStockSubquery = "SELECT p2.id FROM products p2 WHERE COALESCE((SELECT SUM(b2.quantity) FROM batches b2 WHERE b2.product_id=p2.id),0) < p2.min_stock";

$allProducts = $pdo->query("SELECT p.*,COALESCE(SUM(b.quantity),0) AS stock FROM products p LEFT JOIN batches b ON p.id=b.product_id GROUP BY p.id ORDER BY p.name")->fetchAll();
$hasProducts = !empty($allProducts);
$transferSourceBatches = $pdo->query("SELECT b.id, b.product_id, b.branch_id, b.batch_no, b.quantity, p.name AS product_name, COALESCE(br.name, 'No Branch') AS branch_name FROM batches b JOIN products p ON p.id=b.product_id LEFT JOIN branches br ON br.id=b.branch_id WHERE b.quantity > 0 ORDER BY p.name ASC, b.batch_no ASC")->fetchAll();

$prodSql = "SELECT p.*, COALESCE(SUM(b.quantity),0) AS stock FROM products p LEFT JOIN batches b ON p.id=b.product_id";
$prodWhere = [];
$prodParams = [];

if($productSearch !== ''){
    $prodWhere[] = "(p.name LIKE ? OR p.generic_name LIKE ? OR p.category LIKE ?)";
    $like = '%' . $productSearch . '%';
    $prodParams[] = $like;
    $prodParams[] = $like;
    $prodParams[] = $like;
}
if($categoryFilter !== ''){
    $prodWhere[] = "p.category = ?";
    $prodParams[] = $categoryFilter;
}
if($lowStockFilter){
    $prodWhere[] = "p.id IN ($lowStockSubquery)";
}
if(!empty($prodWhere)){
    $prodSql .= " WHERE " . implode(" AND ", $prodWhere);
}
$prodSql .= " GROUP BY p.id ORDER BY p.name";

$stmt = $pdo->prepare($prodSql);
$stmt->execute($prodParams);
$prods = $stmt->fetchAll();

$batchSql = "SELECT b.*, p.name, p.generic_name, p.category, s.name AS supplier_name, br.name AS branch_name, DATEDIFF(b.expiry_date, CURDATE()) AS expiry_days
             FROM batches b
             JOIN products p ON b.product_id=p.id
             LEFT JOIN suppliers s ON b.supplier_id=s.id
             LEFT JOIN branches br ON br.id=b.branch_id";
$batchWhere = [];
$batchParams = [];

if($batchSearch !== ''){
    $batchWhere[] = "(p.name LIKE ? OR b.batch_no LIKE ? OR COALESCE(s.name,'') LIKE ?)";
    $like = '%' . $batchSearch . '%';
    $batchParams[] = $like;
    $batchParams[] = $like;
    $batchParams[] = $like;
}
if($supplierFilter > 0){
    $batchWhere[] = "b.supplier_id = ?";
    $batchParams[] = $supplierFilter;
}
if($categoryFilter !== ''){
    $batchWhere[] = "p.category = ?";
    $batchParams[] = $categoryFilter;
}
if($lowStockFilter){
    $batchWhere[] = "b.product_id IN ($lowStockSubquery)";
}
if($expiryFilter === 'expired'){
    $batchWhere[] = "b.expiry_date < CURDATE()";
} else if($expiryFilter === '30'){
    $batchWhere[] = "b.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY";
} else if($expiryFilter === '90'){
    $batchWhere[] = "b.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 90 DAY";
}
if(!empty($batchWhere)){
    $batchSql .= " WHERE " . implode(" AND ", $batchWhere);
}
$batchSql .= " ORDER BY b.expiry_date ASC";

$stmt = $pdo->prepare($batchSql);
$stmt->execute($batchParams);
$batches = $stmt->fetchAll();

$prodsMeta = paginate_array($prods, 'products_page', 10);
$prods = $prodsMeta['rows'];

$batchesMeta = paginate_array($batches, 'batches_page', 10);
$batches = $batchesMeta['rows'];

$totalProducts = count($allProducts);
$totalStock = 0.0;
foreach($allProducts as $ap){
    $totalStock += (float)($ap['stock'] ?? 0);
}
$expiringSoon = (int)$pdo->query("SELECT COUNT(*) FROM batches WHERE quantity > 0 AND expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY")->fetchColumn();
$supplierCount = count($suppliers);

$f = flash_msg();

$editProductId = (int)($_GET['edit_product'] ?? 0);
$viewProductId = (int)($_GET['view_product'] ?? 0);
$editBatchId = (int)($_GET['edit_batch'] ?? 0);
$viewBatchId = (int)($_GET['view_batch'] ?? 0);

$editProduct = null;
if($editProductId > 0){
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$editProductId]);
    $editProduct = $stmt->fetch();
}

$viewProduct = null;
if($viewProductId > 0){
    $stmt = $pdo->prepare("SELECT p.*,COALESCE(SUM(b.quantity),0) as stock FROM products p LEFT JOIN batches b ON p.id=b.product_id WHERE p.id=? GROUP BY p.id");
    $stmt->execute([$viewProductId]);
    $viewProduct = $stmt->fetch();
}

$editBatch = null;
if($editBatchId > 0){
    $stmt = $pdo->prepare("SELECT * FROM batches WHERE id=?");
    $stmt->execute([$editBatchId]);
    $editBatch = $stmt->fetch();
    if($editBatch && !$isAdmin){
        $editBranchId = (int)($editBatch['branch_id'] ?? 0);
        if(!$canManageInventory || $currentUserBranchId <= 0 || $editBranchId !== $currentUserBranchId){
            flash_msg('You can only edit stock from your assigned branch.', 'error');
            redirect_with_fallback('?module=inventory');
        }
    }
}

$viewBatch = null;
if($viewBatchId > 0){
    $stmt = $pdo->prepare("SELECT b.*,p.name,p.generic_name,s.name AS supplier_name, br.name AS branch_name FROM batches b JOIN products p ON b.product_id=p.id LEFT JOIN suppliers s ON b.supplier_id=s.id LEFT JOIN branches br ON br.id=b.branch_id WHERE b.id=?");
    $stmt->execute([$viewBatchId]);
    $viewBatch = $stmt->fetch();
}
?>
<div class="space-y-6">
<?php if($f): ?><div class="p-3 rounded-lg text-sm border <?= $f['type']=='error'?'bg-red-50 text-red-700 border-red-200':'bg-emerald-50 text-emerald-700 border-emerald-200' ?>"><?= e($f['msg']) ?></div><?php endif; ?>

<div class="flex gap-2">
    <?php if($canManageInventory): ?>
    <button type="button" onclick="toggleFormPanel('productFormPanel')" class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Add Product</button>
    <button type="button" onclick="toggleFormPanel('batchFormPanel')" class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Add Stock</button>
    <?php endif; ?>
    <?php if($canTransferStock): ?>
    <button type="button" onclick="toggleFormPanel('transferStockPanel')" class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Transfer Stock</button>
    <?php endif; ?>
    <button type="button" onclick="toggleFilterPanel()" class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium" id="filterToggleBtn">Filter</button>
</div>

<?php if($lowStockFilter): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 text-sm flex items-center justify-between gap-3">
    <div><strong>Low stock filter active.</strong> Showing products and batches below minimum stock.</div>
    <a href="?module=inventory&show_products=<?= $showProducts ? '1' : '0' ?>" class="text-amber-900 hover:underline font-medium">Clear</a>
</div>
<?php endif; ?>

<section id="filterPanel" class="hidden bg-white p-5 rounded-2xl shadow border border-slate-100">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-base font-semibold">Filters & Search</h3>
        <a href="?module=inventory" class="text-sm text-primary hover:underline">Reset</a>
    </div>
    <form method="GET" class="grid md:grid-cols-8 gap-2 items-end">
        <input type="hidden" name="module" value="inventory">
        <input type="hidden" name="show_products" value="<?= $showProducts ? '1' : '0' ?>">
        <input type="hidden" name="low_stock" value="<?= $lowStockFilter ? '1' : '0' ?>">

        <div class="md:col-span-2">
            <label class="text-xs text-slate-500">Product Search</label>
            <input name="product_search" value="<?= e($productSearch) ?>" placeholder="Name, generic, category" class="w-full p-2.5 border rounded-lg text-sm">
        </div>
        <div class="md:col-span-2">
            <label class="text-xs text-slate-500">Batch Search</label>
            <input name="batch_search" value="<?= e($batchSearch) ?>" placeholder="Batch no, product, supplier" class="w-full p-2.5 border rounded-lg text-sm">
        </div>
        <div>
            <label class="text-xs text-slate-500">Supplier</label>
            <select name="supplier_id" class="w-full p-2.5 border rounded-lg text-sm">
                <option value="0">All Suppliers</option>
                <?php foreach($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $supplierFilter === (int)$s['id'] ? 'selected' : '' ?>><?= e((string)$s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs text-slate-500">Category</label>
            <select name="category" class="w-full p-2.5 border rounded-lg text-sm">
                <option value="">All Categories</option>
                <?php foreach($categories as $cat): ?>
                <option value="<?= e((string)$cat['category']) ?>" <?= $categoryFilter === (string)$cat['category'] ? 'selected' : '' ?>><?= e((string)$cat['category']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs text-slate-500">Expiry</label>
            <select name="expiry" class="w-full p-2.5 border rounded-lg text-sm">
                <option value="all" <?= $expiryFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="expired" <?= $expiryFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                <option value="30" <?= $expiryFilter === '30' ? 'selected' : '' ?>>Next 30 days</option>
                <option value="90" <?= $expiryFilter === '90' ? 'selected' : '' ?>>Next 90 days</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-slate-500">Stock</label>
            <select name="low_stock" class="w-full p-2.5 border rounded-lg text-sm">
                <option value="0" <?= !$lowStockFilter ? 'selected' : '' ?>>All</option>
                <option value="1" <?= $lowStockFilter ? 'selected' : '' ?>>Low Stock Only</option>
            </select>
        </div>
        <div>
            <button class="w-full bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Apply Filters</button>
        </div>
    </form>
</section>

<?php if($viewBatch): ?>
<div id="viewBatchPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4" onclick="if(event.target.id==='viewBatchPanel') window.location='?module=inventory'">
    <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-lg w-full">
        <div class="flex items-center justify-between p-6 border-b border-slate-100">
            <h3 class="text-lg font-semibold text-slate-800">View Batch</h3>
            <a href="?module=inventory" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
        </div>
        <div class="p-6">
            <div class="grid md:grid-cols-2 gap-3 text-sm bg-slate-50 rounded-xl p-3 border border-slate-200">
                <div><span class="text-gray-500">Product:</span> <?= e((string)$viewBatch['name']) ?></div>
                <div><span class="text-gray-500">Batch No:</span> <?= e((string)$viewBatch['batch_no']) ?></div>
                <div><span class="text-gray-500">Expiry:</span> <?= e((string)$viewBatch['expiry_date']) ?></div>
                <div><span class="text-gray-500">Qty:</span> <?= e((string)$viewBatch['quantity']) ?></div>
                <div><span class="text-gray-500">Supplier:</span> <?= e((string)($viewBatch['supplier_name'] ?? 'N/A')) ?></div>
                <div><span class="text-gray-500">Branch:</span> <?= e((string)($viewBatch['branch_name'] ?? 'N/A')) ?></div>
                <div><span class="text-gray-500">Cost:</span> <?= npr((float)$viewBatch['cost_price']) ?></div>
                <div><span class="text-gray-500">Sell:</span> <?= npr((float)$viewBatch['sell_price']) ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if($viewProduct): ?>
<div id="viewProductPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4" onclick="if(event.target.id==='viewProductPanel') window.location='?module=inventory'">
    <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-md w-full">
        <div class="flex items-center justify-between p-6 border-b border-slate-100">
            <h3 class="text-lg font-semibold text-slate-800">View Product</h3>
            <a href="?module=inventory" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-3 text-sm bg-slate-50 rounded-xl p-3 border border-slate-200">
                <div><span class="text-gray-500">Name:</span> <?= e((string)$viewProduct['name']) ?></div>
                <div><span class="text-gray-500">Generic:</span> <?= e((string)$viewProduct['generic_name']) ?></div>
                <div><span class="text-gray-500">Category:</span> <?= e((string)$viewProduct['category']) ?></div>
                <div><span class="text-gray-500">Stock:</span> <?= e((string)$viewProduct['stock']) ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($canManageInventory): ?>
<div id="productFormPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 <?= $editProduct ? '' : 'hidden' ?>" onclick="if(event.target.id==='productFormPanel') toggleFormPanel('productFormPanel')">
    <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-md w-full max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white">
            <h3 class="text-lg font-semibold text-slate-800"><?= $editProduct ? 'Edit Product' : 'Add Product' ?></h3>
            <?php if($editProduct): ?>
                <a href="?module=inventory" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
            <?php else: ?>
                <button type="button" onclick="toggleFormPanel('productFormPanel')" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</button>
            <?php endif; ?>
        </div>
        <form method="POST" class="p-6 space-y-5">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <?php if($editProduct): ?>
            <input type="hidden" name="update_product" value="1">
            <input type="hidden" name="product_id" value="<?= (int)$editProduct['id'] ?>">
        <?php else: ?>
            <input type="hidden" name="add_product" value="1">
        <?php endif; ?>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Brand Name <span class="text-red-500">*</span></label>
                <input name="name" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editProduct['name'] ?? '')) ?>" placeholder="e.g., Aspirin 500mg">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Generic Name</label>
                <input name="gen" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editProduct['generic_name'] ?? '')) ?>" placeholder="e.g., Acetylsalicylic Acid">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Category</label>
                    <input name="cat" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editProduct['category'] ?? '')) ?>" placeholder="e.g., Analgesic">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Min Stock</label>
                    <input name="min" type="number" value="<?= e((string)($editProduct['min_stock'] ?? '10')) ?>" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" placeholder="10">
                </div>
            </div>
        </div>
        <div class="pt-2 flex gap-2">
            <button class="flex-1 bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition"><?= $editProduct ? 'Update Product' : 'Save Product' ?></button>
            <?php if($editProduct): ?><a href="?module=inventory" class="flex-1 text-center bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition">Cancel</a><?php endif; ?>
        </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if($canManageInventory): ?>
<div id="batchFormPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 <?= $editBatch ? '' : 'hidden' ?>" onclick="if(event.target.id==='batchFormPanel') toggleFormPanel('batchFormPanel')">
    <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white">
            <h3 class="text-lg font-semibold text-slate-800"><?= $editBatch ? 'Edit Batch / Stock In' : 'Add Batch / Stock In' ?></h3>
            <?php if($editBatch): ?>
                <a href="?module=inventory" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
            <?php else: ?>
                <button type="button" onclick="toggleFormPanel('batchFormPanel')" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</button>
            <?php endif; ?>
        </div>
        <form method="POST" class="p-6 space-y-5">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <?php if($editBatch): ?>
            <input type="hidden" name="update_batch" value="1">
            <input type="hidden" name="batch_id" value="<?= (int)$editBatch['id'] ?>">
        <?php else: ?>
            <input type="hidden" name="add_batch" value="1">
        <?php endif; ?>
        <?php if(!$hasProducts): ?><div class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">Warning: No products found. Add a product first, then stock-in will be available.</div><?php endif; ?>
        <div class="space-y-4">
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Product <span class="text-red-500">*</span></label>
                    <select name="pid" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
                        <?php if(!$hasProducts): ?><option value="" selected disabled>No products available</option><?php endif; ?>
                        <?php foreach($allProducts as $p): ?><option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)($editBatch['product_id'] ?? 0)) ? 'selected' : '' ?>><?= e((string)$p['name']) ?> (<?= e((string)$p['stock']) ?> in stock)</option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Supplier</label>
                    <select name="sup_id" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
                        <option value="">- Select Supplier -</option>
                        <?php foreach($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === (int)($editBatch['supplier_id'] ?? 0)) ? 'selected' : '' ?>><?= e((string)$s['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Branch <span class="text-red-500">*</span></label>
                <?php if($isAdmin): ?>
                    <select name="branch_id" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
                        <option value="">- Select Branch -</option>
                        <?php foreach($branches as $branch): ?>
                            <option value="<?= (int)$branch['id'] ?>" <?= (int)($editBatch['branch_id'] ?? 0) === (int)$branch['id'] ? 'selected' : '' ?>><?= e((string)$branch['name']) ?> (<?= e((string)$branch['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="branch_id" value="<?= (int)$currentUserBranchId ?>">
                    <input type="text" readonly class="w-full px-4 py-2.5 border border-slate-300 rounded-lg bg-slate-50" value="<?= e((string)($branchNameById[$currentUserBranchId] ?? 'Assigned Branch')) ?>">
                <?php endif; ?>
            </div>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Batch Number <span class="text-red-500">*</span></label>
                    <input name="bno" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editBatch['batch_no'] ?? '')) ?>" placeholder="e.g., B20250411001">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Expiry Date <span class="text-red-500">*</span></label>
                    <input type="date" name="exp" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editBatch['expiry_date'] ?? '')) ?>">
                </div>
            </div>
            <div class="grid md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Quantity <span class="text-red-500">*</span></label>
                    <input name="qty" type="number" step="0.1" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editBatch['quantity'] ?? '')) ?>" placeholder="0.0">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Cost Price <span class="text-red-500">*</span></label>
                    <input name="cp" type="number" step="0.01" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editBatch['cost_price'] ?? '')) ?>" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Sell Price <span class="text-red-500">*</span></label>
                    <input name="sp" type="number" step="0.01" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editBatch['sell_price'] ?? '')) ?>" placeholder="0.00">
                </div>
            </div>
        </div>
        <div class="pt-2 flex gap-2">
            <button class="flex-1 bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition disabled:opacity-60 disabled:cursor-not-allowed" <?= $hasProducts ? '' : 'disabled' ?>><?= $editBatch ? 'Update Stock' : 'Add Stock' ?></button>
            <?php if($editBatch): ?><a href="?module=inventory" class="flex-1 text-center bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition">Cancel</a><?php endif; ?>
        </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if($canTransferStock): ?>
<div id="transferStockPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 hidden" onclick="if(event.target.id==='transferStockPanel') toggleFormPanel('transferStockPanel')">
    <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white">
            <h3 class="text-lg font-semibold text-slate-800">Transfer Stock</h3>
            <button type="button" onclick="toggleFormPanel('transferStockPanel')" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</button>
        </div>
        <form method="POST" class="p-6 space-y-5" onsubmit="return validateTransferStockForm();">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="transfer_stock" value="1">

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">From Branch <span class="text-red-500">*</span></label>
                    <select id="transfer_from_branch" name="from_branch_id" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" onchange="refreshTransferBatchOptions()">
                        <option value="">- Select Branch -</option>
                        <?php foreach($branches as $branch): ?>
                            <option value="<?= (int)$branch['id'] ?>"><?= e((string)$branch['name']) ?> (<?= e((string)$branch['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">To Branch <span class="text-red-500">*</span></label>
                    <select id="transfer_to_branch" name="to_branch_id" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
                        <option value="">- Select Branch -</option>
                        <?php foreach($branches as $branch): ?>
                            <option value="<?= (int)$branch['id'] ?>"><?= e((string)$branch['name']) ?> (<?= e((string)$branch['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Product <span class="text-red-500">*</span></label>
                <input id="transfer_product_search" type="text" class="w-full px-4 py-2.5 mb-2 border border-slate-300 rounded-lg" placeholder="Search product by name..." oninput="filterTransferProducts()">
                <select id="transfer_product" name="pid" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" onchange="refreshTransferBatchOptions()">
                    <option value="">- Select Product -</option>
                    <?php foreach($allProducts as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e((string)$p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Batch <span class="text-red-500">*</span></label>
                <select id="transfer_batch" name="batch_id" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
                    <option value="">- Select Batch -</option>
                    <?php foreach($transferSourceBatches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" data-branch-id="<?= (int)($b['branch_id'] ?? 0) ?>" data-product-id="<?= (int)$b['product_id'] ?>" data-qty="<?= e((string)$b['quantity']) ?>"><?= e((string)$b['product_name']) ?> | Batch <?= e((string)$b['batch_no']) ?> | Qty <?= e((string)$b['quantity']) ?> | <?= e((string)($b['branch_name'] ?? '-')) ?></option>
                    <?php endforeach; ?>
                </select>
                <p id="transfer_batch_hint" class="text-xs text-slate-500 mt-1">Select source branch and product to narrow batches.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Transfer Quantity <span class="text-red-500">*</span></label>
                <input id="transfer_qty" name="transfer_qty" type="number" min="0.1" step="0.1" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" placeholder="0.0">
            </div>

            <div class="pt-2 flex gap-2">
                <button class="flex-1 bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition">Transfer</button>
                <button type="button" onclick="toggleFormPanel('transferStockPanel')" class="flex-1 text-center bg-slate-200 hover:bg-slate-300 text-slate-700 font-medium px-4 py-2.5 rounded-lg transition">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="productListPanel" class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-2">
                <h3 class="font-semibold text-slate-800">Product List</h3>
                <span class="inline-block bg-slate-200 text-slate-700 px-2.5 py-0.5 rounded-full text-xs font-medium"><?= (int)$prodsMeta['total'] ?></span>
                <?php if($lowStockFilter): ?><span class="inline-block bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded-full text-xs font-medium">Low Stock Only</span><?php endif; ?>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="module" value="inventory">
                <input type="hidden" name="batch_search" value="<?= e($batchSearch) ?>">
                <input type="hidden" name="category" value="<?= e($categoryFilter) ?>">
                <input type="hidden" name="supplier_id" value="<?= (int)$supplierFilter ?>">
                <input type="hidden" name="expiry" value="<?= e($expiryFilter) ?>">
                <input type="hidden" name="low_stock" value="<?= $lowStockFilter ? '1' : '0' ?>">
                <input type="hidden" name="show_products" value="<?= $showProducts ? '1' : '0' ?>">
                <input type="text" name="product_search" value="<?= e($productSearch) ?>" placeholder="Search name, generic, category" class="px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                <button class="bg-primary hover:bg-teal-800 text-white px-3 py-2 rounded-lg text-sm font-medium">Search</button>
                <?php if($productSearch !== ''): ?><a href="?module=inventory&batch_search=<?= e($batchSearch) ?>&category=<?= e($categoryFilter) ?>&supplier_id=<?= (int)$supplierFilter ?>&expiry=<?= e($expiryFilter) ?>&low_stock=<?= $lowStockFilter ? '1' : '0' ?>&show_products=<?= $showProducts ? '1' : '0' ?>" class="text-sm text-primary hover:underline">Clear</a><?php endif; ?>
            </form>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 bg-slate-50">
                <th class="px-6 py-3 text-left font-semibold text-slate-700">Product Name</th>
                <th class="px-6 py-3 text-left font-semibold text-slate-700">Generic</th>
                <th class="px-6 py-3 text-left font-semibold text-slate-700">Category</th>
                <th class="px-6 py-3 text-right font-semibold text-slate-700">Stock Qty</th>
                <th class="px-6 py-3 text-center font-semibold text-slate-700">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($prods)): ?>
            <tr><td colspan="5" class="px-6 py-8 text-center text-slate-500">No products found matching your filters.</td></tr>
        <?php else: ?>
            <?php foreach($prods as $p): ?>
            <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                <td class="px-6 py-3.5 font-medium text-slate-900"><?= e((string)$p['name']) ?></td>
                <td class="px-6 py-3.5 text-slate-600"><?= e((string)$p['generic_name']) ?: '-' ?></td>
                <td class="px-6 py-3.5 text-slate-600"><?= e((string)$p['category']) ?: '-' ?></td>
                <td class="px-6 py-3.5 text-right">
                    <span class="bg-emerald-100 text-emerald-800 px-3 py-1 rounded-full text-xs font-semibold"><?= e((string)$p['stock']) ?></span>
                </td>
                <td class="px-6 py-3.5 text-center">
                    <div class="flex justify-center gap-1.5">
                        <a href="?module=inventory&view_product=<?= (int)$p['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="View">View</a>
                        <?php if($canManageInventory): ?>
                        <a href="?module=inventory&edit_product=<?= (int)$p['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="Edit">Edit</a>
                        <form method="POST" onsubmit="return confirm('Delete this product?');" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="delete_product" value="1">
                            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                            <button class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="Delete">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        </table>
    </div>
    <?= render_pagination($prodsMeta) ?>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-2">
                <h3 class="font-semibold text-slate-800">Batch Tracking (FEFO)</h3>
                <span class="inline-block bg-slate-200 text-slate-700 px-2.5 py-0.5 rounded-full text-xs font-medium"><?= (int)$batchesMeta['total'] ?></span>
                <?php if($lowStockFilter): ?><span class="inline-block bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded-full text-xs font-medium">Low Stock Only</span><?php endif; ?>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="module" value="inventory">
                <input type="hidden" name="product_search" value="<?= e($productSearch) ?>">
                <input type="hidden" name="category" value="<?= e($categoryFilter) ?>">
                <input type="hidden" name="supplier_id" value="<?= (int)$supplierFilter ?>">
                <input type="hidden" name="expiry" value="<?= e($expiryFilter) ?>">
                <input type="hidden" name="low_stock" value="<?= $lowStockFilter ? '1' : '0' ?>">
                <input type="hidden" name="show_products" value="<?= $showProducts ? '1' : '0' ?>">
                <input type="text" name="batch_search" value="<?= e($batchSearch) ?>" placeholder="Search batch no, product, supplier" class="px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                <button class="bg-primary hover:bg-teal-800 text-white px-3 py-2 rounded-lg text-sm font-medium">Search</button>
                <?php if($batchSearch !== ''): ?><a href="?module=inventory&product_search=<?= e($productSearch) ?>&category=<?= e($categoryFilter) ?>&supplier_id=<?= (int)$supplierFilter ?>&expiry=<?= e($expiryFilter) ?>&low_stock=<?= $lowStockFilter ? '1' : '0' ?>&show_products=<?= $showProducts ? '1' : '0' ?>" class="text-sm text-primary hover:underline">Clear</a><?php endif; ?>
            </form>
        </div>
        <p class="text-xs text-slate-500 mt-2">Sorted by expiry date (oldest first)</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm"><thead class="border-b border-slate-200 bg-slate-50"><tr><th class="px-6 py-3 text-left font-semibold text-slate-700">Product</th><th class="px-6 py-3 text-left font-semibold text-slate-700">Supplier</th><th class="px-6 py-3 text-left font-semibold text-slate-700">Branch</th><th class="px-6 py-3 text-left font-semibold text-slate-700">Batch No</th><th class="px-6 py-3 text-left font-semibold text-slate-700">Category</th><th class="px-6 py-3 text-left font-semibold text-slate-700">Expiry</th><th class="px-6 py-3 text-right font-semibold text-slate-700">Qty</th><th class="px-6 py-3 text-right font-semibold text-slate-700">Sell Price</th><th class="px-6 py-3 text-center font-semibold text-slate-700">Status</th><th class="px-6 py-3 text-center font-semibold text-slate-700">Actions</th></tr></thead>
    <tbody>
    <?php if(empty($batches)): ?>
        <tr><td colspan="10" class="px-6 py-8 text-center text-slate-500">No batch records match current filters.</td></tr>
    <?php else: ?>
        <?php foreach($batches as $b): ?>
            <?php
                $d = strtotime((string)$b['expiry_date']);
                $days = (int)ceil(($d - time()) / 86400);
                $bgRow = $days < 0 ? 'bg-red-50' : ($days <= 30 ? 'bg-amber-50' : '');
                if($days < 0){
                    $statusBadge = '<span class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700">Expired</span>';
                } else if($days <= 30){
                    $statusBadge = '<span class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700">Expiring</span>';
                } else {
                    $statusBadge = '<span class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">Safe</span>';
                }
            ?>
            <tr class="border-b border-slate-100 hover:bg-slate-50 transition <?= $bgRow ?>">
                <td class="px-6 py-3.5 font-medium text-slate-900"><?= e((string)$b['name']) ?><br><span class="text-xs text-slate-500"><?= e((string)$b['generic_name']) ?></span></td>
                <td class="px-6 py-3.5 text-slate-600"><?= e((string)($b['supplier_name'] ?? '-')) ?></td>
                <td class="px-6 py-3.5 text-slate-600"><?= e((string)($b['branch_name'] ?? '-')) ?></td>
                <td class="px-6 py-3.5 font-mono text-slate-700"><?= e((string)$b['batch_no']) ?></td>
                <td class="px-6 py-3.5 text-slate-600"><?= e((string)$b['category']) ?></td>
                <td class="px-6 py-3.5 font-medium text-slate-900"><?= e((string)$b['expiry_date']) ?></td>
                <td class="px-6 py-3.5 text-right font-semibold text-slate-900"><?= e((string)$b['quantity']) ?></td>
                <td class="px-6 py-3.5 text-right text-slate-900"><?= npr((float)$b['sell_price']) ?></td>
                <td class="px-6 py-3.5 text-center"><?= $statusBadge ?></td>
                <td class="px-6 py-3.5 text-center">
                    <?php $canManageThisBatch = $canManageInventory && ($isAdmin || (int)($b['branch_id'] ?? 0) === $currentUserBranchId); ?>
                    <div class="flex justify-center gap-1.5">
                        <a href="?module=inventory&view_batch=<?= (int)$b['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="View">View</a>
                        <?php if($canManageThisBatch): ?>
                            <a href="?module=inventory&edit_batch=<?= (int)$b['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="Edit">Edit</a>
                            <form method="POST" onsubmit="return confirm('Delete this batch?');" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="delete_batch" value="1">
                                <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
                                <button class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="Delete">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody></table>
    </div>
    <?= render_pagination($batchesMeta) ?>
</div>
</div>

<script>
function toggleFormPanel(panelId){
    var panel = document.getElementById(panelId);
    panel.classList.toggle('hidden');
}
function toggleFilterPanel(){
    var panel = document.getElementById('filterPanel');
    var btn = document.getElementById('filterToggleBtn');
    panel.classList.toggle('hidden');
    var visible = !panel.classList.contains('hidden');
    btn.textContent = visible ? 'Hide Filter' : 'Filter';
}

function filterTransferProducts(){
    var search = document.getElementById('transfer_product_search');
    var product = document.getElementById('transfer_product');
    if(!search || !product) return;

    var term = search.value.toLowerCase().trim();
    var visibleCount = 0;

    Array.prototype.slice.call(product.options).forEach(function(opt, idx){
        if(idx === 0){
            opt.hidden = false;
            opt.disabled = false;
            return;
        }
        var matches = !term || opt.text.toLowerCase().indexOf(term) !== -1;
        opt.hidden = !matches;
        opt.disabled = !matches;
        if(matches) visibleCount++;
    });

    if(product.selectedIndex > 0 && product.options[product.selectedIndex].hidden){
        product.value = '';
    }

    if(term && visibleCount === 1){
        var firstMatch = Array.prototype.slice.call(product.options).find(function(opt, idx){
            return idx > 0 && !opt.hidden;
        });
        if(firstMatch){
            product.value = firstMatch.value;
        }
    }

    refreshTransferBatchOptions();
}

function refreshTransferBatchOptions(){
    var fromBranch = document.getElementById('transfer_from_branch');
    var product = document.getElementById('transfer_product');
    var batch = document.getElementById('transfer_batch');
    var hint = document.getElementById('transfer_batch_hint');
    if(!fromBranch || !product || !batch) return;

    var branchId = fromBranch.value;
    var productId = product.value;
    var count = 0;

    Array.prototype.slice.call(batch.options).forEach(function(opt, idx){
        if(idx === 0){
            opt.hidden = false;
            return;
        }
        var matchesBranch = !branchId || opt.dataset.branchId === branchId;
        var matchesProduct = !productId || opt.dataset.productId === productId;
        var visible = matchesBranch && matchesProduct;
        opt.hidden = !visible;
        if(visible) count++;
    });

    if(batch.selectedIndex > 0 && batch.options[batch.selectedIndex].hidden){
        batch.value = '';
    }

    if(hint){
        hint.textContent = count > 0 ? (count + ' batch option(s) available for transfer.') : 'No matching batches found for selected source branch and product.';
    }
}

function validateTransferStockForm(){
    var fromBranch = document.getElementById('transfer_from_branch');
    var toBranch = document.getElementById('transfer_to_branch');
    var qty = parseFloat((document.getElementById('transfer_qty') || {}).value || '0');
    var batch = document.getElementById('transfer_batch');

    if(fromBranch && toBranch && fromBranch.value && toBranch.value && fromBranch.value === toBranch.value){
        alert('Source and destination branches must be different.');
        return false;
    }

    if(batch && batch.value){
        var selected = batch.options[batch.selectedIndex];
        var available = parseFloat(selected.dataset.qty || '0');
        if(qty > available){
            alert('Transfer quantity exceeds selected batch quantity.');
            return false;
        }
    }

    return true;
}
</script>