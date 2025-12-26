<?php
/**
 * Instructor Settings - Activity Configuration
 *
 * Allows instructors to define which xAPI activities will be graded for the course.
 */

require_once "../../config.php";
require_once "lib/xapi.php";

use \Tsugi\Core\LTIX;
use \Tsugi\Util\U;

// Retrieve the launch data
$LAUNCH = LTIX::requireData();

// Only instructors can access settings
if (!$LAUNCH->user->instructor) {
    header('Location: index.php');
    exit;
}

$contextId = $LAUNCH->context->id;

// Get LRS configuration
$lrsEndpoint = U::get($_SESSION, 'lrs_endpoint',
    getenv('LRS_ENDPOINT') ?: 'http://localhost:8080/xapi');
$lrsKey = U::get($_SESSION, 'lrs_api_key',
    getenv('LRS_API_KEY') ?: 'my_api_key');
$lrsSecret = U::get($_SESSION, 'lrs_api_secret',
    getenv('LRS_API_SECRET') ?: 'my_api_secret');

global $PDOX, $CFG;

$message = null;
$error = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new activity
    if (isset($_POST['add_activity'])) {
        $title = trim($_POST['title'] ?? '');
        $xapiActivityId = trim($_POST['xapi_activity_id'] ?? '');
        $pointsPossible = floatval($_POST['points_possible'] ?? 100);

        if (empty($title)) {
            $error = 'Activity title is required.';
        } else {
            // Get max display order
            $stmt = $PDOX->prepare("SELECT MAX(display_order) as max_order FROM {$CFG->dbprefix}xapi_activities WHERE context_id = :context_id");
            $stmt->execute([':context_id' => $contextId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $displayOrder = ($row['max_order'] ?? 0) + 1;

            $stmt = $PDOX->prepare("INSERT INTO {$CFG->dbprefix}xapi_activities
                (context_id, title, xapi_activity_id, points_possible, display_order)
                VALUES (:context_id, :title, :xapi_activity_id, :points_possible, :display_order)");
            $stmt->execute([
                ':context_id' => $contextId,
                ':title' => $title,
                ':xapi_activity_id' => $xapiActivityId ?: null,
                ':points_possible' => $pointsPossible,
                ':display_order' => $displayOrder
            ]);
            $message = 'Activity added successfully.';
        }
    }

    // Delete activity
    if (isset($_POST['delete_activity'])) {
        $activityId = intval($_POST['activity_id'] ?? 0);
        if ($activityId > 0) {
            $stmt = $PDOX->prepare("DELETE FROM {$CFG->dbprefix}xapi_activities WHERE activity_id = :activity_id AND context_id = :context_id");
            $stmt->execute([':activity_id' => $activityId, ':context_id' => $contextId]);
            $message = 'Activity deleted.';
        }
    }

    // Update activity
    if (isset($_POST['update_activity'])) {
        $activityId = intval($_POST['activity_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $xapiActivityId = trim($_POST['xapi_activity_id'] ?? '');
        $pointsPossible = floatval($_POST['points_possible'] ?? 100);

        if ($activityId > 0 && !empty($title)) {
            $stmt = $PDOX->prepare("UPDATE {$CFG->dbprefix}xapi_activities
                SET title = :title, xapi_activity_id = :xapi_activity_id, points_possible = :points_possible
                WHERE activity_id = :activity_id AND context_id = :context_id");
            $stmt->execute([
                ':title' => $title,
                ':xapi_activity_id' => $xapiActivityId ?: null,
                ':points_possible' => $pointsPossible,
                ':activity_id' => $activityId,
                ':context_id' => $contextId
            ]);
            $message = 'Activity updated.';
        }
    }

    // Move activity up/down
    if (isset($_POST['move_up']) || isset($_POST['move_down'])) {
        $activityId = intval($_POST['activity_id'] ?? 0);
        $direction = isset($_POST['move_up']) ? -1 : 1;

        if ($activityId > 0) {
            // Get current order
            $stmt = $PDOX->prepare("SELECT display_order FROM {$CFG->dbprefix}xapi_activities WHERE activity_id = :activity_id");
            $stmt->execute([':activity_id' => $activityId]);
            $current = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($current) {
                $currentOrder = $current['display_order'];
                $newOrder = $currentOrder + $direction;

                // Find activity to swap with
                $op = $direction < 0 ? '<' : '>';
                $sort = $direction < 0 ? 'DESC' : 'ASC';
                $stmt = $PDOX->prepare("SELECT activity_id, display_order FROM {$CFG->dbprefix}xapi_activities
                    WHERE context_id = :context_id AND display_order $op :current_order
                    ORDER BY display_order $sort LIMIT 1");
                $stmt->execute([':context_id' => $contextId, ':current_order' => $currentOrder]);
                $swap = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($swap) {
                    // Swap orders
                    $stmt = $PDOX->prepare("UPDATE {$CFG->dbprefix}xapi_activities SET display_order = :order WHERE activity_id = :id");
                    $stmt->execute([':order' => $swap['display_order'], ':id' => $activityId]);
                    $stmt->execute([':order' => $currentOrder, ':id' => $swap['activity_id']]);
                }
            }
        }
    }
}

// Fetch configured activities
$stmt = $PDOX->prepare("SELECT * FROM {$CFG->dbprefix}xapi_activities WHERE context_id = :context_id ORDER BY display_order ASC");
$stmt->execute([':context_id' => $contextId]);
$activities = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// Start output
$OUTPUT->header();
?>
<link rel="stylesheet" href="css/styles.css">
<style>
.settings-header {
    background: linear-gradient(135deg, #5a6fd6 0%, #6b5b95 100%);
    color: white;
    padding: 25px 30px;
    border-radius: 10px;
    margin-bottom: 30px;
}
.activity-table {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}
.activity-table table {
    margin-bottom: 0;
}
.activity-table th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}
.add-form {
    background: white;
    border-radius: 10px;
    padding: 25px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}
.btn-move {
    padding: 2px 8px;
    font-size: 0.8rem;
}
.xapi-id-cell {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.xapi-id-cell:hover {
    white-space: normal;
    word-break: break-all;
}
</style>
<?php
$OUTPUT->bodyStart();
$OUTPUT->topNav();
$OUTPUT->flashMessages();

if ($message) {
    echo '<div class="alert alert-success">' . htmlspecialchars($message) . '</div>';
}
if ($error) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}
?>

<div class="container-fluid">
    <div class="settings-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2>Activity Configuration</h2>
                <p class="mb-0">Define the xAPI activities that will be graded for this course.</p>
            </div>
            <a href="index.php" class="btn btn-light">Back to Viewer</a>
        </div>
    </div>

    <!-- Add New Activity Form -->
    <div class="add-form">
        <h4>Add New Activity</h4>
        <form method="post" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Activity Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" placeholder="e.g., Lab 1: Getting Started" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">xAPI Activity ID</label>
                <input type="text" name="xapi_activity_id" class="form-control" placeholder="e.g., http://example.com/activities/lab1">
                <small class="text-muted">Optional - for exact matching. Leave blank to match by title.</small>
            </div>
            <div class="col-md-2">
                <label class="form-label">Points Possible</label>
                <input type="number" name="points_possible" class="form-control" value="100" min="0" step="0.01">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" name="add_activity" class="btn btn-primary w-100">Add Activity</button>
            </div>
        </form>
    </div>

    <!-- Activities List -->
    <?php if (empty($activities)): ?>
        <div class="alert alert-info">
            <strong>No activities configured yet.</strong><br>
            Add activities above to define what will be graded for this course.
        </div>
    <?php else: ?>
        <div class="activity-table">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th style="width: 50px;">Order</th>
                        <th>Title</th>
                        <th>xAPI Activity ID</th>
                        <th style="width: 100px;">Points</th>
                        <th style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $index => $activity): ?>
                        <tr>
                            <td>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="activity_id" value="<?= $activity['activity_id'] ?>">
                                    <button type="submit" name="move_up" class="btn btn-sm btn-outline-secondary btn-move" <?= $index === 0 ? 'disabled' : '' ?>>&#9650;</button>
                                    <button type="submit" name="move_down" class="btn btn-sm btn-outline-secondary btn-move" <?= $index === count($activities) - 1 ? 'disabled' : '' ?>>&#9660;</button>
                                </form>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($activity['title']) ?></strong>
                            </td>
                            <td class="xapi-id-cell text-muted">
                                <?= htmlspecialchars($activity['xapi_activity_id'] ?: '(match by title)') ?>
                            </td>
                            <td><?= number_format($activity['points_possible'], 0) ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $activity['activity_id'] ?>">
                                    Edit
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this activity?');">
                                    <input type="hidden" name="activity_id" value="<?= $activity['activity_id'] ?>">
                                    <button type="submit" name="delete_activity" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editModal<?= $activity['activity_id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Activity</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="activity_id" value="<?= $activity['activity_id'] ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Activity Title</label>
                                                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($activity['title']) ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">xAPI Activity ID</label>
                                                <input type="text" name="xapi_activity_id" class="form-control" value="<?= htmlspecialchars($activity['xapi_activity_id'] ?? '') ?>">
                                                <small class="text-muted">Leave blank to match by title.</small>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Points Possible</label>
                                                <input type="number" name="points_possible" class="form-control" value="<?= $activity['points_possible'] ?>" min="0" step="0.01">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_activity" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-4 text-muted">
            <small>
                <strong>Tip:</strong> Students will see their progress for each configured activity.
                Activities are matched to xAPI statements by Activity ID (if specified) or by title similarity.
            </small>
        </div>
    <?php endif; ?>
</div>

<?php
$OUTPUT->footerStart();
$OUTPUT->footerEnd();
