<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);

$month = (string) ($_GET['month'] ?? current_month());
if (!preg_match('/^\d{4}-\d{2}$/', $month) || !checkdate((int) substr($month, 5, 2), 1, (int) substr($month, 0, 4))) {
    $month = current_month();
}

$type = (string) ($_GET['type'] ?? 'all');
if (!in_array($type, ['all', 'income', 'expense'], true)) {
    $type = 'all';
}

$categories = get_categories($pdo, $userId);
$categoryFilter = (int) ($_GET['category_id'] ?? 0);
if ($categoryFilter !== 0) {
    $categoryExists = false;
    foreach ($categories as $category) {
        if ((int) $category['id'] === $categoryFilter) {
            $categoryExists = true;
            break;
        }
    }
    if (!$categoryExists) {
        $categoryFilter = 0;
    }
}

$searchText = trim((string) ($_GET['q'] ?? ''));
$search = function_exists('mb_strtolower') ? mb_strtolower($searchText, 'UTF-8') : strtolower($searchText);

$params = [':user_id' => $userId, ':month' => $month];
$sql = "SELECT t.id, t.type, t.amount, t.transaction_date, t.note, t.category_id,
               c.name AS category_name, c.icon AS category_icon
        FROM transactions t
        JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = :user_id AND DATE_FORMAT(t.transaction_date, '%Y-%m') = :month";
if ($type !== 'all') {
    $sql .= ' AND t.type = :type';
    $params[':type'] = $type;
}
if ($categoryFilter !== 0) {
    $sql .= ' AND t.category_id = :category_id';
    $params[':category_id'] = $categoryFilter;
}
if ($search !== '') {
    $sql .= " AND LOCATE(:search, LOWER(COALESCE(t.note, ''))) > 0";
    $params[':search'] = $search;
}
$sql .= ' ORDER BY t.transaction_date DESC, t.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$income = 0;
$expense = 0;
foreach ($transactions as $transaction) {
    if ((string) $transaction['type'] === 'income') {
        $income += (int) $transaction['amount'];
    } else {
        $expense += (int) $transaction['amount'];
    }
}
$net = $income - $expense;

render_header('Transaksi', 'transactions');
?>
<section class="flex flex-col gap-space-md md:flex-row md:items-end md:justify-between">
  <div>
    <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Pencatatan Transaksi</p>
    <h1 class="mt-1 font-headline-xl text-headline-xl text-on-surface md:font-display-lg md:text-display-lg">Riwayat keuangan pribadi</h1>
    <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Catat pemasukan dan pengeluaran harianmu, lalu pantau alokasinya.</p>
  </div>
  <div class="flex flex-wrap items-center gap-space-sm">
    <button class="inline-flex h-12 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary shadow-md transition-colors hover:bg-primary" type="button" data-modal-open="transaction-create">
      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add_circle</span>
      Tambah transaksi
    </button>
    <button class="inline-flex h-12 items-center gap-2 rounded-xl border border-on-surface/10 bg-white px-5 font-label-lg text-label-lg text-on-surface shadow-sm transition-colors hover:bg-surface-container-low" type="button" data-modal-open="category-create">
      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">category</span>
      Kategori baru
    </button>
  </div>
</section>

<section class="mt-space-lg grid grid-cols-1 gap-gutter sm:grid-cols-3 md:gap-gutter-desktop">
  <article class="bento-card">
    <div class="flex items-center justify-between">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Pemasukan (filter)</span>
      <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-primary"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">south_west</span></span>
    </div>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($income)) ?></p>
    <span class="pill pill-lime mt-space-md w-fit"><?= count($transactions) ?> transaksi</span>
  </article>
  <article class="bento-card">
    <div class="flex items-center justify-between">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Pengeluaran (filter)</span>
      <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-red-600"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">north_east</span></span>
    </div>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($expense)) ?></p>
    <span class="pill pill-red mt-space-md w-fit">dari data ditampilkan</span>
  </article>
  <article class="bento-card">
    <div class="flex items-center justify-between">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Selisih (filter)</span>
      <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-primary"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">account_balance_wallet</span></span>
    </div>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($net)) ?></p>
    <span class="pill pill-neutral mt-space-md w-fit">masuk dikurangi keluar</span>
  </article>
</section>

