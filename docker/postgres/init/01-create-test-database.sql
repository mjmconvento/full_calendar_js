-- Runs once, when the postgres volume is first initialised.
--
-- The test suite normally runs on SQLite (.env.test). Pointed at this service
-- instead, DoctrineBundle appends `_test` to the database name (dbname_suffix in
-- config/packages/doctrine.yaml), so that database has to exist before the
-- first `make test-postgres`.
CREATE DATABASE booking_calendar_test OWNER booking_calendar;
