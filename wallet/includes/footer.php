</main>
<footer class="foot"><?= e(setting($pdo, 'app_name', 'Elias Control')) ?> · <?= date('Y') ?></footer>
<script>
// تنسيق حقول المبالغ أثناء الكتابة (1,500,000)
document.querySelectorAll('input[data-money]').forEach(function (inp) {
  inp.addEventListener('input', function () {
    var raw = inp.value.replace(/[^\d.]/g, '');
    var parts = raw.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    inp.value = parts.length > 1 ? parts[0] + '.' + parts[1].slice(0, 2) : parts[0];
  });
});
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (ev) {
    if (!confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
  });
});
</script>
</body>
</html>
