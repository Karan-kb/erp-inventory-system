<?php

namespace App\Http\Requests\StockTransferRequest;


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
        $id = $this->route('stock-transfer');
        return [
            'company_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'invoice_date' => 'nullable|date_format:Y-m-d',
            'invoice_date_bs' => 'nullable|date_format:Y-m-d',
            'type' => 'nullable|string|max:255',
            'bill_number' => [
                'nullable',
                'string',
               
            ],
            'address' => 'nullable|string',


            'party_id' => 'nullable|integer',
            'from_branch' => 'nullable|integer',
            'to_branch' => 'nullable|integer',
            'location_id' => 'nullable|integer',

            'batch_no' => 'nullable|string|max:255',
            'credit_days' => 'nullable|string|max:255',
            'balance' => 'nullable|string|max:255',

            'ref_bill_number' => 'nullable|string|max:255',
            'return_bill_number' => 'nullable|string|max:255',
            'reasons' => 'nullable|string|max:255',
            'discount_type' => 'nullable|string|max:255',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_after_vat' => 'nullable|numeric|min:0',
            'sub_total_before_discount' => 'nullable|numeric|min:0',
            'taxable_amount' =>'nullable|numeric|min:0',
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
            'stock_transfers' => 'required|array',
            'stock_transfers.*.product_id' => 'required|integer|exists:products,id',
            'stock_transfers.*.type' => 'nullable|string',
            'stock_transfers.*.stock_type' => 'nullable|string',
            'stock_transfers.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'stock_transfers.*.quantity' => 'required|numeric|min:0',
            'stock_transfers.*.is_vatable' => 'required|boolean',
            'stock_transfers.*.stock_product_id' => 'nullable|integer',
            'stock_transfers.*.stock_movement_id' => 'nullable|integer',
            'stock_transfers.*.party_id' => 'nullable|integer',
            'stock_transfers.*.expiry_date' => 'nullable|string',
            'stock_transfers.*.mfd' => 'nullable|string',
            'stock_transfers.*.price' => 'nullable|numeric|min:0',
            'stock_transfers.*.discount_percent' => 'nullable|numeric|min:0',
            'stock_transfers.*.discount_amount' =>'nullable|numeric|min:0',
            'stock_transfers.*.amount' => 'nullable|numeric|min:0',
            'stock_transfers.*.batch_no' => 'nullable|string',
            'stock_transfers.*.direction' => 'nullable|string',
            'stock_transfers.*.field_values.*' => 'array',
            'stock_transfers.*.field_values.*.*.stock_product_id' => 'nullable|integer',
            'stock_transfers.*.field_values.*.*.stock_transaction_id' => 'nullable|integer',
            'stock_transfers.*.field_values.*.*.stock_movement_id' => 'nullable|integer',
            'stock_transfers.*.field_values.*.*.quantity_index' => 'required|integer',
            'stock_transfers.*.field_values.*.*.quantity_type' => 'nullable|string',





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
