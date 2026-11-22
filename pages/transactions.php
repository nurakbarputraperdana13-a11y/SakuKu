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
<div class="page-head">
  <div>
    <h1>Transaksi Pribadi</h1>
    <p>Catat pemasukan dan pengeluaran harianmu, lalu pantau alokasinya.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="button" data-modal-open="transaction-create">
      <?= icon('plus') ?> Tambah transaksi
    </button>
    <button class="btn" type="button" data-modal-open="category-create">
      <?= icon('target') ?> Kategori baru
    </button>
  </div>
</div>

<div class="grid grid-stats">
  <div class="stat-card stat-income">
    <p class="stat-label">Pemasukan (filter)</p>
    <p class="stat-value"><?= e(format_rupiah($income)) ?></p>
    <div class="stat-meta"><?= icon('arrow-up') ?> <?= count($transactions) ?> transaksi</div>
    <span class="stat-icon"><?= icon('arrow-up') ?></span>
  </div>
  <div class="stat-card stat-expense">
    <p class="stat-label">Pengeluaran (filter)</p>
    <p class="stat-value"><?= e(format_rupiah($expense)) ?></p>
    <div class="stat-meta"><?= icon('arrow-down') ?> dari total yang ditampilkan</div>
    <span class="stat-icon"><?= icon('arrow-down') ?></span>
  </div>
  <div class="stat-card">
    <p class="stat-label">Selisih (filter)</p>
    <p class="stat-value"><?= e(format_rupiah($net)) ?></p>
    <div class="stat-meta"><?= icon('wallet') ?> pemasukan dikurangi pengeluaran</div>
    <span class="stat-icon"><?= icon('wallet') ?></span>
  </div>
</div>

<form class="filter-bar" method="get" action="<?= e(url('pages/transactions.php')) ?>">
  <div class="field">
    <label for="filter-month">Bulan</label>
    <input id="filter-month" name="month" type="month" value="<?= e($month) ?>">
  </div>
  <div class="field">
    <label for="filter-type">Jenis</label>
    <select id="filter-type" name="type">
      <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>Semua</option>
      <option value="income" <?= $type === 'income' ? 'selected' : '' ?>>Pemasukan</option>
      <option value="expense" <?= $type === 'expense' ? 'selected' : '' ?>>Pengeluaran</option>
    </select>
  </div>
  <div class="field">
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
  <div class="field">
    <label for="filter-note">Cari catatan</label>
    <input id="filter-note" name="q" type="search" value="<?= e($searchText) ?>" placeholder="Kata kunci catatan">
  </div>
  <button class="btn btn-sm btn-primary" type="submit"><?= icon('search') ?> Terapkan</button>
  <a class="btn btn-sm btn-ghost" href="<?= e(url('pages/transactions.php')) ?>">Reset</a>
</form>

