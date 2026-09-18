# Verification — 2026-09-19

Environment: Windows, PHP 8.3.0, SQLite, Laravel 12.69.2, Sanctum 4.3.3, PHPUnit 11.5.56 and php-amqplib 3.7.4. Exact versions are in `composer.lock`.

- **24 tests, 141 assertions passed.** Tests run through the real HTTP kernel, Sanctum tokens and database queue worker.
- Registration creates the user, wallet and two jobs without generating addresses. A forced failure on the second queue insert rolls all those writes back.
- Both worker-first and request-first provisioning paths passed. A later queued job reuses the address created synchronously.
- A failed background job was released and succeeded on retry. A failed HTTP fallback left the queued work available.
- Two separate PHP processes provisioned the same wallet/chain against a temporary SQLite database. Both returned the same record and only one row was stored.
- Ownership, token ability, expiry, logout, rate limiting, input validation and bcrypt byte limits were exercised.
- Deposit callback tests covered commit-before-ACK, matching redelivery, distinct event indexes, conflicting redelivery, invalid messages, database failure/requeue and a lost ACK after commit.
- Actual log output was checked after forced failures; passwords, bearer tokens, SQL details and deposit payload identifiers were excluded.
- Live local HTTP smoke test passed: registration 201, login 200, synchronous address response 200, real worker execution, unchanged address on repeat, and logout 200.
- The local replay command accepted the same deposit message twice and stored one record. The development HTTP server was stopped afterwards.
- Route inspection showed the four documented API endpoints. The RabbitMQ consumer command is registered and returns a clear error when credentials are missing.
- Composer validation passed; the dependency audit reported no security advisories at the time of the check.
- All 46 PHP source/configuration/test/tool files passed syntax checks, and all 27 local documentation links resolved.
- The 61-file ZIP was extracted into a separate directory. A fresh Composer install from the lockfile, setup, key generation, migration and test run passed there too: **24 tests, 141 assertions**. The extracted copy used its own dependencies and database.
- ZIP integrity and file-selection checks passed; actual environment files, databases, logs, dependencies and local backups were excluded.

**Not verified:** a live RabbitMQ connection, broker dead-letter routing/recovery, MySQL, real blockchain address generation or production deployment. Docker's broker environment was not running, so message-handler tests and local replay must not be mistaken for a live AMQP test. The demo never sends payments.
