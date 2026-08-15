#!/usr/bin/env bash
#
# Create the local development and test databases.
#
#   ./scripts/dev-databases.sh
#
# Creates, on BOTH MySQL and PostgreSQL:
#
#   vmail        an iRedMail account database, for development
#   vmail_test   the same, for the test suite
#
# and, on both:
#
#   mailward      Mailward's own database, for development
#   mailward_test the same, for the test suite
#
# The vmail schema is downloaded from iRedMail at a pinned tag and loaded as
# it ships. It is deliberately NOT vendored into this repository: it is GPL,
# and Mailward is MIT (docs/decisions/0006-mit-license.md). Reading the schema
# to learn the data model is fine; redistributing it here is not.
#
# This gives the full test matrix locally — two SQL drivers, real DDL, real
# triggers, real type divergences (docs/reference/schema-type-matrix.md) —
# without needing the disposable iRedMail VM. The VM is still required for
# runtime behaviour: password hashing and Dovecot's own delivery.
#
# Local credentials come from playbooks/local-environment.md: PostgreSQL as
# the postgres superuser without a password, MySQL as root without a password.
# Both are local-only and never leave this machine.

set -euo pipefail

IREDMAIL_TAG="${IREDMAIL_TAG:-1.8.4}"
BASE="https://raw.githubusercontent.com/iredmail/iRedMail/${IREDMAIL_TAG}/samples/iredmail"
WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

echo "==> iRedMail schema, tag ${IREDMAIL_TAG}"
curl -sSfL -o "$WORKDIR/iredmail.mysql" "$BASE/iredmail.mysql"
curl -sSfL -o "$WORKDIR/iredmail.pgsql" "$BASE/iredmail.pgsql"

load_mysql() {
    local db="$1"
    echo "==> mysql: $db"
    mysql -u root -e "DROP DATABASE IF EXISTS \`$db\`;
                      CREATE DATABASE \`$db\`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    mysql -u root "$db" < "$WORKDIR/iredmail.mysql"
}

load_pgsql() {
    local db="$1"
    echo "==> postgres: $db"
    dropdb -U postgres --if-exists "$db"
    createdb -U postgres "$db"
    psql -U postgres -q -d "$db" -f "$WORKDIR/iredmail.pgsql"
}

# Mailward's own databases are created only if absent. Unlike the vmail ones,
# they hold work — migrations, audit log, settings — and a setup script must
# never be the thing that destroys it. Use `php artisan migrate:fresh` when a
# rebuild is actually what you want.
create_if_absent() {
    local db="$1"
    if psql -U postgres -Atlq | cut -d'|' -f1 | grep -qx "$db"; then
        echo "==> mailward database: $db (postgres, exists — left alone)"
    else
        echo "==> mailward database: $db (postgres, creating)"
        createdb -U postgres "$db"
    fi

    mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$db\`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
}

# The vmail databases are disposable by design: they hold no work of ours,
# only iRedMail's DDL, so they are rebuilt every run.
for db in vmail vmail_test; do
    load_mysql "$db"
    load_pgsql "$db"
done

for db in mailward mailward_test; do
    create_if_absent "$db"
done

# Mailward owns these two, so they are migrated rather than loaded from a
# schema file. The test database is migrated too — forgetting it turns every
# feature test into an undefined-table error that looks like a code fault.
if [ -f artisan ]; then
    echo "==> migrating mailward"
    php artisan migrate --force
    echo "==> migrating mailward_test"
    DB_DATABASE=mailward_test php artisan migrate --force
fi

echo
echo "==> verifying"
printf 'mysql    vmail tables: %s\n' \
    "$(mysql -N -B -u root -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='vmail';")"
printf 'postgres vmail tables: %s\n' \
    "$(psql -U postgres -At -d vmail -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public';")"

# The used_quota trigger exists on MySQL and on nothing else. That asymmetry
# is D14, and it is the reason per-domain quota is correlated through
# used_quota.username rather than grouped on used_quota.domain.
printf 'mysql    triggers: %s (expected 1)\n' \
    "$(mysql -N -B -u root -e "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='vmail';")"
printf 'postgres triggers: %s (expected 0)\n' \
    "$(psql -U postgres -At -d vmail -c "SELECT COUNT(*) FROM information_schema.triggers;")"

echo
echo "Done. Run the suite against either driver:"
echo "  VMAIL_DB_DRIVER=mysql ./vendor/bin/pest"
echo "  VMAIL_DB_DRIVER=pgsql VMAIL_DB_PORT=5432 ./vendor/bin/pest"
