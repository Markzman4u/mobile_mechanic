<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isAdmin()) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

$pdo = getPDO();

// ── Filter values ─────────────────────────────────────────────────────────────
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to']   ?? '');

// ── Base WHERE clause (applies to all queries) ────────────────────────────────
$whereParts = ['hr.hidden_by_admin = FALSE'];
$params     = [];

if ($dateFrom) {
    $whereParts[] = 'hr.completed_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $whereParts[] = 'hr.completed_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$where = 'WHERE ' . implode(' AND ', $whereParts);

// ── Query helper ──────────────────────────────────────────────────────────────
function runQuery($pdo, $sql, $params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// ── 1. Overview cards ─────────────────────────────────────────────────────────
$overview = runQuery($pdo,
    "SELECT
        COUNT(*)                                                                    AS total_jobs,
        SUM(CASE WHEN hr.status = 'completed' THEN 1 ELSE 0 END)                   AS completed,
        SUM(CASE WHEN hr.status = 'rejected'  THEN 1 ELSE 0 END)                   AS rejected,
        ROUND(AVG(CASE WHEN hr.status = 'completed'
              AND hr.request_created_at IS NOT NULL AND hr.completed_at IS NOT NULL
              THEN TIMESTAMPDIFF(MINUTE, hr.request_created_at, hr.completed_at)
              END) / 60, 1)                                                         AS avg_hours,
        ROUND(AVG(f.mechanic_rating), 1)                                            AS avg_mechanic_rating,
        ROUND(AVG(f.service_rating),  1)                                            AS avg_service_rating
     FROM history_records hr
     LEFT JOIN feedback f ON f.history_id = hr.id
     $where",
    $params
)->fetch();
$completionRate = ($overview['total_jobs'] > 0)
    ? round(($overview['completed'] / $overview['total_jobs']) * 100, 1)
    : 0;

// ── 2. Mechanic performance (completed jobs only) ─────────────────────────────
$mechanicPerf = runQuery($pdo,
    "SELECT
        hr.mechanic_name,
        COUNT(*)                                                                    AS job_count,
        ROUND(AVG(f.mechanic_rating), 1)                                            AS avg_rating,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, hr.request_created_at, hr.completed_at))
              / 60, 1)                                                              AS avg_hours
     FROM history_records hr
     LEFT JOIN feedback f ON f.history_id = hr.id
     $where AND hr.status = 'completed' AND hr.mechanic_name IS NOT NULL
     GROUP BY hr.mechanic_id, hr.mechanic_name
     ORDER BY avg_rating DESC, job_count DESC",
    $params
)->fetchAll();

// ── 3. Feedback ratings ───────────────────────────────────────────────────────
$ratings = runQuery($pdo,
    "SELECT
        ROUND(AVG(f.mechanic_rating), 1) AS avg_mechanic,
        ROUND(AVG(f.service_rating),  1) AS avg_service,
        COUNT(f.id)                       AS feedback_count
     FROM history_records hr
     JOIN feedback f ON f.history_id = hr.id
     $where",
    $params
)->fetch();

// ── 4. Top feedback tags ──────────────────────────────────────────────────────
$topTags = runQuery($pdo,
    "SELECT ft.label, ft.is_positive, ft.category, COUNT(*) AS cnt
     FROM feedback_tag_selections fts
     JOIN feedback_tags ft   ON ft.id  = fts.tag_id
     JOIN feedback f         ON f.id   = fts.feedback_id
     JOIN history_records hr ON hr.id  = f.history_id
     $where
     GROUP BY fts.tag_id, ft.label, ft.is_positive, ft.category
     ORDER BY cnt DESC
     LIMIT 12",
    $params
)->fetchAll();

// ── 5. Job duration by problem type (completed only) ─────────────────────────
$durationData = runQuery($pdo,
    "SELECT
        COALESCE(hr.problem_type, 'Unknown') AS problem_type,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, hr.request_created_at, hr.completed_at))
              / 60, 1)                                                              AS avg_hours,
        COUNT(*) AS job_count
     FROM history_records hr
     $where AND hr.status = 'completed'
         AND hr.request_created_at IS NOT NULL
         AND hr.completed_at IS NOT NULL
     GROUP BY hr.problem_type
     ORDER BY avg_hours DESC",
    $params
)->fetchAll();
$durationChartHeight = max(220, count($durationData) * 52);

