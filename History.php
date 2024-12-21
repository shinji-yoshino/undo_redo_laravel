<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class History extends Model
{
    use HasFactory;

    const CURRENT = 1;
    const UNDO_TARGET = 2;
    const REDO_TARGET = 3;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'status',
    ];

    public function history_shift_staffs(): HasMany
    {
        return $this->hasMany(HistoryShiftStaff::class);
    }

}
