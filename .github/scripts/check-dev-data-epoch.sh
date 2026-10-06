#!/usr/bin/env bash
# Fails a merge request that edits or deletes an existing migration, or touches a
# seeder, without bumping the dev data epoch (api/database/DEV_DATA_EPOCH, line 1).
#
# Those are the changes that leave an existing dev database wrong in a way a plain
# `migrate` does not fix; `make up` rebuilds each machine's database when the epoch
# moves, so the bump is what reaches everyone (CONTRIBUTING.md). A new migration file
# is additive and needs nothing. A change that is not breaking despite touching one of
# these files (a comment, a typo) can say so with a line in the merge request
# description: `Dev-data-epoch: not needed`.
#
# This catches the mechanical cases only. A semantic one, such as a new rule about
# what the sync log must contain, still depends on the author remembering; the rule in
# AGENTS.md and CONTRIBUTING.md is for that.
#
# Usage: check-dev-data-epoch.sh [base] [head]   (defaults: HEAD^1 and HEAD, i.e. the
# merge commit GitHub checks out for a pull_request run). PR_BODY is the description.
set -euo pipefail

base="${1:-HEAD^1}"
head="${2:-HEAD}"
epoch=api/database/DEV_DATA_EPOCH

# Existing migrations modified or deleted (a rename shows as a delete plus an add), and
# any change to a seeder. A newly added migration is not listed.
flagged=$(git diff --name-status --no-renames "$base" "$head" -- api/database/migrations api/database/seeders \
  | awk -F'\t' '($2 ~ /^api\/database\/seeders\//) || ($1 != "A") { print }')

if [ -z "$flagged" ]; then
  echo "No existing migration or seeder changed; no epoch bump needed."
  exit 0
fi

if printf '%s\n' "${PR_BODY:-}" | grep -qi '^dev-data-epoch: not needed'; then
  echo "Changed files below, but the merge request says 'Dev-data-epoch: not needed':"
  echo "$flagged"
  exit 0
fi

# Line 1 of the epoch file at a revision, or 0 if it does not exist there yet.
epoch_at() {
  local value
  value=$(git show "$1:$epoch" 2>/dev/null | sed -n 1p | tr -d '[:space:]' || true)
  if [[ "$value" =~ ^[0-9]+$ ]]; then echo "$value"; else echo 0; fi
}

before=$(epoch_at "$base")
after=$(epoch_at "$head")

if [ "$after" -gt "$before" ]; then
  echo "Epoch bumped ($before -> $after) alongside:"
  echo "$flagged"
  exit 0
fi

echo "This merge request changes files that can leave existing dev databases wrong:"
echo "$flagged"
echo
echo "but $epoch line 1 did not increase (it is $after; it was $before)."
echo "Bump the number on line 1 and give the reason on line 2, so 'make up' rebuilds"
echo "everyone's dev database. If this change is not breaking, add this line to the"
echo "merge request description instead:  Dev-data-epoch: not needed"
exit 1
