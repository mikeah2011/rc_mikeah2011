<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => 'required|url:http,https',
            'method' => 'required|in:GET,POST,PUT,DELETE,PATCH,HEAD,OPTIONS',
            'headers' => 'sometimes|array',
            'headers.*' => 'string',
            'body' => 'sometimes|string',
            'idempotency_key' => 'sometimes|string',
            'client_id' => 'sometimes|string',
        ];
    }
}
