<?php

namespace App\Models;

use App\Models\Contracts\UsedByLunchDays;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealOption extends Model implements UsedByLunchDays
{
    use HasFactory;

    protected $fillable = ['name', 'is_active', 'sort'];

    protected $casts = ['is_active' => 'boolean'];

    /** Lunch days offering this option on any menu; deleting it would cascade members' picks away. */
    public function lunchDaysCount(): int
    {
        return Lunch::whereHas('options', fn ($options) => $options->where('meal_option_id', $this->id))->count();
    }
}
