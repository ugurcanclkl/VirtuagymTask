# Request walkthrough

The main example is a user asking for a deposit address before background provisioning has finished.

## Registration prepares the work

`POST /api/auth/register` reaches [AuthController::register](../app/Http/Controllers/AuthController.php). [RegisterRequest](../app/Http/Requests/RegisterRequest.php) validates the name, email and confirmed password. Email is normalized to lowercase. Passwords are limited to 72 bytes because this application uses bcrypt.

[RegisterUser::handle](../app/Actions/RegisterUser.php) runs one database transaction:

1. Store the user with `Hash::make`.
2. Create their single logical wallet with a public UUID.
3. Queue one `ProvisionChainWallet` job for each of the two demo chains.
4. Commit and return HTTP 201 with the user and wallet ID. No password hash is returned.

The jobs use Laravel's **database queue on the same connection** as the application tables. They are inserted inside the registration transaction, so a rollback removes the user, wallet and jobs together. Another connection cannot consume the jobs before the commit. This is intentional; switching to Redis or RabbitMQ for these jobs would require a different transaction strategy, such as an outbox.

The job contains a wallet ID and chain enum, not the registration request or password. The wallet exists immediately; the chain-specific addresses may not exist yet.

## Login and authorization

`POST /api/auth/login` looks up the user with an Eloquent bound query and calls `Hash::check`. Unknown emails and wrong passwords return the same error; unknown users also take a dummy hash comparison path. Sanctum issues a bearer token with `wallet:access` ability and stores a hash of it. Tokens expire after 60 minutes. Logout deletes the current token.

| Endpoint | Protection |
| --- | --- |
| `POST /api/auth/register` | Registration validation and per-IP rate limit |
| `POST /api/auth/login` | Validation; limits per IP and email/IP |
| `POST /api/auth/logout` | `auth:sanctum` |
| `POST /api/wallets/{wallet}/deposit-address` | `auth:sanctum`, rate limit, token ability, wallet ownership |

[WalletController::address](../app/Http/Controllers/WalletController.php) queries through the authenticated user's `wallet()` relationship. Guessing another wallet ID therefore returns 404. A valid token without the required ability returns 403. Only `demo-eth` and `demo-tron` are accepted. Extra request fields are not passed into model creation.

## Background path and synchronous fallback

Registration queues [ProvisionChainWallet](../app/Jobs/ProvisionChainWallet.php). A worker eventually runs its `handle()` method.

The address endpoint first queries the selected chain wallet. If it exists, it returns it. If it is absent, the controller calls `Bus::dispatchSync(new ProvisionChainWallet(...))` and queries again before returning HTTP 200. That runs immediately in the HTTP process. It does not wait for a queue worker or add another database job.

Both paths reach [EnsureChainWallet::handle](../app/Actions/EnsureChainWallet.php). Inside a transaction it:

1. Loads the logical wallet with `lockForUpdate()`.
2. Returns an existing address for that chain if present.
3. Calls the injected `AddressGenerator`.
4. Uses Eloquent `createOrFirst` to persist the chain wallet.

The database has a unique constraint on `(wallet_id, chain)`. This is essential: an initial existence query alone cannot prevent duplicates. Row locking helps on engines that support it. SQLite has no row-level `FOR UPDATE`; the unique constraint and transaction retries still protect this sample, and the test runs two competing PHP processes against the same SQLite file.

The demo generator is deterministic and has no external side effects. A real address service would also need a stable idempotency key. Database rollback cannot undo work done in an external service.

The queued copy remains after synchronous fallback. When it runs, it finds and reuses the existing address. Jobs allow three attempts with backoff; a failed HTTP fallback returns a safe error and leaves the queued work available.

## Response, failures and logging

[ApiResponse](../app/Http/ApiResponse.php) builds the success JSON. [bootstrap/app.php](../bootstrap/app.php) registers the common [ApiErrorHandler](../app/Exceptions/ApiErrorHandler.php).

| Failure | HTTP | Application code |
| --- | --- | --- |
| Invalid registration or chain input | 422 | 1000 |
| Duplicate email caught during a concurrent insert | 422 | 1005 |
| Missing, invalid or expired token | 401 | 1001 |
| Incorrect login credentials | 401 | 1003 |
| Insufficient token ability | 403 | 1004 |
| Missing wallet or another user's wallet | 404 | 404 |
| Rate limit exceeded | 429 | 429 |
| Unexpected database or provisioning failure | 500 | 9000 |

Laravel's transaction helper catches failures, rolls back and rethrows. The central handler maps HTTP errors; extra controller catch blocks would duplicate it. Queue workers use Laravel's retry handling. The optional message consumer has explicit catch blocks because ACK/reject decisions belong to that transport.

Unexpected errors log only the exception class and request ID. Expected HTTP errors log the status and application code. Message handling generates its own delivery ID. Logs go to `storage/logs/laravel.log`; raw exception messages, SQL, passwords, bearer tokens, addresses and message bodies are not passed to the application logger. Tests inspect the actual output after forced failures.

The demo disables storage of raw failed-job exceptions. After exhausted attempts, operators would need a reviewed failure store and replay process; that operational facility is not implemented here.

## Database and class responsibilities

- `users`: authenticated people and password hashes.
- `wallets`: one logical wallet per user.
- `chain_wallets`: one deposit address per logical wallet and chain.
- `jobs`: work awaiting the database queue worker.
- `deposits`: accepted notifications from the optional message consumer.
- `personal_access_tokens`: Sanctum token hashes.

Controllers handle HTTP, actions own database operations, the job connects the queue to the same action, and the address interface isolates the external-service boundary. Models define relationships. There is no additional repository layer around Eloquent.

Eloquent binds values to queries. Transactions and unique constraints enforce the invariants in the database. SQLite has no password; [config/database.php](../config/database.php) also shows how MySQL credentials would come from environment variables. MySQL is not covered by this sample's tests.
