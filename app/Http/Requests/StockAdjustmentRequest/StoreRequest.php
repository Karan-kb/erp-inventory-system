<?php

namespace App\Http\Requests\StockAdjustmentRequest;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class StoreRequest extends FormRequest
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
        return [


            'company_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'invoice_date' => 'nullable|date_format:Y-m-d',
            'invoice_date_bs' => 'nullable|date_format:Y-m-d',
            'type' => 'nullable|string|max:255',
            'bill_number' => 'nullable|string',
            // 'address' => 'nullable|string',


            'party_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',

            'batch_no' => 'nullable|string|max:255',
            'credit_days' => 'nullable|string|max:255',
            'balance' => 'nullable|string|max:255',

            'ref_bill_number' => 'nullable|string|max:255',
            'return_bill_number' => 'nullable|string|max:255',
            'reasons' => 'nullable|string|max:255',
            
            
            
            'remarks' => 'nullable',
            'stock_details' => 'required|array',
            'stock_details.*.product_id' => 'required|integer|exists:products,id',
            'stock_details.*.type' => 'nullable|string',
            'stock_details.*.stock_type' => 'nullable|string',
            'stock_details.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'stock_details.*.quantity' => 'required|numeric',
            'stock_details.*.is_vatable' => 'required|boolean',
            'stock_details.*.stock_product_id' => 'nullable|integer',
            'stock_details.*.stock_movement_id' => 'nullable|integer',
            'stock_details.*.party_id' => 'nullable|integer',
            'stock_details.*.expiry_date' => 'nullable|string',
            'stock_details.*.mfd' => 'nullable|string',
          
            'stock_details.*.batch_no' => 'nullable|string',
            'stock_details.*.direction' => 'nullable|string',
            'stock_details.*.field_values' => 'nullable|array',
            'stock_details.*.field_values.*' => 'array',
            'stock_details.*.field_values.*.*.key' => 'nullable|string',
            'stock_details.*.field_values.*.*.value' => 'nullable|string|max:255',
            'stock_details.*.field_values.*.*.product_id' => 'nullable|numeric',

            'stock_details.*.field_values.*.*.stock_product_id' => 'nullable|numeric',
            'stock_details.*.field_values.*.*.stock_movement_id' => 'nullable|numeric',
            'stock_details.*.field_values.*.*.quantity_index' => 'nullable|numeric',
            'stock_details.*.field_values.*.*.quantity_type' => 'nullable|string|max:255',



        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Validation failed',
            'messages' => $validator->errors(),
            'status' => 422,
        ], 422));
    }
}
