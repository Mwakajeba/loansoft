# Supervisor — SmartFinance

Runs **queued jobs** (bulk upload, backup, bulk loans, etc.) and **scheduled jobs** (mature interest, SMS reminders, subscription checks).

Each site needs **two** config files in `/etc/supervisor/conf.d/` (queue worker + scheduler). Program names must be unique per site.

| Site path | Config files |
|-----------|----------------|
| `/var/www/html/green` | `green-queue-worker.conf`, `green-scheduler.conf` |
| `/var/www/html/loans/jomwe` | `jomwe-queue-worker.conf`, `jomwe-scheduler.conf` |

Copy this folder’s `deploy/supervisor/*.conf` into the project on the server, or create new pairs by copying `green-*` and replacing the path + `[program:...]` name.

## Prerequisites

```bash
cd /var/www/html/green

# Queue must use database (not sync)
grep QUEUE_CONNECTION .env
# QUEUE_CONNECTION=database

php artisan migrate --force   # ensures `jobs` table exists
sudo apt install supervisor -y
```

## Install

```bash
cd /var/www/html/green

sudo cp deploy/supervisor/green-queue-worker.conf /etc/supervisor/conf.d/
sudo cp deploy/supervisor/green-scheduler.conf /etc/supervisor/conf.d/

# Permissions for www-data
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start green-queue-worker:*
sudo supervisorctl start green-scheduler
```

## Verify

```bash
sudo supervisorctl status
# green-queue-worker:green-queue-worker_00   RUNNING
# green-scheduler                          RUNNING

tail -f /var/www/html/green/storage/logs/queue-worker.log
tail -f /var/www/html/green/storage/logs/scheduler.log
```

## After code deploy

```bash
sudo supervisorctl restart green-queue-worker:*
sudo supervisorctl restart green-scheduler
php artisan config:cache
```

## Stop old manual workers (if any)

```bash
pkill -f "artisan queue:work"
rm -f /var/www/html/green/storage/logs/queue-worker.pid
```

## More throughput (optional)

Edit `/etc/supervisor/conf.d/green-queue-worker.conf` and set `numprocs=2`, then:

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

## Example: `/var/www/html/loans/jomwe`

```bash
cd /var/www/html/loans/jomwe

grep QUEUE_CONNECTION .env   # must be: database
php artisan migrate --force

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache

sudo cp deploy/supervisor/jomwe-queue-worker.conf /etc/supervisor/conf.d/
sudo cp deploy/supervisor/jomwe-scheduler.conf /etc/supervisor/conf.d/

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start jomwe-queue-worker:*
sudo supervisorctl start jomwe-scheduler

sudo supervisorctl status
tail -f storage/logs/queue-worker.log
```

After deploy: `sudo supervisorctl restart jomwe-queue-worker:* jomwe-scheduler`

## Troubleshooting

| Issue | Fix |
|-------|-----|
| FATAL / can't find php | `which php` and update `command=` path in conf |
| Permission denied on storage | `chown -R www-data:www-data storage` |
| Jobs not processing | `php artisan queue:failed` / check `jobs` table |
| Scheduler not firing | Confirm `{site}-scheduler` is RUNNING |
