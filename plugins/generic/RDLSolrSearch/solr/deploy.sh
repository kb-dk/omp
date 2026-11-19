#!/usr/bin/env bash
#
# plugins/generic/RDLSolrSearch/solr/deploy.sh
#
# Copyright (c) 2026 Royal Danish Library
# Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
#
# Copies the Solr configuration in solr/conf/ (the source of truth, kept in
# git) to the standalone Solr server and reloads or creates the core.
#
#   - Existing core: backs up the running conf, installs the new one and
#     reloads. If the reload fails, the backup is restored.
#   - Missing core:  creates it.
#   - --reset-index: deletes all indexed documents (needed when a schema
#     change is incompatible with existing data). Rebuild afterwards with
#     php tools/rebuildSearchIndex.php
#
# Solr must be running. Nothing is written inside the OMP directory.

set -euo pipefail

SOLR_HOME=/home/omp/solr-data
CORE=omp
SOLR_URL=http://127.0.0.1:8983/solr
RESET_INDEX=0
KEEP_BACKUPS=5

usage() {
	cat <<EOF
Usage: $(basename "$0") [options]

  --solr-home DIR   Solr home directory        (default: $SOLR_HOME)
  --core NAME       Core name                  (default: $CORE)
  --url URL         Solr base URL              (default: $SOLR_URL)
  --reset-index     Delete all documents in the core after deploying
  -h, --help        Show this help
EOF
}

while [ $# -gt 0 ]; do
	case "$1" in
		--solr-home) SOLR_HOME=${2:?}; shift 2 ;;
		--core) CORE=${2:?}; shift 2 ;;
		--url) SOLR_URL=${2%/}; shift 2 ;;
		--reset-index) RESET_INDEX=1; shift ;;
		-h|--help) usage; exit 0 ;;
		*) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
	esac
done

if ! [[ "$CORE" =~ ^[A-Za-z0-9_-]+$ ]]; then
	echo "Invalid core name: $CORE" >&2
	exit 2
fi

SRC_CONF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/conf"
INSTANCE_DIR="$SOLR_HOME/$CORE"
DEST_CONF="$INSTANCE_DIR/conf"

log() { echo "[deploy] $*"; }
fail() { echo "[deploy] ERROR: $*" >&2; exit 1; }

# Call the CoreAdmin API; prints the JSON response, fails on HTTP errors.
core_admin() {
	curl -sS --fail-with-body --max-time 120 "$SOLR_URL/admin/cores?wt=json&$1"
}

# Prints the Solr status code (0 = OK) from a JSON response on stdin.
json_status() {
	python3 -I -c 'import json,sys; print(json.load(sys.stdin)["responseHeader"]["status"])'
}

# Right after a Solr (re)start a core is listed before it has finished
# loading; wait until it answers a ping (max. 60 seconds).
wait_for_core() {
	for _ in $(seq 1 60); do
		curl -sS --fail --max-time 5 -o /dev/null "$SOLR_URL/$CORE/admin/ping?wt=json" 2>/dev/null && return 0
		sleep 1
	done
	return 1
}

core_exists() {
	core_admin "action=STATUS&core=$CORE" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
sys.exit(0 if d["status"].get(sys.argv[1], {}).get("name") else 1)' "$CORE"
}

# 1. Sanity checks
[ -f "$SRC_CONF/solrconfig.xml" ] && [ -f "$SRC_CONF/schema.xml" ] || fail "No solrconfig.xml/schema.xml in $SRC_CONF"
[ -d "$SOLR_HOME" ] || fail "Solr home $SOLR_HOME does not exist"
if command -v xmllint >/dev/null; then
	xmllint --noout "$SRC_CONF/solrconfig.xml" "$SRC_CONF/schema.xml" || fail "Invalid XML in $SRC_CONF"
fi
curl -sS --fail --max-time 10 -o /dev/null "$SOLR_URL/admin/info/system?wt=json" \
	|| fail "Solr is not reachable at $SOLR_URL (start it with /home/omp/solr/bin/solr start)"

# 2. Deploy
if core_exists; then
	wait_for_core || fail "Core '$CORE' does not answer; check $SOLR_HOME/logs/solr.log"
	BACKUP="$INSTANCE_DIR/conf.bak-$(date +%Y%m%d-%H%M%S)"
	log "Backing up running config to $BACKUP"
	cp -a "$DEST_CONF" "$BACKUP"

	log "Installing config from $SRC_CONF"
	rm -rf "$DEST_CONF"
	cp -a "$SRC_CONF" "$DEST_CONF"

	log "Reloading core '$CORE'"
	if ! RESPONSE=$(core_admin "action=RELOAD&core=$CORE") || [ "$(echo "$RESPONSE" | json_status)" != 0 ]; then
		echo "$RESPONSE" >&2
		log "Reload failed; restoring previous config"
		rm -rf "$DEST_CONF"
		cp -a "$BACKUP" "$DEST_CONF"
		core_admin "action=RELOAD&core=$CORE" >/dev/null || log "WARNING: reloading the restored config failed too; check $SOLR_HOME/logs/solr.log"
		fail "New config was rejected by Solr; see $SOLR_HOME/logs/solr.log"
	fi

	# Keep only the newest backups.
	ls -1dt "$INSTANCE_DIR"/conf.bak-* 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -rf
else
	[ -e "$INSTANCE_DIR/core.properties" ] && fail "$INSTANCE_DIR/core.properties exists but Solr has no core '$CORE' loaded; check $SOLR_HOME/logs/solr.log"
	log "Creating core '$CORE' in $INSTANCE_DIR"
	mkdir -p "$INSTANCE_DIR"
	rm -rf "$DEST_CONF"
	cp -a "$SRC_CONF" "$DEST_CONF"
	RESPONSE=$(core_admin "action=CREATE&name=$CORE&instanceDir=$CORE&config=solrconfig.xml&schema=schema.xml") \
		|| { echo "$RESPONSE" >&2; fail "Could not create core '$CORE'"; }
fi

# 3. Optionally empty the index
if [ "$RESET_INDEX" = 1 ]; then
	log "Deleting all documents in '$CORE'"
	curl -sS --fail-with-body --max-time 120 -H 'Content-Type: application/json' \
		-d '{"delete":{"query":"*:*"}}' "$SOLR_URL/$CORE/update?commit=true&wt=json" >/dev/null
	# Reload again: the open index writer still remembers how existing fields
	# were indexed (e.g. without offsets) and would reject the new settings.
	core_admin "action=RELOAD&core=$CORE" >/dev/null || fail "Could not reload core '$CORE' after emptying it"
	wait_for_core
	log "Index is empty; rebuild it with: php tools/rebuildSearchIndex.php"
fi

NUM_DOCS=$(curl -sS --fail --max-time 30 "$SOLR_URL/$CORE/select?q=*:*&rows=0&wt=json" \
	| python3 -I -c 'import json,sys; print(json.load(sys.stdin)["response"]["numFound"])')
log "Done. Core '$CORE' is running with the new config ($NUM_DOCS documents)."
