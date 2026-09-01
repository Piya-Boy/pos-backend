<?php

namespace App\Pos\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

// / Redis atomic lock, ports Apps Script LockService (Services.js tryLock).
// / withLock throws AppError('BUSY', $busyMessage) if the lock isn't acquired.
class LockManager
{
    /**
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public function withLock(string $name, int $ms, string $busyMessage, callable $fn)
    {
        $seconds = (int) max(1, ceil($ms / 1000));
        $lock = Cache::lock($name, $seconds + 5);
        try {
            // block up to $seconds waiting for the lock
            return $lock->block($seconds, $fn);
        } catch (LockTimeoutException) {
            throw new AppError('BUSY', $busyMessage);
        } finally {
            optional($lock)->release();
        }
    }
}
