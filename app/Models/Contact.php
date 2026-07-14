<?php

namespace App\Models;

use App\Enums\ContactType;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'person_id',
        'type',
        'value',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'type'       => ContactType::class,
            'is_primary' => 'boolean',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
