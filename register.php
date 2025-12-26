<?php

$REGISTER_LTI2 = array(
    "name" => "xAPI Learning Records Viewer",
    "FontAwesome" => "fa-chart-line",
    "short_name" => "xAPI Viewer",
    "description" => "View xAPI learning records from an LRS filtered by student email. Instructors can configure which activities to grade. Displays activity progress, scores, and task completion status.",
    "messages" => array("launch", "launch_grade"),
    "privacy_level" => "name_only",  // We need email for xAPI lookup
    "license" => "Apache",
    "languages" => array("English"),
    "source_url" => "https://github.com/frazier-at-cpcc/tsugi-xapi",
    "placements" => array("course"),
    "screen_shots" => array(),
    "analytics" => false,
    "tool_phase" => "new",
    "hide_from_store" => false,
);
