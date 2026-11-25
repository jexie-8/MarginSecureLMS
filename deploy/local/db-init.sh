#!/bin/sh
# Runs once, the first time the database volume is created.
#
# Three database accounts, so a flaw in the web app cannot reach everything:
#   margin_owner   owns the tables; used only to run migrations
#   margin_runtime what the running app uses; NOT a superuser and NOT the table
#                  owner, so Row-Level Security is enforced against it
#   postgres       superuser; used only to seed demonstration data
set -eu

psql -v ON_ERROR_STOP=1 --username postgres --dbname postgres <<SQL
CREATE ROLE margin_app NOLOGIN NOSUPERUSER NOBYPASSRLS;
CREATE ROLE margin_owner LOGIN PASSWORD '${MARGIN_OWNER_PASSWORD}' NOSUPERUSER;
CREATE ROLE margin_runtime LOGIN PASSWORD '${MARGIN_RUNTIME_PASSWORD}' NOSUPERUSER NOBYPASSRLS IN ROLE margin_app;
CREATE DATABASE margin_db OWNER margin_owner;
REVOKE ALL ON DATABASE margin_db FROM PUBLIC;
GRANT CONNECT ON DATABASE margin_db TO margin_runtime;
SQL

psql -v ON_ERROR_STOP=1 --username postgres --dbname margin_db <<SQL
GRANT USAGE ON SCHEMA public TO margin_app;
ALTER DEFAULT PRIVILEGES FOR ROLE margin_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO margin_app;
ALTER DEFAULT PRIVILEGES FOR ROLE margin_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO margin_app;
SQL
