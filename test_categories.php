<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Test script for verifying question category queries.
 *
 * @package    mod_quizgame
 * @copyright  2025 John Okely <john@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

require_login();

// Simulate course context.
$courseid = 2; // Assuming course ID 2 is the Wingfoiling course.
$context = context_course::instance($courseid);

echo "Testing question category query for course ID: $courseid\n";
echo "Context ID: " . $context->id . "\n\n";

// Test our query to fetch categories.
$categories = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.name, c.parent, c.sortorder, c.contextid
       FROM {question_categories} c
       JOIN {context} ctx ON c.contextid = ctx.id
      WHERE ctx.contextlevel IN (" . CONTEXT_SYSTEM . ", " . CONTEXT_COURSECAT . ", " . CONTEXT_COURSE . ")
        AND (ctx.contextlevel = " . CONTEXT_SYSTEM .
             " OR ctx.path LIKE :coursepath" .
             " OR ctx.path LIKE :categorypath)
   ORDER BY c.parent, c.sortorder, c.name ASC",
    [
        'coursepath' => $context->path . '/%',
        'categorypath' => '%/' . $courseid . '/%',
    ]
);

echo "Found " . count($categories) . " categories:\n";
foreach ($categories as $category) {
    echo "- ID: {$category->id}, Name: {$category->name}, Parent: {$category->parent}, Context: {$category->contextid}\n";
}

echo "\nTesting hierarchical display:\n";
$categorytree = [];
foreach ($categories as $category) {
    $categorytree[$category->parent][] = $category;
}

$options = ['' => 'Choose...'];
build_category_options($categorytree, $options, 0, $context);

foreach ($options as $id => $name) {
    if ($id !== '') {
        echo "- $name\n";
    }
}

/**
 * Builds a hierarchical list of category options for display.
 *
 * @param array $categorytree The tree of categories indexed by parent ID.
 * @param array $options Reference to options array to populate.
 * @param int $parentid The parent category ID to start from.
 * @param context $context The current context.
 * @param int $level The current indentation level.
 */
function build_category_options($categorytree, &$options, $parentid, $context, $level = 0) {
    if (!isset($categorytree[$parentid])) {
        return;
    }

    foreach ($categorytree[$parentid] as $category) {
        $indent = str_repeat('    ', $level);
        $name = format_string($category->name, true, ['context' => $context]);
        $options[$category->id] = $indent . $name;

        // Recursively add child categories.
        build_category_options($categorytree, $options, $category->id, $context, $level + 1);
    }
}
