<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignQzMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['manager', 'waiter'], true);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'request' => ['required', 'string', 'max:200000'],
        ];
    }
}
