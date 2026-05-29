<?php
require_once __DIR__ . '/../config.php';

// H-10: Authorization check — only admins or users with dashboard.view permission
if(!is_admin_user() && !has_permission('dashboard.view')){
    flash_msg('You do not have permission to view the dashboard.', 'error');
    redirect_with_fallback(get_base_url() . '/dashboard.php?module=sale');
}

$totalSalesToday = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$totalInvoicesToday = (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$totalCustomersDue = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE current_due > 0")->fetchColumn();
$totalDueAmount = (float)$pdo->query("SELECT COALESCE(SUM(current_due),0) FROM customers WHERE current_due > 0")->fetchColumn();
$expiringSoon = (int)$pdo->query("SELECT COUNT(*) FROM batches WHERE quantity > 0 AND expiry_date <= CURDATE() + INTERVAL 30 DAY")->fetchColumn();
$lowStock = (int)$pdo->query("SELECT COUNT(*) FROM products p WHERE COALESCE((SELECT SUM(b.quantity) FROM batches b WHERE b.product_id=p.id),0) < p.min_stock")->fetchColumn();

$salesTrendRows = $pdo->query("SELECT DATE(created_at) AS day_key, COALESCE(SUM(total_amount),0) AS total
    FROM sales
    WHERE DATE(created_at) >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(created_at)
    ORDER BY day_key ASC")->fetchAll();
$salesTrendMap = [];
foreach($salesTrendRows as $row){
    $salesTrendMap[(string)$row['day_key']] = (float)$row['total'];
}
$salesTrend = [];
for($offset = 6; $offset >= 0; $offset--){
    $dayKey = date('Y-m-d', strtotime('-' . $offset . ' days'));
    $salesTrend[] = [
        'date' => $dayKey,
        'label' => date('D', strtotime($dayKey)),
        'total' => $salesTrendMap[$dayKey] ?? 0.0,
    ];
}
$salesTrendMax = 0.0;
foreach($salesTrend as $row){
    $salesTrendMax = max($salesTrendMax, (float)$row['total']);
}

$lowStockItems = $pdo->query("SELECT p.id, p.name, p.min_stock, COALESCE((SELECT SUM(b.quantity) FROM batches b WHERE b.product_id=p.id),0) AS stock
    FROM products p
    WHERE COALESCE((SELECT SUM(b.quantity) FROM batches b WHERE b.product_id=p.id),0) < p.min_stock
    ORDER BY stock ASC, p.name ASC
    LIMIT 5")->fetchAll();

$nearExpiryItems = $pdo->query("SELECT b.id, b.batch_no, b.expiry_date, b.quantity, p.name
    FROM batches b
    JOIN products p ON p.id=b.product_id
    WHERE b.quantity > 0 AND b.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY
    ORDER BY b.expiry_date ASC, b.quantity ASC
    LIMIT 5")->fetchAll();

$recentSales = $pdo->query("SELECT invoice_no, customer_name, total_amount, payment_method, COALESCE(NULLIF(sale_date_bs, ''), DATE(created_at)) AS sale_date_ad FROM sales ORDER BY id DESC LIMIT 10")->fetchAll();
?>

<div class="space-y-6">
    <div class="grid md:grid-cols-3 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-sm text-slate-500">Today's Sales</div>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= npr($totalSalesToday) ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= $totalInvoicesToday ?> invoices</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-sm text-slate-500">Customer Due</div>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= npr($totalDueAmount) ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= $totalCustomersDue ?> customers pending</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-sm text-slate-500">Stock Alerts</div>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= $lowStock + $expiringSoon ?></div>
            <div class="text-xs text-slate-500 mt-1">Low stock: <?= $lowStock ?>, Expiring: <?= $expiringSoon ?></div>
        </div>
    </div>

    <div class="grid xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-slate-800">Sales Analytics</h3>
                    <p class="text-xs text-slate-500 mt-1">Last 7 days trend</p>
                </div>
                <div class="text-sm font-medium text-slate-700"><?= npr($totalSalesToday) ?> today</div>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-7 gap-3 items-end h-60">
                    <?php foreach($salesTrend as $row): ?>
                        <?php $height = $salesTrendMax > 0 ? max(8, (int)round(((float)$row['total'] / $salesTrendMax) * 100)) : 8; ?>
                        <div class="flex flex-col items-center gap-2 h-full justify-end">
                            <div class="w-full flex items-end h-44">
                                <div class="w-full rounded-t-xl bg-gradient-to-t from-teal-700 to-teal-400" style="height: <?= $height ?>%;"></div>
                            </div>
                            <div class="text-xs font-medium text-slate-600"><?= e($row['label']) ?></div>
                            <div class="text-[11px] text-slate-500"><?= npr((float)$row['total']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
                <h3 class="font-semibold text-slate-800">Alerts</h3>
                <p class="text-xs text-slate-500 mt-1">Stock and expiry summary</p>
            </div>
            <div class="p-6 space-y-4">
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs text-slate-500">Low Stock Products</div>
                    <div class="text-2xl font-bold text-slate-900 mt-1"><?= count($lowStockItems) ?></div>
                    <div class="text-xs text-slate-500 mt-1">Items shown on dashboard</div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs text-slate-500">Near Expiry Batches</div>
                    <div class="text-2xl font-bold text-slate-900 mt-1"><?= count($nearExpiryItems) ?></div>
                    <div class="text-xs text-slate-500 mt-1">Within next 30 days</div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-slate-800">Low Stock Products</h3>
                    <p class="text-xs text-slate-500 mt-1">Top 5 items below minimum stock</p>
                </div>
                <a href="?module=inventory&low_stock=1&show_products=1" class="text-sm text-primary hover:underline font-medium">View All</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-6 py-3 text-left font-semibold text-slate-700">Product</th>
                            <th class="px-6 py-3 text-right font-semibold text-slate-700">Stock</th>
                            <th class="px-6 py-3 text-right font-semibold text-slate-700">Min</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($lowStockItems)): ?>
                            <tr><td colspan="3" class="px-6 py-8 text-center text-slate-500">No low stock items.</td></tr>
                        <?php else: foreach($lowStockItems as $item): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                                <td class="px-6 py-3.5 font-medium text-slate-900"><?= e((string)$item['name']) ?></td>
                                <td class="px-6 py-3.5 text-right text-amber-700 font-semibold"><?= e((string)$item['stock']) ?></td>
                                <td class="px-6 py-3.5 text-right text-slate-700"><?= e((string)$item['min_stock']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-slate-800">Near Expiry Batches</h3>
                    <p class="text-xs text-slate-500 mt-1">Top 5 batches expiring within 30 days</p>
                </div>
                <a href="?module=inventory&expiry=30&show_products=0" class="text-sm text-primary hover:underline font-medium">View All</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-6 py-3 text-left font-semibold text-slate-700">Product</th>
                            <th class="px-6 py-3 text-left font-semibold text-slate-700">Batch</th>
                            <th class="px-6 py-3 text-left font-semibold text-slate-700">Expiry</th>
                            <th class="px-6 py-3 text-right font-semibold text-slate-700">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($nearExpiryItems)): ?>
                            <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">No near expiry batches.</td></tr>
                        <?php else: foreach($nearExpiryItems as $item): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                                <td class="px-6 py-3.5 font-medium text-slate-900"><?= e((string)$item['name']) ?></td>
                                <td class="px-6 py-3.5 text-slate-700 font-mono"><?= e((string)$item['batch_no']) ?></td>
                                <td class="px-6 py-3.5 text-slate-700"><?= e((string)$item['expiry_date']) ?></td>
                                <td class="px-6 py-3.5 text-right text-slate-900 font-semibold"><?= e((string)$item['quantity']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="font-semibold text-slate-800">Recent Sales</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Invoice</th>
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Customer</th>
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Date (AD)</th>
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Method</th>
                        <th class="px-6 py-3 text-right font-semibold text-slate-700">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($recentSales)): ?>
                        <tr><td colspan="5" class="px-6 py-8 text-center text-slate-500">No sales yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($recentSales as $s): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                                <td class="px-6 py-3.5 font-medium text-slate-900"><?= e((string)$s['invoice_no']) ?></td>
                                <td class="px-6 py-3.5 text-slate-700"><?= e((string)($s['customer_name'] ?: 'Walk-In Customer')) ?></td>
                                <td class="px-6 py-3.5 text-slate-700"><?= e((string)($s['sale_date_ad'] ?? '-')) ?></td>
                                <td class="px-6 py-3.5 text-slate-700"><?= e(ucfirst((string)$s['payment_method'])) ?></td>
                                <td class="px-6 py-3.5 text-right text-slate-900"><?= npr((float)$s['total_amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
