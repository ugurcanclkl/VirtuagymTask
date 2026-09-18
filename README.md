# VirtuagymTask

This is a small adaptation of the wallet provisioning flow from my Payment project.

When someone registers, the application creates their user and wallet records and queues jobs to prepare deposit addresses. If they ask for an address before the worker gets there, the request runs the same job synchronously. A later job or repeated request reuses the existing address.

There is also a short RabbitMQ consumer showing how an incoming deposit notification becomes a database record. It is optional for the main walkthrough.

**All addresses and deposits are for demonstration.** The address generator produces `demo-eth_...` and `demo-tron_...` identifiers, not usable blockchain addresses. No private keys are generated and no funds are moved.

## Run locally

Requires 64-bit PHP 8.2+, Composer, PDO SQLite, mbstring, OpenSSL, sockets, and DOM/XML. The lockfile pins the dependencies.

```sh
composer install
php tools/setup.php
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

On a PHP installation where sockets is installed but disabled, use `php -d extension=sockets` for PHP commands, or enable it in your local PHP configuration.

Use [examples/requests.http](examples/requests.http) to register, log in and request an address. Copy the returned wallet ID and bearer token into the local variables. Keep the queue worker stopped for the first request so you can see the synchronous fallback.

Then, in another terminal:

```sh
php artisan queue:work database --queue=wallets --stop-when-empty --sleep=0
```

Request the same address again. It stays the same. Register another user and run the worker *before* asking for an address to see the normal background path.

The demo uses the SQLite file `database/wallet-demo.sqlite`. The setup command creates it, and the migration creates the application tables.

## Where to start reading

- **Registration:** [AuthController::register()](app/Http/Controllers/AuthController.php) calls [RegisterUser::handle()](app/Actions/RegisterUser.php).
- **Main request:** `POST /api/wallets/{wallet}/deposit-address` enters [WalletController::address()](app/Http/Controllers/WalletController.php).
- **Job and database logic:** [ProvisionChainWallet::handle()](app/Jobs/ProvisionChainWallet.php) delegates to [EnsureChainWallet::handle()](app/Actions/EnsureChainWallet.php).
- **Response:** [ApiResponse::success()](app/Http/ApiResponse.php); failures go through [ApiErrorHandler::render()](app/Exceptions/ApiErrorHandler.php).
- **HTTP boundary:** [public/index.php](public/index.php) captures the request and lets Laravel send the response.

[Request walkthrough](docs/REQUEST-FLOW.md) follows both paths. [Design notes](docs/CHANGES.md) cover the original implementation, trade-offs and limits.

## Tests and optional messaging

```sh
composer test
```

Tests use SQLite in memory, real bearer tokens and the real database queue worker. The concurrency test uses two child processes and a temporary SQLite database. No test requires a broker or a real blockchain.

[Verification](VERIFICATION.md) records the checks run. [RabbitMQ notes](docs/RABBITMQ.md) explain the optional consumer and a local replay command that exercises its handler without a broker.

To make a source-only ZIP, run `python tools/package.py`. Real environment files, databases, logs and installed dependencies are excluded from Git and the ZIP.
