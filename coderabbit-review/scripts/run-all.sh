#!/usr/bin/env bash
# Runs every audit block in sequence. This can take a long time (144 CodeRabbit
# reviews total) -- prefer running the numbered scripts one at a time instead,
# picking your pace. Kept here for convenience if you want to leave it running.
set -uo pipefail
cd "$(dirname "$0")"

for script in 00-orphan-files.sh 01-app-actions.sh 02-app-support.sh 03-app-core-1.sh \
  04-app-core-2.sh 05-database.sh 06-docs.sh 07-resources.sh 08-root-misc-1.sh \
  09-root-misc-2.sh 10-tests-feature-a.sh 11-tests-feature-b.sh 12-tests-unit.sh \
  13-tests-misc.sh; do
  echo "########## $script ##########"
  bash "$script"
done
