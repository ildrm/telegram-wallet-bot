<?php

namespace App\Custody;

interface CustodyProvider
{
    public function allocateAddress(string $walletCode): string;

    public function validateAddress(string $destination): bool;

    /** @return array{network_fee:string,expires_at:string} */
    public function quoteWithdrawal(string $destination, string $amount): array;

    /** A provider must enforce permanent idempotency, including identical request parameters. */
    public function submitWithdrawal(string $idempotencyKey, string $destination, string $amount, string $maxNetworkFee): array;

    /** null means authoritative absence; transport errors MUST throw, never masquerade as absence. */
    public function withdrawalStatus(string $idempotencyKey): ?array;

    /** Confirmed, spendable custody units across the same accounting scope. */
    public function balance(): string;
}
