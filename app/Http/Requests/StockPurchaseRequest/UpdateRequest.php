<?php

namespace App\Http\Requests\StockPurchaseRequest;


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
        $id = $this->route('stock_purchase');
       
        return [


            'company_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'invoice_date' => 'nullable|date_format:Y-m-d',
            'invoice_date_bs' => 'nullable|date_format:Y-m-d',
            'party_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'store_id' => 'nullable|integer',
            'type' => 'nullable|string|max:255',
            'batch_no' => 'nullable|string|max:255',
            'credit_days' => 'nullable|string|max:255',
            'balance' => 'nullable|string|max:255',
            'bill_number' => 'nullable|string',

            'ref_bill_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('stocks', 'ref_bill_number')
                    ->where(function ($query) {
                        return $query->where('party_id', $this->party_id);
                    })
                    ->ignore($id),
            ],
            'return_bill_number' => 'nullable|string|max:255',
            'reasons' => 'nullable|string|max:255',
            'pan_number' => 'nullable|string|max:255',
            'discount_type' => 'nullable|string|max:255',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_after_vat' =>'nullable|numeric|min:0',
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

            'stock_products' => 'required|array',


            'stock_products.*.id' => 'nullable|integer|exists:stock_products,id',
            'stock_products.*.product_id' => 'required|integer|exists:products,id',
            'stock_products.*.stock_product_id' => 'nullable|integer',
            'stock_products.*.stock_movement_id' => 'nullable|integer',
            'stock_products.*.party_id' => 'nullable|numeric',
            'stock_products.*.expiry_date' => 'nullable|string',
            'stock_products.*.mfd' => 'nullable|string',
            'stock_products.*.type' => 'nullable|string',
            'stock_products.*.price' => 'nullable|numeric|min:0',
            'stock_products.*.discount_percent' => 'nullable|numeric|min:0',
            'stock_products.*.discount_amount' => 'nullable|numeric|min:0',
            'stock_products.*.amount' => 'nullable|numeric|min:0',
            'stock_products.*.batch_no' => 'nullable|string',


            'stock_products.*.stock_type' => 'nullable|string',

            'stock_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',

            'stock_products.*.quantity' => 'nullable|numeric|min:0',
            'stock_products.*.free_quantity' => 'nullable|numeric|min:0',

            'stock_products.*.is_vatable' => 'required|boolean',
            'stock_products.*.field_values' => 'nullable|array',
            'stock_products.*.field_values.*' => 'array',
            'stock_products.*.field_values.*.*.id' => 'nullable|numeric|exists:stock_product_field_values,id',
            'stock_products.*.field_values.*.*.quantity_type' => 'nullable|string',
            'stock_products.*.field_values.*.*.quantity_index' => 'nullable|numeric',
            'stock_products.*.field_values.*.*.key' => 'required|string',
            'stock_products.*.field_values.*.*.value' => 'required|string|max:255',



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
