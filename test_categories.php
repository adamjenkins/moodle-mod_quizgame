<?php
// Simple test script to verify question category query
require_once('config.php');

// Simulate course context
$courseid = 2; // Assuming course ID 2 is the Wingfoiling course
$context = context_course::instance($courseid);

echo "Testing question category query for course ID: $courseid\n";
echo "Context ID: " . $context->id . "\n\n";

// Test our query
$categories = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.name, c.parent, c.sortorder, c.contextid
       FROM {question_categories} c
       JOIN {context} ctx ON c.contextid = ctx.id
      WHERE ctx.contextlevel IN (" . CONTEXT_SYSTEM . ", " . CONTEXT_COURSECAT . ", " . CONTEXT_COURSE . ")
        AND (ctx.contextlevel = " . CONTEXT_SYSTEM . " 
             OR ctx.path LIKE :coursepath 
             OR ctx.path LIKE :categorypath)
   ORDER BY c.parent, c.sortorder, c.name ASC",
    [
        'coursepath' => $context->path . '/%',
        'categorypath' => '%/' . $courseid . '/%'
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

function build_category_options($categorytree, &$options, $parentid, $context, $level = 0) {
    if (!isset($categorytree[$parentid])) {
        return;
    }
    
    foreach ($categorytree[$parentid] as $category) {
        $indent = str_repeat('    ', $level);
        $name = format_string($category->name, true, ['context' => $context]);
        $options[$category->id] = $indent . $name;
        
        // Recursively add child categories
        build_category_options($categorytree, $options, $category->id, $context, $level + 1);
    }
}
?> 