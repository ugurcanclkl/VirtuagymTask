# Optional RabbitMQ example

Keep this after the wallet walkthrough. It shows how a request from another system is acknowledged after a database write.

`ConsumeDeposits::handle()` in [the command](../app/Console/Commands/ConsumeDeposits.php) connects to RabbitMQ and registers a manual-ack callback. [DepositConsumer::handle()](../app/Messaging/DepositConsumer.php) parses and validates the delivery through [RecordDeposit::handle()](../app/Actions/RecordDeposit.php). The record is committed before ACK.

| Delivery | Result |
| --- | --- |
| Valid new deposit | Insert and commit, then ACK |
| Same event and same payload | Return the existing record, then ACK |
| Same event with a different amount or destination | Reject without requeue; send to the dead-letter queue |
| Invalid JSON, invalid fields or unknown address | Reject without requeue; send to the dead-letter queue |
| Unexpected database error | Requeue and stop the consumer; restart with backoff |
| Connection lost while sending ACK | Close the connection; a redelivery finds the committed record |

The event identity includes chain, transaction hash and event index. A transaction hash alone is not sufficient when a transaction contains multiple transfer events. The amount is an integer in demo minor units; 100 units represents 1 DEMO. No real token conversion is attempted.

## Without a broker

First create an address through the HTTP endpoint. Copy [examples/deposit.json](../examples/deposit.json) to an ignored local file such as `storage/deposit.json` and replace its address with the returned demo address.

```sh
php artisan deposits:replay storage/deposit.json
php artisan deposits:replay storage/deposit.json
```

Both runs should print ACK; the `deposits` table has one row. This calls the same consumer handler with local ACK/reject callbacks. It does **not** test an AMQP connection.

## With a local broker

Set `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD` and optionally `RABBITMQ_VHOST` in your untracked environment file.

```sh
php artisan deposits:consume
```

The command declares the durable `wallet-demo.deposits` queue and a dead-letter exchange/queue named `wallet-demo.rejected`. It consumes one unacknowledged message at a time. Publish the JSON example as a **persistent message** through the broker's management UI, using the default exchange and routing key `wallet-demo.deposits`. A publisher should use confirms when delivery matters.

For a one-delivery check, use `php artisan deposits:consume --once`. Invalid or unknown-address messages remain in the rejected queue for inspection/replay. Their bodies are still in the broker, even though the application does not log them; restrict queue access and set retention appropriately.

The consumer exits after a transient failure to avoid an immediate requeue loop. Production would supervise it with restart backoff and a bounded retry policy. This local example has no TLS setup, publisher implementation, broker provisioning or production retry infrastructure.
