<?php

namespace App\Http\Requests\StockPurchaseReturnRequest;


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
            'party_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'type' => 'nullable|string|max:255',
            'batch_no' => 'nullable|string|max:255',
            'credit_days' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric|min:0',
            'purchase_bill_number' => 'nullable|string',
            'bill_number' => 'required|string',
            'ref_bill_number' => 'nullable|string|max:255',
            'return_bill_number' => 'nullable|string|max:255',
            'reasons' => 'nullable|string|max:255',
            'pan_number' => 'nullable|string|max:255',
            'discount_type' => 'nullable|string|max:255',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_after_vat' => 'nullable|numeric|min:0',
            'sub_total_before_discount' => 'nullable|numeric|min:0',
            'taxable_amount' => 'nullable|numeric|min:0',
            'non_taxable_amount' => 'nullable|numeric|min:0',
            'excise_duty' => 'nullable|numeric|min:0',
            'vat_percent' => 'nullable|numeric|min:0',
            'health_insurance' => 'nullable|numeric|min:0',
            'freight_amount' => 'nullable|numeric|min:0',
            'roundoff_type' => 'nullable',
            'roundoff_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'payment' => 'nullable',
            'remarks' => 'nullable',
            'address' => 'nullable|string',

            'stock_transactions' => 'required|array',

            'stock_transactions.*.product_id' => 'required|integer|exists:products,id',
            'stock_transactions.*.stock_product_id' => 'nullable|integer',
            'stock_transactions.*.stock_movement_id' => 'nullable|integer',
            'stock_transactions.*.party_id' => 'nullable|integer',
            'stock_transactions.*.expiry_date' => 'nullable|string',
            'stock_transactions.*.mfd' => 'nullable|string',
            'stock_transactions.*.type' => 'nullable|string',
            'stock_transactions.*.price' => 'nullable|numeric|min:0',
            'stock_transactions.*.discount_percent' => 'nullable|numeric|min:0',
            'stock_transactions.*.discount_amount' => 'nullable|numeric|min:0',
            'stock_transactions.*.amount' => 'nullable|numeric|min:0',
            'stock_transactions.*.batch_no' => 'nullable|string',
        
          
            'stock_transactions.*.direction' => 'nullable|string',

            'stock_transactions.*.measure_unit_id' => 'required|integer|exists:measure_units,id',

            'stock_transactions.*.quantity' => 'nullable|numeric|min:0',
            'stock_transactions.*.free_quantity' => 'nullable|numeric|min:0',

            'stock_transactions.*.is_vatable' => 'required|boolean',
            'stock_transactions.*.field_values' => 'nullable|array',
            'stock_transactions.*.field_values.*' => 'array',
            'stock_transactions.*.field_values.*.*.stock_product_id' => 'nullable|integer',
            'stock_transactions.*.field_values.*.*.quantity_index' => 'required|integer',
            'stock_transactions.*.field_values.*.*.quantity_type' => 'nullable|string',
          



        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Validation failed',
            'messages' => [
                $validator->errors()->first()
            ],
            'status' => 422,
        ], 422));
    }
}
