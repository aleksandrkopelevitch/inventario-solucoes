<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call (or plain association) between two catalog solutions, as one UML
 * diagram of one documentation page states it. Derived data — written only by
 * App\Actions\Documentation\SyncPageSolutionLinks, read only by
 * App\Services\SolutionRelationshipService. See the migration's docblock.
 */
class UmlSolutionLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'documentation_page_id',
        'from_solution_id',
        'to_solution_id',
        'directed',
        'diagram_position',
        'ordinal',
        'diagram_type',
        'diagram_title',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'directed'         => 'boolean',
            'diagram_position' => 'integer',
            'ordinal'          => 'integer',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(DocumentationPage::class, 'documentation_page_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'from_solution_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'to_solution_id');
    }
}
