<?php

namespace App\Support\Filament;

use App\Models\TableView;

/**
 * One request's answers to "which view does this list open on".
 *
 * `TableView::defaultFor()` is asked twice per admin list — once by the mount hook that may
 * redirect, once while the saved-views menu is built — and both go to the database. Bound
 * `scoped`, never `singleton`: a queue worker outlives the request, and an answer keyed to a
 * person must not survive into somebody else's.
 *
 * @var array<string, TableView|null>
 */
class TableViewDefaultMemo
{
    /** @var array<string, mixed> */
    public array $answers = [];
}
