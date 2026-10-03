<?php
// إدارة المستخدمين — كل مستخدم تُنشأ له محفظة تلقائياً
require __DIR__ . '/includes/bootstrap.php';
$me = requireAdmin();

function validUsername($u)
{
    return (bool)preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $u);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = post('action');
    try {
        if ($action === 'create') {
            $username = post('username');
            $name     = post('full_name');
            $pass     = isset($_POST['password']) ? (string)$_POST['password'] : '';
            $role     = post('role') === 'admin' ? 'admin' : 'user';
            $withWallet = $role === 'user' || post('with_wallet') === '1';
            if (!validUsername($username)) throw new WalletError('اسم المستخدم: 3 أحرف على الأقل، إنكليزي/أرقام فقط');
            if ($name === '') throw new WalletError('أدخل الاسم الكامل');
            if (strlen($pass) < 6) throw new WalletError('كلمة المرور 6 أحرف على الأقل');
            $st = $pdo->prepare("SELECT COUNT(*) FROM wl_users WHERE username = ?");
            $st->execute([$username]);
            if ($st->fetchColumn()) throw new WalletError('اسم المستخدم مستخدم مسبقاً');

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO wl_users (username, password_hash, full_name, phone, role) VALUES (?, ?, ?, ?, ?)")
                ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $name, post('phone') ?: null, $role]);
            $uid = (int)$pdo->lastInsertId();
            if ($withWallet) {
                $pdo->prepare("INSERT INTO wl_wallets (user_id, name) VALUES (?, ?)")->execute([$uid, $name]);
            }
            $pdo->commit();
            flash('success', 'تم إنشاء المستخدم «' . $name . '»' . ($withWallet ? ' ومحفظته' : ''));
        } elseif ($action === 'update') {
            $id   = (int)post('id');
            $name = post('full_name');
            $role = post('role') === 'admin' ? 'admin' : 'user';
            if ($name === '') throw new WalletError('أدخل الاسم الكامل');
            if ($id === (int)$me['id'] && $role !== 'admin') throw new WalletError('لا يمكنك إزالة صلاحية المدير عن نفسك');
            $pdo->prepare("UPDATE wl_users SET full_name = ?, phone = ?, role = ? WHERE id = ?")
                ->execute([$name, post('phone') ?: null, $role, $id]);
            if (post('wallet_name') !== '') {
                $pdo->prepare("UPDATE wl_wallets SET name = ? WHERE user_id = ?")->execute([post('wallet_name'), $id]);
            }
            $pass = isset($_POST['password']) ? (string)$_POST['password'] : '';
            if ($pass !== '') {
                if (strlen($pass) < 6) throw new WalletError('كلمة المرور 6 أحرف على الأقل');
                $pdo->prepare("UPDATE wl_users SET password_hash = ? WHERE id = ?")->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            }
            flash('success', 'تم حفظ التعديلات');
        } elseif ($action === 'toggle') {
            $id = (int)post('id');
            if ($id === (int)$me['id']) throw new WalletError('لا يمكنك إيقاف حسابك');
            $pdo->prepare("UPDATE wl_users SET active = 1 - active WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE wl_wallets w JOIN wl_users u ON u.id = w.user_id SET w.active = u.active WHERE u.id = ?")->execute([$id]);
            flash('success', 'تم تغيير حالة الحساب');
        } elseif ($action === 'create_wallet') {
            $id = (int)post('id');
            $st = $pdo->prepare("SELECT full_name FROM wl_users WHERE id = ?");
            $st->execute([$id]);
            $n = $st->fetchColumn();
            if (!$n) throw new WalletError('المستخدم غير موجود');
            $pdo->prepare("INSERT IGNORE INTO wl_wallets (user_id, name) VALUES (?, ?)")->execute([$id, $n]);
            flash('success', 'تم إنشاء المحفظة');
        }
    } catch (WalletError $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('users: ' . $ex->getMessage());
        flash('error', 'حدث خطأ أثناء الحفظ');
    }
    redirect('users.php');
}