<div class="card">
  <div class="card-header">
    <h2>Riwayat transaksi</h2>
    <span class="badge"><?= count($transactions) ?> data</span>
  </div>
  <div class="card-body">
    <?php if ($transactions === []): ?>
      <div class="empty-state">
        <?= icon('list') ?>
        <strong>Belum ada transaksi</strong>
        <span>Ubah filter atau tambahkan transaksi baru.</span>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Jenis</th>
              <th>Kategori</th>
              <th>Catatan</th>
              <th class="num">Nominal</th>
              <th class="num">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $transaction): ?>
              <?php $transactionId = (int) $transaction['id']; ?>
              <tr>
                <td><span class="cell-main"><?= e(date('d/m/Y', strtotime((string) $transaction['transaction_date']))) ?></span></td>
                <td>
                  <span class="badge <?= (string) $transaction['type'] === 'income' ? 'badge-success' : 'badge-danger' ?>">
                    <?= (string) $transaction['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran' ?>
                  </span>
                </td>
                <td>
                  <span class="cell-main"><?= icon((string) $transaction['category_icon']) ?> <?= e($transaction['category_name']) ?></span>
                </td>
                <td>
                  <?php if (trim((string) $transaction['note']) !== ''): ?>
                    <span><?= e($transaction['note']) ?></span>
                  <?php else: ?>
                    <span class="cell-sub">Tidak ada catatan</span>
                  <?php endif; ?>
                </td>
                <td class="num cell-main"><?= e(format_rupiah((int) $transaction['amount'])) ?></td>
                <td>
                  <div class="row-actions">
                    <button class="btn btn-sm btn-icon" type="button" data-modal-open="edit-transaction-<?= $transactionId ?>" aria-label="Edit transaksi">
                      <?= icon('edit') ?>
                    </button>
                    <form method="post" action="<?= e(url('process/transaction_process.php')) ?>" data-confirm="Hapus transaksi ini?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $transactionId ?>">
                      <input type="hidden" name="month" value="<?= e($month) ?>">
                      <button class="btn btn-sm btn-icon btn-danger" type="submit" aria-label="Hapus transaksi"><?= icon('trash') ?></button>
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
</div>

<div class="modal" id="transaction-create" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="transaction-create-title">
    <div class="modal-head">
      <h2 id="transaction-create-title">Tambah transaksi</h2>
      <button class="icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
      <form method="post" action="<?= e(url('process/transaction_process.php')) ?>" data-category-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <div class="form-grid">
          <div class="field full">
            <label>Jenis transaksi</label>
            <div class="segmented">
              <label><input type="radio" name="type" value="expense" checked><span><?= icon('arrow-down') ?> Pengeluaran</span></label>
              <label><input type="radio" name="type" value="income"><span><?= icon('arrow-up') ?> Pemasukan</span></label>
            </div>
          </div>
          <div class="field">
            <label for="create-amount">Nominal</label>
            <input id="create-amount" name="amount" type="text" inputmode="numeric" placeholder="Rp50.000" required>
          </div>
          <div class="field">
            <label for="create-category">Kategori</label>
            <select id="create-category" name="category_id" data-category-select required>
              <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" data-type="<?= e($category['type']) ?>"><?= e($category['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="create-date">Tanggal</label>
            <input id="create-date" name="transaction_date" type="date" value="<?= e(date('Y-m-d')) ?>" required>
          </div>
          <div class="field full">
            <label for="create-note">Catatan (opsional)</label>
            <input id="create-note" name="note" type="text" maxlength="255" placeholder="Contoh: makan siang">
            <span class="field-hint">Maksimal 255 karakter.</span>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('check') ?> Simpan transaksi</button>
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal" id="category-create" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="category-create-title">
    <div class="modal-head">
      <h2 id="category-create-title">Tambah kategori kustom</h2>
      <button class="icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
      <form method="post" action="<?= e(url('process/transaction_process.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_category">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <input type="hidden" name="icon" value="circle">
        <div class="form-grid cols-1">
          <div class="field">
            <label for="category-name">Nama kategori</label>
            <input id="category-name" name="name" type="text" maxlength="50" placeholder="Contoh: Langganan Internet" required>
          </div>
          <div class="field">
            <label for="category-type">Jenis</label>
            <select id="category-type" name="type" required>
              <option value="expense">Pengeluaran</option>
              <option value="income">Pemasukan</option>
            </select>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Simpan kategori</button>
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php foreach ($transactions as $transaction): ?>
  <?php $transactionId = (int) $transaction['id']; ?>
  <div class="modal" id="edit-transaction-<?= $transactionId ?>" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="edit-transaction-title-<?= $transactionId ?>">
      <div class="modal-head">
        <h2 id="edit-transaction-title-<?= $transactionId ?>">Edit transaksi</h2>
        <button class="icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('close') ?></button>
      </div>
      <div class="modal-body">
        <form method="post" action="<?= e(url('process/transaction_process.php')) ?>" data-category-form>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= $transactionId ?>">
          <input type="hidden" name="month" value="<?= e($month) ?>">
          <div class="form-grid">
            <div class="field full">
              <label>Jenis transaksi</label>
              <div class="segmented">
                <label><input type="radio" name="type" value="expense" <?= (string) $transaction['type'] === 'expense' ? 'checked' : '' ?>><span><?= icon('arrow-down') ?> Pengeluaran</span></label>
                <label><input type="radio" name="type" value="income" <?= (string) $transaction['type'] === 'income' ? 'checked' : '' ?>><span><?= icon('arrow-up') ?> Pemasukan</span></label>
              </div>
            </div>
            <div class="field">
              <label for="edit-amount-<?= $transactionId ?>">Nominal</label>
              <input id="edit-amount-<?= $transactionId ?>" name="amount" type="text" inputmode="numeric" value="<?= e(format_rupiah((int) $transaction['amount'])) ?>" required>
            </div>
            <div class="field">
              <label for="edit-category-<?= $transactionId ?>">Kategori</label>
              <select id="edit-category-<?= $transactionId ?>" name="category_id" data-category-select required>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int) $category['id'] ?>" data-type="<?= e($category['type']) ?>" <?= (int) $category['id'] === (int) $transaction['category_id'] ? 'selected' : '' ?>>
                    <?= e($category['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="edit-date-<?= $transactionId ?>">Tanggal</label>
              <input id="edit-date-<?= $transactionId ?>" name="transaction_date" type="date" value="<?= e((string) $transaction['transaction_date']) ?>" required>
            </div>
            <div class="field full">
              <label for="edit-note-<?= $transactionId ?>">Catatan (opsional)</label>
              <input id="edit-note-<?= $transactionId ?>" name="note" type="text" maxlength="255" value="<?= e((string) $transaction['note']) ?>">
            </div>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('check') ?> Simpan perubahan</button>
            <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
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