// ── 6. Request volume by hour of day ─────────────────────────────────────────
$volumeRaw = runQuery($pdo,
    "SELECT HOUR(hr.request_created_at) AS hr_hour, COUNT(*) AS cnt
     FROM history_records hr
     $where AND hr.request_created_at IS NOT NULL
     GROUP BY HOUR(hr.request_created_at)
     ORDER BY hr_hour",
    $params
)->fetchAll();
$volumeByHour = array_fill(0, 24, 0);
foreach ($volumeRaw as $row) {
    $volumeByHour[(int)$row['hr_hour']] = (int)$row['cnt'];
}
$totalVolume = array_sum($volumeByHour);
$peakHour = $peakCount = $peakLabel = null;
if ($totalVolume > 0) {
    $peakHour  = (int)array_search(max($volumeByHour), $volumeByHour);
    $peakCount = $volumeByHour[$peakHour];
    $peakLabel = ($peakHour === 0 ? '12 AM' :
                 ($peakHour < 12 ? $peakHour . ' AM' :
                 ($peakHour === 12 ? '12 PM' : ($peakHour - 12) . ' PM')));
}

// ── 7. Customer map locations ─────────────────────────────────────────────────
$mapLocations = runQuery($pdo,
    "SELECT hr.latitude, hr.longitude, hr.customer_name, hr.problem_type, hr.status
     FROM history_records hr
     $where AND hr.latitude IS NOT NULL AND hr.longitude IS NOT NULL",
    $params
)->fetchAll();

// ── JSON encode for JS ────────────────────────────────────────────────────────
$jsOverview = json_encode($overview,     JSON_NUMERIC_CHECK);
$jsMechPerf = json_encode($mechanicPerf, JSON_NUMERIC_CHECK);
$jsRatings  = json_encode($ratings,      JSON_NUMERIC_CHECK);
$jsTopTags  = json_encode($topTags);
$jsDuration = json_encode($durationData, JSON_NUMERIC_CHECK);
$jsVolume   = json_encode(array_values($volumeByHour));
$jsMapLocs  = json_encode($mapLocations, JSON_NUMERIC_CHECK);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<main>
<div class="card">

