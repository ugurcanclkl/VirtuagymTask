# Design notes

This sample comes from my Payment project's wallet provisioning flow. In that project, `UserObserver` creates a logical wallet, `WalletObserver` emits an event, and a queued listener dispatches `ChainWalletCreateJob`. The address endpoint calls the same job synchronously when the requested chain wallet is missing.

The sample keeps those two paths and makes the entry points easier to follow.

| Original approach                       | Runnable sample                                       |
| ---                                     | ---                                                   |
| User/wallet observers and a queued listener | One explicit registration action creates the wallet and dispatches jobs |
| Multiple blockchain clients and mnemonic storage | An address-generator interface with a deterministic demo implementation |
| Existing-record checks before creating addresses | Existing-record checks plus a database unique constraint and transaction retries |
| Many account roles and payment features | Wallet ownership and a Sanctum token ability |
| Network-specific RabbitMQ handlers and downstream events | One optional deposit consumer ending at the database record |
| Laravel 9 application | Standalone Laravel 12 application with a committed lockfile |

The demo is an adaptation, not an unchanged extract or a production wallet service.

## Why keep a synchronous path?

Usually the worker finishes before the user asks. The fallback covers a delayed or unavailable worker without requiring the frontend to poll for an address. The cost is that the HTTP request can take longer. If address generation were slow or unpredictable, I would return 202 with a status endpoint instead.

The sample reuses the same job and action rather than maintaining separate creation logic. The unique constraint handles duplicate creation attempts. The concurrency test checks two processes, but it is not a production load test.

## Deliberate limits

Addresses are clearly marked demo identifiers. No mnemonic, private key, blockchain validation, confirmation-depth check, chain reorganization handling or actual payment is included.

The RabbitMQ example trusts a restricted internal publisher. It records one confirmed demo deposit per `(chain, tx_hash, event_index)`. Matching redelivery is harmless; changed amount or destination is rejected. It does not implement a complete ledger or a wallet balance API.

Registration is atomic because its queue lives in the same database. Moving to an external broker would need an outbox or reconciliation process; simply sending a message before committing is unsafe. Switching to dispatch-after-commit alone leaves a commit-to-publish crash window.

The application logger deliberately records little diagnostic detail. Production needs richer diagnostics with tested redaction, retention and access controls. Failed-job storage is disabled here to avoid persisting unfiltered exception traces. Email verification, password reset, 2FA and registration abuse prevention beyond rate limiting are omitted.

SQLite is convenient for review. MySQL configuration is illustrative until its integration and concurrency tests run. A real address provider needs idempotent calls, timeouts and a strategy for calls that succeed remotely but fail before local persistence.

## Review and deployment

I would review ownership checks, transaction boundaries, retries and failure responses first. Run the tests and PHP lint, then `composer audit --locked`. Dependency updates should change the lockfile through a reviewed pull request and rerun the suite.

A deployment would serve only `public/`, use HTTPS, inject secrets, share the rate-limit cache across instances, supervise queue workers and restart them after code changes. The broker needs TLS and a restricted publisher/consumer account. The included plain AMQP connection is for a local demo. Migrations, backups, worker failure handling and broker retry/dead-letter policies need staging verification before production.
