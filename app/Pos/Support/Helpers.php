<?php

namespace App\Pos\Support;

use Carbon\Carbon;
use Illuminate\Support\Str;

// / Ports cp-pos Code.js helpers.

function money(float|int|string $v): float
{
    $n = is_numeric($v) ? (float) $v : 0.0;

    return round(($n + PHP_FLOAT_EPSILON) * 100) / 100;
}

function uuidPrefixed(string $prefix): string
{
    return $prefix.substr(str_replace('-', '', (string) Str::uuid()), 0, 20);
}

function sha256(string $v): string
{
    return hash('sha256', $v);
}

function normalizeText(mixed $v, int $max = 0): string
{
    // is_scalar guard: a client can send an array/object where a string is
    // expected; casting that directly would throw. Coerce non-scalars to ''.
    $text = trim(str_replace(['<', '>'], '', is_scalar($v) ? (string) $v : ''));

    return $max > 0 ? mb_substr($text, 0, $max) : $text;
}

function numberOr(mixed $v, float $fallback = 0): float
{
    return is_numeric($v) ? (float) $v : $fallback;
}

function boolish(mixed $v): bool
{
    if (! is_scalar($v)) {
        return false;
    }
    $s = (string) $v;

    return $v === true || strtolower($s) === 'true' || $s === '1';
}

function nowIso(): string
{
    return Carbon::now('Asia/Bangkok')->format('Y-m-d\TH:i:sP');
}

function sanitizeHttpsUrl(mixed $v): string
{
    $url = normalizeText($v, 1000);
    if ($url === '') {
        return '';
    }
    if (! preg_match('#^https://#i', $url)) {
        throw new AppError('INVALID_URL', 'ลิงก์รูปภาพต้องขึ้นต้นด้วย https://');
    }

    return $url;
}

function apiOk(mixed $data): array
{
    return ['ok' => true, 'data' => $data ?? null];
}

/**
 * Coerces untrusted request input to a string. Scalars stringify; arrays,
 * objects, and null become ''. Guards against `(string) $array` throwing a
 * PHP "Array to string conversion" Error when a client sends the wrong type.
 */
function strInput(mixed $v): string
{
    return is_scalar($v) ? (string) $v : '';
}
