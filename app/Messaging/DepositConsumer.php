<?php

namespace App\Messaging;

use App\Actions\RecordDeposit;
use App\Exceptions\RejectedDeposit;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;
use Throwable;

class DepositConsumer
{
    public function __construct(private RecordDeposit $deposits) {}

    public function handle(string $body, Closure $ack, Closure $reject): void
    {
        $deliveryId = (string) Str::uuid();
        try {
            if (strlen($body) > 4096) {
                throw new RejectedDeposit('Message is too large.');
            }
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_is_list($payload)) {
                throw new RejectedDeposit('Expected a JSON object.');
            }
            $this->deposits->handle($payload);
        } catch (JsonException|ValidationException|RejectedDeposit $error) {
            Log::notice('Deposit rejected', ['delivery_id' => $deliveryId, 'exception_type' => get_class($error)]);
            $reject(false); // Dead-letter malformed, unknown-address or conflicting messages.
            return;
        } catch (Throwable $error) {
            Log::error('Deposit processing failed', ['delivery_id' => $deliveryId, 'exception_type' => get_class($error)]);
            $reject(true);
            // Stop rather than repeatedly consuming the same failing message.
            throw new RuntimeException('Deposit processing failed; consumer stopped. Check the application log.');
        }

        // Outside the catch: if ACK fails, the committed record safely handles redelivery.
        $ack();
    }
}
