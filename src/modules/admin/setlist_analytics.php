<?php
/**
 * Admin — Setlist Analytics
 *
 * Displays:
 *   1. Top-40 play-count table (with year-by-year breakdown)
 *   2. Recency flagging list (in_repertoire songs not played in >2 years)
 *
 * Queries mirror the logic in cli/etl/analyze_setlists.py (SetlistAnalytics)
 * so both surfaces always reflect the same definitions.
 *
 * Data pipeline note: this page queries the live DB on every request.
 * As the dataset grows, consider adding a caching layer (e.g. a nightly
 * materialized view or a JSON cache file written by the ETL script).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

const RECENCY_THRESHOLD_DAYS = 730; // 2 years
const TOP_N = 40;
const YEAR_MIN = 2013;

// ---------------------------------------------------------------------------
// 1. Play frequency — per-song total + yearly breakdown
// ---------------------------------------------------------------------------

$yearMax = (int) date('Y');
$years = range(YEAR_MIN, $yearMax);

// Totals
$stmt = $pdo->query(
    "SELECT
         s.id,
         s.artist,
         s.title,
         s.in_repertoire,
         s.is_jazz,
         s.genre,
         COUNT(ss.id) AS play_count
     FROM songs s
     LEFT JOIN setlist_songs ss ON ss.song_id = s.id
     WHERE s.deleted_at IS NULL
     GROUP BY s.id, s.artist, s.title, s.in_repertoire, s.is_jazz, s.genre
     ORDER BY play_count DESC, s.artist ASC, s.title ASC"
);
$allSongs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Year breakdown
$stmt2 = $pdo->query(
    "SELECT
         s.id,
         YEAR(g.gig_date) AS yr,
         COUNT(ss.id)     AS cnt
     FROM songs s
     JOIN setlist_songs ss ON ss.song_id = s.id
     JOIN setlists sl      ON sl.id = ss.setlist_id
     JOIN gigs g           ON g.id = sl.gig_id
     WHERE s.deleted_at IS NULL
       AND g.gig_date IS NOT NULL
     GROUP BY s.id, YEAR(g.gig_date)"
);
$byYearRaw = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Index: [song_id][year] => count
$byYear = [];
foreach ($byYearRaw as $row) {
    $byYear[(int)$row['id']][(int)$row['yr']] = (int)$row['cnt'];
}

$top40    = array_slice(array_filter($allSongs, fn($s) => $s['play_count'] > 0), 0, TOP_N);
$zeroPlay = array_filter($allSongs, fn($s) => (int)$s['play_count'] === 0);

// Summary stats
$totalSongs = count($allSongs);
$totalPlays = array_sum(array_column($allSongs, 'play_count'));
$inRepCount = count(array_filter($allSongs, fn($s) => $s['in_repertoire'] && !$s['is_jazz']));

// ---------------------------------------------------------------------------
// 2. Recency — songs not played in >2 years (in_repertoire only)
// ---------------------------------------------------------------------------

$cutoff = date('Y-m-d', strtotime('-' . RECENCY_THRESHOLD_DAYS . ' days'));

$staleStmt = $pdo->prepare(
    "SELECT
         s.id,
         s.artist,
         s.title,
         s.genre,
         MAX(g.gig_date) AS last_played
     FROM songs s
     LEFT JOIN setlist_songs ss ON ss.song_id = s.id
     LEFT JOIN setlists sl      ON sl.id = ss.setlist_id
     LEFT JOIN gigs g           ON g.id = sl.gig_id
                               AND g.gig_date IS NOT NULL
     WHERE s.deleted_at IS NULL
       AND s.in_repertoire = 1
       AND s.is_jazz = 0
     GROUP BY s.id, s.artist, s.title, s.genre
     HAVING last_played IS NULL OR last_played < :cutoff
     ORDER BY last_played ASC, s.artist ASC"
);
$staleStmt->execute([':cutoff' => $cutoff]);
$staleSongs = $staleStmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

render_layout('Setlist Analytics', function () use (
    $top40, $zeroPlay, $staleSongs, $byYear, $years,
    $totalSongs, $totalPlays, $inRepCount
) {
    $thresholdYears = RECENCY_THRESHOLD_DAYS / 365;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="mb-0">Setlist Analytics</h2>
  <span class="text-muted small">Generated <?= htmlspecialchars(date('Y-m-d')) ?></span>
</div>

<!-- Summary strip -->
<div class="row g-2 mb-4">
  <div class="col-auto">
    <div class="card border-0 bg-light px-3 py-2 text-center">
      <div class="fs-4 fw-bold"><?= $totalSongs ?></div>
      <div class="small text-muted">Total songs</div>
    </div>
  </div>
  <div class="col-auto">
    <div class="card border-0 bg-light px-3 py-2 text-center">
      <div class="fs-4 fw-bold"><?= $inRepCount ?></div>
      <div class="small text-muted">In repertoire</div>
    </div>
  </div>
  <div class="col-auto">
    <div class="card border-0 bg-light px-3 py-2 text-center">
      <div class="fs-4 fw-bold"><?= $totalPlays ?></div>
      <div class="small text-muted">Total play slots</div>
    </div>
  </div>
  <div class="col-auto">
    <div class="card border-0 bg-light px-3 py-2 text-center">
      <div class="fs-4 fw-bold text-warning"><?= count($staleSongs) ?></div>
      <div class="small text-muted">Stale (&gt;<?= (int)$thresholdYears ?>yr)</div>
    </div>
  </div>
  <div class="col-auto">
    <div class="card border-0 bg-light px-3 py-2 text-center">
      <div class="fs-4 fw-bold text-secondary"><?= count($zeroPlay) ?></div>
      <div class="small text-muted">Never played</div>
    </div>
  </div>
</div>

<!-- Nav tabs -->
<ul class="nav nav-tabs mb-3" id="analyticsTabs" role="tablist">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-top40" type="button">
      Top 40
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-stale" type="button">
      Recency review
      <?php if (count($staleSongs)): ?>
        <span class="badge bg-warning text-dark ms-1"><?= count($staleSongs) ?></span>
      <?php endif; ?>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-zero" type="button">
      Never played
      <?php if (count($zeroPlay)): ?>
        <span class="badge bg-secondary ms-1"><?= count($zeroPlay) ?></span>
      <?php endif; ?>
    </button>
  </li>
</ul>

<div class="tab-content">

  <!-- ------------------------------------------------------------------ -->
  <!-- Tab 1: Top 40                                                       -->
  <!-- ------------------------------------------------------------------ -->
  <div class="tab-pane fade show active" id="tab-top40">
    <p class="text-muted small mb-2">
      Total play slots per song across all gigs (2013–<?= date('Y') ?>).
      Year columns show how many sets that song appeared in during that year.
    </p>
    <div class="table-responsive">
      <table class="table table-sm table-bordered table-hover" style="font-size:0.82rem">
        <thead class="table-dark sticky-top">
          <tr>
            <th class="text-end" style="width:2.5rem">#</th>
            <th>Artist</th>
            <th>Title</th>
            <th class="text-end" style="width:3.5rem">Total</th>
            <?php foreach ($years as $y): ?>
            <th class="text-end" style="width:2.8rem"><?= $y ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach (array_values($top40) as $rank => $song):
              $id = (int)$song['id'];
              $yrData = $byYear[$id] ?? [];
        ?>
          <tr>
            <td class="text-end text-muted"><?= $rank + 1 ?></td>
            <td><?= htmlspecialchars($song['artist']) ?></td>
            <td><?= htmlspecialchars($song['title']) ?></td>
            <td class="text-end fw-semibold"><?= (int)$song['play_count'] ?></td>
            <?php foreach ($years as $y): ?>
            <td class="text-end <?= isset($yrData[$y]) ? 'text-primary' : 'text-muted' ?>">
              <?= $yrData[$y] ?? '' ?>
            </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ------------------------------------------------------------------ -->
  <!-- Tab 2: Recency review                                               -->
  <!-- ------------------------------------------------------------------ -->
  <div class="tab-pane fade" id="tab-stale">
    <p class="text-muted small mb-2">
      Songs with <code>in_repertoire = 1</code> not played in over <?= (int)$thresholdYears ?> years
      (or never played). Consider rehearsing or retiring these.
    </p>
    <?php if (empty($staleSongs)): ?>
      <div class="alert alert-success">All in-repertoire songs have been played recently.</div>
    <?php else: ?>
    <table class="table table-sm table-bordered">
      <thead class="table-light">
        <tr>
          <th>Artist</th>
          <th>Title</th>
          <th>Genre</th>
          <th>Last played</th>
          <th class="text-end">Days since</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($staleSongs as $s):
            $last    = $s['last_played'] ?? null;
            $daysSince = $last
                ? (int) floor((time() - strtotime($last)) / 86400)
                : null;
            $rowClass = ($daysSince === null)
                ? 'table-secondary'
                : ($daysSince > 1095 ? 'table-danger' : '');
      ?>
        <tr class="<?= $rowClass ?>">
          <td><?= htmlspecialchars($s['artist']) ?></td>
          <td><?= htmlspecialchars($s['title']) ?></td>
          <td class="text-muted small"><?= htmlspecialchars($s['genre'] ?? '—') ?></td>
          <td><?= $last ? htmlspecialchars(substr($last, 0, 10)) : '<em class="text-muted">never</em>' ?></td>
          <td class="text-end">
            <?= $daysSince !== null ? number_format($daysSince) : '∞' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ------------------------------------------------------------------ -->
  <!-- Tab 3: Never played                                                 -->
  <!-- ------------------------------------------------------------------ -->
  <div class="tab-pane fade" id="tab-zero">
    <p class="text-muted small mb-2">
      Songs present in the DB but with zero setlist appearances.
    </p>
    <?php if (empty($zeroPlay)): ?>
      <div class="alert alert-success">Every song in the DB has been played at least once.</div>
    <?php else: ?>
    <table class="table table-sm table-bordered">
      <thead class="table-light">
        <tr>
          <th>Artist</th>
          <th>Title</th>
          <th>Genre</th>
          <th>In repertoire</th>
          <th>Jazz</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($zeroPlay as $s): ?>
        <tr>
          <td><?= htmlspecialchars($s['artist']) ?></td>
          <td><?= htmlspecialchars($s['title']) ?></td>
          <td class="text-muted small"><?= htmlspecialchars($s['genre'] ?? '—') ?></td>
          <td class="text-center"><?= $s['in_repertoire'] ? '✓' : '—' ?></td>
          <td class="text-center"><?= $s['is_jazz'] ? '✓' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div><!-- /.tab-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmkTlPMG1s5xHjbSmLpRn1dbWhL"
        crossorigin="anonymous"></script>
<?php
});
