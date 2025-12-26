<?php
/**
 * xAPI Learning Records Viewer - Tsugi Module
 *
 * Displays xAPI learning records from an LRS filtered by student email.
 * Shows only instructor-configured activities for the course.
 * Supports automatic grade passback to the LMS gradebook.
 */

require_once "../../config.php";
require_once "lib/xapi.php";

use \Tsugi\Core\LTIX;
use \Tsugi\Util\U;

// Retrieve the launch data and user/context info
$LAUNCH = LTIX::requireData();

global $PDOX, $CFG;

// Get LRS configuration from Tsugi config or environment
$lrsEndpoint = U::get($_SESSION, 'lrs_endpoint',
    getenv('LRS_ENDPOINT') ?: 'http://localhost:8081/xapi');
$lrsKey = U::get($_SESSION, 'lrs_api_key',
    getenv('LRS_API_KEY') ?: 'my_api_key');
$lrsSecret = U::get($_SESSION, 'lrs_api_secret',
    getenv('LRS_API_SECRET') ?: 'my_api_secret');
$timezone = getenv('APP_TIMEZONE') ?: 'America/New_York';

// Get user info from Tsugi launch
$userEmail = $LAUNCH->user->email ?? null;
$userName = $LAUNCH->user->displayname ?? 'Student';
$contextTitle = $LAUNCH->context->title ?? 'Course';
$contextId = $LAUNCH->context->id;
$isInstructor = $LAUNCH->user->instructor ?? false;

// Initialize variables
$error = null;
$statements = [];
$allXapiActivities = [];
$configuredActivities = [];
$activityProgress = [];

// Fetch configured activities for this course
$stmt = $PDOX->prepare("SELECT * FROM {$CFG->dbprefix}xapi_activities WHERE context_id = :context_id ORDER BY display_order ASC");
$stmt->execute([':context_id' => $contextId]);
$configuredActivities = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// Fetch xAPI statements if we have a valid email
if ($userEmail) {
    $result = getXapiStatements($lrsEndpoint, $lrsKey, $lrsSecret, $userEmail);

    if ($result['error']) {
        $error = 'Error fetching records: ' . $result['error'];
    } else {
        $statements = $result['statements'];
        $allXapiActivities = groupStatementsByActivity($statements);

        // Match configured activities to xAPI data
        foreach ($configuredActivities as &$config) {
            $matched = null;

            // Try to match by xAPI Activity ID first
            if (!empty($config['xapi_activity_id'])) {
                foreach ($allXapiActivities as $activityId => $activity) {
                    if (stripos($activityId, $config['xapi_activity_id']) !== false ||
                        stripos($config['xapi_activity_id'], $activityId) !== false) {
                        $matched = ['id' => $activityId, 'activity' => $activity];
                        break;
                    }
                }
            }

            // Fall back to title matching
            if (!$matched && !empty($config['title'])) {
                $matched = findMatchingActivity($allXapiActivities, $config['title'], null);
            }

            // Store the match result
            $config['matched'] = $matched;
            if ($matched) {
                $config['grade'] = calculateActivityGrade($matched['activity']);
            } else {
                $config['grade'] = null;
            }
        }
        unset($config); // break reference
    }
} elseif (!$isInstructor) {
    $error = 'Email address not available from LMS launch.';
}

// Calculate overall statistics based on configured activities
$totalConfigured = count($configuredActivities);
$completedCount = 0;
$passedCount = 0;
$totalScore = 0;
$scoredCount = 0;

foreach ($configuredActivities as $config) {
    if ($config['matched']) {
        $activity = $config['matched']['activity'];
        if (in_array($activity['status'], ['passed', 'completed', 'failed'])) {
            $completedCount++;
        }
        if ($activity['status'] === 'passed') {
            $passedCount++;
        }
        if ($config['grade'] !== null) {
            $totalScore += $config['grade'];
            $scoredCount++;
        }
    }
}
$avgScore = $scoredCount > 0 ? round(($totalScore / $scoredCount) * 100, 1) : null;

