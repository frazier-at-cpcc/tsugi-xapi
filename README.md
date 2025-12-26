# xAPI Learning Records Viewer - Tsugi Module

A Tsugi LTI module that displays xAPI learning records from a Learning Record Store (LRS) filtered by student email. Instructors can configure which activities will be graded for their course.

## Features

- **Instructor Activity Configuration**: Define which xAPI activities will be graded for the course
- **Student Progress View**: Students see their progress on configured activities
- **Activity Grouping**: Parent-child task hierarchy with nested task display
- **Status Tracking**: Not Started, In Progress, Completed, Passed, Failed
- **Score Display**: Shows percentage scores and points earned
- **Activity Matching**: Match by xAPI Activity ID or title similarity

## Installation

### Option 1: Git Clone into Tsugi mod folder

```bash
cd /path/to/tsugi/mod
git clone https://github.com/frazier-at-cpcc/lti-xapi-viewer.git xapi-viewer
```

The module should appear automatically in Tsugi's tool list.

### Option 2: Install as separate folder

1. Clone the repository as a peer to your Tsugi installation:

```bash
cd /path/to/htdocs
git clone https://github.com/frazier-at-cpcc/lti-xapi-viewer.git tsugi-xapi-viewer
```

2. Edit your Tsugi `config.php` to add the module path:

```php
$CFG->tool_folders = array("admin", "mod", "../tsugi-xapi-viewer");
```

3. Run database upgrade from Tsugi Admin to create the required tables.

## Configuration

### Environment Variables

Set these environment variables in your server configuration:

| Variable | Description | Default |
|----------|-------------|---------|
| `LRS_ENDPOINT` | xAPI LRS endpoint URL | `http://localhost:8080/xapi` |
| `LRS_API_KEY` | LRS authentication key | `my_api_key` |
| `LRS_API_SECRET` | LRS authentication secret | `my_api_secret` |
| `APP_TIMEZONE` | Timezone for date display | `America/New_York` |

### LMS Setup

1. Navigate to Tsugi's LTI tool configuration
2. Register the xAPI Viewer as an LTI tool in your LMS
3. Ensure the LMS sends `lis_person_contact_email_primary` (email is required)

## Instructor Guide

### Configuring Activities

1. Launch the tool as an instructor
2. Click "Configure Activities" button
3. Add activities that students should complete:
   - **Title**: Display name for the activity (e.g., "Lab 1: Getting Started")
   - **xAPI Activity ID**: Optional - for exact matching to xAPI activity IDs
   - **Points Possible**: Maximum points for this activity (default: 100)
4. Reorder activities using the up/down arrows
5. Edit or delete activities as needed

### Activity Matching

Activities are matched to xAPI statements in this order:

1. **By xAPI Activity ID**: If specified, matches statements where the activity ID contains this value
2. **By Title**: Falls back to fuzzy matching against xAPI activity names

### What Students See

- List of all configured activities with their current status
- Progress statistics (total, completed, passed, average score)
- Expandable task lists for activities with child tasks
- Points earned vs. points possible

## File Structure

```
tsugi-xapi-viewer/
├── index.php        # Main student/instructor view
├── settings.php     # Instructor activity configuration
├── register.php     # Tsugi tool registration
├── database.php     # Database schema for activity config
├── README.md        # This file
├── css/
│   └── styles.css   # Application styles
└── lib/
    └── xapi.php     # xAPI helper functions
```

## Database Tables

The module creates one table:

- `{prefix}xapi_activities`: Stores configured activities per course context
  - `activity_id`: Primary key
  - `context_id`: Foreign key to Tsugi course context
  - `title`: Display title
  - `xapi_activity_id`: Optional xAPI activity ID for matching
  - `points_possible`: Maximum points
  - `display_order`: Sort order

## Requirements

- PHP 7.4 or higher
- Tsugi framework
- Access to an xAPI-compliant LRS
- LMS with LTI 1.1 or 1.3 support

## How It Works

1. **Instructor Setup**: Instructor configures which activities to grade
2. **Student Launch**: Student launches the tool from their LMS
3. **Authentication**: Tsugi handles LTI authentication
4. **Data Fetch**: Tool fetches xAPI statements from LRS filtered by student email
5. **Matching**: Configured activities are matched to xAPI data
6. **Display**: Student sees their progress on each configured activity

## License

Apache 2.0
