#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_SLUG="kreativ-ai-content-optimizer"
REMOTE_HOST="${DEPLOY_HOST:-}"
REMOTE_USER="${DEPLOY_USER:-}"
REMOTE_PORT="${DEPLOY_PORT:-22}"
REMOTE_PLUGIN_DIR="${DEPLOY_PLUGIN_DIR:-}"
SSH_KEY_PATH="${DEPLOY_SSH_KEY_PATH:-}"

if [[ -z "$REMOTE_HOST" || -z "$REMOTE_USER" || -z "$REMOTE_PLUGIN_DIR" ]]; then
  echo "Missing deploy settings. Required: DEPLOY_HOST, DEPLOY_USER, DEPLOY_PLUGIN_DIR" >&2
  exit 1
fi

SSH_OPTS=("-p" "$REMOTE_PORT" "-o" "StrictHostKeyChecking=accept-new")
if [[ -n "$SSH_KEY_PATH" ]]; then
  SSH_OPTS+=("-i" "$SSH_KEY_PATH")
fi

REMOTE_BASE="$(dirname "$REMOTE_PLUGIN_DIR")"

RSYNC_RSH="ssh ${SSH_OPTS[*]}"
export RSYNC_RSH

ssh "${SSH_OPTS[@]}" "$REMOTE_USER@$REMOTE_HOST" "mkdir -p '$REMOTE_BASE' '$REMOTE_PLUGIN_DIR'"

rsync -az --delete \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.DS_Store' \
  --exclude='*.zip' \
  "$ROOT_DIR/" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PLUGIN_DIR/"

echo "Deployed $PLUGIN_SLUG to $REMOTE_USER@$REMOTE_HOST:$REMOTE_PLUGIN_DIR"