$users = $pdo->query("SELECT u.*, w.id AS wallet_id, w.name AS wallet_name, w.balance
                      FROM wl_users u LEFT JOIN wl_wallets w ON w.user_id = u.id
                      ORDER BY u.active DESC, u.role, u.full_name")->fetchAll();
$edit = null;
if (get('edit')) {
    foreach ($users as $u) {
        if ((int)$u['id'] === (int)get('edit')) $edit = $u;
    }
}

$pageTitle = 'المستخدمون';
$active = 'users';
require __DIR__ . '/includes/header.php';
?>
<h1>👥 المستخدمون والمحافظ</h1>

<div class="grid g2" style="align-items:start">
  <div class="card">
    <?php if ($edit): ?>
      <h2>✏️ تعديل: <?= e($edit['full_name']) ?></h2>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <div class="form-row"><label>اسم المستخدم</label><input type="text" value="<?= e($edit['username']) ?>" disabled dir="ltr"></div>
        <div class="form-row"><label>الاسم الكامل</label><input type="text" name="full_name" value="<?= e($edit['full_name']) ?>" required></div>
        <div class="form-row"><label>الهاتف</label><input type="text" name="phone" value="<?= e($edit['phone']) ?>" dir="ltr"></div>
        <?php if ($edit['wallet_id']): ?>
          <div class="form-row"><label>اسم المحفظة</label><input type="text" name="wallet_name" value="<?= e($edit['wallet_name']) ?>"></div>
        <?php endif; ?>
        <div class="form-row"><label>الصلاحية</label>
          <select name="role">
            <option value="user" <?= $edit['role'] === 'user' ? 'selected' : '' ?>>مستخدم (محفظته فقط)</option>
            <option value="admin" <?= $edit['role'] === 'admin' ? 'selected' : '' ?>>مدير النظام</option>
          </select></div>
        <div class="form-row"><label>كلمة مرور جديدة</label><input type="password" name="password" minlength="6" autocomplete="new-password" placeholder="اتركه فارغاً لعدم التغيير"></div>
        <div class="actions"><button class="btn">حفظ</button><a class="btn btn-gray" href="users.php">إلغاء</a></div>
      </form>
    <?php else: ?>
      <h2>➕ مستخدم جديد</h2>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row"><label>الاسم الكامل</label><input type="text" name="full_name" required></div>
        <div class="grid g2">
          <div class="form-row"><label>اسم المستخدم (للدخول)</label><input type="text" name="username" required dir="ltr" pattern="[A-Za-z0-9_.\-]{3,60}" autocomplete="off"></div>
          <div class="form-row"><label>كلمة المرور</label><input type="text" name="password" required minlength="6" dir="ltr" autocomplete="off"></div>
        </div>
        <div class="grid g2">
          <div class="form-row"><label>الهاتف</label><input type="text" name="phone" dir="ltr"></div>
          <div class="form-row"><label>الصلاحية</label>
            <select name="role"><option value="user">مستخدم (محفظته فقط)</option><option value="admin">مدير النظام</option></select></div>
        </div>
        <div class="form-row"><label class="inline-check"><input type="checkbox" name="with_wallet" value="1" checked> إنشاء محفظة له (إلزامي للمستخدم العادي)</label></div>
        <button class="btn btn-block">إنشاء المستخدم ومحفظته</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>القائمة (<?= count($users) ?>)</h2>
    <div class="tbl-wrap">
    <table>
      <thead><tr><th>الاسم</th><th>الصلاحية</th><th>رصيد المحفظة</th><th>آخر دخول</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr style="<?= (int)$u['active'] !== 1 ? 'opacity:.55' : '' ?>">
          <td><b><?= e($u['full_name']) ?></b><div class="sub" dir="ltr" style="text-align:right"><?= e($u['username']) ?></div></td>
          <td><?= $u['role'] === 'admin' ? '<span class="pill p-transfer_out">مدير</span>' : '<span class="pill p-deposit">مستخدم</span>' ?>
              <?= (int)$u['active'] !== 1 ? '<br><span class="pill p-void">موقوف</span>' : '' ?></td>
          <td class="num"><?php if ($u['wallet_id']): ?><a href="wallet.php?id=<?= (int)$u['wallet_id'] ?>"><?= e(money($u['balance'])) ?></a><?php else: ?>
            <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="create_wallet"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn-light btn-sm">+ محفظة</button></form>
          <?php endif; ?></td>
          <td class="sub num"><?= e($u['last_login'] ? substr($u['last_login'], 0, 16) : '—') ?></td>
          <td class="actions">
            <a class="btn btn-gray btn-sm" href="?edit=<?= (int)$u['id'] ?>">✏️</a>
            <?php if ((int)$u['id'] !== (int)$me['id']): ?>
            <form method="post" data-confirm="<?= (int)$u['active'] === 1 ? 'إيقاف الحساب والمحفظة؟' : 'تفعيل الحساب؟' ?>">
              <?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn-sm <?= (int)$u['active'] === 1 ? 'btn-red' : 'btn-light' ?>"><?= (int)$u['active'] === 1 ? 'إيقاف' : 'تفعيل' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="hint">لا يُحذف المستخدم حفاظاً على سجل الحركات — أوقفه بدلاً من ذلك. المحفظة الموقوفة لا تقبل مصاريف ولا تغذية.</p>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php';
