<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'endpoint_key' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'idempotency_key' => ['required', 'string', 'max:200', 'regex:/^[\\x21-\\x7E]+$/'],
            'payload' => ['nullable'],
            'body_base64' => ['nullable', 'string'],
            'content_type' => ['required', 'string', 'max:150', 'not_regex:/[\\r\\n]/'],
            'headers' => ['sometimes', 'array'],
            'headers.*' => ['string', 'max:2000', 'not_regex:/[\\r\\n]/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $hasPayload = array_key_exists('payload', $this->all());
            $hasBase64 = array_key_exists('body_base64', $this->all());

            if ($hasPayload === $hasBase64) {
                $validator->errors()->add('payload', 'Provide exactly one of payload or body_base64.');
            }

            if ($hasBase64 && base64_decode((string) $this->input('body_base64'), true) === false) {
                $validator->errors()->add('body_base64', 'The body_base64 field must be valid base64.');
            }
        }];
    }
}
