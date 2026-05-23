<?php

namespace App\Support;

use App\Models\Operator;
use Illuminate\Support\Facades\Session;

class CurrentOperator
{
    private const SESSION_KEY = 'current_operator_id';

    public static function id(): ?int
    {
        $id = Session::get(self::SESSION_KEY);

        return $id ? (int) $id : null;
    }

    public static function get(): ?Operator
    {
        $id = self::id();

        return $id ? Operator::find($id) : null;
    }

    public static function set(?int $operatorId): void
    {
        if ($operatorId === null) {
            Session::forget(self::SESSION_KEY);

            return;
        }

        Session::put(self::SESSION_KEY, $operatorId);
    }

    public static function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    public static function isAll(): bool
    {
        return self::id() === null;
    }
}
