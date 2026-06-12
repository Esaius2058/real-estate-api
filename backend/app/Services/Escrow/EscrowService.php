<?php

declare(strict_types=1);

namespace App\Services\Escrow;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class EscrowService
{
    /**
     * Process the validated payload from the payment gateway webhook.
     */
    public function processWebhookPayload(array $payload): void
    {
        $transactionReference = $payload['data']['reference'] ?? null;
        $status = $payload['data']['status'] ?? null;

        if (!$transactionReference || $status !== 'successful') {
            Log::warning('Ignored escrow webhook: Invalid payload or failed status', $payload);
            return;
        }

        $transaction = Transaction::where('reference', $transactionReference)->first();

        if (!$transaction) {
            Log::error("Escrow webhook failed: Transaction {$transactionReference} not found.");
            return;
        }

        // 1. Update the transaction record
        $transaction->update([
            'escrow_status' => 'funded',
            'funded_at'     => now(),
        ]);

        // 2. Automatically advance the Lead's CRM stage
        if ($transaction->lead) {
            $transaction->lead->update(['kanban_stage' => 'escrow']);
        }

        // 3. Trigger domain events (e.g., for notifications)
        // event(new \App\Events\EscrowFunded($transaction));
    }
}