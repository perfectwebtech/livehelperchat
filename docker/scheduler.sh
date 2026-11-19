#!/bin/sh
# Runs Live Helper Chat's background jobs without system cron, for a dedicated
# "scheduler" container (see docker-compose.yml). One process, no daemon.
#
#   every minute: workflow, department availability, login monitoring, transfers
#   every hour:   message anonymisation (cron/encrypt), chat archiving and the
#                 data retention policy (cron/data_retention)
#
# Each job runs as www-data so files it creates stay writable for Apache.
# Extra jobs: LHC_SCHEDULER_MINUTE_JOBS / LHC_SCHEDULER_HOURLY_JOBS, space separated.
set -u
cd "${LHC_APP_DIR:-/var/www/html}"

MINUTE_JOBS="cron/workflow cron/departament-availability cron/login_monitoring cron/transfer_workflow ${LHC_SCHEDULER_MINUTE_JOBS:-}"
HOURLY_JOBS="cron/encrypt cron/archive cron/data_retention ${LHC_SCHEDULER_HOURLY_JOBS:-}"

run() {
    start=$(date +%s)
    if su -p -s /bin/sh www-data -c "php cron.php -s site_admin -c $1" > /tmp/lhc-job.log 2>&1; then
        echo "[lhc-scheduler] $(date -u +%FT%TZ) ok   $1 ($(( $(date +%s) - start ))s)"
    else
        echo "[lhc-scheduler] $(date -u +%FT%TZ) FAIL $1"; tail -n 20 /tmp/lhc-job.log
    fi
}

echo "[lhc-scheduler] started"
last_hour=""
while true; do
    tick=$(date +%s)
    for job in $MINUTE_JOBS; do run "$job"; done
    hour=$(date -u +%Y%m%d%H)
    if [ "$hour" != "$last_hour" ]; then
        for job in $HOURLY_JOBS; do run "$job"; done
        last_hour="$hour"
    fi
    elapsed=$(( $(date +%s) - tick ))
    [ "$elapsed" -lt 60 ] && sleep $(( 60 - elapsed ))
done
