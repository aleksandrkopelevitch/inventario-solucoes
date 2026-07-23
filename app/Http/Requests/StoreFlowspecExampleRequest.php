<?php

namespace App\Http\Requests;

use App\Models\FlowspecExample;

class StoreFlowspecExampleRequest extends FlowspecExampleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FlowspecExample::class) ?? false;
    }
}
