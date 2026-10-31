<?php
require __DIR__.'/vendor/autoload.php';

use IslamGaber\ArabicSearch\ArabicNormalizer;
use IslamGaber\ArabicSearch\LikePattern;

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, city TEXT)');
$rows = [['أحمد إبراهيم', 'جدة'], ['إحمد علي', 'الطائف'], ['أَحْمَد سالم', 'مكة المكرمة'], ['احمد يوسف', 'الرياض'], ['محمود حسن', 'الدمام'], ['مدرسة الأمل الأهلية', 'الطائف'], ['مصطفى عبدالله', 'جدة']];
$ins = $pdo->prepare('INSERT INTO customers (name, city) VALUES (?, ?)');
foreach ($rows as $r) { $ins->execute($r); }

$arabic = ArabicNormalizer::make();
$arabic->registerSqliteFunction($pdo);

function run(PDO $pdo, string $sql, array $params): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }

$cases = [['احمد', 'name'], ['مدرسه الامل', 'name'], ['جده', 'city'], ['مصطفي', 'name']];
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
body{font-family:'Noto Sans Arabic','DejaVu Sans',sans-serif;background:#0b1220;color:#e2e8f0;margin:0;padding:36px}
h1{margin:0 0 6px;font-size:30px;color:#fff} p.sub{margin:0 0 26px;color:#94a3b8}
.case{background:#101c36;border:1px solid #1e2a44;border-radius:14px;padding:18px 22px;margin-bottom:18px}
.q{font-size:20px;margin-bottom:12px}.q b{color:#6ee7b7}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.box{border-radius:10px;padding:12px 14px}.bad{background:#2a1520;border:1px solid #7f1d1d}.good{background:#0f2a22;border:1px solid #065f46}
.box h3{margin:0 0 8px;font-size:15px}.bad h3{color:#fca5a5}.good h3{color:#6ee7b7}
code{direction:ltr;display:block;text-align:left;font-size:12px;color:#94a3b8;margin-bottom:8px;font-family:monospace}
ul{margin:0;padding-right:18px} li{margin:3px 0} .none{color:#fca5a5}
</style></head><body>
<h1>arabic-search: نتائج حقيقية على قاعدة بيانات SQLite</h1>
<p class="sub">نفس البيانات ونفس كلمة البحث: البحث العادي بـ LIKE مقابل البحث بعد التوحيد العربي</p>
<?php foreach ($cases as [$term, $col]):
  $plain = run($pdo, "SELECT name, city FROM customers WHERE $col LIKE ?", ['%'.$term.'%']);
  $expr = $arabic->sqlExpression($col, 'sqlite');
  $smart = run($pdo, "SELECT name, city FROM customers WHERE $expr LIKE ? ESCAPE '!'", [LikePattern::build($arabic->normalize($term))]);
?>
<div class="case"><div class="q">المستخدم كتب: <b><?= htmlspecialchars($term) ?></b> (في عمود <?= $col === 'name' ? 'الاسم' : 'المدينة' ?>)</div>
<div class="grid">
<div class="box bad"><h3>LIKE العادي: <?= count($plain) ?> نتيجة</h3><code>WHERE <?= $col ?> LIKE '%<?= htmlspecialchars($term) ?>%'</code>
<?php if (!$plain): ?><div class="none">لا توجد نتائج</div><?php else: ?><ul><?php foreach ($plain as $r): ?><li><?= htmlspecialchars($r['name']) ?> · <?= htmlspecialchars($r['city']) ?></li><?php endforeach ?></ul><?php endif ?></div>
<div class="box good"><h3>arabic-search: <?= count($smart) ?> نتيجة</h3><code>whereArabicLike('<?= $col ?>', '<?= htmlspecialchars($term) ?>')</code>
<ul><?php foreach ($smart as $r): ?><li><?= htmlspecialchars($r['name']) ?> · <?= htmlspecialchars($r['city']) ?></li><?php endforeach ?></ul></div>
</div></div>
<?php endforeach ?>
</body></html>
