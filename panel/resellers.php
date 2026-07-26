<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = (string) ($_POST['action'] ?? 'save_plan');
    if ($action === 'approve_reseller') {
        $userId = trim((string) ($_POST['user_id'] ?? ''));
        $tierCode = trim((string) ($_POST['tier_code'] ?? ''));
        try {
            $tierForApproval = db_fetch($pdo, "SELECT * FROM reseller_tiers WHERE code=? AND is_active=1 LIMIT 1", [$tierCode]);
            if (!$tierForApproval) {
                throw new RuntimeException('Plan is unavailable.');
            }
            $approvalFee = max(0, (int) ($tierForApproval['signup_fee'] ?? 0));
            $approvalRef = 'reseller-plan-' . $userId . '-' . $tierForApproval['id'] . '-' . date('Ym');
            if ($approvalFee > 0) {
                (new WalletService($pdo))->debitPurchase($userId, $approvalFee, $approvalRef, [
                    'kind' => 'reseller_subscription_approval',
                    'tier_code' => $tierCode,
                ]);
            }
            (new ResellerService($pdo))->activate($userId, $tierCode);
            $approvalDays = min(3650, max(0, (int) ($tierForApproval['duration_days'] ?? 0)));
            if ($approvalDays > 0) {
                db_query($pdo, 'UPDATE reseller_profiles SET expires_at=? WHERE user_id=?', [
                    date('Y-m-d H:i:s', time() + ($approvalDays * 86400)),
                    $userId,
                ]);
            }
            flash('success', 'نماینده تأیید و فعال شد.');
        } catch (InsufficientWalletBalance $e) {
            flash('error', 'موجودی کیف پول کاربر برای فعال‌سازی این پلن کافی نیست.');
        } catch (Throwable $e) {
            if (!empty($approvalFee) && !empty($approvalRef)) {
                try {
                    (new WalletService($pdo))->refundPurchase($userId, $approvalFee, $approvalRef, [
                        'kind' => 'reseller_subscription_approval_failed',
                        'tier_code' => $tierCode,
                    ]);
                } catch (Throwable $refundError) {
                    error_log('Panel reseller approval refund: ' . $refundError->getMessage());
                }
            }
            flash('error', 'فعال‌سازی نماینده انجام نشد.');
            error_log('Panel reseller approval: ' . $e->getMessage());
        }
        header('Location: resellers.php');
        exit;
    }
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $name = trim((string) ($_POST['name'] ?? ''));
    if (!$id || $name === '') {
        flash('error', 'اطلاعات پلن کامل نیست.');
    } else {
        $features = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($_POST['features'] ?? '')))));
        db_query($pdo,
            "UPDATE reseller_tiers SET name=?, signup_fee=?, duration_days=?, default_discount_bps=?, credit_limit=?, min_retail_margin=?, can_create_bot=?, auto_approve=?, is_active=?, description=?, features=?, sort_order=? WHERE id=? AND legacy_agent IN ('n','n2')",
            [
                $name,
                max(0, (int) ($_POST['signup_fee'] ?? 0)),
                min(3650, max(0, (int) ($_POST['duration_days'] ?? 0))),
                min(10000, max(0, (int) ($_POST['default_discount_percent'] ?? 0) * 100)),
                max(0, (int) ($_POST['credit_limit'] ?? 0)),
                max(0, (int) ($_POST['min_retail_margin'] ?? 0)),
                isset($_POST['can_create_bot']) ? 1 : 0,
                isset($_POST['auto_approve']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                trim((string) ($_POST['description'] ?? '')),
                json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int) ($_POST['sort_order'] ?? 0),
                $id,
            ]
        );
        flash('success', 'پلن نمایندگی ذخیره شد.');
    }
    header('Location: resellers.php');
    exit;
}

