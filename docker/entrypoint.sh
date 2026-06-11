#!/bin/sh
set -e

DB_PORT="${DB_PORT:-3306}"

# Wait for MySQL to accept a connection *as our app user against our database*.
# This is stricter than a bare port/ping check: it only succeeds once MySQL has
# finished creating MYSQL_DATABASE/MYSQL_USER, closing the classic init race.
echo "Waiting for database at ${DB_HOST}:${DB_PORT}..."
until php -r '
    $dsn = "mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE");
    try { new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); }
    catch (Throwable $e) { exit(1); }
' 2>/dev/null; do
    sleep 2
done
echo "Database is ready."

# Migrations are idempotent (only pending ones run).
php artisan migrate --force

# Seed only when the catalog is empty, so a second `up` neither duplicates rows
# nor fails on a unique key (the seeder is not itself idempotent).
COUNT=$(php -r '
    $dsn = "mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE");
    $pdo = new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
    echo (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
')
if [ "$COUNT" = "0" ]; then
    echo "Seeding database..."
    php artisan db:seed --force
else
    echo "Products already present (${COUNT}); skipping seed."
fi

exec php artisan serve --host=0.0.0.0 --port=8000
