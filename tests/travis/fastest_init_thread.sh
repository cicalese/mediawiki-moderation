#!/bin/bash -xe
###############################################################################
# Clone MediaWiki database: one database per each parallel thread of "phpunit".
# (for parallel testing via Fastest)
###############################################################################
# The following environment variables are provided by Travis:
# $DBNAME - typically "testwiki"
# $DBUSER - typically "root"
# $DBTYPE - either "mysql" or "postgres"
###############################################################################

ORIGINAL_DB_NAME="${DBNAME}"

# Suffix of cloned DB must be same as in ModerationSettings.php
CLONED_DB_NAME="${ORIGINAL_DB_NAME}_thread${ENV_TEST_CHANNEL}"

# Clone the database (including the initial data, if any).
if [ "$DBTYPE" = "mysql" ]; then
	mysql -h 127.0.0.1 -u "${DBUSER}" -e "CREATE DATABASE ${CLONED_DB_NAME}"
	mysqldump -h 127.0.0.1 -u "${DBUSER}" "${ORIGINAL_DB_NAME}" | mysql -h 127.0.0.1 -u "${DBUSER}" -D "${CLONED_DB_NAME}"
else if [ "$DBTYPE" = "postgres" ]; then
	echo "CREATE DATABASE ${CLONED_DB_NAME} TEMPLATE ${ORIGINAL_DB_NAME};" | psql -U postgres "${ORIGINAL_DB_NAME}"
fi; fi
