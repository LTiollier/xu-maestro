<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filename'     => ['required', 'regex:/^[a-z0-9-]+$/'],
            'yaml_content' => ['required', 'string'],
            'force'        => ['boolean'],
        ];
    }
}
