<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'price_rule_save') {
  csrf_check_post();
  $productId = max(0, (int) ($_POST['product_id'] ?? 0));
  $tierId = max(0, (int) ($_POST['tier_id'] ?? 0));
  $resellerUserId = trim((string) ($_POST['reseller_user_id'] ?? ''));
  $operation = (string) ($_POST['operation'] ?? 'new');
  $mode = (string) ($_POST['mode'] ?? 'fixed');
  $allowedOperations = ['new', 'renew', 'extra_volume', 'extra_time'];
  $allowedModes = ['fixed', 'percentage_discount', 'markup'];

  if (!$productId || (!in_array($operation, $allowedOperations, true)) || (!in_array($mode, $allowedModes, true))) {
    flash('error', 'اطلاعات قانون قیمت‌گذاری معتبر نیست.');
    header('Location: product.php');
    exit;
  }
  if ($resellerUserId === '' && !$tierId) {
    flash('error', 'یک سطح نمایندگی یا شناسه نماینده انتخاب کنید.');
    header('Location: product.php');
    exit;
  }

  try {
    $pdo->beginTransaction();
    if ($resellerUserId !== '') {
      db_query(
        $pdo,
        "DELETE FROM product_prices WHERE product_id=? AND operation=? AND reseller_user_id=?",
        [$productId, $operation, $resellerUserId]
      );
      $tierId = 0;
    } else {
      db_query(
        $pdo,
        "DELETE FROM product_prices WHERE product_id=? AND operation=? AND reseller_user_id IS NULL AND tier_id=?",
        [$productId, $operation, $tierId]
      );
    }
    db_query(
      $pdo,
      "INSERT INTO product_prices
       (product_id,tier_id,reseller_user_id,operation,mode,amount,percent_bps,min_retail_price,max_retail_price,priority,is_active)
       VALUES (?,?,?,?,?,?,?,?,?,?,1)",
      [
        $productId,
        $tierId ?: null,
        $resellerUserId !== '' ? $resellerUserId : null,
        $operation,
        $mode,
        (int) ($_POST['amount'] ?? 0),
        max(0, min(10000, (int) round(((float) ($_POST['percentage'] ?? 0)) * 100))),
        ($_POST['min_retail_price'] ?? '') !== '' ? max(0, (int) $_POST['min_retail_price']) : null,
        ($_POST['max_retail_price'] ?? '') !== '' ? max(0, (int) $_POST['max_retail_price']) : null,
        (int) ($_POST['priority'] ?? 0),
      ]
    );
    $pdo->commit();
    flash('success', 'قیمت اختصاصی با موفقیت ذخیره شد.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    flash('error', 'ذخیره قیمت اختصاصی انجام نشد: ' . $e->getMessage());
  }
  header('Location: product.php');
  exit;
}

if (isset($_GET['delete_price_rule'])) {
  csrf_check_get();
  db_query($pdo, 'DELETE FROM product_prices WHERE id = ?', [(int) $_GET['delete_price_rule']]);
  flash('success', 'قانون قیمت‌گذاری حذف شد.');
  header('Location: product.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  csrf_check_post();
  $name = trim($_POST['name_product'] ?? '');
  if ($name === '') {
    flash('error', $textbotlang['panel']['productNameRequired']);
    header('Location: product.php');
    exit;
  }
  if (db_count($pdo, "SELECT COUNT(*) FROM product WHERE name_product = ?", [$name])) {
    flash('error', $textbotlang['panel']['productNameExists']);
    header('Location: product.php');
    exit;
  }
  $code = bin2hex(random_bytes(2));
  try {
    db_query(
      $pdo,
      "INSERT INTO product (name_product,code_product,price_product,Volume_constraint,Service_time,Location,agent,data_limit_reset,note,category,hide_panel,one_buy_status,ip_limit) VALUES (?,?,?,?,?,?,?,'no_reset',?,?,'{}','0',?)",
      [$name, $code, max(0, (int) ($_POST['price_product'] ?? 0)), max(0, (int) ($_POST['volume_product'] ?? 0)), max(0, (int) ($_POST['time_product'] ?? 0)), $_POST['namepanel'] ?? '', $_POST['agent_product'] ?? '', $_POST['note_product'] ?? '', $_POST['cetegory_product'] ?? '', max(0, (int) ($_POST['ip_limit'] ?? 0))]
    );
    flash('success', $textbotlang['panel']['productAddedPrefix'] . $name . $textbotlang['panel']['productAddedSuffix']);
  } catch (Exception $e) {
    flash('error', $textbotlang['panel']['productDbError'] . $e->getMessage());
  }
  header('Location: product.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  csrf_check_post();
  $pid = (int) ($_POST['edit_id'] ?? 0);
  $name = trim($_POST['name_product'] ?? '');
  if ($pid && $name !== '') {
    try {
      db_query(
        $pdo,
        "UPDATE product SET name_product=?,price_product=?,Volume_constraint=?,Service_time=?,Location=?,agent=?,note=?,category=?,ip_limit=? WHERE id=?",
        [$name, max(0, (int) ($_POST['price_product'] ?? 0)), max(0, (int) ($_POST['volume_product'] ?? 0)), max(0, (int) ($_POST['time_product'] ?? 0)), $_POST['namepanel'] ?? '', $_POST['agent_product'] ?? '', $_POST['note_product'] ?? '', $_POST['cetegory_product'] ?? '', max(0, (int) ($_POST['ip_limit'] ?? 0)), $pid]
      );
      flash('success', $textbotlang['panel']['productEdited']);
    } catch (Exception $e) {
      flash('error', $textbotlang['panel']['productErrorPrefix'] . $e->getMessage());
    }
  }
  header('Location: product.php');
  exit;
}

if (isset($_GET['delete'])) {
  csrf_check_get();
  $deleteProductId = (int) $_GET['delete'];
  $pdo->beginTransaction();
  try {
    db_query($pdo, "DELETE FROM product_prices WHERE product_id = ?", [$deleteProductId]);
    db_query($pdo, "DELETE FROM product_audiences WHERE product_id = ?", [$deleteProductId]);
    db_query($pdo, "DELETE FROM product WHERE id = ?", [$deleteProductId]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
  flash('success', $textbotlang['panel']['productDeleted']);
  header('Location: product.php');
  exit;
}

$panels = [];
try {
  $panels = db_fetchAll($pdo, "SELECT * FROM marzban_panel");
} catch (Exception $e) {
}
$products = db_fetchAll($pdo, "SELECT * FROM product ORDER BY id");
$resellerTiers = db_fetchAll($pdo, "SELECT * FROM reseller_tiers WHERE is_active=1 ORDER BY id");
$priceRules = db_fetchAll(
  $pdo,
  "SELECT pp.*, p.name_product, rt.name AS tier_name
   FROM product_prices pp
   JOIN product p ON p.id=pp.product_id
   LEFT JOIN reseller_tiers rt ON rt.id=pp.tier_id
   ORDER BY p.id, pp.operation, pp.id DESC"
);

$pageTitle = $textbotlang['panel']['productsTitle'];
$pageLede = $textbotlang['panel']['productsSubtitle'];
$activeNav = 'product';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($products) ?> <?= $textbotlang['panel']['productsHeading'] ?></div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> <?= $textbotlang['panel']['productAddProductBtn'] ?></button>
</div>

<div class="card fade-up d1">
  <?php if (empty($products)): ?>
    <div class="empty" style="padding:60px 20px">
      <svg class="ill" viewBox="0 0 200 160" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="40" y="30" width="120" height="100" rx="12" fill="var(--surface-3)" />
        <rect x="56" y="50" width="88" height="12" rx="6" fill="var(--border-strong)" />
        <rect x="56" y="72" width="60" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="90" width="72" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="108" width="44" height="8" rx="4" fill="var(--border)" />
        <circle cx="155" cy="125" r="22" fill="var(--accent-s)" stroke="var(--accent)" stroke-width="2" />
        <path d="M147 125h16M155 117v16" stroke="var(--accent)" stroke-width="2.5" stroke-linecap="round" />
      </svg>
      <p><?= $textbotlang['panel']['productColName'] ?></p>
      <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?>
        <?= $textbotlang['panel']['productColVolume'] ?></button>
    </div>
  <?php else: ?>
    <div class="toolbar">
      <div class="toolbar-title"><?= $textbotlang['panel']['productColTime'] ?> <small>(<?= count($products) ?>)</small></div>
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" placeholder="<?= htmlspecialchars($textbotlang['panel']['productSearchPlaceholder']) ?>" data-filter="prodTbl">
        <button type="button" class="search-clear">✕</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table id="prodTbl" class="tbl-xl">
        <thead>
          <tr>
            <th>#</th>
            <th><?= $textbotlang['panel']['productColPrice'] ?></th>
            <th><?= $textbotlang['panel']['productColActions'] ?></th>
            <th><?= $textbotlang['panel']['productNoProductFound'] ?></th>
            <th><?= $textbotlang['panel']['productNoProductYet'] ?></th>
            <th><?= $textbotlang['panel']['productAddProductTitle'] ?></th>
            <th><?= $textbotlang['panel']['productEditProductTitle'] ?></th>
            <th><?= $textbotlang['panel']['productFieldProductName'] ?></th>
            <th><?= $textbotlang['panel']['productFieldVolumeGb'] ?></th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($products as $p): ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><?= htmlspecialchars($p['name_product'] ?? '') ?></td>
              <td class="cn cs"><?= number_format((int) ($p['price_product'] ?? 0)) ?> <span class="cf"><?= $textbotlang['panel']['productFieldServiceDays'] ?></span></td>
              <td class="cn"><?= htmlspecialchars($p['Volume_constraint'] ?? '—') ?> <span class="cf">GB</span></td>
              <td class="cn"><?= htmlspecialchars($p['Service_time'] ?? '—') ?> <span class="cf"><?= $textbotlang['panel']['productFieldPriceToman'] ?></span></td>
              <td class="cf"><?= htmlspecialchars(trunc($p['Location'] ?? '—', 16)) ?></td>
              <td><?php if (!empty($p['category'])): ?><span
                    class="tag tag-info"><?= htmlspecialchars($p['category']) ?></span><?php else: ?><span
                    class="cf">—</span><?php endif; ?></td>
              <td class="cm" style="font-size:.72rem"><?= htmlspecialchars($p['code_product'] ?? '') ?></td>
              <td>
                <div style="display:flex;gap:5px">
                  <button class="btn btn-ghost btn-sm" title="قیمت نمایندگان"
                    onclick="openPriceModal(<?= (int) $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name_product'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                    قیمت نماینده
                  </button>
                  <button class="btn btn-ghost btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productEditBtn']) ?>"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
                    <?= icon('edit', 13) ?>
                  </button>
                  <a href="product.php?delete=<?= (int) $p['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-no btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productDeleteBtn']) ?>"
                    data-confirm="<?= htmlspecialchars(sprintf($textbotlang['panel']['productConfirmDeleteProduct'], $p['name_product'])) ?>">
                    <?= icon('trash', 13) ?>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card fade-up d1" style="margin-top:18px">
  <div class="toolbar">
    <div class="toolbar-title">قیمت‌های اختصاصی نمایندگان <small>(<?= count($priceRules) ?>)</small></div>
  </div>
  <?php if (!$priceRules): ?>
    <div class="empty" style="padding:28px">هنوز قیمت اختصاصی تعریف نشده است.</div>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-xl">
        <thead><tr><th>محصول</th><th>مخاطب</th><th>عملیات</th><th>نوع</th><th>مقدار</th><th>کف فروش</th><th>عملیات</th></tr></thead>
        <tbody>
          <?php foreach ($priceRules as $rule): ?>
            <tr>
              <td><?= htmlspecialchars($rule['name_product']) ?></td>
              <td><?= htmlspecialchars($rule['reseller_user_id'] ?: ($rule['tier_name'] ?: '—')) ?></td>
              <td><?= htmlspecialchars($rule['operation']) ?></td>
              <td><?= htmlspecialchars($rule['mode']) ?></td>
              <td class="cn"><?php if ($rule['mode'] === 'percentage_discount'): ?><?= number_format(((int) $rule['percent_bps']) / 100, 2) ?>٪<?php else: ?><?= number_format((int) $rule['amount']) ?> تومان<?php endif; ?></td>
              <td class="cn"><?= $rule['min_retail_price'] !== null ? number_format((int) $rule['min_retail_price']) : '—' ?></td>
              <td><a class="btn btn-no btn-sm" href="product.php?delete_price_rule=<?= (int) $rule['id'] ?>&_csrf=<?= csrf_token() ?>" data-confirm="این قانون قیمت حذف شود؟">حذف</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="modal-veil" id="addModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['productFieldProductType'] ?></h3>
      <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
          <div class="field full">
            <label><?= $textbotlang['panel']['productFieldDescription'] ?></label>
            <input type="text" name="name_product" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productNameExample']) ?>" required>
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productSaveBtn'] ?></label>
            <input type="number" name="price_product" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productZeroValue']) ?>" min="0">
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productVolumeGbSuffix'] ?></label>
            <input type="number" name="volume_product" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productFiftyValue']) ?>" min="0">
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productCancelBtn'] ?></label>
            <input type="number" name="time_product" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productThirtyValue']) ?>" min="0">
          </div>
          <div class="field">
            <label>IP Limit (تعداد آی‌پی مجاز)</label>
            <input type="number" name="ip_limit" class="input" value="0" min="0" step="1">
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productFieldLocation'] ?></label>
            <input type="text" name="cetegory_product" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productTypeExample']) ?>">
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productFieldCategory'] ?></label>
            <select name="namepanel" class="select">
              <option value=""><?= $textbotlang['panel']['productFieldNote'] ?></option>
              <?php foreach ($panels as $pl): ?>
                <option value="<?= htmlspecialchars($pl['name_panel'] ?? $pl['id']) ?>">
                  <?= htmlspecialchars($pl['name_panel'] ?? $pl['id']) ?>
                </option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productColId'] ?></label>
            <select name="agent_product" class="select">
              <option value="f"><?= $textbotlang['panel']['productColType'] ?></option>
              <option value="n"><?= $textbotlang['panel']['productColLocation'] ?></option>
              <option value="n2"><?= $textbotlang['panel']['productColCategory'] ?></option>
            </select>
          </div>
          <div class="field full">
            <label><?= $textbotlang['panel']['productColDescription'] ?></label>
            <input type="text" name="note_product" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productDescriptionOptional']) ?>">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> <?= $textbotlang['panel']['productColNote'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')"><?= $textbotlang['panel']['productColCreatedAt'] ?></button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['productDetailTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('editModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="form-grid">
          <div class="field full">
            <label><?= $textbotlang['panel']['productDetailName'] ?></label>
            <input type="text" name="name_product" id="edit_name" class="input" required>
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productDetailVolume'] ?></label>
            <input type="number" name="price_product" id="edit_price" class="input" min="0">
          </div>
          <div class="field">
            <label>IP Limit (تعداد آی‌پی مجاز)</label>
            <input type="number" name="ip_limit" id="edit_ip_limit" class="input" min="0" step="1">
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productVolumeGbSuffix'] ?></label>
            <input type="number" name="volume_product" id="edit_volume" class="input" min="0">
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productDetailTime'] ?></label>
            <input type="number" name="time_product" id="edit_time" class="input" min="0">
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productDetailPrice'] ?></label>
            <input type="text" name="cetegory_product" id="edit_cat" class="input">
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productDetailType'] ?></label>
            <select name="namepanel" id="edit_panel" class="select">
              <option value=""><?= $textbotlang['panel']['productDetailLocation'] ?></option>
              <?php foreach ($panels as $pl): ?>
                <option value="<?= htmlspecialchars($pl['name_panel'] ?? $pl['id']) ?>">
                  <?= htmlspecialchars($pl['name_panel'] ?? $pl['id']) ?>
                </option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productDetailCategory'] ?></label>
            <select name="agent_product" id="edit_agent" class="select">
              <option value="f"><?= $textbotlang['panel']['productDetailDescription'] ?></option>
              <option value="n"><?= $textbotlang['panel']['productDetailNote'] ?></option>
              <option value="n2"><?= $textbotlang['panel']['productCloseBtn'] ?></option>
            </select>
          </div>
          <div class="field full">
            <label><?= $textbotlang['panel']['productUnlimitedLabel'] ?></label>
            <input type="text" name="note_product" id="edit_note" class="input">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $textbotlang['panel']['productDayUnit'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')"><?= $textbotlang['panel']['productTomanUnit'] ?></button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="priceModal">
  <div class="modal">
    <div class="modal-head">
      <h3>قیمت اختصاصی: <span id="price_product_name"></span></h3>
      <button class="modal-x" onclick="closeModal('priceModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="price_rule_save">
        <input type="hidden" name="product_id" id="price_product_id">
        <div class="form-grid">
          <div class="field">
            <label>سطح نمایندگی</label>
            <select name="tier_id" class="select">
              <option value="">انتخاب سطح</option>
              <?php foreach ($resellerTiers as $tier): ?>
                <option value="<?= (int) $tier['id'] ?>"><?= htmlspecialchars($tier['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>یا شناسه نماینده خاص</label>
            <input name="reseller_user_id" class="input" inputmode="numeric" placeholder="Telegram ID">
          </div>
          <div class="field">
            <label>نوع خرید</label>
            <select name="operation" class="select">
              <option value="new">فروش جدید</option>
              <option value="renew">تمدید</option>
              <option value="extra_volume">حجم اضافه</option>
              <option value="extra_time">زمان اضافه</option>
            </select>
          </div>
          <div class="field">
            <label>روش قیمت</label>
            <select name="mode" class="select">
              <option value="fixed">قیمت ثابت</option>
              <option value="percentage_discount">تخفیف درصدی</option>
              <option value="markup">افزایش روی قیمت پایه</option>
            </select>
          </div>
          <div class="field">
            <label>مبلغ ثابت / افزایش (تومان)</label>
            <input type="number" name="amount" class="input" value="0">
          </div>
          <div class="field">
            <label>درصد</label>
            <input type="number" name="percentage" class="input" value="0" min="0" max="100" step="0.01">
          </div>
          <div class="field">
            <label>حداقل قیمت فروش</label>
            <input type="number" name="min_retail_price" class="input" min="0">
          </div>
          <div class="field">
            <label>حداکثر قیمت فروش</label>
            <input type="number" name="max_retail_price" class="input" min="0">
          </div>
          <input type="hidden" name="priority" value="0">
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary">ذخیره قیمت</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('priceModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<script src="js/product.js"></script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
