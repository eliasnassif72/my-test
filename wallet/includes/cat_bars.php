<?php
// يتوقع: $catTotals
$__max = 0; $__sum = 0;
foreach ($catTotals as $c) { $__max = max($__max, (float)$c['total']); $__sum += (float)$c['total']; }
if (!$catTotals): ?>
  <div class="empty">لا توجد مصاريف في هذه الفترة</div>
<?php else: foreach ($catTotals as $c): ?>
  <div class="bar-row">
    <div class="bar-head">
      <span><?= e($c['icon'] . ' ' . $c['name']) ?> <span class="sub">(<?= (int)$c['cnt'] ?>)</span></span>
      <b class="num"><?= e(money($c['total'])) ?> <span class="sub"><?= $__sum > 0 ? round($c['total'] * 100 / $__sum) : 0 ?>%</span></b>
    </div>
    <div class="bar"><i style="width:<?= $__max > 0 ? max(2, round($c['total'] * 100 / $__max)) : 0 ?>%"></i></div>
  </div>
<?php endforeach; ?>
  <div class="bar-head" style="border-top:1px solid var(--line);padding-top:8px;margin-top:4px"><b>الإجمالي</b><b class="num"><?= e(money($__sum)) ?></b></div>
<?php endif;
