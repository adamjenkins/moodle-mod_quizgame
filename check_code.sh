#!/bin/bash
# Script to check code style using Moodle coding standards

# Path to phpcs
PHPCS="/tmp/moodle_test/ci/vendor/bin/phpcs"

# Default memory limit
MEMORY="2G"

# Help function
function show_help {
    echo "Usage: $0 [options] <files...>"
    echo "Options:"
    echo "  -h, --help     Show this help message"
    echo "  -m MEM         Set memory limit (default: 2G)"
    echo "  -f, --fix      Try to automatically fix code style issues"
    echo "Examples:"
    echo "  $0 lib.php"
    echo "  $0 -m 4G lib.php locallib.php"
    echo "  $0 --fix lib.php"
}

# Parse arguments
FIX=false
while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            exit 0
            ;;
        -m)
            MEMORY="$2"
            shift
            shift
            ;;
        -f|--fix)
            FIX=true
            shift
            ;;
        *)
            break
            ;;
    esac
done

if [ $# -eq 0 ]; then
    echo "Error: No files specified"
    show_help
    exit 1
fi

if [ "$FIX" = true ]; then
    echo "Attempting to fix code style issues..."
    "$PHPCS" -d memory_limit="$MEMORY" --standard=moodle --fix "$@"
else
    "$PHPCS" -d memory_limit="$MEMORY" --standard=moodle "$@"
fi
