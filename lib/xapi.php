<?php
/**
 * xAPI Helper Functions
 *
 * Functions for fetching and processing xAPI statements from a Learning Record Store (LRS)
 */

/**
 * Query xAPI statements from the LRS for a specific actor email
 */
function getXapiStatements($endpoint, $key, $secret, $email, $limit = 100) {
    $agent = json_encode([
        "mbox" => "mailto:" . $email
    ]);

    $url = rtrim($endpoint, '/') . "/statements?" . http_build_query([
        'agent' => $agent,
        'limit' => $limit
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($key . ':' . $secret),
        'X-Experience-API-Version: 1.0.3',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => "HTTP $httpCode: $error", 'statements' => []];
    }

    $data = json_decode($response, true);
    return ['error' => null, 'statements' => $data['statements'] ?? []];
}

/**
 * Format a timestamp for display
 */
function formatTimestamp($timestamp, $timezone = 'America/New_York') {
    $dt = new DateTime($timestamp);
    $dt->setTimezone(new DateTimeZone($timezone));
    return $dt->format('M j, Y g:i A');
}

/**
 * Extract a human-readable verb name
 */
function getVerbName($verb) {
    if (isset($verb['display']['en-US'])) {
        return $verb['display']['en-US'];
    }
    if (isset($verb['display']['en'])) {
        return $verb['display']['en'];
    }
    $parts = explode('/', $verb['id']);
    return ucfirst(end($parts));
}

/**
 * Extract a human-readable object name
 */
function getObjectName($object) {
    if (isset($object['definition']['name']['en-US'])) {
        return $object['definition']['name']['en-US'];
    }
    if (isset($object['definition']['name']['en'])) {
        return $object['definition']['name']['en'];
    }
    return $object['id'] ?? 'Unknown';
}

/**
 * Get parent activity ID from statement context
 */
function getParentActivityId($statement) {
    if (isset($statement['context']['contextActivities']['parent'][0]['id'])) {
        return $statement['context']['contextActivities']['parent'][0]['id'];
    }
    if (isset($statement['context']['contextActivities']['grouping'][0]['id'])) {
        return $statement['context']['contextActivities']['grouping'][0]['id'];
    }
    return null;
}

/**
 * Update activity statistics from a statement
 */
function updateActivityStats(&$activity, $statement) {
    $verb = strtolower(getVerbName($statement['verb']));

    // Update latest timestamp
    if ($statement['timestamp'] > $activity['latestTimestamp']) {
        $activity['latestTimestamp'] = $statement['timestamp'];
    }

    // Update status based on verb
    if (in_array($verb, ['passed', 'mastered'])) {
        $activity['status'] = 'passed';
    } elseif ($verb === 'failed' && $activity['status'] !== 'passed') {
        $activity['status'] = 'failed';
    } elseif (in_array($verb, ['completed', 'finished']) && !in_array($activity['status'], ['passed', 'failed'])) {
        $activity['status'] = 'completed';
    }

    // Track highest score
    if (isset($statement['result']['score']['scaled'])) {
        $score = $statement['result']['score']['scaled'];
        if ($activity['highestScore'] === null || $score > $activity['highestScore']) {
            $activity['highestScore'] = $score;
            $activity['bestAttempt'] = $statement;
        }
    }
}

/**
 * Group statements by parent activity with children nested
 */
