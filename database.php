<?php

// Database tables for xAPI Viewer module

// Table to store course-level activity configurations
// Instructors define which xAPI activities should be graded

$DATABASE_UNINSTALL = array(
    array("DROP TABLE IF EXISTS {$CFG->dbprefix}xapi_activities")
);

$DATABASE_INSTALL = array(
    // Activities table - stores configured activities for grading
    array("{$CFG->dbprefix}xapi_activities",
        "CREATE TABLE {$CFG->dbprefix}xapi_activities (
            activity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            context_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            xapi_activity_id VARCHAR(512) DEFAULT NULL,
            points_possible DECIMAL(10,2) DEFAULT 100.00,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (activity_id),
            CONSTRAINT fk_xapi_activities_context
                FOREIGN KEY (context_id)
                REFERENCES {$CFG->dbprefix}lti_context (context_id)
                ON DELETE CASCADE,
            INDEX idx_context (context_id),
            INDEX idx_xapi_id (xapi_activity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8")
);
