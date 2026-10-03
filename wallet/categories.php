<?php
// تصنيفات المصاريف المسبقة
require __DIR__ . '/includes/bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = post('action');
    $name = mb_substr(post('name'), 0, 100);
    $icon = mb_substr(post('icon'), 0, 8);
    $sort = (int)post('sort_order');
    $id   = (int)post('id');
    try {
        if ($action === 'create') {
            if ($name === '') throw new WalletError('أدخل اسم التصنيف');
            $pdo->prepare("INSERT INTO wl_categories (name, icon, sort_order) VALUES (?, ?, ?)")->execute([$name, $icon, $sort]);
            flash('success', 'تمت إضافة التصنيف');
        } elseif ($action === 'update') {
            if ($name === '') throw new WalletError('أدخل اسم التصنيف');
            $pdo->prepare("UPDATE wl_categories SET name = ?, icon = ?, sort_order = ? WHERE id = ?")->execute([$name, $icon, $sort, $id]);
            flash('success', 'تم الحفظ');
        } elseif ($action === 'toggle') {
            $pdo->prepare("UPDATE wl_categories SET active = 1 - active WHERE id = ?")->execute([$id]);
            flash('success', 'تم تغيير الحالة');
        } elseif ($action === 'delete') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM wl_transactions WHERE category_id = ?");
            $st->execute([$id]);
            if ($st->fetchColumn()) throw new WalletError('التصنيف مستخدم في مصاريف مسجّلة — أوقفه بدل حذفه');
            $pdo->prepare("DELETE FROM wl_categories WHERE id = ?")->execute([$id]);
            flash('success', 'تم حذف التصنيف');
        }
    } catch (WalletError $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('categories.php');
}

$cats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM wl_transactions t WHERE t.category_id = c.id) AS used,
                            (SELECT COALESCE(SUM(-t.amount),0) FROM wl_transactions t WHERE t.category_id = c.id AND t.voided = 0) AS total
                     FROM wl_categories c ORDER BY c.active DESC, c.sort_order, c.name")->fetchAll();

$pageTitle = 'التصنيفات';
$active = 'categories';
require __DIR__ . '/includes/header.php';
?>
<h1>🏷️ تصنيفات المصاريف</h1>
<div class="card">
  <h2>➕ تصنيف جديد</h2>
  <form method="post" class="filters">
    <?= csrfField() ?><input type="hidden" name="action" value="create">
    <div class="form-row"><label>أيقونة</label><input type="text" name="icon" maxlength="8" placeholder="🧾"></div>
    <div class="form-row" style="grid-column:span 2"><label>الاسم</label><input type="text" name="name" required></div>
    <div class="form-row"><label>الترتيب</label><input type="number" name="sort_order" value="<?= (count($cats) + 1) * 10 ?>"></div>
    <div class="form-row"><button class="btn">إضافة</button></div>
  </form>
</div>
<div class="card">
  <div class="tbl-wrap">
  <table>
    <thead><tr><th>أيقونة</th><th>الاسم</th><th>الترتيب</th><th>الاستخدام</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cats as $c): $fid = 'cat' . (int)$c['id']; ?>
      <tr style="<?= (int)$c['active'] !== 1 ? 'opacity:.55' : '' ?>">
        <td style="width:80px"><input form="<?= $fid ?>" type="text" name="icon" value="<?= e($c['icon']) ?>" maxlength="8" style="text-align:center"></td>
        <td><input form="<?= $fid ?>" type="text" name="name" value="<?= e($c['name']) ?>" required></td>
        <td style="width:90px"><input form="<?= $fid ?>" type="number" name="sort_order" value="<?= (int)$c['sort_order'] ?>"></td>
        <td class="sub"><?= (int)$c['used'] ?> مصروف<br><span class="num"><?= e(money($c['total'])) ?></span></td>
        <td class="actions" style="white-space:nowrap">
          <form id="<?= $fid ?>" method="post"><?= csrfField() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm">حفظ</button></form>
          <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-gray btn-sm"><?= (int)$c['active'] === 1 ? 'إيقاف' : 'تفعيل' ?></button></form>
          <?php if (!(int)$c['used']): ?>
          <form method="post" data-confirm="حذف التصنيف نهائياً؟"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-red btn-sm">حذف</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint">التصنيف الموقوف لا يظهر عند تسجيل مصروف جديد، لكن يبقى في التقارير القديمة.</p>
</div>
<?php require __DIR__ . '/includes/footer.php';