// Start Tsugi output
$OUTPUT->header();
?>
<link rel="stylesheet" href="css/styles.css">
<style>
.activity-row {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border-left: 4px solid #e2e3e5;
}
.activity-row.status-passed { border-left-color: #28a745; }
.activity-row.status-failed { border-left-color: #dc3545; }
.activity-row.status-completed { border-left-color: #17a2b8; }
.activity-row.status-not-started { border-left-color: #6c757d; background: #fafafa; }
.activity-title {
    font-weight: 600;
    font-size: 1.1rem;
    color: #333;
}
.activity-score {
    font-size: 1.5rem;
    font-weight: bold;
}
.activity-score.has-score { color: #667eea; }
.activity-score.no-score { color: #adb5bd; }
.points-label {
    font-size: 0.85rem;
    color: #6c757d;
}
.instructor-link {
    position: absolute;
    top: 15px;
    right: 15px;
}
.empty-config {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 10px;
    padding: 30px;
    text-align: center;
}
</style>
<?php
$OUTPUT->bodyStart();
$OUTPUT->topNav();
$OUTPUT->flashMessages();
?>

<div class="container-fluid position-relative">
    <?php if ($isInstructor): ?>
        <a href="settings.php" class="btn btn-outline-primary instructor-link">
            <i class="fa fa-cog"></i> Configure Activities
        </a>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error-box">
            <h3>Unable to Load Records</h3>
            <p class="text-danger"><?= htmlspecialchars($error) ?></p>
        </div>
    <?php else: ?>
        <!-- Header -->
        <div class="xapi-header">
            <h1>My Learning Records</h1>
            <p class="mb-0">Welcome, <?= htmlspecialchars($userName) ?>!</p>
            <?php if ($userEmail): ?>
                <small>Tracking records for: <?= htmlspecialchars($userEmail) ?></small>
            <?php endif; ?>
            <br><small>Course: <?= htmlspecialchars($contextTitle) ?></small>
        </div>

        <?php if (empty($configuredActivities)): ?>
            <!-- No activities configured -->
            <div class="empty-config">
                <h4>No Activities Configured</h4>
                <p class="text-muted mb-0">
                    <?php if ($isInstructor): ?>
                        You haven't configured any activities for this course yet.
                        <br><a href="settings.php" class="btn btn-primary mt-3">Configure Activities</a>
                    <?php else: ?>
                        Your instructor has not configured any activities for grading yet.<br>
                        Please check back later.
                    <?php endif; ?>
                </p>
            </div>
        <?php elseif (!$userEmail && !$isInstructor): ?>
            <div class="alert alert-warning">
                <strong>Email Not Available</strong><br>
                Your email address was not provided by the LMS. Please contact your instructor.
            </div>
        <?php else: ?>
            <!-- Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-number"><?= $totalConfigured ?></div>
                        <div>Total Activities</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-number"><?= $completedCount ?></div>
                        <div>Completed</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-number"><?= $passedCount ?></div>
                        <div>Passed</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-number"><?= $avgScore !== null ? $avgScore . '%' : '-' ?></div>
                        <div>Avg Score</div>
                    </div>
                </div>
            </div>

            <!-- Activity List -->
            <h4 class="mb-3">Course Activities</h4>
            <?php foreach ($configuredActivities as $index => $config): ?>
                <?php
                $matched = $config['matched'];
                $activity = $matched ? $matched['activity'] : null;

                // Determine status
                $statusClass = 'status-not-started';
                $statusLabel = 'Not Started';
                if ($activity) {
                    if ($activity['status'] === 'passed') {
                        $statusClass = 'status-passed';
                        $statusLabel = 'Passed';
                    } elseif ($activity['status'] === 'failed') {
                        $statusClass = 'status-failed';
                        $statusLabel = 'Failed';
                    } elseif ($activity['status'] === 'completed') {
                        $statusClass = 'status-completed';
                        $statusLabel = 'Completed';
                    } else {
                        $statusClass = 'status-attempted';
                        $statusLabel = 'In Progress';
                    }
                }

                $hasChildren = $activity && !empty($activity['children']);
                $childCount = $hasChildren ? count($activity['children']) : 0;
                $passedChildren = 0;
                if ($hasChildren) {
                    foreach ($activity['children'] as $child) {
                        if ($child['status'] === 'passed') $passedChildren++;
                    }
                }

                $scoreDisplay = $config['grade'] !== null ? round($config['grade'] * 100) : '-';
                $earnedPoints = $config['grade'] !== null ? round($config['grade'] * $config['points_possible'], 1) : '-';
                ?>
                <div class="activity-row <?= $statusClass ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                                <?php if ($hasChildren): ?>
                                    <span class="task-count"><?= $passedChildren ?>/<?= $childCount ?> tasks passed</span>
                                <?php endif; ?>
                            </div>
                            <div class="activity-title">
                                <?= htmlspecialchars($config['title']) ?>
                            </div>
                            <?php if ($activity): ?>
                                <div class="timestamp mt-1">
                                    Last activity: <?= formatTimestamp($activity['latestTimestamp'], $timezone) ?>
                                </div>
                            <?php else: ?>
                                <div class="timestamp mt-1 text-muted">
                                    No activity recorded yet
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <div class="activity-score <?= $config['grade'] !== null ? 'has-score' : 'no-score' ?>">
                                <?= $scoreDisplay ?><?= $config['grade'] !== null ? '%' : '' ?>
                            </div>
                            <div class="points-label">
                                <?= $earnedPoints ?> / <?= number_format($config['points_possible'], 0) ?> pts
                            </div>
                        </div>
                    </div>

                    <!-- Nested children tasks -->
                    <?php if ($hasChildren): ?>
                        <div class="mt-3">
                            <button class="btn btn-sm btn-outline-secondary toggle-tasks" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#children-<?= $index ?>"
                                    aria-expanded="false">
                                <span class="show-text">Show Tasks (<?= $childCount ?>)</span>
                                <span class="hide-text">Hide Tasks</span>
                            </button>
                        </div>
                        <div class="collapse" id="children-<?= $index ?>">
                            <div class="children-list mt-3">
                                <?php foreach ($activity['children'] as $childId => $child): ?>
                                    <?php
                                    $childStatusClass = 'status-attempted';
                                    if ($child['status'] === 'passed') $childStatusClass = 'status-passed';
                                    elseif ($child['status'] === 'failed') $childStatusClass = 'status-failed';
                                    elseif ($child['status'] === 'completed') $childStatusClass = 'status-completed';
                                    ?>
                                    <div class="child-item <?= $childStatusClass ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="child-status-icon <?= $childStatusClass ?>">
                                                    <?php if ($child['status'] === 'passed'): ?>
                                                        &#10003;
                                                    <?php elseif ($child['status'] === 'failed'): ?>
                                                        &#10007;
                                                    <?php else: ?>
                                                        &#9679;
                                                    <?php endif; ?>
                                                </span>
                                                <span class="child-name"><?= htmlspecialchars($child['name']) ?></span>
                                            </div>
                                            <?php if ($child['highestScore'] !== null): ?>
                                                <div class="child-score">
                                                    <?= round($child['highestScore'] * 100) ?>%
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$OUTPUT->footerStart();
?>
<script>
// Initialize Bootstrap collapse toggle text
document.querySelectorAll('.toggle-tasks').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var showText = this.querySelector('.show-text');
        var hideText = this.querySelector('.hide-text');
        if (this.getAttribute('aria-expanded') === 'true') {
            showText.style.display = 'inline';
            hideText.style.display = 'none';
        } else {
            showText.style.display = 'none';
            hideText.style.display = 'inline';
        }
    });
});
</script>
<?php
$OUTPUT->footerEnd();
