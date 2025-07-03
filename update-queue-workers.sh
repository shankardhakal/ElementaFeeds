#!/bin/bash
# ===============================================================
# Generate Supervisor Config from .env for ElementaFeeds
# ===============================================================
# This script generates supervisor configuration for queue workers
# based on settings in the .env file.
# ===============================================================

# Configuration
SITE_PATH="/home/feedadmin/htdocs/feedadmin.elementa.fi"
ENV_FILE="${SITE_PATH}/.env"
SUPERVISOR_CONFIG="/etc/supervisor/conf.d/laravel-worker.conf"

# Function to read values from .env file
read_env() {
    local key=$1
    local default=$2
    local value=$(grep "^${key}=" "$ENV_FILE" | cut -d '=' -f2)
    
    if [ -z "$value" ]; then
        echo "$default"
    else
        echo "$value"
    fi
}

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   echo "This script must be run as root" 
   exit 1
fi

# Check if .env file exists
if [ ! -f "$ENV_FILE" ]; then
    echo "Error: .env file not found at $ENV_FILE"
    exit 1
fi

echo "Generating Supervisor configuration from .env file..."

# Read values from .env file
WORKER_COUNT=$(read_env "QUEUE_WORKER_COUNT" "4")
WORKER_MEMORY=$(read_env "QUEUE_WORKER_MEMORY" "128")
WORKER_MAX_TIME=$(read_env "QUEUE_WORKER_MAX_TIME" "3600")
WORKER_SLEEP=$(read_env "QUEUE_WORKER_SLEEP" "3")
WORKER_TRIES=$(read_env "QUEUE_WORKER_TRIES" "3")
WORKER_TIMEOUT=$(read_env "QUEUE_WORKER_TIMEOUT" "60")
WORKER_MAX_JOBS=$(read_env "QUEUE_WORKER_MAX_JOBS" "500")

echo "Using settings:"
echo "- Worker count: $WORKER_COUNT"
echo "- Memory limit: $WORKER_MEMORY MB"
echo "- Max time: $WORKER_MAX_TIME seconds"
echo "- Sleep time: $WORKER_SLEEP seconds"
echo "- Retry count: $WORKER_TRIES"
echo "- Timeout: $WORKER_TIMEOUT seconds"
echo "- Max jobs: $WORKER_MAX_JOBS"

# Backup existing config if it exists
if [ -f "$SUPERVISOR_CONFIG" ]; then
    cp "$SUPERVISOR_CONFIG" "${SUPERVISOR_CONFIG}.backup.$(date +%Y%m%d%H%M%S)"
    echo "Backed up existing configuration"
fi

# Generate new configuration
cat > "$SUPERVISOR_CONFIG" << EOF
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${SITE_PATH}/artisan queue:work --sleep=${WORKER_SLEEP} --tries=${WORKER_TRIES} --max-time=${WORKER_MAX_TIME} --memory=${WORKER_MEMORY} --timeout=${WORKER_TIMEOUT} --max-jobs=${WORKER_MAX_JOBS}
autostart=true
autorestart=true
user=feedadmin
numprocs=${WORKER_COUNT}
redirect_stderr=true
stdout_logfile=${SITE_PATH}/storage/logs/worker.log
EOF

echo "Configuration generated at $SUPERVISOR_CONFIG"

# Reload Supervisor
echo "Reloading Supervisor..."
supervisorctl reread
supervisorctl update

# Restart workers
echo "Restarting queue workers..."
supervisorctl restart laravel-worker:*

echo "Worker configuration applied successfully!"
echo "Current worker status:"
supervisorctl status laravel-worker:*

# Check current memory usage
echo ""
echo "Current memory usage:"
free -m | grep Mem

# Check current worker processes
echo ""
echo "Current worker processes:"
ps aux | grep "[q]ueue:work" | awk '{print "PID: " $2 ", Memory: " $6 "KB, CPU: " $3 "%"}'

echo ""
echo "To modify worker settings, edit the following values in .env file:"
echo "QUEUE_WORKER_COUNT=$WORKER_COUNT"
echo "QUEUE_WORKER_MEMORY=$WORKER_MEMORY"
echo "QUEUE_WORKER_MAX_TIME=$WORKER_MAX_TIME"
echo "QUEUE_WORKER_SLEEP=$WORKER_SLEEP"
echo "QUEUE_WORKER_TRIES=$WORKER_TRIES"
echo "QUEUE_WORKER_TIMEOUT=$WORKER_TIMEOUT"
echo "QUEUE_WORKER_MAX_JOBS=$WORKER_MAX_JOBS"
echo ""
echo "Then run this script again to apply changes."
