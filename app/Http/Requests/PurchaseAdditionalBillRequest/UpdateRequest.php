<?php

namespace App\Http\Requests\PurchaseAdditionalBillRequest;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class UpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {

        $id = $this->route('purchase_additionalbill');

        return [
            'stock_id' => [
                'required',
                'integer'



            ],
            'is_active' => 'boolean|nullable',
            'additionals' => 'required|array',
            'additionals.*.id' => 'nullable|integer|exists:purchase_additionalbill_details,id',
            'additionals.*.purchase_additionalbill_id' => 'nullable|integer|exists:purchase_additionalbills,id',
            'additionals.*.account_head_id' => 'nullable|integer',
            'additionals.*.amount' => 'nullable|numeric',




        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = collect($validator->errors()->all())->values();
        throw new HttpResponseException(response()->json([
            'error' => 'Validation failed',
            'messages' => [
                $validator->errors()->first()
            ],
            'status' => 422,
        ], 422));
    }
}