<style>
    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 14px;
        margin-bottom: 28px;
    }
    .stat-card {
        background: #f9f9f9;
        border: 1px solid #eee;
        border-radius: 10px;
        padding: 20px 16px;
        text-align: center;
    }
    .stat-value { font-size: 1.9rem; font-weight: 700; color: #ff6600; line-height: 1.1; }
    .stat-label { font-size: 0.8rem; color: #888; margin-top: 6px; }
    .stat-sub   { font-size: 0.74rem; color: #bbb; margin-top: 3px; }

    .sec-title { font-size: 1rem; font-weight: 600; color: #333; margin: 0 0 6px; }
    .sec-sub   { font-size: 0.82rem; color: #999; margin: 0 0 16px; }

    .chart-wrap { position: relative; margin-bottom: 24px; }

    .empty-state { text-align: center; padding: 48px 20px; color: #ccc; }
    .empty-state .ei { font-size: 2.8rem; margin-bottom: 10px; }
    .empty-state p { font-size: 0.9rem; color: #aaa; }

    .tag-list  { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 4px; }
    .tag-badge {
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.82rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .tag-pos { background: #e8f7e9; color: #2f6627; border: 1px solid #b5ddb8; }
    .tag-neg { background: #fff4f4; color: #a94442; border: 1px solid #f5c6cb; }
    .tag-cnt { font-size: 0.73rem; opacity: 0.75; font-weight: 700; }

    .filter-bar {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: flex-end;
        padding: 14px 16px;
        background: #f9f9f9;
        border: 1px solid #eee;
        border-radius: 8px;
        margin-bottom: 18px;
    }
    .filter-bar label { font-size: 0.8rem; color: #666; display: block; margin-bottom: 4px; }
    .filter-bar input[type="date"] {
        padding: 7px 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 0.85rem;
        background: #fff;
        margin: 0;
    }

    /* ── Section switcher dropdown ── */
    .section-switcher {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 24px;
    }
    .section-switcher label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #555;
        white-space: nowrap;
    }
    .section-switcher select {
        padding: 9px 14px;
        border: 2px solid #ff6600;
        border-radius: 7px;
        font-size: 0.92rem;
        font-weight: 600;
        color: #333;
        background: #fff;
        cursor: pointer;
        min-width: 220px;
        margin: 0;
    }
    .section-switcher select:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(255,102,0,0.15);
    }
</style>

    <!-- ── Page Header ──────────────────────────────────────────────────── -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
        <div>
            <h2 style="margin:0;">Reports &amp; Analytics</h2>
            <p class="muted" style="margin:4px 0 0;">Insights from completed and rejected service records.</p>
        </div>
        <a href="<?php echo getBasePath(); ?>admin/history.php"
           class="btn" style="font-size:0.85rem;padding:7px 14px;">
            ← Back to History
        </a>
    </div>

    <!-- ── Filter Bar ───────────────────────────────────────────────────── -->
    <form method="get" class="filter-bar">
        <div>
            <label>Date From</label>
            <input type="date" name="date_from" value="<?php echo e($dateFrom); ?>">
        </div>
        <div>
            <label>Date To</label>
            <input type="date" name="date_to" value="<?php echo e($dateTo); ?>">
        </div>
        <button type="submit" class="btn btn-primary" style="padding:8px 18px;">Apply Filters</button>
        <?php if ($dateFrom || $dateTo): ?>
            <a href="<?php echo getBasePath(); ?>admin/reports.php"
               class="btn" style="padding:8px 14px;">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($dateFrom || $dateTo): ?>
        <div style="background:#fff8f0;border:1px solid #ffd8b0;color:#995500;padding:8px 14px;border-radius:6px;margin-bottom:18px;font-size:0.85rem;">
            Filters active:
            <?php if ($dateFrom): ?> From <strong><?php echo e($dateFrom); ?></strong><?php endif; ?>
            <?php if ($dateTo):   ?> to <strong><?php echo e($dateTo); ?></strong><?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ── Section Switcher Dropdown ────────────────────────────────────── -->
    <div class="section-switcher">
        <label for="section-select">View:</label>
        <select id="section-select">
            <option value="overview">📊 Overview</option>
            <option value="mechanic">🔧 Mechanic Performance</option>
            <option value="feedback">⭐ Feedback &amp; Ratings</option>
            <option value="duration">⏱ Job Duration</option>
            <option value="volume">📈 Request Volume</option>
            <option value="map">🗺 Customer Map</option>
        </select>
    </div>

    <!-- ════════════════ SECTION: OVERVIEW ════════════════ -->
    <div class="report-section" id="tab-overview">
        <?php if ($overview['total_jobs'] == 0): ?>
            <div class="empty-state">
                <div class="ei">📭</div>
                <p>No records found for the selected filters.</p>
            </div>
        <?php else: ?>

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo (int)$overview['total_jobs']; ?></div>
                    <div class="stat-label">Total Jobs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $completionRate; ?>%</div>
                    <div class="stat-label">Completion Rate</div>
                    <div class="stat-sub">
                        <?php echo (int)$overview['completed']; ?> done &middot;
                        <?php echo (int)$overview['rejected']; ?> rejected
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php echo $overview['avg_mechanic_rating']
                            ? number_format((float)$overview['avg_mechanic_rating'], 1)
                            : 'N/A'; ?>
                    </div>
                    <div class="stat-label">Avg Mechanic Rating</div>
                    <div class="stat-sub">out of 5</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php echo $overview['avg_hours']
                            ? number_format((float)$overview['avg_hours'], 1) . 'h'
                            : 'N/A'; ?>
                    </div>
                    <div class="stat-label">Avg Job Duration</div>
                    <div class="stat-sub">completed jobs only</div>
                </div>
            </div>

            <h3 class="sec-title">Completed vs Rejected</h3>
            <p class="sec-sub">Overall breakdown of all jobs in the selected period</p>
            <div class="chart-wrap" style="max-width:320px;margin:0 auto 24px;">
                <canvas id="chart-donut"></canvas>
            </div>

        <?php endif; ?>
    </div>

    <!-- ════════════════ SECTION: MECHANIC PERFORMANCE ════════════════ -->
    <div class="report-section" id="tab-mechanic" style="display:none;">
        <?php if (empty($mechanicPerf)): ?>
            <div class="empty-state">
                <div class="ei">🔧</div>
                <p>No mechanic data available for the selected filters.</p>
            </div>
        <?php else: ?>

            <h3 class="sec-title">Average Rating per Mechanic</h3>
            <p class="sec-sub">Ranked highest to lowest &middot; based on customer feedback</p>
            <div class="chart-wrap" style="height:<?php echo max(260, count($mechanicPerf) * 58); ?>px;">
                <canvas id="chart-mechanic"></canvas>
            </div>

            <h3 class="sec-title" style="margin-top:8px;">Mechanic Summary</h3>
            <table>
                <thead>
                    <tr>
                        <th>Mechanic</th>
                        <th style="text-align:center;">Jobs</th>
                        <th style="text-align:center;">Avg Rating</th>
                        <th style="text-align:center;">Avg Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mechanicPerf as $i => $m): ?>
                        <tr>
                            <td>
                                <?php if ($i === 0): ?>
                                    <span style="color:#f39c12;font-weight:700;">🥇 </span>
                                <?php endif; ?>
                                <?php echo e($m['mechanic_name']); ?>
                            </td>
                            <td style="text-align:center;"><?php echo (int)$m['job_count']; ?></td>
                            <td style="text-align:center;">
                                <?php if ($m['avg_rating']): ?>
                                    <span style="color:#f39c12;">★</span>
                                    <?php echo number_format((float)$m['avg_rating'], 1); ?>
                                <?php else: ?>
                                    <span style="color:#bbb;font-size:0.82rem;">No ratings yet</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php echo $m['avg_hours']
                                    ? number_format((float)$m['avg_hours'], 1) . ' hrs'
                                    : '—'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>
    </div>

    <!-- ════════════════ SECTION: FEEDBACK & RATINGS ════════════════ -->
    <div class="report-section" id="tab-feedback" style="display:none;">
        <?php if (!$ratings || (int)$ratings['feedback_count'] === 0): ?>
            <div class="empty-state">
                <div class="ei">⭐</div>
                <p>No feedback submitted yet for the selected filters.</p>
            </div>
        <?php else: ?>

            <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr));margin-bottom:24px;">
                <div class="stat-card">
                    <div class="stat-value"><?php echo (int)$ratings['feedback_count']; ?></div>
                    <div class="stat-label">Feedbacks Received</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php echo $ratings['avg_mechanic']
                            ? number_format((float)$ratings['avg_mechanic'], 1)
                            : 'N/A'; ?>
                    </div>
                    <div class="stat-label">Avg Mechanic Rating</div>
                    <div class="stat-sub">out of 5</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php echo $ratings['avg_service']
                            ? number_format((float)$ratings['avg_service'], 1)
                            : 'N/A'; ?>
                    </div>
                    <div class="stat-label">Avg Service Rating</div>
                    <div class="stat-sub">out of 5</div>
                </div>
            </div>

            <h3 class="sec-title">Mechanic vs Service Rating</h3>
            <p class="sec-sub">Average scores across all submitted feedback</p>
            <div class="chart-wrap" style="height:240px;">
                <canvas id="chart-feedback"></canvas>
            </div>

            <?php if (!empty($topTags)):
                $positiveTags = array_filter($topTags, fn($t) => (bool)$t['is_positive']);
                $negativeTags = array_filter($topTags, fn($t) => !(bool)$t['is_positive']);
            ?>
            <h3 class="sec-title" style="margin-top:8px;">Most Selected Tags</h3>
            <?php if (!empty($positiveTags)): ?>
                <p style="font-size:0.82rem;color:#2f6627;font-weight:600;margin:0 0 8px;">👍 Positive</p>
                <div class="tag-list" style="margin-bottom:16px;">
                    <?php foreach ($positiveTags as $tag): ?>
                        <span class="tag-badge tag-pos">
                            <?php echo e($tag['label']); ?>
                            <span class="tag-cnt"><?php echo (int)$tag['cnt']; ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($negativeTags)): ?>
                <p style="font-size:0.82rem;color:#a94442;font-weight:600;margin:0 0 8px;">👎 Negative</p>
                <div class="tag-list">
                    <?php foreach ($negativeTags as $tag): ?>
                        <span class="tag-badge tag-neg">
                            <?php echo e($tag['label']); ?>
                            <span class="tag-cnt"><?php echo (int)$tag['cnt']; ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- ════════════════ SECTION: JOB DURATION ════════════════ -->
    <div class="report-section" id="tab-duration" style="display:none;">
        <?php if (empty($durationData)): ?>
            <div class="empty-state">
                <div class="ei">⏱</div>
                <p>No duration data available for the selected filters.</p>
            </div>
        <?php else: ?>

            <h3 class="sec-title">Average Job Duration by Problem Type</h3>
            <p class="sec-sub">Completed jobs only &middot; hours from request submission to job completion</p>
            <div class="chart-wrap" style="height:<?php echo $durationChartHeight; ?>px;">
                <canvas id="chart-duration"></canvas>
            </div>

            <table style="margin-top:8px;">
                <thead>
                    <tr>
                        <th>Problem Type</th>
                        <th style="text-align:center;">Avg Duration</th>
                        <th style="text-align:center;">Jobs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($durationData as $d): ?>
                        <tr>
                            <td><?php echo e($d['problem_type']); ?></td>
                            <td style="text-align:center;"><?php echo number_format((float)$d['avg_hours'], 1); ?> hrs</td>
                            <td style="text-align:center;"><?php echo (int)$d['job_count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>
    </div>

    <!-- ════════════════ SECTION: REQUEST VOLUME ════════════════ -->
    <div class="report-section" id="tab-volume" style="display:none;">
        <?php if ($totalVolume === 0): ?>
            <div class="empty-state">
                <div class="ei">📈</div>
                <p>No request volume data available for the selected filters.</p>
            </div>
        <?php else: ?>

            <h3 class="sec-title">Request Volume by Hour of Day</h3>
            <p class="sec-sub">When do customers tend to submit service requests?</p>
            <div class="chart-wrap" style="height:300px;">
                <canvas id="chart-volume"></canvas>
            </div>
            <p style="text-align:center;font-size:0.85rem;color:#888;margin-top:4px;">
                Peak hour: <strong style="color:#ff6600;"><?php echo $peakLabel; ?></strong>
                with <strong><?php echo $peakCount; ?></strong>
                request<?php echo $peakCount !== 1 ? 's' : ''; ?>
            </p>

        <?php endif; ?>
    </div>

    <!-- ════════════════ SECTION: CUSTOMER MAP ════════════════ -->
    <div class="report-section" id="tab-map" style="display:none;">
        <?php if (empty($mapLocations)): ?>
            <div class="empty-state">
                <div class="ei">🗺</div>
                <p>No location data available for the selected filters.</p>
            </div>
        <?php else: ?>

            <h3 class="sec-title">Customer Job Locations</h3>
            <p class="sec-sub">
                <?php echo count($mapLocations); ?> location<?php echo count($mapLocations) !== 1 ? 's' : ''; ?> &nbsp;&middot;&nbsp;
                <span style="color:#ff6600;">●</span> Completed &nbsp;
                <span style="color:#e74c3c;">●</span> Rejected
            </p>
            <div id="report-map" style="height:480px;border-radius:8px;border:1px solid #eee;"></div>

        <?php endif; ?>
    </div>

</div><!-- /card -->
</main>

<script>
const overview = <?php echo $jsOverview; ?>;
const mechPerf = <?php echo $jsMechPerf; ?>;
const ratings  = <?php echo $jsRatings; ?>;
const topTags  = <?php echo $jsTopTags; ?>;
const duration = <?php echo $jsDuration; ?>;
const volume   = <?php echo $jsVolume; ?>;
const mapLocs  = <?php echo $jsMapLocs; ?>;

// ── Section switching via dropdown ───────────────────────────────────────────
const inited = {};
const select = document.getElementById('section-select');

function showTab(name) {
    document.querySelectorAll('.report-section').forEach(el => el.style.display = 'none');
    const section = document.getElementById('tab-' + name);
    if (section) section.style.display = '';
    if (select.value !== name) select.value = name;
    sessionStorage.setItem('reports_tab', name);
    if (!inited[name]) { inited[name] = true; initSection(name); }
}

select.addEventListener('change', () => showTab(select.value));

function initSection(name) {
    const map = {
        overview: initOverview,
        mechanic: initMechanic,
        feedback: initFeedback,
        duration: initDuration,
        volume:   initVolume,
        map:      initMap,
    };
    if (map[name]) map[name]();
}

const C_ORANGE     = '#ff6600';
const C_ORANGE_DIM = 'rgba(255,102,0,0.5)';
const C_RED        = '#e74c3c';
const C_BLUE       = '#2980b9';
const FONT         = { family: "'Segoe UI', Arial, sans-serif", size: 13 };

function initOverview() {
    const el = document.getElementById('chart-donut');
    if (!el || !overview) return;
    new Chart(el, {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'Rejected'],
            datasets: [{
                data: [overview.completed || 0, overview.rejected || 0],
                backgroundColor: [C_ORANGE, C_RED],
                borderWidth: 3,
                borderColor: '#fff',
            }]
        },
        options: {
            cutout: '62%',
            plugins: {
                legend: { position: 'bottom', labels: { padding: 22, font: FONT } }
            }
        }
    });
}

function initMechanic() {
    const el = document.getElementById('chart-mechanic');
    if (!el || !mechPerf.length) return;
    new Chart(el, {
        type: 'bar',
        data: {
            labels: mechPerf.map(m => m.mechanic_name || 'Unknown'),
            datasets: [{
                label: 'Avg Rating',
                data: mechPerf.map(m => m.avg_rating || 0),
                backgroundColor: mechPerf.map((_, i) => i === 0 ? C_ORANGE : C_ORANGE_DIM),
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { min: 0, max: 5, ticks: { stepSize: 1, font: FONT } },
                x: { ticks: { font: FONT } }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterLabel: ctx => {
                            const m = mechPerf[ctx.dataIndex];
                            return [
                                'Jobs: ' + m.job_count,
                                'Avg Duration: ' + (m.avg_hours || 'N/A') + ' hrs'
                            ];
                        }
                    }
                }
            }
        }
    });
}

function initFeedback() {
    const el = document.getElementById('chart-feedback');
    if (!el || !ratings) return;
    new Chart(el, {
        type: 'bar',
        data: {
            labels: ['Mechanic Rating', 'Service Rating'],
            datasets: [{
                data: [ratings.avg_mechanic || 0, ratings.avg_service || 0],
                backgroundColor: [C_ORANGE, C_BLUE],
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { min: 0, max: 5, ticks: { stepSize: 1, font: FONT } },
                x: { ticks: { font: FONT } }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => '  ' + Number(ctx.parsed.y).toFixed(1) + ' / 5'
                    }
                }
            }
        }
    });
}

function initDuration() {
    const el = document.getElementById('chart-duration');
    if (!el || !duration.length) return;
    new Chart(el, {
        type: 'bar',
        data: {
            labels: duration.map(d => d.problem_type),
            datasets: [{
                label: 'Avg Hours',
                data: duration.map(d => d.avg_hours || 0),
                backgroundColor: C_ORANGE_DIM,
                borderColor: C_ORANGE,
                borderWidth: 1,
                borderRadius: 5,
                borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { ticks: { callback: v => v + 'h', font: FONT } },
                y: { ticks: { font: FONT } }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => '  ' + Number(ctx.parsed.x).toFixed(1) + ' hours'
                    }
                }
            }
        }
    });
}

function initVolume() {
    const el = document.getElementById('chart-volume');
    if (!el) return;
    const labels = Array.from({length: 24}, (_, i) => {
        if (i === 0)  return '12 AM';
        if (i === 12) return '12 PM';
        return i < 12 ? i + ' AM' : (i - 12) + ' PM';
    });
    const maxVal = Math.max(...volume);
    new Chart(el, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Requests',
                data: volume,
                backgroundColor: volume.map(v => v === maxVal && maxVal > 0 ? C_ORANGE : C_ORANGE_DIM),
                borderRadius: 4,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { ticks: { stepSize: 1, font: FONT } },
                x: { ticks: { font: { size: 11 } } }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => '  ' + ctx.parsed.y + ' request' + (ctx.parsed.y !== 1 ? 's' : '')
                    }
                }
            }
        }
    });
}

