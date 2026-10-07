<?php
// يتوقع: $rows ، $showWallet (bool) ، $canVoid (bool)
if (!$rows): ?>
  <div class="empty">لا توجد حركات</div>
<?php else: ?>
<div class="tbl-wrap">
<table class="txn">
  <thead><tr>
    <th>التاريخ</th>
    <?php if ($showWallet): ?><th>المحفظة</th><?php endif; ?>
    <th>النوع</th>
    <th>التفاصيل</th>
    <th>المبلغ</th>
    <th class="hide-sm">الرصيد بعدها</th>
    <th class="hide-sm">بواسطة</th>
    <?php if ($canVoid): ?><th></th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $v = (int)$r['voided'] === 1; ?>
    <tr class="<?= $v ? 'voided' : '' ?>">
      <td class="num"><?= e($r['txn_date']) ?></td>
      <?php if ($showWallet): ?>
        <td><a href="wallet.php?id=<?= (int)$r['wallet_id'] ?>"><?= e($r['wallet_name']) ?></a></td>
      <?php endif; ?>
      <td class="keep">
        <span class="pill p-<?= e($r['type']) ?>"><?= e(txnTypeLabel($r['type'])) ?></span>
        <?php if ($v): ?><br><span class="pill p-void" title="<?= e($r['void_reason']) ?>">ملغاة</span><?php endif; ?>
      </td>
      <td class="t-details">
        <?php if ($r['type'] === 'expense'): ?>
          <b><?= e($r['cat_icon'] . ' ' . $r['cat_name']) ?></b>
        <?php elseif ($r['counter_name']): ?>
          <?= $r['type'] === 'transfer_in' ? 'من: ' : 'إلى: ' ?><b><?= e($r['counter_name']) ?></b>
          <?php if ($r['fx_rate'] && $r['counter_currency'] && $r['counter_currency'] !== $r['currency']): ?>
            <div class="sub">💱 <?= $r['type'] === 'transfer_in' ? 'خرج منها' : 'وصلها' ?> <span class="num"><?= e(money(abs($r['counter_amount']), true, $r['counter_currency'])) ?></span> · <?= e(wlFxLabel($r['currency'], $r['counter_currency'], $r['fx_rate'])) ?></div>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($r['note']): ?><div class="sub"><?= e($r['note']) ?></div><?php endif; ?>
        <?php if ($r['receipt']): ?><div><a class="sub" href="receipt.php?id=<?= (int)$r['id'] ?>" target="_blank">📎 الإيصال</a></div><?php endif; ?>
        <?php if ($v && $r['void_reason']): ?><div class="sub keep">سبب الإلغاء: <?= e($r['void_reason']) ?></div><?php endif; ?>
      </td>
      <td class="num t-amount <?= $r['amount'] < 0 ? 'neg' : 'pos' ?>" style="font-weight:800"><?= $r['amount'] > 0 ? '+' : '' ?><?= e(money($r['amount'], false)) ?><?= $r['currency'] !== baseCurrency() ? ' <small>' . e(currencySymbol($r['currency'])) . '</small>' : '' ?></td>
      <td class="num hide-sm"><?= e(money($r['balance_after'], false)) ?></td>
      <td class="sub hide-sm"><?= e($r['creator_name']) ?></td>
      <?php if ($canVoid): ?>
        <td class="keep t-void">
          <?php if (!$v): ?>
          <form method="post" action="void.php" onsubmit="var r=prompt('سبب إلغاء الحركة؟ (سيُعاد المبلغ للرصيد)');if(r===null)return false;this.reason.value=r;return true;">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="reason" value="">
            <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
            <button class="btn btn-red btn-sm" title="إلغاء الحركة">✕</button>
          </form>
          <?php endif; ?>
        </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif;
