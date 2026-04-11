<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

// Ensure schema_migrations tracking table exists.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        version     VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// On Plesk: document root is httpdocs/src/, so repo root is one level above src/.
// __DIR__ = .../httpdocs/src/modules/admin → ../../../ = httpdocs/ (repo root)
// In Docker: db/ is not mounted into the PHP container; use `make migrate-dev` instead.
$migrationsDir = realpath(__DIR__ . '/../../../db/migrations') ?: null;
if ($migrationsDir === null) {
    render_layout('Migrations', function () {
        echo '<div class="alert alert-warning">Migration files are not accessible from this environment. '
           . 'Use <code>make migrate-dev</code> in development.</div>';
    });
    exit;
}

$files = glob($migrationsDir . '/*.sql') ?: [];
natsort($files);

$applied = $pdo->query("SELECT version FROM schema_migrations ORDER BY version")
               ->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_flip($applied);

$notice = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target = $_POST['version'] ?? '';
    // Validate: must be a known migration filename, not already applied.
    $validVersions = array_map('basename', $files);
    if (!in_array($target, $validVersions, true)) {
        $error = 'Unknown migration version.';
    } elseif (isset($appliedSet[$target])) {
        $error = 'Migration already applied.';
    } else {
        $sql = file_get_contents($migrationsDir . '/' . $target);
        if ($sql === false) {
            $error = 'Could not read migration file.';
        } else {
            try {
                // Note: DDL statements (ALTER TABLE, CREATE TABLE) cause an implicit
                // commit in MariaDB, so we cannot wrap the full migration in a transaction.
                // We execute statements sequentially; on failure the schema may be partially
                // applied, but schema_migrations is only updated on full success.
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    fn($s) => $s !== ''
                );
                foreach ($statements as $stmt) {
                    $pdo->exec($stmt);
                }
                $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)")
                    ->execute([$target]);
                $appliedSet[$target] = true;
                $notice = "Applied: $target";
            } catch (Throwable $e) {
                $error = 'Migration failed: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

render_layout('Migrations', function () use ($files, $appliedSet, $notice, $error) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Schema migrations</h2>
  </div>

  <?php if ($notice): ?>
  <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <?php if (empty($files)): ?>
  <div class="alert alert-info">No migration files found.</div>
  <?php else: ?>
  <table class="table table-sm table-bordered">
    <thead class="table-light">
      <tr>
        <th>Migration</th>
        <th style="width:130px">Status</th>
        <th style="width:130px">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($files as $file):
        $version = basename($file);
        $isApplied = isset($appliedSet[$version]);
    ?>
      <tr class="<?= $isApplied ? 'table-success' : '' ?>">
        <td class="font-monospace"><?= htmlspecialchars($version) ?></td>
        <td><?= $isApplied ? '<span class="text-success">Applied</span>' : '<span class="text-warning">Pending</span>' ?></td>
        <td>
          <?php if (!$isApplied): ?>
          <form method="post" action="/admin/migrations"
                onsubmit="return confirm('Apply <?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?>?')">
            <input type="hidden" name="version" value="<?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?>">
            <button class="btn btn-sm btn-warning">Apply</button>
          </form>
          <?php else: ?>
          <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
<?php
});