function initMap() {
    const el = document.getElementById('report-map');
    if (!el || !mapLocs.length) return;

    const avgLat = mapLocs.reduce((s, l) => s + parseFloat(l.latitude),  0) / mapLocs.length;
    const avgLng = mapLocs.reduce((s, l) => s + parseFloat(l.longitude), 0) / mapLocs.length;

    const map = L.map('report-map').setView([avgLat, avgLng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 18,
    }).addTo(map);

    mapLocs.forEach(loc => {
        L.circleMarker([parseFloat(loc.latitude), parseFloat(loc.longitude)], {
            radius:      9,
            fillColor:   loc.status === 'completed' ? C_ORANGE : C_RED,
            color:       '#fff',
            weight:      2,
            fillOpacity: 0.85,
        }).addTo(map).bindPopup(
            '<strong>' + (loc.customer_name || 'Customer') + '</strong><br>' +
            (loc.problem_type || 'Service Request') + '<br>' +
            '<span style="text-transform:capitalize;font-size:0.82em;color:#888;">' +
            loc.status + '</span>'
        );
    });
}

// ── Boot: restore last active section ────────────────────────────────────────
const savedTab = sessionStorage.getItem('reports_tab') || 'overview';
// Guard: if saved tab was 'revenue', fall back to overview
showTab(['overview','mechanic','feedback','duration','volume','map'].includes(savedTab) ? savedTab : 'overview');
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>