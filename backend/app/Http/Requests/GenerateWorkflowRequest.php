<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brief'  => ['required', 'string', 'min:10'],
            'engine' => ['sometimes', 'string', 'in:gemini-cli,claude-code'],
        ];
    }
}
