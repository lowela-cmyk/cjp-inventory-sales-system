<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IdempotencyService
{
    /**
     * Run an idempotent write operation protected by database unique constraints.
     *
     * @param  \Closure(): array{reference_id?: int|string|null, response_reference?: ?string}  $callback
     * @return array{duplicate: bool, reference_id: ?int, response_reference: ?string}
     */
    public function run(string $key, string $action, int $userId, \Closure $callback): array
    {
        $existing = DB::table('idempotency_keys')->where('key', $key)->first();
        if ($existing) {
            if ((int) $existing->user_id !== $userId) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'The idempotency key is assigned to another user.',
                ]);
            }

            return [
                'duplicate' => true,
                'reference_id' => $existing->reference_id !== null ? (int) $existing->reference_id : null,
                'response_reference' => $existing->response_reference,
            ];
        }

        return DB::transaction(function () use ($key, $action, $userId, $callback): array {
            try {
                $recordId = DB::table('idempotency_keys')->insertGetId([
                    'key' => $key,
                    'action' => $action,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                $existing = DB::table('idempotency_keys')->where('key', $key)->first();
                if ($existing) {
                    if ((int) $existing->user_id !== $userId) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => 'The idempotency key is assigned to another user.',
                        ]);
                    }

                    return [
                        'duplicate' => true,
                        'reference_id' => $existing->reference_id !== null ? (int) $existing->reference_id : null,
                        'response_reference' => $existing->response_reference,
                    ];
                }

                throw $e;
            }

            $result = $callback();

            $referenceId = isset($result['reference_id']) ? (int) $result['reference_id'] : null;
            $responseReference = isset($result['response_reference']) ? (string) $result['response_reference'] : null;

            DB::table('idempotency_keys')
                ->where('id', $recordId)
                ->update([
                    'reference_id' => $referenceId,
                    'response_reference' => $responseReference,
                    'updated_at' => now(),
                ]);

            return [
                'duplicate' => false,
                'reference_id' => $referenceId,
                'response_reference' => $responseReference,
            ];
        });
    }

    /**
     * Retry an insert/operation when a QueryException occurs due to a unique code collision on the given column.
     *
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    public function retryOnCollision(string $column, \Closure $operation, int $maxAttempts = 3): mixed
    {
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                return $operation();
            } catch (QueryException $e) {
                if ($attempts < $maxAttempts && $this->isCodeCollision($e, $column)) {
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Check if a QueryException is a unique code collision on the given column.
     */
    public function isCodeCollision(QueryException $e, string $column): bool
    {
        $message = $e->getMessage();

        $isUnique = $e->getCode() === '23000'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, '1062');

        if (! $isUnique) {
            return false;
        }

        $bareColumn = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

        return str_contains($message, $column)
            || str_contains($message, $bareColumn)
            || str_contains($message, str_replace('_', '', $bareColumn));
    }
}