function groupStatementsByActivity($statements) {
    $parents = [];
    $children = [];
    $parentIds = [];

    // First pass: identify all parent IDs
    foreach ($statements as $statement) {
        $parentId = getParentActivityId($statement);
        if ($parentId) {
            $parentIds[$parentId] = true;
        }
    }

    // Second pass: categorize statements as parents or children
    foreach ($statements as $statement) {
        $objectId = $statement['object']['id'] ?? 'unknown';
        $parentId = getParentActivityId($statement);

        // If this statement's object is referenced as a parent by others, or has no parent itself, it's a parent
        if (isset($parentIds[$objectId]) || $parentId === null) {
            if (!isset($parents[$objectId])) {
                $parents[$objectId] = [
                    'name' => getObjectName($statement['object']),
                    'object' => $statement['object'],
                    'highestScore' => null,
                    'bestAttempt' => null,
                    'status' => 'attempted',
                    'attempts' => [],
                    'children' => [],
                    'latestTimestamp' => $statement['timestamp']
                ];
            }
            $parents[$objectId]['attempts'][] = $statement;
            updateActivityStats($parents[$objectId], $statement);
        } else {
            // This is a child statement
            if (!isset($children[$parentId])) {
                $children[$parentId] = [];
            }
            if (!isset($children[$parentId][$objectId])) {
                $children[$parentId][$objectId] = [
                    'name' => getObjectName($statement['object']),
                    'object' => $statement['object'],
                    'highestScore' => null,
                    'bestAttempt' => null,
                    'status' => 'attempted',
                    'attempts' => [],
                    'latestTimestamp' => $statement['timestamp']
                ];
            }
            $children[$parentId][$objectId]['attempts'][] = $statement;
            updateActivityStats($children[$parentId][$objectId], $statement);
        }
    }

    // Attach children to parents
    foreach ($children as $parentId => $childActivities) {
        if (isset($parents[$parentId])) {
            $parents[$parentId]['children'] = $childActivities;
            // Update parent status based on children
            $allPassed = true;
            $anyFailed = false;
            foreach ($childActivities as $child) {
                if ($child['status'] === 'failed') {
                    $anyFailed = true;
                    $allPassed = false;
                } elseif ($child['status'] !== 'passed') {
                    $allPassed = false;
                }
            }
            if (!empty($childActivities)) {
                if ($allPassed && count($childActivities) > 0) {
                    $parents[$parentId]['status'] = 'passed';
                } elseif ($anyFailed) {
                    $parents[$parentId]['status'] = 'failed';
                }
            }
        } else {
            // Parent doesn't exist in statements, promote children to top level
            foreach ($childActivities as $childId => $child) {
                $parents[$childId] = $child;
                $parents[$childId]['children'] = [];
            }
        }
    }

    // Sort by latest timestamp (most recent first)
    uasort($parents, function($a, $b) {
        return strcmp($b['latestTimestamp'], $a['latestTimestamp']);
    });

    return $parents;
}

/**
 * Find matching activity for the current LTI launch
 */
function findMatchingActivity($groupedActivities, $resourceLinkTitle, $customLabId = null) {
    // If custom_lab_id is provided, match by activity ID containing it
    if ($customLabId) {
        foreach ($groupedActivities as $activityId => $activity) {
            if (stripos($activityId, $customLabId) !== false) {
                return ['id' => $activityId, 'activity' => $activity];
            }
        }
    }

    // Match by resource_link_title against activity name
    if ($resourceLinkTitle) {
        $titleLower = strtolower($resourceLinkTitle);
        foreach ($groupedActivities as $activityId => $activity) {
            $nameLower = strtolower($activity['name']);
            // Check if title contains activity name or vice versa
            if (stripos($nameLower, $titleLower) !== false ||
                stripos($titleLower, $nameLower) !== false ||
                similar_text($nameLower, $titleLower) > min(strlen($nameLower), strlen($titleLower)) * 0.6) {
                return ['id' => $activityId, 'activity' => $activity];
            }
        }

        // Try matching key parts of the title
        $titleParts = preg_split('/[\s\-_:]+/', $titleLower);
        foreach ($groupedActivities as $activityId => $activity) {
            $nameLower = strtolower($activity['name']);
            foreach ($titleParts as $part) {
                if (strlen($part) > 3 && stripos($nameLower, $part) !== false) {
                    return ['id' => $activityId, 'activity' => $activity];
                }
            }
        }
    }

    return null;
}

/**
 * Calculate grade for an activity (0.0 to 1.0)
 */
function calculateActivityGrade($activity) {
    // If activity has a score, use that
    if ($activity['highestScore'] !== null) {
        return $activity['highestScore'];
    }

    // If activity has children, calculate based on passed/total
    if (!empty($activity['children'])) {
        $passed = 0;
        $total = count($activity['children']);
        foreach ($activity['children'] as $child) {
            if ($child['status'] === 'passed') {
                $passed++;
            }
        }
        return $total > 0 ? $passed / $total : 0;
    }

    // Based on status alone
    switch ($activity['status']) {
        case 'passed':
        case 'mastered':
            return 1.0;
        case 'completed':
            return 1.0;
        case 'failed':
            return 0.0;
        default:
            return 0.0;
    }
}
