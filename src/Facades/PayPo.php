<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Facades;

use Beziworld\PayPo\PayPoManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Beziworld\PayPo\PayPoClient connection(?string $name = null)
 * @method static \Beziworld\PayPo\PayPoClient using(\Beziworld\PayPo\PayPoConfig|array<string, mixed> $config)
 * @method static void resolveConnectionsUsing(\Closure(string): (\Beziworld\PayPo\PayPoConfig|array<string, mixed>|null) $resolver)
 * @method static string getDefaultConnection()
 * @method static list<string> connectionNames()
 * @method static void purge(?string $name = null)
 * @method static \Beziworld\PayPo\PayPoConfig config()
 * @method static ?string connectionName()
 * @method static ?string notifyUrl()
 * @method static \Beziworld\PayPo\Data\CreatedTransaction createTransaction(\Beziworld\PayPo\Data\TransactionRequest $request)
 * @method static \Beziworld\PayPo\Data\Transaction transaction(string $transactionId)
 * @method static \Beziworld\PayPo\Data\OperationResult confirm(string $transactionId)
 * @method static \Beziworld\PayPo\Data\OperationResult cancel(string $transactionId)
 * @method static \Beziworld\PayPo\Data\OperationResult refund(string $transactionId, int $amount, ?string $referenceRefundId = null)
 * @method static \Beziworld\PayPo\Data\Notification parseNotification(string $rawBody, ?string $signature, string|list<string> $paths, bool $verifySignature = true)
 *
 * @see \Beziworld\PayPo\PayPoManager
 * @see \Beziworld\PayPo\PayPoClient
 */
final class PayPo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PayPoManager::class;
    }
}
