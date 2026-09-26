#!/usr/bin/env bash
set -euo pipefail

project_root="${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel)}"
exec "$project_root/bin/doctrine-doctor-context" --format=markdown