$tiers = db_fetchAll($pdo, "SELECT * FROM reseller_tiers WHERE legacy_agent IN ('n','n2') ORDER BY sort_order,id");
$profiles = db_fetchAll($pdo,
    "SELECT rp.*, u.username, u.Balance, rt.name AS tier_name, rt.code AS tier_code
     FROM reseller_profiles rp JOIN user u ON u.id=rp.user_id JOIN reseller_tiers rt ON rt.id=rp.tier_id
     ORDER BY rp.updated_at DESC LIMIT 100"
);
$pageTitle = 'نمایندگان و پلن‌های فروش';
$pageLede = 'قیمت عضویت، مدت، تخفیف، اعتبار و قابلیت ربات اختصاصی را از یک نقطه مدیریت کنید.';
$activeNav = 'resellers';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="card fade-up">
  <div class="card-head"><div><div class="card-title">پلن‌های قابل فروش</div><div class="card-subtitle">قیمت صفر یعنی پلن رایگان؛ مدت صفر یعنی بدون انقضا.</div></div></div>
  <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:14px">
    <?php foreach ($tiers as $tier): $features = json_decode($tier['features'] ?? '[]', true) ?: []; ?>
      <form method="post" class="card" style="padding:16px;margin:0">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= (int) $tier['id'] ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label class="field" style="grid-column:1/-1"><span>نام پلن</span><input name="name" value="<?= htmlspecialchars($tier['name']) ?>" required></label>
          <label class="field"><span>هزینه عضویت (تومان)</span><input type="number" min="0" name="signup_fee" value="<?= (int) $tier['signup_fee'] ?>"></label>
          <label class="field"><span>مدت (روز)</span><input type="number" min="0" name="duration_days" value="<?= (int) $tier['duration_days'] ?>"></label>
          <label class="field"><span>تخفیف پیش‌فرض (%)</span><input type="number" min="0" max="100" name="default_discount_percent" value="<?= (int) floor($tier['default_discount_bps']/100) ?>"></label>
          <label class="field"><span>سقف اعتبار (تومان)</span><input type="number" min="0" name="credit_limit" value="<?= (int) $tier['credit_limit'] ?>"></label>
          <label class="field"><span>حداقل سود فروش</span><input type="number" min="0" name="min_retail_margin" value="<?= (int) $tier['min_retail_margin'] ?>"></label>
          <label class="field"><span>ترتیب نمایش</span><input type="number" name="sort_order" value="<?= (int) $tier['sort_order'] ?>"></label>
          <label class="field" style="grid-column:1/-1"><span>توضیح کوتاه</span><textarea name="description" rows="2"><?= htmlspecialchars($tier['description'] ?? '') ?></textarea></label>
          <label class="field" style="grid-column:1/-1"><span>امکانات (هر خط یک مورد)</span><textarea name="features" rows="4"><?= htmlspecialchars(implode("\n", $features)) ?></textarea></label>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0;font-size:.82rem">
          <label><input type="checkbox" name="auto_approve" <?= $tier['auto_approve'] ? 'checked' : '' ?>> تأیید خودکار</label>
          <label><input type="checkbox" name="is_active" <?= $tier['is_active'] ? 'checked' : '' ?>> فعال</label>
        </div>
        <button class="btn btn-primary" type="submit">ذخیره پلن <?= htmlspecialchars($tier['code']) ?></button>
      </form>
    <?php endforeach; ?>
  </div>
</div>

<div class="card fade-up d1" style="margin-top:16px">
  <div class="card-head"><div><div class="card-title">آخرین نمایندگان</div><div class="card-subtitle"><?= count($profiles) ?> رکورد اخیر</div></div></div>
  <div class="tbl-wrap"><table><thead><tr><th>کاربر</th><th>پلن</th><th>وضعیت</th><th>موجودی</th><th>انقضا</th><th>به‌روزرسانی</th><th>عملیات</th></tr></thead><tbody>
    <?php foreach ($profiles as $profile): ?><tr>
      <td><a href="user.php?id=<?= urlencode($profile['user_id']) ?>"><?= $profile['username'] ? '@'.htmlspecialchars($profile['username']) : htmlspecialchars($profile['user_id']) ?></a></td>
      <td><span class="tag tag-info"><?= htmlspecialchars($profile['tier_name']) ?></span></td>
      <td><?= htmlspecialchars($profile['status']) ?></td><td class="cn"><?= number_format((int) $profile['Balance']) ?></td>
      <td><?= htmlspecialchars($profile['expires_at'] ?: 'بدون انقضا') ?></td><td><?= htmlspecialchars($profile['updated_at']) ?></td>
      <td><?php if ($profile['status'] === 'pending'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="approve_reseller"><input type="hidden" name="user_id" value="<?= htmlspecialchars($profile['user_id']) ?>"><input type="hidden" name="tier_code" value="<?= htmlspecialchars($profile['tier_code']) ?>"><button class="btn btn-ok btn-sm" type="submit">تأیید</button></form><?php else: ?><span class="cf">—</span><?php endif; ?></td>
    </tr><?php endforeach; ?>
  </tbody></table></div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
