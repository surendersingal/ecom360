#!/bin/bash
# Auto-sync ecom360 to AWS server (ecom.buildnetic.com)
# Usage: ./sync-to-server.sh        (one-time sync)
#        ./sync-to-server.sh watch   (auto-sync on file changes)

PROJECT_DIR="/Users/surenderaggarwal/Projects/ecom360"
REMOTE="ddfapp-aws:/var/www/ecom360/"

RSYNC_OPTS=(
  -avz --delete
  --exclude='.env'
  --exclude='vendor/'
  --exclude='node_modules/'
  --exclude='storage/logs/*'
  --exclude='storage/framework/cache/*'
  --exclude='storage/framework/sessions/*'
  --exclude='storage/framework/views/*'
  --exclude='.git/'
  --exclude='public/build/'
  --exclude='public/hot'
  --exclude='sync-to-server.sh'
  --exclude='bootstrap/cache/'
)

do_sync() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] Syncing to server..."
  rsync "${RSYNC_OPTS[@]}" "$PROJECT_DIR/" "$REMOTE"
  
  # Re-cache on server after sync (skip composer install for speed on watch mode)
  ssh ddfapp-aws "cd /var/www/ecom360 && php artisan view:clear && php artisan view:cache" 2>/dev/null
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] Sync complete."
}

# One-time sync
do_sync

if [ "$1" = "watch" ]; then
  echo ""
  echo "Watching for changes in $PROJECT_DIR ..."
  echo "Press Ctrl+C to stop."
  echo ""
  
  fswatch -o \
    --exclude='\.git' \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='storage/logs' \
    --exclude='storage/framework' \
    --exclude='\.env' \
    "$PROJECT_DIR" | while read -r _; do
      # Debounce: wait 2 seconds for rapid changes to settle
      sleep 2
      # Drain any queued events
      while read -r -t 1 _; do :; done
      do_sync
    done
fi
