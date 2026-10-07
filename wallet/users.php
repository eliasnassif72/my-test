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

            $cur  = post('currency') ?: baseCurrency();
            $curs = wlCurrencies();
            if ($withWallet && (!isset($curs[$cur]) || ($cur !== baseCurrency() && (float)$curs[$cur]['rate'] <= 0))) {
                throw new WalletError('عملة المحفظة غير صالحة أو سعرها غير محدد');
            }
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO wl_users (username, password_hash, full_name, phone, role) VALUES (?, ?, ?, ?, ?)")
                ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $name, post('phone') ?: null, $role]);
            $uid = (int)$pdo->lastInsertId();
            if ($withWallet) {
                $pdo->prepare("INSERT INTO wl_wallets (user_id, name, currency) VALUES (?, ?, ?)")->execute([$uid, $name, $cur]);
            }
            $pdo->commit();
            flash('success', 'تم إنشاء المستخدم «' . $name . '»' . ($withWallet ? ' ومحفظته' : ''));
        } elseif ($action === 'update') {
            $id   = (int)post('id');
            $name = post('full_name');
            $role = post('role') === 'admin' ? 'admin' : 'user';
            if ($name === '') throw new WalletError('أدخل الاسم الكامل');
            if ($id === (int)$me['id'] && $role !== 'admin') throw new WalletError('لا يمكنك إزالة صلاحية المدير عن نفسك');
            $pass = isset($_POST['password']) ? (string)$_POST['password'] : '';
            if ($pass !== '' && strlen($pass) < 6) throw new WalletError('كلمة المرور 6 أحرف على الأقل — لم يُحفظ أي تعديل');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE wl_users SET full_name = ?, phone = ?, role = ? WHERE id = ?")
                ->execute([$name, post('phone') ?: null, $role, $id]);
            if (post('wallet_name') !== '') {
                $pdo->prepare("UPDATE wl_wallets SET name = ? WHERE user_id = ?")->execute([post('wallet_name'), $id]);
            }
            $pdo->prepare("UPDATE wl_wallets SET account_no = ?, account_name = ? WHERE user_id = ?")
                ->execute([preg_replace('/[^0-9A-Za-z]/', '', post('account_no')) ?: null, mb_substr(post('account_name'), 0, 120) ?: null, $id]);
            if ($pass !== '') {
                $pdo->prepare("UPDATE wl_users SET password_hash = ? WHERE id = ?")->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            }
            $pdo->commit();
            flash('success', 'تم حفظ التعديلات');
        } elseif ($action === 'toggle') {
            $id = (int)post('id');
            if ($id === (int)$me['id']) throw new WalletError('لا يمكنك إيقاف حسابك');
            $st = $pdo->prepare("SELECT COUNT(*) FROM wl_wallets WHERE user_id = ? AND archived = 1");
            $st->execute([$id]);
            if ($st->fetchColumn()) throw new WalletError('محفظته مؤرشفة — استخدم «استعادة» من قائمة المؤرشفين');
            $pdo->prepare("UPDATE wl_users SET active = 1 - active WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE wl_wallets w JOIN wl_users u ON u.id = w.user_id SET w.active = u.active WHERE u.id = ?")->execute([$id]);
            flash('success', 'تم تغيير حالة الحساب');
        } elseif ($action === 'delete') {
            $id = (int)post('id');
            if ($id === (int)$me['id']) throw new WalletError('لا يمكنك حذف حسابك');
            wlDeleteUser($pdo, $id);
            flash('success', 'تم حذف المستخدم ومحفظته نهائياً');
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

$all = $pdo->query("SELECT u.*, w.id AS wallet_id, w.name AS wallet_name, w.balance, w.currency, w.account_no, w.account_name,
                           COALESCE(w.archived, 0) AS archived, w.archived_at,
                           (SELECT COUNT(*) FROM wl_transactions t
                             WHERE t.created_by = u.id OR t.wallet_id = w.id OR t.counter_wallet_id = w.id) AS txn_count
                    FROM wl_users u LEFT JOIN wl_wallets w ON w.user_id = u.id
                    ORDER BY u.active DESC, u.role, u.full_name")->fetchAll();
$users = [];
$archivedUsers = [];
foreach ($all as $u) {
    if ((int)$u['archived'] === 1) {
        $archivedUsers[] = $u;
    } else {
        $users[] = $u;
    }
}
$edit = null;
if (get('edit')) {
    foreach ($all as $u) {
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
          <div class="grid g2">
            <div class="form-row"><label>رقم حساب السلفة (المحاسبة)</label><input type="text" name="account_no" value="<?= e($edit['account_no']) ?>" dir="ltr" placeholder="1631001"></div>
            <div class="form-row"><label>اسم الحساب</label><input type="text" name="account_name" value="<?= e($edit['account_name']) ?>" placeholder="سلف امين الجندي"></div>
          </div>
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
        <div class="grid g2">
          <div class="form-row"><label class="inline-check" style="margin-top:28px"><input type="checkbox" name="with_wallet" value="1" checked> إنشاء محفظة له</label></div>
          <div class="form-row"><label>عملة المحفظة</label>
            <select name="currency">
              <?php foreach (wlCurrencies() as $c): if (!(int)$c['active'] || ($c['code'] !== baseCurrency() && (float)$c['rate'] <= 0)) continue; ?>
                <option value="<?= e($c['code']) ?>" <?= $c['code'] === baseCurrency() ? 'selected' : '' ?>><?= e($c['name'] . ' (' . $c['symbol'] . ')') ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
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
          <td><b><?= e($u['full_name']) ?></b><div class="sub" dir="ltr" style="text-align:right"><?= e($u['username']) ?><?= $u['account_no'] ? ' · ' . e($u['account_no']) : '' ?></div></td>
          <td><?= $u['role'] === 'admin' ? '<span class="pill p-transfer_out">مدير</span>' : '<span class="pill p-deposit">مستخدم</span>' ?>
              <?= (int)$u['active'] !== 1 ? '<br><span class="pill p-void">موقوف</span>' : '' ?></td>
          <td class="num"><?php if ($u['wallet_id']): ?><a href="wallet.php?id=<?= (int)$u['wallet_id'] ?>"><?= e(money($u['balance'], true, $u['currency'])) ?></a><?php else: ?>
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
            <?php if ($u['wallet_id']): ?>
              <a class="btn btn-gray btn-sm" href="archive.php?wallet=<?= (int)$u['wallet_id'] ?>" title="تسليم العهدة وأرشفة">📦</a>
            <?php endif; ?>
            <?php if ((int)$u['txn_count'] === 0): ?>
            <form method="post" data-confirm="حذف «<?= e($u['full_name']) ?>» ومحفظته نهائياً؟ لا يمكن التراجع.">
              <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn-red btn-sm" title="حذف نهائي (لا توجد حركات)">🗑</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="hint">🗑 الحذف النهائي يظهر فقط لمن ليس عليه أي حركة (أُنشئ بالغلط). لمن ترك العمل استخدم 📦 «تسليم العهدة وأرشفة»: تُصفّى العهدة وتُخفى المحفظة ويبقى سجلها. «إيقاف» يمنع الدخول مؤقتاً فقط.</p>
  </div>
</div>

<div class="card" id="archived">
  <h2>📦 المحافظ المؤرشفة (<?= count($archivedUsers) ?>)</h2>
  <?php if (!$archivedUsers): ?>
    <div class="empty">لا توجد محافظ مؤرشفة</div>
  <?php else: ?>
  <div class="tbl-wrap"><table>
    <thead><tr><th>الاسم</th><th>تاريخ الأرشفة</th><th>الرصيد</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($archivedUsers as $u): ?>
      <tr>
        <td><b><?= e($u['full_name']) ?></b><div class="sub" dir="ltr" style="text-align:right"><?= e($u['username']) ?></div></td>
        <td class="sub num"><?= e(substr((string)$u['archived_at'], 0, 10)) ?></td>
        <td class="num"><?= e(money($u['balance'], true, $u['currency'])) ?></td>
        <td class="actions">
          <a class="btn btn-gray btn-sm" href="wallet.php?id=<?= (int)$u['wallet_id'] ?>">السجل</a>
          <form method="post" action="archive.php" data-confirm="استعادة المحفظة وتفعيل حساب «<?= e($u['full_name']) ?>»؟">
            <?= csrfField() ?><input type="hidden" name="wallet_id" value="<?= (int)$u['wallet_id'] ?>"><input type="hidden" name="action" value="restore">
            <button class="btn btn-light btn-sm">↩️ استعادة</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php';
