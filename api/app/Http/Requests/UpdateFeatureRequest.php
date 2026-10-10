<?php

namespace App\Http\Requests;

class UpdateFeatureRequest extends StoreFeatureRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            ...self::sharedRules($this->targetProjectId()),
        ];
    }
}