<form class="bento-card mt-space-lg grid grid-cols-1 gap-space-md sm:grid-cols-2 lg:grid-cols-6" method="get" action="<?= e(url('pages/transactions.php')) ?>">
  <div class="field lg:col-span-1">
    <label for="filter-month">Bulan</label>
    <input id="filter-month" name="month" type="month" value="<?= e($month) ?>">
  </div>
  <div class="field lg:col-span-1">
    <label for="filter-type">Jenis</label>
    <select id="filter-type" name="type">
      <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>Semua</option>
      <option value="income" <?= $type === 'income' ? 'selected' : '' ?>>Pemasukan</option>
      <option value="expense" <?= $type === 'expense' ? 'selected' : '' ?>>Pengeluaran</option>
    </select>
  </div>
  <div class="field lg:col-span-2">
    <label for="filter-category">Kategori</label>
    <select id="filter-category" name="category_id">
      <option value="0">Semua kategori</option>
      <?php foreach ($categories as $category): ?>
        <option value="<?= (int) $category['id'] ?>" <?= (int) $category['id'] === $categoryFilter ? 'selected' : '' ?>>
          <?= e($category['name']) ?> (<?= (string) $category['type'] === 'income' ? 'pemasukan' : 'pengeluaran' ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field lg:col-span-2">
    <label for="filter-note">Cari catatan</label>
    <input id="filter-note" name="q" type="search" value="<?= e($searchText) ?>" placeholder="Kata kunci catatan">
  </div>
  <div class="flex items-end gap-space-sm sm:col-span-2 lg:col-span-6">
    <button class="inline-flex h-11 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary" type="submit">
      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">filter_list</span>
      Terapkan filter
    </button>
    <a class="inline-flex h-11 items-center rounded-xl border border-on-surface/10 px-5 font-label-lg text-label-lg text-on-surface-variant transition-colors hover:bg-surface-container-low" href="<?= e(url('pages/transactions.php')) ?>">Reset</a>
  </div>
</form>

<section class="bento-card mt-space-lg">
  <div class="flex flex-wrap items-center justify-between gap-space-sm">
    <div>
      <h2 class="font-headline-lg text-headline-lg text-on-surface">Riwayat transaksi</h2>
      <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Mutasi keuangan pada <?= e($month) ?></p>
    </div>
    <span class="pill pill-neutral"><?= count($transactions) ?> data</span>
  </div>
  <div class="mt-space-lg">
    <?php if ($transactions === []): ?>
      <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-12 text-center">
        <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">receipt_long</span>
        <p class="font-headline-md text-headline-md text-on-surface">Belum ada transaksi</p>
        <p class="max-w-sm font-body-md text-body-md text-on-surface-variant">Ubah filter atau tambahkan transaksi baru.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left font-body-md text-body-md">
          <thead>
            <tr class="border-b border-surface-container text-on-surface-variant">
              <th class="py-2 font-label-md text-label-md">Tanggal</th>
              <th class="py-2 font-label-md text-label-md">Jenis</th>
              <th class="py-2 font-label-md text-label-md">Kategori</th>
              <th class="py-2 font-label-md text-label-md">Catatan</th>
              <th class="py-2 text-right font-label-md text-label-md">Nominal</th>
              <th class="py-2 text-right font-label-md text-label-md">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $transaction): ?>
              <?php $transactionId = (int) $transaction['id']; ?>
              <tr class="border-b border-surface-container/70 transition-colors hover:bg-surface-container-low">
                <td class="py-3 tabular-nums text-on-surface-variant"><?= e(date('d/m/Y', strtotime((string) $transaction['transaction_date']))) ?></td>
                <td class="py-3"><span class="pill <?= (string) $transaction['type'] === 'income' ? 'pill-lime' : 'pill-red' ?>"><?= (string) $transaction['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran' ?></span></td>
                <td class="py-3 font-semibold text-on-surface"><?= e($transaction['category_name']) ?></td>
                <td class="py-3">
                  <?php if (trim((string) $transaction['note']) !== ''): ?>
                    <span class="text-on-surface-variant"><?= e($transaction['note']) ?></span>
                  <?php else: ?>
                    <span class="font-label-sm text-label-sm text-outline">Tidak ada catatan</span>
                  <?php endif; ?>
                </td>
                <td class="py-3 text-right font-bold tabular-nums text-on-surface"><?= e(format_rupiah((int) $transaction['amount'])) ?></td>
                <td class="py-3">
                  <div class="flex items-center justify-end gap-space-xs">
                    <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-primary transition-colors hover:bg-surface-container-high" type="button" data-modal-open="edit-transaction-<?= $transactionId ?>" aria-label="Edit transaksi">
                      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                    </button>
                    <form method="post" action="<?= e(url('process/transaction_process.php')) ?>" data-confirm="Hapus transaksi ini?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $transactionId ?>">
                      <input type="hidden" name="month" value="<?= e($month) ?>">
                      <button class="grid h-9 w-9 place-items-center rounded-full bg-red-50 text-red-600 transition-colors hover:bg-red-100" type="submit" aria-label="Hapus transaksi">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="modal" id="transaction-create" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel max-w-2xl overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="transaction-create-title">
    <div class="flex items-center justify-between gap-space-sm border-b border-surface-container p-6">
      <div>
        <h2 class="font-headline-md text-headline-md" id="transaction-create-title">Tambah transaksi</h2>
        <p class="font-label-sm text-label-sm text-on-surface-variant">Catat mutasi transaksi harian</p>
      </div>
      <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-on-surface-variant" type="button" data-modal-close aria-label="Tutup"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span></button>
    </div>
    <div class="max-h-[70vh] overflow-y-auto p-6">
      <form class="flex flex-col gap-space-md" method="post" action="<?= e(url('process/transaction_process.php')) ?>" data-category-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <div class="field">
          <label>Jenis transaksi</label>
          <div class="flex gap-1 rounded-xl bg-surface-container-low p-1">
            <label class="flex-1"><input class="peer sr-only" type="radio" name="type" value="expense" checked><span class="block cursor-pointer rounded-lg px-3 py-2 text-center font-label-md text-label-md text-on-surface-variant peer-checked:bg-secondary-fixed peer-checked:font-bold peer-checked:text-on-secondary-fixed">Pengeluaran</span></label>
            <label class="flex-1"><input class="peer sr-only" type="radio" name="type" value="income"><span class="block cursor-pointer rounded-lg px-3 py-2 text-center font-label-md text-label-md text-on-surface-variant peer-checked:bg-secondary-fixed peer-checked:font-bold peer-checked:text-on-secondary-fixed">Pemasukan</span></label>
          </div>
        </div>
        <div class="grid grid-cols-1 gap-space-md sm:grid-cols-2">
          <div class="field"><label for="create-amount">Nominal</label><input id="create-amount" name="amount" type="text" inputmode="numeric" placeholder="Rp50.000" required></div>
          <div class="field"><label for="create-category">Kategori</label><select id="create-category" name="category_id" data-category-select required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" data-type="<?= e($category['type']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="create-date">Tanggal</label><input id="create-date" name="transaction_date" type="date" value="<?= e(date('Y-m-d')) ?>" required></div>
          <div class="field"><label for="create-note">Catatan (opsional)</label><input id="create-note" name="note" type="text" maxlength="255" placeholder="Contoh: makan siang"><span class="field-hint">Maksimal 255 karakter.</span></div>
        </div>
        <div class="flex flex-wrap gap-space-sm pt-2">
          <button class="inline-flex h-11 items-center rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary hover:bg-primary" type="submit">Simpan transaksi</button>
          <button class="inline-flex h-11 items-center rounded-xl px-5 font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-low" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal" id="category-create" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="category-create-title">
    <div class="flex items-center justify-between gap-space-sm border-b border-surface-container p-6">
      <div>
        <h2 class="font-headline-md text-headline-md" id="category-create-title">Tambah kategori kustom</h2>
        <p class="font-label-sm text-label-sm text-on-surface-variant">Kategori baru untuk transaksimu</p>
      </div>
      <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-on-surface-variant" type="button" data-modal-close aria-label="Tutup"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span></button>
    </div>
    <div class="p-6">
      <form class="flex flex-col gap-space-md" method="post" action="<?= e(url('process/transaction_process.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_category">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <input type="hidden" name="icon" value="circle">
        <div class="field"><label for="category-name">Nama kategori</label><input id="category-name" name="name" type="text" maxlength="50" placeholder="Contoh: Langganan Internet" required></div>
        <div class="field"><label for="category-type">Jenis</label><select id="category-type" name="type" required><option value="expense">Pengeluaran</option><option value="income">Pemasukan</option></select></div>
        <div class="flex flex-wrap gap-space-sm pt-2">
          <button class="inline-flex h-11 items-center rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary hover:bg-primary" type="submit">Simpan kategori</button>
          <button class="inline-flex h-11 items-center rounded-xl px-5 font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-low" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php foreach ($transactions as $transaction): ?>
  <?php $transactionId = (int) $transaction['id']; ?>
  <div class="modal" id="edit-transaction-<?= $transactionId ?>" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-panel max-w-2xl overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="edit-transaction-title-<?= $transactionId ?>">
      <div class="flex items-center justify-between gap-space-sm border-b border-surface-container p-6">
        <h2 class="font-headline-md text-headline-md" id="edit-transaction-title-<?= $transactionId ?>">Edit transaksi</h2>
        <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-on-surface-variant" type="button" data-modal-close aria-label="Tutup"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span></button>
      </div>
      <div class="max-h-[70vh] overflow-y-auto p-6">
        <form class="flex flex-col gap-space-md" method="post" action="<?= e(url('process/transaction_process.php')) ?>" data-category-form>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= $transactionId ?>">
          <input type="hidden" name="month" value="<?= e($month) ?>">
          <div class="field">
            <label>Jenis transaksi</label>
            <div class="flex gap-1 rounded-xl bg-surface-container-low p-1">
              <label class="flex-1"><input class="peer sr-only" type="radio" name="type" value="expense" <?= (string) $transaction['type'] === 'expense' ? 'checked' : '' ?>><span class="block cursor-pointer rounded-lg px-3 py-2 text-center font-label-md text-label-md text-on-surface-variant peer-checked:bg-secondary-fixed peer-checked:font-bold peer-checked:text-on-secondary-fixed">Pengeluaran</span></label>
              <label class="flex-1"><input class="peer sr-only" type="radio" name="type" value="income" <?= (string) $transaction['type'] === 'income' ? 'checked' : '' ?>><span class="block cursor-pointer rounded-lg px-3 py-2 text-center font-label-md text-label-md text-on-surface-variant peer-checked:bg-secondary-fixed peer-checked:font-bold peer-checked:text-on-secondary-fixed">Pemasukan</span></label>
            </div>
          </div>
          <div class="grid grid-cols-1 gap-space-md sm:grid-cols-2">
            <div class="field"><label for="edit-amount-<?= $transactionId ?>">Nominal</label><input id="edit-amount-<?= $transactionId ?>" name="amount" type="text" inputmode="numeric" value="<?= e(format_rupiah((int) $transaction['amount'])) ?>" required></div>
            <div class="field"><label for="edit-category-<?= $transactionId ?>">Kategori</label><select id="edit-category-<?= $transactionId ?>" name="category_id" data-category-select required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" data-type="<?= e($category['type']) ?>" <?= (int) $category['id'] === (int) $transaction['category_id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="edit-date-<?= $transactionId ?>">Tanggal</label><input id="edit-date-<?= $transactionId ?>" name="transaction_date" type="date" value="<?= e((string) $transaction['transaction_date']) ?>" required></div>
            <div class="field"><label for="edit-note-<?= $transactionId ?>">Catatan (opsional)</label><input id="edit-note-<?= $transactionId ?>" name="note" type="text" maxlength="255" value="<?= e((string) $transaction['note']) ?>"></div>
          </div>
          <div class="flex flex-wrap gap-space-sm pt-2">
            <button class="inline-flex h-11 items-center rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary hover:bg-primary" type="submit">Simpan perubahan</button>
            <button class="inline-flex h-11 items-center rounded-xl px-5 font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-low" type="button" data-modal-close>Batal</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<script>
  (function () {
    function applyCategoryFilter(form) {
      const checked = form.querySelector('input[name="type"]:checked');
      const select = form.querySelector('[data-category-select]');
      if (!checked || !select) return;

      const options = Array.from(select.options);
      options.forEach((option) => {
        const match = option.dataset.type === checked.value;
        option.hidden = !match;
        option.disabled = !match;
      });

      const selected = options.find((option) => option.selected && !option.disabled);
      if (!selected) {
        const first = options.find((option) => !option.disabled);
        if (first) select.value = first.value;
      }
    }

    document.querySelectorAll('[data-category-form]').forEach((form) => {
      applyCategoryFilter(form);
      form.querySelectorAll('input[name="type"]').forEach((input) => {
        input.addEventListener('change', () => {
          const select = form.querySelector('[data-category-select]');
          if (select) Array.from(select.options).forEach((option) => { option.selected = false; });
          applyCategoryFilter(form);
        });
      });
    });
  })();
</script>
<?php
render_footer();
