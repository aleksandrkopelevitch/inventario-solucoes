<?php

namespace App\Http\Requests;

use App\Models\FlowspecGuideline;

class StoreFlowspecGuidelineRequest extends FlowspecGuidelineRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FlowspecGuideline::class) ?? false;
    }
}
